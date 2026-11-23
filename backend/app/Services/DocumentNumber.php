<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    // Must execute inside the same transaction as the document creation/issue.
    public static function next(string $prefix): string
    {
        $type = ['FAC'=>'invoice','DEV'=>'quote','CMD'=>'order'][$prefix] ?? null;
        $companyId = app(TenantContext::class)->id();
        if ($type && $companyId) {
            $series = DB::table('admin_document_series')
                ->where('company_id', $companyId)
                ->where('scope_key', 'company')
                ->where('document_type', $type)
                ->where('period_year', (int) now()->format('Y'))
                ->where('active', true)
                ->lockForUpdate()->first();
            if ($series) {
                $sequenceValue = (int) $series->next_number;
                // Include a stable company identifier to keep legacy globally
                // unique document.number constraints valid across tenants.
                $number = $series->prefix.'-T'.$companyId.'-'.$series->period_year.'-'
                    .str_pad((string) $sequenceValue, 5, '0', STR_PAD_LEFT);
                $operationKey = (string) \Illuminate\Support\Str::uuid();
                $digest = hash('sha256', json_encode([$series->id, $operationKey]));
                DB::table('admin_document_allocations')->insert([
                    'company_id' => $companyId,
                    'series_id' => $series->id,
                    'sequence_value' => $sequenceValue,
                    'document_number' => $number,
                    'operation_key' => $operationKey,
                    'payload_sha256' => $digest,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('admin_document_series')
                    ->where('company_id', $companyId)->where('id', $series->id)
                    ->update([
                        'next_number' => $sequenceValue + 1,
                        'version' => $series->version + 1,
                        'updated_at' => now(),
                    ]);
                return $number;
            }
        }

        // Preserve the existing numbering contract for tenants that have not
        // adopted M17 company series yet. Existing issued numbers never change.
        $key = $prefix.'-'.now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'value' => 0]);
        $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
        $value = $row->value + 1;
        DB::table('document_sequences')->where('key', $key)->update(['value' => $value]);

        return $key.'-'.str_pad((string) $value, 5, '0', STR_PAD_LEFT);
    }
}
