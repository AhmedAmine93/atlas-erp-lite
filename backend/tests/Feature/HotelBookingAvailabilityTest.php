<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelBookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company, string $suffix): void
    {
        $roleId = Role::where('name', 'admin')->value('id');
        $user = User::create(['name' => 'Reception',
            'email' => 'reception-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!', 'role_id' => $roleId]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    private function draft(int $room, string $ref, string $arrival, string $departure): int
    {
        return $this->postJson('/api/hotel-bookings', [
            'room_id' => $room, 'reference' => $ref,
            'guest_display_name' => 'Guest fictif',
            'arrival_date' => $arrival, 'departure_date' => $departure,
        ])->assertCreated()->json('id');
    }

    public function test_confirm_rejects_overlap_allows_adjacent_dates_and_releases_on_cancel(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'HOTEL_AVAIL_B', 'name' => 'Fictive B']);
        $this->admin($a, 'a');
        $hotel = $this->postJson('/api/hotels', [
            'code' => 'H-AVAIL', 'name' => 'Fictive property', 'timezone' => 'Africa/Tunis',
        ])->assertCreated()->json('id');
        $room = $this->postJson('/api/hotel-rooms', [
            'hotel_id' => $hotel, 'room_number' => '201', 'category' => 'Double',
        ])->assertCreated()->json('id');
        $first = $this->draft($room, 'STAY-A', '2026-12-01', '2026-12-04');
        $overlap = $this->draft($room, 'STAY-B', '2026-12-03', '2026-12-06');
        $adjacent = $this->draft($room, 'STAY-C', '2026-12-04', '2026-12-06');
        $this->postJson("/api/hotel-bookings/$first/confirm")->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->postJson("/api/hotel-bookings/$first/confirm")->assertStatus(409);
        $this->postJson("/api/hotel-bookings/$overlap/confirm")->assertStatus(409);
        $this->postJson("/api/hotel-bookings/$adjacent/confirm")->assertOk();
        $this->postJson("/api/hotel-bookings/$first/cancel")->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/hotel-bookings/$overlap/confirm")->assertStatus(409); // STAY-C still overlaps.
        $this->postJson("/api/hotel-bookings/$adjacent/cancel")->assertOk();
        $this->postJson("/api/hotel-bookings/$overlap/confirm")->assertOk();
        $this->admin($b, 'b');
        $this->postJson("/api/hotel-bookings/$overlap/cancel")->assertNotFound();
        $this->postJson("/api/hotel-bookings/$overlap/confirm")->assertNotFound();
    }

    public function test_postgresql_rejects_overlapping_confirmed_rows_even_bypassing_controller(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exclusion GiST vérifiée uniquement sur PostgreSQL.');
        }
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->admin($a, 'sql');
        $hotel = $this->postJson('/api/hotels', [
            'code' => 'H-SQL', 'name' => 'Test SQL', 'timezone' => 'Europe/Paris',
        ])->assertCreated()->json('id');
        $room = $this->postJson('/api/hotel-rooms', [
            'hotel_id' => $hotel, 'room_number' => '101', 'category' => 'Suite',
        ])->assertCreated()->json('id');
        $first = $this->draft($room, 'SQL-1', '2026-12-10', '2026-12-12');
        $second = $this->draft($room, 'SQL-2', '2026-12-11', '2026-12-13');
        $this->postJson("/api/hotel-bookings/$first/confirm")->assertOk();
        try {
            \Illuminate\Support\Facades\DB::table('hotel_bookings')
                ->where('id', $second)->update(['status' => 'confirmed']);
            $this->fail('La contrainte GiST doit interdire le chevauchement.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23P01', $e->errorInfo[0] ?? $e->getCode());
        }
    }
}
