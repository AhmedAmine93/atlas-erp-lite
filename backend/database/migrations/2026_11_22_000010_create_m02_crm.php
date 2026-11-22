<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M02 CRM: additive schema. The legacy customers and issued invoice snapshots
 * remain authoritative; this migration never rewrites their history.
 */
return new class extends Migration {
    private const TABLES = [
        'crm_prospects', 'crm_contacts', 'crm_opportunities', 'crm_activities',
        'crm_segments', 'crm_segment_memberships', 'crm_import_batches',
    ];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason', 500)->nullable();
            $table->index(['company_id', 'archived_at'], 'customers_company_archive_idx');
            // Required by composite tenant FKs; legacy customer ID alone is PK.
            $table->unique(['company_id','id'], 'customers_tenant_id_unique');
        });

        Schema::create('crm_prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('status', 24)->default('new');
            $table->boolean('opt_out')->default(false);
            $table->unsignedBigInteger('converted_customer_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->text('lost_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id','converted_customer_id'])->references(['company_id','id'])->on('customers');
            $table->foreign('owner_id')->references('id')->on('users');
        });
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('consent', 20)->default('unknown');
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id','customer_id']);
            $table->foreign(['company_id','customer_id'])->references(['company_id','id'])->on('customers');
        });
        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 255);
            $table->string('stage', 24)->default('new');
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('probability_bps')->default(0);
            $table->date('expected_close_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id','id']);
            $table->index(['company_id','stage','expected_close_at']);
            $table->foreign(['company_id','customer_id'])->references(['company_id','id'])->on('customers');
            $table->foreign(['company_id','prospect_id'])->references(['company_id','id'])->on('crm_prospects');
            $table->foreign('owner_id')->references('id')->on('users');
        });
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 255);
            $table->string('type', 24)->default('call');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('planned');
            $table->timestampTz('due_at');
            $table->string('timezone', 80)->default('Europe/Paris');
            $table->timestampTz('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->unsignedInteger('reschedule_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id','id']);
            $table->index(['company_id','status','due_at']);
            $table->foreign(['company_id','customer_id'])->references(['company_id','id'])->on('customers');
            $table->foreign(['company_id','prospect_id'])->references(['company_id','id'])->on('crm_prospects');
            $table->foreign(['company_id','contact_id'])->references(['company_id','id'])->on('crm_contacts');
            $table->foreign(['company_id','opportunity_id'])->references(['company_id','id'])->on('crm_opportunities');
            $table->foreign('owner_id')->references('id')->on('users');
        });
        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('code', 60);
            $table->string('name', 180);
            $table->string('mode', 16)->default('static');
            $table->json('criteria')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id','id']);
            $table->unique(['company_id','code']);
        });
        Schema::create('crm_segment_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('segment_id');
            $table->unsignedBigInteger('customer_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id','segment_id','customer_id'],'crm_segment_member_tenant_unique');
            $table->foreign(['company_id','segment_id'])->references(['company_id','id'])->on('crm_segments');
            $table->foreign(['company_id','customer_id'])->references(['company_id','id'])->on('customers');
        });
        Schema::create('crm_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->char('sha256', 64);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('rejections');
            $table->unsignedBigInteger('actor_id');
            $table->timestamps();
            $table->unique(['company_id','sha256']);
            $table->foreign('actor_id')->references('id')->on('users');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
                $rights = $name === 'crm_import_batches' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
                DB::statement("GRANT {$rights} ON TABLE public.\"{$name}\" TO atlas_app_runtime");
                DB::statement("REVOKE DELETE ON TABLE public.\"{$name}\" FROM atlas_app_runtime");
                DB::statement("ALTER TABLE public.\"{$name}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.\"{$name}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
                $sequences = DB::select("SELECT seq.relname AS name FROM pg_class seq JOIN pg_depend dep ON dep.objid=seq.oid AND dep.deptype IN ('a','i') JOIN pg_class tbl ON tbl.oid=dep.refobjid WHERE seq.relkind='S' AND tbl.relname=?", [$name]);
                foreach ($sequences as $sequence) {
                    $ident = '"'.str_replace('"', '""', $sequence->name).'"';
                    DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$ident} TO atlas_app_runtime");
                }
            }
            DB::statement("ALTER TABLE crm_prospects ADD CONSTRAINT crm_prospects_status_ck CHECK(status IN ('new','qualified','lost','converted'))");
            DB::statement("ALTER TABLE crm_contacts ADD CONSTRAINT crm_contacts_consent_ck CHECK(consent IN ('unknown','granted','refused'))");
            DB::statement("ALTER TABLE crm_opportunities ADD CONSTRAINT crm_opportunity_stage_ck CHECK(stage IN ('new','qualified','proposal','won','lost'))");
            DB::statement("ALTER TABLE crm_opportunities ADD CONSTRAINT crm_opportunity_probability_ck CHECK(probability_bps BETWEEN 0 AND 10000)");
            DB::statement("ALTER TABLE crm_activities ADD CONSTRAINT crm_activities_status_ck CHECK(status IN ('planned','completed','cancelled'))");
            DB::statement("ALTER TABLE crm_segments ADD CONSTRAINT crm_segments_mode_ck CHECK(mode IN ('static','dynamic'))");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_tenant_id_unique');
            $table->dropIndex('customers_company_archive_idx');
            $table->dropColumn(['archived_at','archive_reason']);
        });
    }
};
