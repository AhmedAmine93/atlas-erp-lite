<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function record(string $action, $entity = null, array $metadata = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'entity_type' => $entity ? class_basename($entity) : 'Session',
            'entity_id' => $entity?->id, 'metadata' => $metadata]);
    }
}
