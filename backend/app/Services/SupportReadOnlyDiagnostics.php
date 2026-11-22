<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fixed catalog only. No SQL, shell, URI, command, connection string,
 * file path or external hostname is taken from request input.
 *
 * Results deliberately exclude exception messages, query text, cache values,
 * database names, connection metadata and personally identifying records.
 */
final class SupportReadOnlyDiagnostics
{
    public const CHECKS = ['database', 'cache', 'tenant_incidents'];

    public function execute(string $check): array
    {
        if (! in_array($check, self::CHECKS, true)) {
            throw new \InvalidArgumentException('Unknown diagnostic.');
        }

        $start = hrtime(true);
        try {
            $metrics = match ($check) {
                'database' => $this->database(),
                'cache' => $this->cache(),
                'tenant_incidents' => $this->tenantIncidents(),
            };
            $status = 'ok';
        } catch (\Throwable $e) {
            // Report server side only; never expose exception or infrastructure
            // information to the browser or persist it in diagnostic metadata.
            report($e);
            $metrics = [];
            $status = 'degraded';
        }

        return [
            'check' => $check,
            'status' => $status,
            'duration_ms' => min(60000, (int) ceil((hrtime(true) - $start) / 1_000_000)),
            'metrics' => $metrics,
        ];
    }

    private function database(): array
    {
        // Constant SQL only: no parameterized editor, dynamic table or queries.
        $row = DB::selectOne('SELECT 1 AS atlas_probe');

        return ['reachable' => (int) ($row->atlas_probe ?? 0) === 1];
    }

    private function cache(): array
    {
        // Read only: no SET / DEL / increment or cache write.
        Cache::get('atlas:diagnostics:readonly:'.app(TenantContext::class)->requireId());

        return ['readable' => true];
    }

    private function tenantIncidents(): array
    {
        return app(IncidentRlsReadProbe::class)->metrics();
    }
}
