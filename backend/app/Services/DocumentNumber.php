<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    // Must run inside the same transaction as document creation/issue.
    public static function next(string $prefix): string
    {
        $key = $prefix.'-'.now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'value' => 0]);
        $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
        $value = $row->value + 1;
        DB::table('document_sequences')->where('key', $key)->update(['value' => $value]);

        return $key.'-'.str_pad((string) $value, 5, '0', STR_PAD_LEFT);
    }
}
