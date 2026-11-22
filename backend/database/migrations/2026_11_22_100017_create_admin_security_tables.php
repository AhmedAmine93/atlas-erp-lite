<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M17: additive and tenant-scoped administration tables.
 * Production rollback requires a retention decision: do not delete historical
 * settings, allocations, or invitations while any issued documents reference them.
 */
return new class extends Migration {
    private const TENANT_TABLES = ['admin_setting_versions', 'admin_document_series', 'admin_document_allocations', 'admin_invitations'];

    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $t) {
            $t->timestamp('suspended_at')->nullable()->index();
        });
        Schema::create('admin_setting_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('key', 64);
            $t->string('value_type', 16);
            $t->json('value');
            $t->unsignedInteger('version');
            $t->timestamp('effective_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','key','version'], 'admin_settings_version_uq');
            $t->index(['company_id','key','effective_at'], 'admin_settings_effective_idx');
        });
        Schema::create('admin_document_series', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('establishment_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('scope_key', 64); // "company" or "site:<establishment id>"
            $t->string('document_type', 24);
            $t->unsignedSmallInteger('period_year');
            $t->string('prefix', 32);
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','scope_key','document_type','period_year'], 'admin_series_scope_uq');
            $t->unique(['company_id','id'], 'admin_series_tenant_uq');
        });
        Schema::create('admin_document_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('series_id')->constrained('admin_document_series')->restrictOnDelete();
            $t->unsignedBigInteger('sequence_value');
            $t->string('document_number', 100);
            $t->string('operation_key', 80);
            $t->string('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','series_id','sequence_value'], 'admin_allocation_sequence_uq');
            $t->unique(['company_id','operation_key'], 'admin_allocation_idempotence_uq');
        });
        Schema::create('admin_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('email');
            $t->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['company_id','email','accepted_at'], 'admin_invitation_email_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE admin_document_allocations ADD CONSTRAINT admin_allocation_series_fk FOREIGN KEY (company_id, series_id) REFERENCES admin_document_series (company_id, id)');
            foreach (self::TENANT_TABLES as $table) {
                DB::statement('ALTER TABLE public."'.$table.'" ENABLE ROW LEVEL SECURITY');
                DB::statement('CREATE POLICY atlas_f20_runtime_tenant_policy ON public."'.$table.'" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting(\'atlas.tenant_id\', true), \'\')::bigint) WITH CHECK (company_id = NULLIF(current_setting(\'atlas.tenant_id\', true), \'\')::bigint)');
                $rights = in_array($table, ['admin_setting_versions','admin_document_allocations','admin_invitations'], true)
                    ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
                if ($table === 'admin_invitations') $rights = 'SELECT, INSERT, UPDATE';
                DB::statement('GRANT '.$rights.' ON public."'.$table.'" TO atlas_app_runtime');
                DB::statement('REVOKE DELETE ON public."'.$table.'" FROM atlas_app_runtime');
                $seq = DB::select("SELECT pg_get_serial_sequence(?, 'id') AS name", ['public.'.$table])[0]->name ?? null;
                if ($seq) DB::statement('GRANT USAGE, SELECT ON SEQUENCE '.$seq.' TO atlas_app_runtime');
            }
        }
    }

    public function down(): void
    {
        // Restrict rollback in production to an explicit retention-approved migration.
        if (app()->environment('production')) {
            throw new RuntimeException('M17 historical data rollback requires an approved retention/migration plan.');
        }
        foreach (array_reverse(self::TENANT_TABLES) as $table) Schema::dropIfExists($table);
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn('suspended_at'));
    }
};
