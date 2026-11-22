<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Services\HotelMinibarReconciliation;
use App\Services\HotelMinibarReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HotelMinibarReconciliationController extends Controller
{
    public function show(Request $request, int $hotel, HotelMinibarReconciliation $reconciliation)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'business_date' => 'required|date_format:Y-m-d',
            'import_id' => 'nullable|integer|min:1',
            'company_id' => 'prohibited',
        ]);

        return response()->json($reconciliation->forDay(
            $property, $data['business_date'], $data['import_id'] ?? null
        ));
    }

    public function export(Request $request, int $hotel,
                           HotelMinibarReconciliation $reconciliation, HotelMinibarReport $report)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'business_date' => 'required|date_format:Y-m-d',
            'import_id' => 'nullable|integer|min:1',
            'company_id' => 'prohibited',
        ]);

        $result = $reconciliation->forDay(
            $property, $data['business_date'], $data['import_id'] ?? null
        );
        $filename = "minibar-reconciliation-{$hotel}-{$data['business_date']}-{$result['import_id']}.csv";
        return $report->csv($filename, [
            'business_date', 'hotel_id', 'import_id', 'mode', 'room_number', 'sku',
            'atlas_cents', 'pms_cents', 'delta_cents', 'pms_line_count', 'status',
            'possible_breakfast_difference',
        ], array_map(static fn (array $row): array => [
            $result['business_date'], $result['hotel_id'], $result['import_id'],
            $result['import_mode'], $row['room_number'], $row['sku'],
            $row['atlas_cents'], $row['pms_cents'], $row['delta_cents'],
            $row['pms_line_count'], $row['status'],
            $row['possible_breakfast_difference'] ? 'yes' : 'no',
        ], $result['lines']));
    }
}
