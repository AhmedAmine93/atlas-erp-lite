<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pilotage_alert_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('name', 120);
            $t->string('metric', 40);
            $t->unsignedInteger('threshold');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'metric', 'active', 'effective_from']);
        });
        Schema::create('pilotage_alert_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('rule_id');
            $t->string('entity_type', 20);
            $t->unsignedBigInteger('entity_id');
            // Typed references are checked by composite tenant FKs in PostgreSQL.
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->foreignId('acknowledged_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('acknowledged_at');
            $t->string('comment', 500);
            $t->foreign(['company_id', 'rule_id'])->references(['company_id', 'id'])
                ->on('pilotage_alert_rules')->restrictOnDelete();
            $t->unique(['company_id', 'rule_id', 'entity_type', 'entity_id'], 'pilotage_ack_unique');
        });
        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement("ALTER TABLE pilotage_alert_rules ADD CONSTRAINT pilotage_metric_check CHECK (metric IN ('low_stock','unpaid_invoice_age_days'))");
        DB::statement("ALTER TABLE pilotage_alert_rules ADD CONSTRAINT pilotage_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS pilotage_invoices_tenant_id_uq ON public.invoices (company_id, id)");
        DB::statement("ALTER TABLE pilotage_alert_acknowledgements ADD CONSTRAINT pilotage_entity_check CHECK ((entity_type = 'product' AND product_id = entity_id AND invoice_id IS NULL) OR (entity_type = 'invoice' AND invoice_id = entity_id AND product_id IS NULL))");
        DB::statement("ALTER TABLE pilotage_alert_acknowledgements ADD CONSTRAINT pilotage_ack_product_tenant_fk FOREIGN KEY (company_id, product_id) REFERENCES public.products (company_id, id)");
        DB::statement("ALTER TABLE pilotage_alert_acknowledgements ADD CONSTRAINT pilotage_ack_invoice_tenant_fk FOREIGN KEY (company_id, invoice_id) REFERENCES public.invoices (company_id, id)");
        foreach (['pilotage_alert_rules' => 'SELECT, INSERT, UPDATE', 'pilotage_alert_acknowledgements' => 'SELECT, INSERT'] as $table => $rights) {
            DB::statement('GRANT '.$rights.' ON public."'.$table.'" TO atlas_app_runtime');
            DB::statement('REVOKE DELETE ON public."'.$table.'" FROM atlas_app_runtime');
            if ($table === 'pilotage_alert_acknowledgements') DB::statement('REVOKE UPDATE ON public."'.$table.'" FROM atlas_app_runtime');
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", ['public.'.$table]);
            if ($seq?->seq) DB::statement('GRANT USAGE, SELECT ON SEQUENCE '.$seq->seq.' TO atlas_app_runtime');
            DB::statement('ALTER TABLE public."'.$table.'" ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY atlas_pilotage_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        // Rolling back an alert journal is destructive. Restrict to clean
        // ephemeral development environments; production uses forward fixes.
        if (DB::table('pilotage_alert_acknowledgements')->exists()
            || DB::table('pilotage_alert_rules')->exists()) {
            throw new RuntimeException('Refus de supprimer des accusés de réception. Migration de correction requise.');
        }
        Schema::dropIfExists('pilotage_alert_acknowledgements');
        Schema::dropIfExists('pilotage_alert_rules');
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP INDEX IF EXISTS public.pilotage_invoices_tenant_id_uq');
    }
};
