<?php

namespace App\Http\Controllers;

use App\Models\HotelBooking;
use App\Models\HotelProperty;
use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class HotelOperationsController extends Controller
{
    private function property(int $hotel): HotelProperty
    {
        Gate::authorize('admin');
        return HotelProperty::query()->findOrFail($hotel);
    }

    private function businessClock(HotelProperty $hotel, string $date): object
    {
        $clock = DB::table('hotel_business_clock_versions')
            ->where('company_id', $hotel->company_id)->where('hotel_id', $hotel->id)
            ->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        abort_unless($clock, 409, 'Configurez d’abord le calendrier hôtelier.');
        return $clock;
    }

    private function assertOpenDay(HotelProperty $hotel, string $date): void
    {
        abort_if(DB::table('hotel_night_audits')
            ->where('company_id', $hotel->company_id)->where('hotel_id', $hotel->id)
            ->where('business_date', $date)->exists(), 409,
            'Journée clôturée : utilisez une correction contrôlée.');
    }

    public function clocks(int $hotel)
    {
        $property = $this->property($hotel);
        return DB::table('hotel_business_clock_versions')
            ->where('company_id', $property->company_id)->where('hotel_id', $hotel)
            ->orderByDesc('effective_on')->paginate(30);
    }

    public function createClock(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'effective_on' => 'required|date_format:Y-m-d',
            'timezone' => 'required|timezone',
            'cutoff_time' => ['required','date_format:H:i'],
            'company_id' => 'prohibited', 'created_by' => 'prohibited',
        ]);
        $last = DB::table('hotel_business_clock_versions')
            ->where('company_id', $property->company_id)->where('hotel_id', $hotel)
            ->orderByDesc('effective_on')->first();
        abort_if($last && $data['effective_on'] <= $last->effective_on, 409,
            'La nouvelle version doit commencer après la précédente.');
        abort_if($last && $data['effective_on'] <= CarbonImmutable::now($last->timezone)->toDateString(),
            409, 'Une modification de calendrier ne peut pas être rétroactive.');
        return DB::transaction(function () use ($data, $property, $request) {
            $id = DB::table('hotel_business_clock_versions')->insertGetId([
                'company_id' => $property->company_id, 'hotel_id' => $property->id,
                'effective_on' => $data['effective_on'], 'timezone' => $data['timezone'],
                'cutoff_time' => $data['cutoff_time'], 'created_by' => $request->user()->id,
            ]);
            Audit::record('hotel.calendar.version.created', $property, ['version_id' => $id]);
            return response()->json(['id' => $id], 201);
        });
    }

    public function folios(int $hotel)
    {
        $property = $this->property($hotel);
        return DB::table('hotel_folios')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->orderByDesc('id')->paginate(30);
    }

    public function createFolio(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'booking_id' => 'required|integer|min:1',
            'folio_number' => 'required|string|max:60',
            'company_id' => 'prohibited', 'status' => 'prohibited',
        ]);
        $booking = HotelBooking::query()->where('hotel_id', $hotel)->findOrFail($data['booking_id']);
        return DB::transaction(function () use ($property, $booking, $data, $request) {
            $id = DB::table('hotel_folios')->insertGetId([
                'company_id' => $property->company_id, 'hotel_id' => $property->id,
                'booking_id' => $booking->id, 'folio_number' => $data['folio_number'],
                'created_by' => $request->user()->id,
            ]);
            Audit::record('hotel.folio.created', $property, ['folio_id' => $id]);
            return response()->json(['id' => $id], 201);
        });
    }

    public function folio(int $hotel, int $folio)
    {
        $property = $this->property($hotel);
        $row = DB::table('hotel_folios')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->where('id', $folio)->first();
        abort_unless($row, 404);
        $entries = DB::table('hotel_folio_entries')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->where('folio_id', $folio)
            ->orderBy('id')->paginate(100);
        $totals = DB::table('hotel_folio_entries')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->where('folio_id', $folio)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind IN ('charge','transfer_in') THEN amount_cents ELSE -amount_cents END),0) AS balance_cents")
            ->first();
        return response()->json(['folio' => $row, 'entries' => $entries, 'balance_cents' => (int)$totals->balance_cents]);
    }

    public function addFolioEntry(Request $request, int $hotel, int $folio)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'business_date' => 'required|date_format:Y-m-d',
            'kind' => ['required', Rule::in(['charge','credit','payment'])],
            'source_type' => ['required', Rule::in(['room','minibar','manual','adjustment','payment'])],
            'source_reference' => 'nullable|string|max:100',
            'amount_cents' => 'required|integer|between:1,100000000000',
            'note' => 'required|string|min:3|max:300',
            'request_id' => 'required|uuid',
            'company_id' => 'prohibited', 'hotel_id' => 'prohibited', 'created_by' => 'prohibited',
        ]);
        $payload = [
            'business_date' => $data['business_date'], 'kind' => $data['kind'],
            'source_type' => $data['source_type'], 'source_reference' => $data['source_reference'] ?? null,
            'amount_cents' => (int)$data['amount_cents'], 'note' => $data['note'],
        ];
        return DB::transaction(function () use ($property, $folio, $data, $payload, $request) {
            $row = DB::table('hotel_folios')->where('company_id', $property->company_id)
                ->where('hotel_id', $property->id)->where('id', $folio)->lockForUpdate()->first();
            abort_unless($row, 404);
            $existing = DB::table('hotel_folio_entries')->where('company_id', $property->company_id)
                ->where('hotel_id', $property->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->folio_id === $folio && collect($payload)->every(
                    fn ($value, $key) => (string)($existing->$key ?? '') === (string)($value ?? '')
                ), 409, 'Clé de requête utilisée avec un contenu différent.');
                return response()->json($existing);
            }
            abort_unless($row->status === 'open', 409, 'Folio fermé.');
            $this->assertOpenDay($property, $data['business_date']);
            $id = DB::table('hotel_folio_entries')->insertGetId([
                'company_id' => $property->company_id, 'hotel_id' => $property->id,
                'folio_id' => $folio, 'request_id' => $data['request_id'],
                'created_by' => $request->user()->id, ...$payload,
            ]);
            Audit::record('hotel.folio.entry.added', $property, ['folio_id' => $folio, 'entry_id' => $id]);
            return response()->json(['id' => $id], 201);
        }, 3);
    }

    public function closeFolio(int $hotel, int $folio)
    {
        $property = $this->property($hotel);
        return DB::transaction(function () use ($property, $folio) {
            $row = DB::table('hotel_folios')->where('company_id', $property->company_id)
                ->where('hotel_id', $property->id)->where('id', $folio)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'open', 409, 'Folio déjà fermé.');
            DB::table('hotel_folios')->where('id', $folio)->update(['status' => 'closed','updated_at' => now()]);
            Audit::record('hotel.folio.closed', $property, ['folio_id' => $folio]);
            return response()->json(['id' => $folio, 'status' => 'closed']);
        });
    }

    public function referenceRules(int $hotel)
    {
        $property = $this->property($hotel);
        return DB::table('hotel_reference_rules')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->orderByDesc('effective_on')->paginate(30);
    }

    public function createReferenceRule(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'effective_on' => 'required|date_format:Y-m-d',
            'prefix_digits' => 'required|integer|between:0,12',
            'separator' => ['required', Rule::in(['_','-','/'])],
            'company_id' => 'prohibited',
        ]);
        return DB::transaction(function () use ($data, $property, $request) {
            $id = DB::table('hotel_reference_rules')->insertGetId([
                'company_id' => $property->company_id, 'hotel_id' => $property->id,
                'created_by' => $request->user()->id, ...$data,
            ]);
            Audit::record('hotel.channel.rule.created', $property, ['rule_id' => $id]);
            return response()->json(['id' => $id], 201);
        });
    }

    public function imports(int $hotel)
    {
        $property = $this->property($hotel);
        return DB::table('hotel_channel_imports')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->orderByDesc('id')->paginate(30);
    }

    private function normalizeReference(string $reference, object $rule): string
    {
        $reference = trim($reference);
        $remove = (int)$rule->prefix_digits;
        if ($remove && preg_match('/^\\d{'.$remove.'}'.preg_quote($rule->separator, '/').'(.+)$/uD', $reference, $match)) {
            return $match[1];
        }
        return $reference;
    }

    public function import(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'source' => 'required|in:pms,crs',
            'business_date' => 'required|date_format:Y-m-d',
            'request_id' => 'required|uuid',
            'synthetic' => 'required|accepted',
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.reference' => 'required|string|max:180',
            'rows.*.status' => 'required|in:active,cancelled,no_show',
            'rows.*.origin' => 'required|in:direct,channel,unknown',
            'rows.*.amount_cents' => 'nullable|integer|between:0,100000000000',
            'company_id' => 'prohibited',
        ]);
        $rule = DB::table('hotel_reference_rules')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->where('effective_on','<=',$data['business_date'])
            ->orderByDesc('effective_on')->first();
        abort_unless($rule, 409, 'Aucune règle de normalisation applicable.');
        $digest = hash('sha256', json_encode([
            $data['source'], $data['business_date'], $rule->id, $data['rows'],
        ], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($data, $property, $rule, $digest, $request) {
            $key = ['company_id' => $property->company_id, 'hotel_id' => $property->id];
            $old = DB::table('hotel_channel_imports')->where($key)->where('request_id',$data['request_id'])->first();
            if ($old) {
                abort_unless($old->payload_digest === $digest, 409, 'Rejeu avec contenu différent.');
                return response()->json(['id' => $old->id, 'row_count' => count($data['rows'])]);
            }
            $id = DB::table('hotel_channel_imports')->insertGetId($key + [
                'source' => $data['source'], 'business_date' => $data['business_date'],
                'request_id' => $data['request_id'], 'reference_rule_id' => $rule->id,
                'payload_digest' => $digest, 'created_by' => $request->user()->id,
            ]);
            $rows = [];
            foreach ($data['rows'] as $i => $record) {
                $rows[] = $key + [
                    'import_id' => $id, 'row_number' => $i + 1,
                    'raw_reference' => $record['reference'],
                    'normalized_reference' => $this->normalizeReference($record['reference'], $rule),
                    'booking_status' => $record['status'], 'booking_origin' => $record['origin'],
                    'amount_cents' => $record['amount_cents'] ?? null,
                ];
            }
            DB::table('hotel_channel_records')->insert($rows);
            Audit::record('hotel.channel.imported', $property, ['import_id' => $id, 'row_count' => count($rows)]);
            return response()->json(['id' => $id,'row_count' => count($rows)], 201);
        }, 3);
    }

    public function reconcile(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'pms_import_id' => 'required|integer|min:1',
            'crs_import_id' => 'required|integer|min:1',
        ]);
        $key = ['company_id' => $property->company_id, 'hotel_id' => $hotel];
        $imports = DB::table('hotel_channel_imports')->where($key)
            ->whereIn('id', array_values($data))->get()->keyBy('id');
        $pms = $imports->get($data['pms_import_id']);
        $crs = $imports->get($data['crs_import_id']);
        abort_unless($pms && $pms->source === 'pms' && $crs && $crs->source === 'crs', 404);
        $read = fn ($id) => DB::table('hotel_channel_records')->where($key)
            ->where('import_id', $id)->orderBy('row_number')->get();
        $left = $read($pms->id)->groupBy('normalized_reference');
        $right = $read($crs->id)->groupBy('normalized_reference');
        $out = [];
        foreach ($left->keys()->merge($right->keys())->unique() as $reference) {
            $l = $left->get($reference, collect());
            $r = $right->get($reference, collect());
            $status = $l->count() > 1 || $r->count() > 1 ? 'ambiguous'
                : ($l->isEmpty() ? 'crs_only' : ($r->isEmpty()
                ? ($l->first()->booking_origin === 'direct' ? 'direct_pms' : 'pms_only')
                : ($l->first()->booking_status !== $r->first()->booking_status ? 'status_mismatch'
                : ($l->first()->amount_cents !== $r->first()->amount_cents ? 'amount_mismatch' : 'matched'))));
            $out[] = ['reference' => $reference, 'status' => $status,
                'pms' => $l->values(), 'crs' => $r->values()];
        }
        return response()->json([
            'scope' => 'local_imports_only', 'synced_live' => false,
            'pms_import_id' => $pms->id, 'crs_import_id' => $crs->id,
            'rows' => $out, 'manual_review_required' => collect($out)->contains(
                fn ($row) => $row['status'] !== 'matched'
            ),
        ]);
    }

    public function audits(int $hotel)
    {
        $property = $this->property($hotel);
        return DB::table('hotel_night_audits')->where('company_id', $property->company_id)
            ->where('hotel_id', $hotel)->orderByDesc('business_date')->paginate(30);
    }

    public function closeDay(Request $request, int $hotel)
    {
        $property = $this->property($hotel);
        $data = $request->validate([
            'business_date' => 'required|date_format:Y-m-d', 'request_id' => 'required|uuid',
            'company_id' => 'prohibited', 'snapshot' => 'prohibited',
        ]);
        return DB::transaction(function () use ($property, $data, $request) {
            // Locks every closure for one hotel; a second closer gets the same immutable snapshot.
            $property = HotelProperty::query()->lockForUpdate()->findOrFail($property->id);
            $key = ['company_id' => $property->company_id, 'hotel_id' => $property->id];
            $old = DB::table('hotel_night_audits')->where($key)
                ->where('business_date', $data['business_date'])->first();
            if ($old) {
                abort_unless($old->request_id === $data['request_id'], 409, 'Journée déjà clôturée.');
                return response()->json($old);
            }
            $clock = $this->businessClock($property, $data['business_date']);
            $local = CarbonImmutable::now($clock->timezone);
            $today = $local->toDateString();
            if ($local->format('H:i:s') < $clock->cutoff_time) {
                $today = $local->subDay()->toDateString();
            }
            abort_if($data['business_date'] > $today, 409, 'Journée métier non terminée.');
            $bookings = DB::table('hotel_bookings')->where($key)
                ->where('arrival_date','<=',$data['business_date'])
                ->where('departure_date','>',$data['business_date'])
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total','status');
            $folios = DB::table('hotel_folio_entries')->where($key)
                ->where('business_date',$data['business_date'])
                ->selectRaw("kind, COALESCE(SUM(amount_cents),0) AS total_cents")
                ->groupBy('kind')->pluck('total_cents','kind');
            $minibar = DB::table('minibar_consumptions')->where($key)
                ->where('business_date',$data['business_date'])->sum('total_cents');
            $snapshot = [
                'business_date' => $data['business_date'], 'clock_version_id' => $clock->id,
                'bookings_by_status' => $bookings, 'folio_entries_by_kind_cents' => $folios,
                'minibar_total_cents' => (int)$minibar,
            ];
            // No invented adjustments: outstanding mismatches remain visible.
            $anomalies = ['external_reconciliation_unverified' => true,
                'external_channel_synced' => false];
            $id = DB::table('hotel_night_audits')->insertGetId($key + [
                'business_date' => $data['business_date'], 'request_id' => $data['request_id'],
                'clock_version_id' => $clock->id,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'anomalies' => json_encode($anomalies, JSON_THROW_ON_ERROR),
                'created_by' => $request->user()->id,
            ]);
            Audit::record('hotel.night_audit.closed', $property, [
                'audit_id' => $id, 'business_date' => $data['business_date'],
            ]);
            return response()->json(['id' => $id, 'snapshot' => $snapshot, 'anomalies' => $anomalies], 201);
        }, 3);
    }
}
