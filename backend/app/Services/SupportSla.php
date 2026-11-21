<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Calendar-time SLA policy, measured from creation. No business-hour pause
 * and no automatic penalties or contracts in F09.
 */
class SupportSla
{
    private const TARGETS = [
        'P1' => ['response' => 15, 'resolution' => 240],
        'P2' => ['response' => 60, 'resolution' => 480],
        'P3' => ['response' => 240, 'resolution' => 2880],
        'P4' => ['response' => 480, 'resolution' => 7200],
    ];

    public function targets(string $severity, CarbonImmutable $createdAt): array
    {
        $policy = self::TARGETS[$severity] ?? null;
        if (! $policy) {
            throw new \InvalidArgumentException('Unsupported support priority.');
        }

        return [
            'response_due_at' => $createdAt->addMinutes($policy['response']),
            'resolution_due_at' => $createdAt->addMinutes($policy['resolution']),
        ];
    }

    public function status(?\DateTimeInterface $actual, ?\DateTimeInterface $deadline): string
    {
        if (! $deadline) {
            return 'not_configured';
        }
        if ($actual) {
            return $actual <= $deadline ? 'met' : 'breached';
        }

        return now()->greaterThan($deadline) ? 'breached' : 'pending';
    }
}
