<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional audit outbox for the optional local ATLAS event pipeline.
 * No business-data changes. The trigger copies a MINIMAL event envelope, never
 * AuditLog.metadata (which might carry confidential values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('audit_log_id')->unique()->constrained('audit_logs')->restrictOnDelete();
            $table->string('event_type', 255);
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->index(['published_at', 'id'], 'platform_outbox_pending_idx');
            $table->unique(['company_id', 'id'], 'platform_outbox_tenant_id_unique');
        });

        Schema::create('platform_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->foreign(['company_id', 'event_id'], 'platform_receipts_tenant_event_fk')
                ->references(['company_id', 'id'])->on('platform_outbox_events')->restrictOnDelete();
            $table->char('payload_sha256', 64);
            $table->string('event_type', 255);
            $table->timestampTz('processed_at');
            $table->unique(['company_id', 'event_id'], 'platform_receipts_tenant_event_unique');
        });

        Schema::create('platform_event_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('event_type', 255);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->timestampTz('last_processed_at');
            $table->unique(['company_id', 'event_type'], 'platform_counters_tenant_type_unique');
        });

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite unit tests do not emulate PostgreSQL triggers/RLS.
            return;
        }

        // Fail closed if staging runtime separation was not installed.
        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $exists) {
            throw new RuntimeException('Platform outbox requires the restricted atlas_app_runtime role.');
        }
        DB::statement('ALTER TABLE public.platform_outbox_events ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
CREATE POLICY platform_runtime_outbox_insert ON public.platform_outbox_events
FOR INSERT TO atlas_app_runtime
WITH CHECK (
    company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint
)
SQL);
        DB::statement('GRANT INSERT ON TABLE public.platform_outbox_events TO atlas_app_runtime');
        DB::statement('GRANT USAGE ON SEQUENCE public.platform_outbox_events_id_seq TO atlas_app_runtime');
        // The restricted app may not list published events or consumer receipts.
        DB::statement('REVOKE SELECT, UPDATE, DELETE ON TABLE public.platform_outbox_events FROM atlas_app_runtime');
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.atlas_platform_enqueue_audit()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = pg_catalog, public
AS $function$
BEGIN
    INSERT INTO public.platform_outbox_events
        (company_id, audit_log_id, event_type, payload, occurred_at)
    VALUES
        (
            NEW.company_id,
            NEW.id,
            NEW.action,
            jsonb_build_object(
                'entity_type', NEW.entity_type,
                'entity_id', NEW.entity_id
            ),
            COALESCE(NEW.created_at, CURRENT_TIMESTAMP)
        )
    ON CONFLICT (audit_log_id) DO NOTHING;
    RETURN NEW;
END
$function$
SQL);
        DB::statement(<<<'SQL'
CREATE TRIGGER atlas_platform_audit_to_outbox
AFTER INSERT ON public.audit_logs
FOR EACH ROW EXECUTE FUNCTION public.atlas_platform_enqueue_audit()
SQL);
    }

    public function down(): void
    {
        if (DB::table('platform_outbox_events')->exists() || DB::table('platform_event_receipts')->exists()) {
            throw new RuntimeException('Outbox or receipts exist. Refusing destructive rollback; use a forward migration.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS atlas_platform_audit_to_outbox ON public.audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS public.atlas_platform_enqueue_audit()');
        }
        Schema::dropIfExists('platform_event_counters');
        Schema::dropIfExists('platform_event_receipts');
        Schema::dropIfExists('platform_outbox_events');
    }
};
