<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HotelLocalInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function login(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => 'Hotel Inventory Test',
            'email' => 'inventory-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    private function hotelAndRoom(): array
    {
        $hotel = $this->postJson('/api/hotels', [
            'code' => 'TEST-INVENTORY',
            'name' => 'Hôtel fictif inventaire',
            'timezone' => 'Africa/Tunis',
        ])->assertCreated()->json('id');
        $room = $this->postJson('/api/hotel-rooms', [
            'hotel_id' => $hotel,
            'room_number' => '101',
            'category' => 'Double',
        ])->assertCreated()->json('id');
        return [$hotel, $room];
    }

    private function availability(int $hotel, string $start, string $end, ?int $room = null)
    {
        $query = http_build_query(array_filter([
            'hotel_id' => $hotel,
            'arrival_date' => $start,
            'departure_date' => $end,
            'room_id' => $room,
        ], fn ($v) => $v !== null));
        return $this->getJson('/api/hotels/rooms/availability?'.$query);
    }

    private function draft(int $room, string $ref, string $start, string $end): int
    {
        return $this->postJson('/api/hotel-bookings', [
            'room_id' => $room,
            'reference' => $ref,
            'guest_display_name' => 'Client fictif',
            'arrival_date' => $start,
            'departure_date' => $end,
        ])->assertCreated()->json('id');
    }

    public function test_occupancy_and_half_open_dates_are_independent_from_cleaning(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->getJson('/api/hotels/rooms/availability?hotel_id=1')->assertUnauthorized();
        $this->login($company, 'admin', 'occupancy');
        [$hotel, $room] = $this->hotelAndRoom();

        $this->availability($hotel, '2026-12-01', '2026-12-03', $room)
            ->assertOk()->assertJsonPath('data.0.locally_available', true)
            ->assertJsonPath('inventory_scope', 'atlas_local_assigned_rooms_v1')
            ->assertJsonPath('external_allotments_checked', false)
            ->assertJsonPath('timezone', 'Africa/Tunis');

        $task = $this->postJson('/api/hotels/housekeeping/tasks', [
            'room_id' => $room, 'business_date' => '2026-12-01',
        ])->assertCreated()->json('id');
        // Cleaning is not sellability: dirty rooms can still be sold for future nights.
        $this->availability($hotel, '2026-12-01', '2026-12-03', $room)
            ->assertJsonPath('data.0.locally_available', true);
        $this->getJson("/api/hotels/housekeeping/readiness?room_id=$room&business_date=2026-12-01")
            ->assertJsonPath('housekeeping_ready', false);

        $first = $this->draft($room, 'LOCAL-1', '2026-12-01', '2026-12-04');
        $this->postJson("/api/hotel-bookings/$first/confirm")->assertOk();
        $this->availability($hotel, '2026-12-03', '2026-12-05', $room)
            ->assertJsonPath('data.0.locally_available', false)
            ->assertJsonPath('data.0.reason_codes.0', 'OCCUPIED_OR_CONFIRMED');
        $this->availability($hotel, '2026-12-04', '2026-12-06', $room)
            ->assertJsonPath('data.0.locally_available', true);
        $this->availability($hotel, '2026-12-04', '2026-12-04', $room)
            ->assertUnprocessable();
        $this->postJson("/api/hotels/housekeeping/tasks/$task/transition", [
            'status' => 'in_progress',
        ])->assertOk();
        // A later maintenance block cannot replace the original booking constraint.
        $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'maintenance',
            'starts_on' => '2026-12-02', 'ends_on' => '2026-12-03',
            'reason' => 'Travaux fictifs',
        ])->assertStatus(409);
    }

    public function test_holds_maintenance_expiration_release_and_tenant_permissions(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'INV_COMPANY_B', 'name' => 'Fictif B']);
        $this->login($a, 'admin', 'admina');
        [$hotel, $room] = $this->hotelAndRoom();

        $hold = $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'hold',
            'starts_on' => '2026-12-10', 'ends_on' => '2026-12-12',
            'expires_at' => now()->addHours(4)->toIso8601String(),
            'reason' => 'Option commerciale fictive',
        ])->assertCreated()->assertJsonPath('kind', 'hold')->json('id');
        $this->availability($hotel, '2026-12-11', '2026-12-12', $room)
            ->assertJsonPath('data.0.locally_available', false)
            ->assertJsonPath('data.0.reason_codes.0', 'ACTIVE_HOLD');
        $draft = $this->draft($room, 'LOCAL-HOLD-1', '2026-12-11', '2026-12-12');
        $this->postJson("/api/hotel-bookings/$draft/confirm")->assertStatus(409);
        $this->postJson("/api/hotels/rooms/blocks/$hold/release")->assertOk()
            ->assertJsonPath('status', 'released');
        $this->postJson("/api/hotels/rooms/blocks/$hold/release")->assertStatus(409);
        $this->postJson("/api/hotel-bookings/$draft/confirm")->assertOk();

        $maintenance = $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'maintenance',
            'starts_on' => '2026-12-12', 'ends_on' => '2026-12-14',
            'reason' => 'Intervention fictive',
        ])->assertCreated()->json('id');
        $this->availability($hotel, '2026-12-13', '2026-12-14', $room)
            ->assertJsonPath('data.0.reason_codes.0', 'MAINTENANCE_WINDOW');
        $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'restriction',
            'starts_on' => '2026-12-13', 'ends_on' => '2026-12-14',
            'reason' => 'Double blocage',
            'company_id' => $b->id,
        ])->assertUnprocessable();
        $this->postJson("/api/hotels/rooms/blocks/$maintenance/release")->assertOk();

        $expiring = $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'hold',
            'starts_on' => '2026-12-15', 'ends_on' => '2026-12-17',
            'expires_at' => now()->addHours(2)->toIso8601String(),
            'reason' => 'Option devant expirer',
        ])->assertCreated()->json('id');
        DB::table('hotel_room_blocks')->where('id', $expiring)
            ->update(['expires_at' => now()->subMinute()]);
        $this->availability($hotel, '2026-12-15', '2026-12-17', $room)
            ->assertJsonPath('data.0.locally_available', true);

        DB::table('hotel_rooms')->where('id', $room)->update(['room_status' => 'blocked']);
        $this->availability($hotel, '2026-12-15', '2026-12-17', $room)
            ->assertJsonPath('data.0.locally_available', false)
            ->assertJsonPath('data.0.reason_codes.0', 'ROOM_PHYSICALLY_BLOCKED');

        $this->login($b, 'admin', 'adminb');
        $this->availability($hotel, '2026-12-15', '2026-12-17', $room)->assertNotFound();
        $this->getJson("/api/hotels/rooms/blocks?hotel_id=$hotel")->assertNotFound();
        $this->postJson("/api/hotels/rooms/blocks/$expiring/release")->assertNotFound();
        $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'hold',
            'starts_on' => '2026-12-20', 'ends_on' => '2026-12-22',
            'expires_at' => now()->addHour()->toIso8601String(),
            'reason' => 'Tentative inter-tenant',
        ])->assertNotFound();

        $this->login($a, 'reader', 'readera');
        $this->availability($hotel, '2026-12-01', '2026-12-02', $room)->assertForbidden();
        $this->postJson('/api/hotels/rooms/blocks', [
            'room_id' => $room, 'kind' => 'maintenance',
            'starts_on' => '2026-12-20', 'ends_on' => '2026-12-22',
            'reason' => 'Non autorisé',
        ])->assertForbidden();
    }
}
