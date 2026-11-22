<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M06 V5.1 — additive finance schema. All monetary amounts are integer cents.
 * Runtime role receives only explicit table/sequence privileges and tenant RLS.
 * Live migration rollback intentionally refuses to erase financial evidence.
 */
return new class extends Migration {
    private const TABLES = [
        'finance_credit_notes','finance_receipts','finance_receipt_allocations',
        'finance_reminder_policies','finance_disputes','finance_reminders',
        'finance_cost_centers','finance_expenses','finance_budget_versions',
        'finance_budget_movements','finance_gl_accounts','finance_gl_periods',
        'finance_gl_journals','finance_gl_entries','finance_gl_entry_lines',
        'finance_tax_versions','finance_fx_rates','finance_export_batches',
        'finance_idempotency','finance_outbox'
    ];
    private const APPEND_ONLY = [
        'finance_credit_notes','finance_receipts','finance_receipt_allocations',
        'finance_reminders','finance_budget_movements','finance_gl_entry_lines',
        'finance_fx_rates','finance_tax_versions','finance_export_batches',
        'finance_idempotency','finance_outbox'
    ];

    private function base(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $columns($t);
            $t->timestamps();
            $t->unique(['company_id','id']);
        });
    }

    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->date('due_at')->nullable()->index();
            $t->char('currency',3)->default('EUR');
        });

        $this->base('finance_credit_notes', function (Blueprint $t) {
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->string('number',40);
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency',3)->default('EUR');
            $t->text('reason');
            $t->json('original_lines')->nullable();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at');
            $t->unique(['company_id','number']);
            $t->index(['company_id','invoice_id']);
        });
        $this->base('finance_receipts', function (Blueprint $t) {
            $t->string('reference',120)->nullable();
            $t->unsignedBigInteger('amount_cents');
            $t->char('currency',3)->default('EUR');
            $t->date('received_on');
            $t->string('method',30);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('external_event_id',140)->nullable();
            $t->unique(['company_id','external_event_id']);
        });
        $this->base('finance_receipt_allocations', function (Blueprint $t) {
            $t->foreignId('receipt_id')->constrained('finance_receipts')->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $t->unique(['company_id','receipt_id','invoice_id'],'fin_alloc_once_per_invoice');
            $t->index(['company_id','invoice_id']);
        });
        $this->base('finance_reminder_policies', function (Blueprint $t) {
            $t->unsignedSmallInteger('cadence_days');
            $t->boolean('enabled')->default(false);
            $t->string('template_version',50);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->unique('company_id');
        });
        $this->base('finance_disputes', function (Blueprint $t) {
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->string('status',20)->default('pending');
            $t->string('reason',1000);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->index(['company_id','invoice_id','status']);
        });
        $this->base('finance_reminders', function (Blueprint $t) {
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->unsignedBigInteger('outstanding_cents');
            $t->string('channel',20);
            $t->string('template_version',50);
            $t->string('status',20)->default('prepared');
            $t->date('reminder_date');
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->string('content_digest',64);
            $t->index(['company_id','invoice_id','reminder_date']);
        });
        $this->base('finance_cost_centers', function (Blueprint $t) {
            $t->string('code',40);
            $t->string('name',160);
            $t->boolean('active')->default(true);
            $t->unique(['company_id','code']);
        });
        $this->base('finance_expenses', function (Blueprint $t) {
            $t->foreignId('cost_center_id')->constrained('finance_cost_centers')->restrictOnDelete();
            $t->string('supplier_name',200);
            $t->string('category',100);
            $t->string('receipt_reference',160);
            $t->string('receipt_digest',64);
            $t->date('expense_date');
            $t->unsignedBigInteger('amount_cents');
            $t->char('currency',3)->default('EUR');
            $t->string('status',20)->default('draft');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('decision_reason',500)->nullable();
            $t->unique(['company_id','receipt_digest']);
            $t->index(['company_id','cost_center_id','expense_date']);
        });
        $this->base('finance_budget_versions', function (Blueprint $t) {
            $t->foreignId('cost_center_id')->constrained('finance_cost_centers')->restrictOnDelete();
            $t->unsignedSmallInteger('fiscal_year');
            $t->unsignedSmallInteger('revision')->default(1);
            $t->char('currency',3)->default('EUR');
            $t->unsignedBigInteger('limit_cents');
            $t->unsignedBigInteger('forecast_cents')->default(0);
            $t->string('status',20)->default('draft');
            $t->string('revision_reason',500)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->unique(['company_id','cost_center_id','fiscal_year','revision'],'fin_budget_version_unique');
        });
        $this->base('finance_budget_movements', function (Blueprint $t) {
            $t->foreignId('budget_version_id')->constrained('finance_budget_versions')->restrictOnDelete();
            $t->string('kind',20);
            $t->unsignedBigInteger('amount_cents');
            $t->foreignId('expense_id')->nullable()->constrained('finance_expenses')->restrictOnDelete();
            $t->string('source_reference',120)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['company_id','kind','expense_id'],'fin_budget_expense_once');
        });
        $this->base('finance_gl_accounts', function (Blueprint $t) {
            $t->string('code',30);
            $t->string('name',180);
            $t->boolean('active')->default(true);
            $t->unique(['company_id','code']);
        });
        $this->base('finance_gl_periods', function (Blueprint $t) {
            $t->string('code',40);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status',20)->default('open');
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['company_id','code']);
        });
        $this->base('finance_gl_journals', function (Blueprint $t) {
            $t->string('code',30);
            $t->string('name',150);
            $t->unique(['company_id','code']);
        });
        $this->base('finance_gl_entries', function (Blueprint $t) {
            $t->foreignId('journal_id')->constrained('finance_gl_journals')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('finance_gl_periods')->restrictOnDelete();
            $t->date('entry_date');
            $t->string('reference',100);
            $t->string('description',500);
            $t->string('status',20)->default('draft');
            $t->string('source_type',40)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->index(['company_id','period_id','status']);
        });
        $this->base('finance_gl_entry_lines', function (Blueprint $t) {
            $t->foreignId('entry_id')->constrained('finance_gl_entries')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('finance_gl_accounts')->restrictOnDelete();
            $t->unsignedBigInteger('debit_cents')->default(0);
            $t->unsignedBigInteger('credit_cents')->default(0);
            $t->string('label',250)->nullable();
            $t->index(['company_id','entry_id']);
        });
        $this->base('finance_tax_versions', function (Blueprint $t) {
            $t->string('jurisdiction',80);
            $t->string('tax_code',40);
            $t->unsignedInteger('rate_bps');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['company_id','jurisdiction','tax_code','effective_from'],'fin_tax_version_unique');
        });
        $this->base('finance_fx_rates', function (Blueprint $t) {
            $t->char('base_currency',3);
            $t->char('quote_currency',3);
            $t->decimal('rate',20,10);
            $t->date('effective_on');
            $t->string('source',100);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['company_id','base_currency','quote_currency','effective_on'],'fin_fx_version_unique');
        });
        $this->base('finance_export_batches', function (Blueprint $t) {
            $t->foreignId('period_id')->constrained('finance_gl_periods')->restrictOnDelete();
            $t->string('format',30)->default('atlas-gl-v1');
            $t->string('checksum',64);
            $t->unsignedInteger('row_count');
            $t->unsignedBigInteger('debit_cents');
            $t->unsignedBigInteger('credit_cents');
            $t->longText('csv_snapshot');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['company_id','period_id','checksum'],'fin_export_replay_unique');
        });
        $this->base('finance_idempotency', function (Blueprint $t) {
            $t->string('operation',80);
            $t->string('request_key',120);
            $t->string('payload_sha256',64);
            $t->unsignedBigInteger('entity_id');
            $t->unique(['company_id','operation','request_key'],'fin_idempotency_unique');
        });
        $this->base('finance_outbox', function (Blueprint $t) {
            $t->string('event_name',100);
            $t->string('aggregate',50);
            $t->unsignedBigInteger('aggregate_id');
            $t->json('payload');
            $t->string('status',20)->default('pending');
            $t->index(['company_id','status','id']);
        });

        if (DB::getDriverName() !== 'pgsql') return;

        // Only additive constraints; historical invoice records remain unchanged.
        foreach ([
            ['finance_credit_notes','invoice_id','invoices'],
            ['finance_receipt_allocations','receipt_id','finance_receipts'],
            ['finance_receipt_allocations','invoice_id','invoices'],
            ['finance_disputes','invoice_id','invoices'],
            ['finance_reminders','invoice_id','invoices'],
            ['finance_expenses','cost_center_id','finance_cost_centers'],
            ['finance_budget_versions','cost_center_id','finance_cost_centers'],
            ['finance_budget_movements','budget_version_id','finance_budget_versions'],
            ['finance_budget_movements','expense_id','finance_expenses'],
            ['finance_gl_entries','journal_id','finance_gl_journals'],
            ['finance_gl_entries','period_id','finance_gl_periods'],
            ['finance_gl_entry_lines','entry_id','finance_gl_entries'],
            ['finance_gl_entry_lines','account_id','finance_gl_accounts'],
            ['finance_export_batches','period_id','finance_gl_periods'],
        ] as [$child,$column,$parent]) {
            DB::statement("ALTER TABLE public.\"{$child}\" ADD CONSTRAINT fin_{$child}_{$column}_tenant_fk FOREIGN KEY (company_id, {$column}) REFERENCES public.\"{$parent}\" (company_id,id)");
        }
        foreach (self::TABLES as $table) {
            $rights = in_array($table, self::APPEND_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            if (in_array($table, self::APPEND_ONLY, true)) DB::statement("REVOKE UPDATE ON public.\"{$table}\" FROM atlas_app_runtime");
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", ['public.'.$table]);
            if ($seq?->seq) DB::statement('GRANT USAGE, SELECT ON SEQUENCE '.$seq->seq.' TO atlas_app_runtime');
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_finance_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
        DB::statement("ALTER TABLE finance_credit_notes ADD CONSTRAINT fin_credit_positive CHECK (amount_cents > 0)");
        DB::statement("ALTER TABLE finance_receipts ADD CONSTRAINT fin_receipt_positive CHECK (amount_cents > 0)");
        DB::statement("ALTER TABLE finance_receipt_allocations ADD CONSTRAINT fin_allocation_positive CHECK (amount_cents > 0)");
        DB::statement("ALTER TABLE finance_expenses ADD CONSTRAINT fin_expense_positive CHECK (amount_cents > 0)");
        DB::statement("ALTER TABLE finance_gl_entry_lines ADD CONSTRAINT fin_gl_line_positive CHECK ((debit_cents > 0 AND credit_cents = 0) OR (credit_cents > 0 AND debit_cents = 0))");
        DB::statement("ALTER TABLE finance_gl_periods ADD CONSTRAINT fin_period_range CHECK (ends_on >= starts_on)");
        DB::statement("ALTER TABLE finance_tax_versions ADD CONSTRAINT fin_tax_range CHECK (rate_bps <= 10000 AND (effective_to IS NULL OR effective_to >= effective_from))");
        DB::statement(<<<'SQL'
CREATE FUNCTION public.atlas_finance_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d bigint; c bigint; n bigint; state text; pstate text; firstdate date; lastdate date; booked date;
BEGIN
  IF TG_TABLE_NAME = 'invoices' THEN
    IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN RAISE EXCEPTION 'issued_invoice_immutable'; END IF;
    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND
      (NEW.number,NEW.order_id,NEW.snapshot,NEW.subtotal_cents,NEW.tax_cents,NEW.total_cents,NEW.currency,NEW.due_at)
      IS DISTINCT FROM
      (OLD.number,OLD.order_id,OLD.snapshot,OLD.subtotal_cents,OLD.tax_cents,OLD.total_cents,OLD.currency,OLD.due_at)
      THEN RAISE EXCEPTION 'issued_invoice_immutable'; END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
  END IF;
  IF TG_TABLE_NAME = 'finance_gl_entry_lines' THEN
    SELECT status INTO state FROM finance_gl_entries WHERE id = COALESCE(NEW.entry_id,OLD.entry_id);
    IF state <> 'draft' THEN RAISE EXCEPTION 'posted_journal_immutable'; END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
  END IF;
  IF TG_TABLE_NAME = 'finance_gl_entries' THEN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'journal_deletion_forbidden'; END IF;
    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' THEN RAISE EXCEPTION 'posted_journal_immutable'; END IF;
    SELECT status,starts_on,ends_on INTO pstate,firstdate,lastdate FROM finance_gl_periods WHERE id=NEW.period_id FOR UPDATE;
    IF pstate <> 'open' OR NEW.entry_date < firstdate OR NEW.entry_date > lastdate THEN RAISE EXCEPTION 'period_closed_or_invalid'; END IF;
    IF TG_OP='UPDATE' AND NEW.status='posted' AND OLD.status='draft' THEN
      SELECT COALESCE(SUM(debit_cents),0),COALESCE(SUM(credit_cents),0),COUNT(*)
      INTO d,c,n FROM finance_gl_entry_lines WHERE entry_id=NEW.id;
      IF n < 2 OR d = 0 OR d <> c THEN RAISE EXCEPTION 'journal_unbalanced'; END IF;
    END IF;
    RETURN NEW;
  END IF;
  RETURN NEW;
END $$;
SQL);
        DB::statement("CREATE TRIGGER atlas_invoice_guard BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION atlas_finance_guard()");
        DB::statement("CREATE TRIGGER atlas_gl_entries_guard BEFORE UPDATE OR DELETE ON finance_gl_entries FOR EACH ROW EXECUTE FUNCTION atlas_finance_guard()");
        DB::statement("CREATE TRIGGER atlas_gl_lines_guard BEFORE UPDATE OR DELETE ON finance_gl_entry_lines FOR EACH ROW EXECUTE FUNCTION atlas_finance_guard()");
    }

    public function down(): void
    {
        // Cannot wipe signed documents, receipts, or posted accounting data.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('M06 financial evidence exists: forward-only repair required.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS atlas_invoice_guard ON invoices');
            DB::statement('DROP FUNCTION IF EXISTS public.atlas_finance_guard() CASCADE');
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['due_at','currency']);
        });
    }
};
