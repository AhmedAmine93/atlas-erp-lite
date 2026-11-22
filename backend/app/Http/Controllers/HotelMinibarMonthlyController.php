<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Services\HotelMinibarReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HotelMinibarMonthlyController extends Controller
{
    /**
     * Report only; PMS imports are synthetic and never change ledger entries.
     * A day without an import has UNKNOWN reconciliation, not a zero variance.
     */
    public function index(Request $request, int $hotel, HotelMinibarReconciliation $reconciliation)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
            'company_id' => 'prohibited',
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end = $start->endOfMonth();
        $first = $start->toDateString();
        $last = $end->toDateString();

        $atlasTotals = DB::table('minibar_consumptions')
            ->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)
            ->whereBetween('business_date', [$first, $last])
            ->groupBy('business_date')
            ->select('business_date')
            ->selectRaw('SUM(total_cents)::bigint AS total_cents')
            ->pluck('total_cents', 'business_date');

        $latestImports = DB::table('minibar_pms_imports')
            ->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)
            ->whereBetween('business_date', [$first, $last])
            ->groupBy('business_date')
            ->select('business_date')
            ->selectRaw('MAX(id) AS import_id')
            ->pluck('import_id', 'business_date');

        $days = [];
        $total = 0;
        $comparedAtlas = 0;
        $comparedPms = 0;
        $unresolved = 0;
        $comparedDays = 0;
        $uncomparedDays = 0;
        for ($current = $start; $current->lte($end); $current = $current->addDay()) {
            $date = $current->toDateString();
            $atlas = (int) ($atlasTotals[$date] ?? 0);
            $total += $atlas;
            $importId = isset($latestImports[$date]) ? (int) $latestImports[$date] : null;
            $pms = null;
            $delta = null;
            $anomalies = null;
            if ($importId !== null) {
                $comparison = $reconciliation->forDay($property, $date, $importId);
                $pms = $comparison['pms_total_cents'];
                $delta = $comparison['delta_cents'];
                $anomalies = array_sum($comparison['counts']) - $comparison['counts']['matched'];
                $comparedDays++;
                $comparedAtlas += $atlas;
                $comparedPms += $pms;
                $unresolved += $anomalies;
            } else {
                $uncomparedDays++;
            }
            $days[] = [
                'business_date' => $date,
                'atlas_total_cents' => $atlas,
                'pms_total_cents' => $pms,
                'delta_cents' => $delta,
                'import_id' => $importId,
                'anomalies_count' => $anomalies,
            ];
        }

        return response()->json([
            'hotel_id' => $property->id,
            'month' => $data['month'],
            'currency' => 'EUR',
            'atlas_total_cents' => $total,
            'compared_atlas_cents' => $comparedAtlas,
            'compared_pms_cents' => $comparedPms,
            'compared_delta_cents' => $comparedAtlas - $comparedPms,
            'compared_days' => $comparedDays,
            'uncompared_days' => $uncomparedDays,
            'anomalies_count' => $unresolved,
            'days' => $days,
        ]);
    }
}
