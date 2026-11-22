<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HotelOperationsTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private int $hotelId;
    private int $roomId;
    private int $bookingId;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->companyId = $company->id;
        $this->login($company, 'primary');
        $this->hotelId = $this->postJson('/api/hotels', [
            'code' => 'M08-TEST', 'name' => 'Hôtel artificiel', 'timezone' => 'Europe/Paris',
        ])->assertCreated()->json('id');
        $this->roomId = $this->postJson('/api/hotel-rooms', [
            'hotel_id' => $this->hotelId, 'room_number' => '101', 'category' => 'Double',
        ])->assertCreated()->json('id');
        $this->bookingId = $this->postJson('/api/hotel-bookings', [
            'room_id' => $this->roomId, 'reference' => 'BOOK-1',
            'guest_display_name' => 'Client fictif', 'arrival_date' => '2026-01-10',
            'departure_date' => '2026-01-13',
        ])->assertCreated()->json('id');
        $this->base = '/api/hotels/'.$this->hotelId;
    }

    private function login(Company $company, string $suffix, string $role = 'admin'): void
    {
        $roleId = Role::where('name', $role)->value('id');
        $user = User::create([
            'name' => 'Tester', 'email' => 'm08-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!', 'role_id' => $roleId,
        ]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_folio_is_ledger_based_idempotent_and_tenant_scoped(): void
    {
        $folio = $this->postJson($this->base.'/folios', [
            'booking_id' => $this->bookingId, 'folio_number' => 'FOL-1',
        ])->assertCreated()->json('id');
        $payload = [
            'business_date' => '2026-01-11', 'kind' => 'charge',
            'source_type' => 'manual', 'amount_cents' => 1000,
            'note' => 'Prestation fictive', 'request_id' => '00000000-0000-4000-8000-000000000011',
        ];
        $endpoint = $this->base.'/folios/'.$folio.'/entries';
        $entry = $this->postJson($endpoint, $payload)->assertCreated()->json('id');
        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('id', $entry);
        $this->postJson($endpoint, array_replace($payload, ['amount_cents' => 1200]))->assertConflict();
        $this->getJson($this->base.'/folios/'.$folio)->assertOk()->assertJsonPath('balance_cents', 1000);
        $this->postJson($this->base.'/folios/'.$folio.'/close')->assertOk();
        $this->postJson($endpoint, array_replace($payload, ['request_id' => '00000000-0000-4000-8000-000000000012']))->assertConflict();
        $this->assertSame(1, DB::table('hotel_folio_entries')->count());

        $this->login(Company::create(['code' => 'M08-B', 'name' => 'Entreprise B']), 'other');
        $this->getJson($this->base.'/folios/'.$folio)->assertNotFound();
        $this->postJson($endpoint, $payload)->assertNotFound();
        $this->login(Company::whereKey($this->companyId)->firstOrFail(), 'reader', 'reader');
        $this->getJson($this->base.'/folios')->assertForbidden();
    }

    public function test_folio_transfer_preserves_double_entry_balance_and_blocks_over_transfer(): void
    {
        $source = $this->postJson($this->base.'/folios', [
            'booking_id' => $this->bookingId, 'folio_number' => 'SOURCE',
        ])->assertCreated()->json('id');
        $target = $this->postJson($this->base.'/folios', [
            'booking_id' => $this->bookingId, 'folio_number' => 'TARGET',
        ])->assertCreated()->json('id');
        $entry = $this->postJson($this->base.'/folios/'.$source.'/entries', [
            'business_date' => '2026-01-11', 'kind' => 'charge',
            'source_type' => 'manual', 'amount_cents' => 2000, 'note' => 'Service fictif',
            'request_id' => '00000000-0000-4000-8000-000000000041',
        ])->assertCreated()->json('id');
        $transfer = [
            'destination_folio_id' => $target, 'source_entry_id' => $entry,
            'amount_cents' => 1200, 'business_date' => '2026-01-11',
            'reason' => 'Partage demandé', 'request_id' => '00000000-0000-4000-8000-000000000042',
        ];
        $url = $this->base.'/folios/'.$source.'/transfer';
        $this->postJson($url,$transfer)->assertCreated();
        $this->postJson($url,$transfer)->assertOk()->assertJsonPath('replayed',true);
        $this->getJson($this->base.'/folios/'.$source)->assertJsonPath('balance_cents',800);
        $this->getJson($this->base.'/folios/'.$target)->assertJsonPath('balance_cents',1200);
        $this->postJson($url,array_replace($transfer, [
            'amount_cents' => 1000, 'request_id' => '00000000-0000-4000-8000-000000000043',
        ]))->assertConflict();
        $this->assertSame(3, DB::table('hotel_folio_entries')->count());
    }

    public function test_post_close_correction_is_traceable_and_does_not_rewrite_original(): void
    {
        $folio = $this->postJson($this->base.'/folios', [
            'booking_id'=>$this->bookingId,'folio_number'=>'CORRECT-TEST',
        ])->assertCreated()->json('id');
        $entry = $this->postJson($this->base.'/folios/'.$folio.'/entries', [
            'business_date'=>'2026-01-11','kind'=>'charge','source_type'=>'manual',
            'amount_cents'=>1000,'note'=>'Charge originale',
            'request_id'=>'00000000-0000-4000-8000-000000000051',
        ])->assertCreated()->json('id');
        $this->postJson($this->base.'/calendar/versions', [
            'effective_on'=>'2026-01-01','timezone'=>'Europe/Paris',
            'cutoff_time'=>'04:00',
        ])->assertCreated();
        $this->postJson($this->base.'/night-audits', [
            'business_date'=>'2026-01-11',
            'request_id'=>'00000000-0000-4000-8000-000000000052',
        ])->assertCreated();
        $url=$this->base.'/folios/'.$folio.'/entries/'.$entry.'/correct';
        $this->postJson($url, [
            'business_date'=>'2026-01-11','amount_cents'=>500,
            'reason'=>'Erreur de saisie',
            'request_id'=>'00000000-0000-4000-8000-000000000053',
        ])->assertConflict();
        $payload = [
            'business_date'=>'2026-01-12','amount_cents'=>500,
            'reason'=>'Erreur de saisie',
            'request_id'=>'00000000-0000-4000-8000-000000000053',
        ];
        $this->postJson($url,$payload)->assertCreated()->assertJsonPath('kind','credit');
        $this->postJson($url,$payload)->assertOk();
        $this->getJson($this->base.'/folios/'.$folio)->assertJsonPath('balance_cents',500);
        $this->assertSame(1000,(int)DB::table('hotel_folio_entries')->where('id',$entry)->value('amount_cents'));
    }

    public function test_channel_imports_are_versioned_replay_safe_and_never_write_bookings(): void
    {
        $this->postJson($this->base.'/channel/rules', [
            'effective_on' => '2026-01-01', 'prefix_digits' => 4, 'separator' => '_',
        ])->assertCreated();
        $pms = [
            'source' => 'pms', 'business_date' => '2026-01-11',
            'request_id' => '00000000-0000-4000-8000-000000000021', 'synthetic' => true,
            'rows' => [[ 'reference' => '1234_RES001', 'status' => 'active',
                'origin' => 'channel', 'amount_cents' => 1000 ]],
        ];
        $pmsId = $this->postJson($this->base.'/channel/imports', $pms)->assertCreated()->json('id');
        $this->postJson($this->base.'/channel/imports', $pms)->assertOk()->assertJsonPath('id', $pmsId);
        $this->postJson($this->base.'/channel/imports', array_replace(
            $pms, ['rows' => [[ 'reference' => '1234_RES001', 'status' => 'cancelled',
                'origin' => 'channel', 'amount_cents' => 1000 ]]]
        ))->assertConflict();
        $crs = array_replace($pms, [
            'source' => 'crs', 'request_id' => '00000000-0000-4000-8000-000000000022',
            'rows' => [[ 'reference' => 'RES001', 'status' => 'active',
                'origin' => 'channel', 'amount_cents' => 500 ]],
        ]);
        $crsId = $this->postJson($this->base.'/channel/imports', $crs)->assertCreated()->json('id');
        $this->getJson($this->base."/channel/reconciliation?pms_import_id=$pmsId&crs_import_id=$crsId")
            ->assertOk()->assertJsonPath('rows.0.reference', 'RES001')
            ->assertJsonPath('rows.0.status', 'amount_mismatch')
            ->assertJsonPath('synced_live', false)
            ->assertJsonPath('manual_review_required', true);
        $this->assertSame(1, DB::table('hotel_bookings')->count());
        $this->assertSame(2, DB::table('hotel_channel_records')->count());
    }

    public function test_night_audit_freezes_snapshot_without_adjusting_anomalies(): void
    {
        $this->postJson($this->base.'/calendar/versions', [
            'effective_on' => '2026-01-01', 'timezone' => 'Europe/Paris',
            'cutoff_time' => '04:00',
        ])->assertCreated();
        $request = [
            'business_date' => '2026-01-11',
            'request_id' => '00000000-0000-4000-8000-000000000031',
        ];
        $this->postJson($this->base.'/night-audits', $request)->assertCreated()
            ->assertJsonPath('anomalies.external_reconciliation_unverified', true);
        $this->postJson($this->base.'/night-audits', $request)->assertOk();
        $this->postJson($this->base.'/night-audits', array_replace($request, [
            'request_id' => '00000000-0000-4000-8000-000000000032',
        ]))->assertConflict();
        $folio = $this->postJson($this->base.'/folios', [
            'booking_id' => $this->bookingId, 'folio_number' => 'AFTER-CLOSE',
        ])->assertCreated()->json('id');
        $this->postJson($this->base.'/folios/'.$folio.'/entries', [
            'business_date' => '2026-01-11', 'kind' => 'charge',
            'source_type' => 'manual', 'amount_cents' => 500,
            'note' => 'Saisie tardive', 'request_id' => '00000000-0000-4000-8000-000000000033',
        ])->assertConflict();
    }
}
