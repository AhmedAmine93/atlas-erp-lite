<?php

namespace App\Services;

use App\Models\HotelProperty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HotelMinibarReconciliation
{
    /**
     * Reconcile one immutable PMS import against tenant-scoped ATLAS ledger.
     * Never modify either source. Duplicate PMS keys are always a manual review
     * even when their summed total happens to match ATLAS.
     */
    public function forDay(HotelProperty $hotel, string $day, ?int $importId = null): array
    {
        $imports = DB::table('minibar_pms_imports')
            ->where('company_id', $hotel->company_id)
            ->where('hotel_id', $hotel->id)
            ->where('business_date', $day);
        $import = $importId === null
            ? $imports->orderByDesc('id')->first()
            : $imports->where('id', $importId)->first();
        abort_unless($import !== null, 404, 'Aucun import PMS pour cet hôtel et cette journée.');

        $mapping = json_decode($import->mapping, true, 512, JSON_THROW_ON_ERROR);
        $pmsRows = json_decode($import->data_rows, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($pmsRows) || ! in_array($mapping['mode'] ?? null, ['room', 'room_product'], true)) {
            throw ValidationException::withMessages(['import_id' => 'Import PMS incompatible.']);
        }

        $mode = $mapping['mode'];
        $atlas = [];
        $pms = [];
        $keyOf = static fn (string $room, ?string $sku): string =>
            $room."\x1F".($mode === 'room_product' ? $sku : '');

        foreach (app(HotelMinibarReport::class)->lines($hotel, $day, $day) as $line) {
            $room = (string) $line->room_number;
            $sku = $mode === 'room_product' ? (string) $line->sku : null;
            $key = $keyOf($room, $sku);
            $atlas[$key] = ($atlas[$key] ?? 0) + (int) $line->total_cents;
        }

        foreach ($pmsRows as $row) {
            if (! is_array($row) || ! isset($row['room'], $row['amount_cents'])
                || ! is_int($row['amount_cents'])) {
                throw ValidationException::withMessages(['import_id' => 'Lignes PMS invalides.']);
            }
            $room = (string) $row['room'];
            $sku = $mode === 'room_product' ? (string) ($row['sku'] ?? '') : null;
            $key = $keyOf($room, $sku);
            if (! isset($pms[$key])) {
                $pms[$key] = ['total' => 0, 'count' => 0];
            }
            $pms[$key]['total'] += $row['amount_cents'];
            $pms[$key]['count']++;
        }

        $keys = array_unique(array_merge(array_keys($atlas), array_keys($pms)));
        sort($keys, SORT_STRING);
        $results = [];
        $counts = ['matched' => 0, 'amount_mismatch' => 0, 'missing_in_pms' => 0,
            'missing_in_atlas' => 0, 'duplicate_pms' => 0];
        foreach ($keys as $key) {
            [$room, $sku] = explode("\x1F", $key, 2);
            $presentAtlas = array_key_exists($key, $atlas);
            $presentPms = array_key_exists($key, $pms);
            $atlasCents = $atlas[$key] ?? 0;
            $pmsCents = $pms[$key]['total'] ?? 0;
            $sourceLines = $pms[$key]['count'] ?? 0;
            $delta = $atlasCents - $pmsCents;
            $status = match (true) {
                $sourceLines > 1 => 'duplicate_pms',
                ! $presentAtlas => 'missing_in_atlas',
                ! $presentPms => 'missing_in_pms',
                $delta !== 0 => 'amount_mismatch',
                default => 'matched',
            };
            $counts[$status]++;
            $results[] = [
                'room_number' => $room,
                'sku' => $mode === 'room_product' ? $sku : null,
                'atlas_cents' => $atlasCents,
                'pms_cents' => $pmsCents,
                'delta_cents' => $delta,
                'pms_line_count' => $sourceLines,
                'status' => $status,
                // A 5 EUR multiple is a hypothesis, never an automatic correction.
                'possible_breakfast_difference' => $delta !== 0 && abs($delta) % 500 === 0,
            ];
        }

        $atlasTotal = array_sum($atlas);
        $pmsTotal = array_sum(array_column($pms, 'total'));

        return [
            'hotel_id' => $hotel->id,
            'business_date' => $day,
            'import_id' => $import->id,
            'import_mode' => $mode,
            'selected_latest' => $importId === null,
            'currency' => 'EUR',
            'atlas_total_cents' => $atlasTotal,
            'pms_total_cents' => $pmsTotal,
            'delta_cents' => $atlasTotal - $pmsTotal,
            'counts' => $counts,
            'manual_review_required' => array_sum($counts) > $counts['matched'],
            'lines' => $results,
        ];
    }
}
