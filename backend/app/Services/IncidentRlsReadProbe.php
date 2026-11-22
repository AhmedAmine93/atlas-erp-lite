<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Fixed read-only SQL probe. The role and GUC are local to a DB transaction.
 * A finally block restores the previous context even inside an outer
 * transaction (e.g. PHPUnit's RefreshDatabase transactions).
 *
 * This is NOT a generic SQL execution endpoint.
 */
final class IncidentRlsReadProbe
{
    public function metrics(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite only for developer convenience, NOT an RLS validation.
            return $this->eloquentMetrics();
        }

        return DB::transaction(function () use ($tenantId): array {
            $previous = DB::selectOne(
                "SELECT current_setting('atlas.tenant_id', true) AS tenant_id"
            )?->tenant_id ?? '';

            try {
                DB::statement('SET LOCAL ROLE atlas_f15_incident_reader');
                DB::selectOne(
                    "SELECT set_config('atlas.tenant_id', ?, true) AS selected_tenant",
                    [(string) $tenantId],
                );

                // Fixed aggregate SQL, never parameters for identifiers or
                // direct caller-provided SQL.
                $metrics = DB::selectOne(<<<'SQL'
SELECT
    COUNT(*) FILTER (WHERE status IN ('open', 'investigating')) AS active_count,
    COUNT(*) FILTER (
        WHERE resolution_due_at IS NOT NULL
          AND resolution_due_at < CURRENT_TIMESTAMP
          AND (resolved_at IS NULL OR resolved_at > resolution_due_at)
    ) AS resolution_sla_breached_count
FROM public.support_incidents
SQL);
                return [
                    'active_count' => (int) $metrics->active_count,
                    'resolution_sla_breached_count' => (int) $metrics->resolution_sla_breached_count,
                ];
            } finally {
                // Restore both dimensions explicitly to avoid leaking a
                // privileged role or tenant GUC across pooled requests.
                DB::statement('RESET ROLE');
                DB::selectOne(
                    "SELECT set_config('atlas.tenant_id', ?, true) AS restored_tenant",
                    [$previous],
                );
            }
        }, 1);
    }

    private function eloquentMetrics(): array
    {
        $incidents = \App\Models\SupportIncident::query();

        return [
            'active_count' => (clone $incidents)
                ->whereIn('status', ['open', 'investigating'])->count(),
            'resolution_sla_breached_count' => (clone $incidents)
                ->whereNotNull('resolution_due_at')->where('resolution_due_at', '<', now())
                ->where(fn ($query) => $query->whereNull('resolved_at')
                    ->orWhereColumn('resolved_at', '>', 'resolution_due_at'))->count(),
        ];
    }
}
