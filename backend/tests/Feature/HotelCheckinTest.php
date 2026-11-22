<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HotelCheckinTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company, string $suffix, string $role = 'admin'): void
    {
        $user = User::create([
            'name' => 'Réception fictive',
            'email' => 'checkin-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_checkin_requires_todays_inspection_and_rejects_maintenance_and_replay(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 11:00:00', 'Europe/Paris'));
        try {
            $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
            $b = Company::create(['code' => 'CHECKIN_OTHER', 'name' => 'Fictive foreign hotel']);
            $this->admin($a, 'admin');
            $hotel = $this->postJson('/api/hotels', [
                'code' => 'CHECKIN-HOTEL', 'name' => 'Hôtel test',
                'timezone' => 'Europe/Paris',
            ])->assertCreated()->json('id');
            $room = $this->postJson('/api/hotel-rooms', [
                'hotel_id' => $hotel, 'room_number' => '101', 'category' => 'Double',
            ])->assertCreated()->json('id');
            $booking = $this->postJson('/api/hotel-bookings', [
                'room_id' => $room, 'reference' => 'CK-IN-1',
                'guest_display_name' => 'Client fictif',
                'arrival_date' => '2026-12-10', 'departure_date' => '2026-12-12',
            ])->assertCreated()->json('id');
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertStatus(409);
            $this->postJson("/api/hotel-bookings/$booking/confirm")->assertOk();

            // Inspection from yesterday cannot be reused for a new business day.
            $yesterday = $this->postJson('/api/hotels/housekeeping/tasks', [
                'room_id' => $room, 'business_date' => '2026-12-09',
            ])->assertCreated()->json('id');
            foreach (['in_progress', 'clean', 'inspected'] as $status) {
                $this->postJson("/api/hotels/housekeeping/tasks/$yesterday/transition", [
                    'status' => $status,
                ])->assertOk();
            }
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertStatus(409);
            $todayTask = $this->postJson('/api/hotels/housekeeping/tasks', [
                'room_id' => $room, 'business_date' => '2026-12-10',
            ])->assertCreated()->json('id');
            foreach (['in_progress', 'clean', 'inspected'] as $status) {
                $this->postJson("/api/hotels/housekeeping/tasks/$todayTask/transition", [
                    'status' => $status,
                ])->assertOk();
            }

            $block = $this->postJson('/api/hotels/rooms/blocks', [
                'room_id' => $room, 'kind' => 'maintenance',
                'starts_on' => '2026-12-12', 'ends_on' => '2026-12-13',
                'reason' => 'Maintenance après départ',
            ])->assertCreated()->json('id');
            $this->postJson("/api/hotel-bookings/$booking/checkin")
                ->assertOk()->assertJsonPath('status', 'checked_in');
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertStatus(409);
            $this->assertDatabaseHas('audit_logs', [
                'company_id' => $a->id, 'action' => 'hotel.booking.checked_in',
            ]);

            // A different tenant must not inspect or mutate a booking.
            $this->admin($b, 'other');
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertNotFound();

            // Explicitly check active maintenance within the stay on another room.
            $this->admin($a, 'admin2');
            $room2 = $this->postJson('/api/hotel-rooms', [
                'hotel_id' => $hotel, 'room_number' => '102', 'category' => 'Double',
            ])->assertCreated()->json('id');
            $book2 = $this->postJson('/api/hotel-bookings', [
                'room_id' => $room2, 'reference' => 'CK-IN-2',
                'guest_display_name' => 'Autre client fictif',
                'arrival_date' => '2026-12-10', 'departure_date' => '2026-12-12',
            ])->assertCreated()->json('id');
            $this->postJson("/api/hotel-bookings/$book2/confirm")->assertOk();
            $task2 = $this->postJson('/api/hotels/housekeeping/tasks', [
                'room_id' => $room2, 'business_date' => '2026-12-10',
            ])->assertCreated()->json('id');
            foreach (['in_progress', 'clean', 'inspected'] as $status) {
                $this->postJson("/api/hotels/housekeeping/tasks/$task2/transition", [
                    'status' => $status,
                ])->assertOk();
            }
            // Physical maintenance can be imposed after confirmation by authorized
            // operations: the check-in must still fail closed.
            DB::table('hotel_rooms')->where('id', $room2)->update(['room_status' => 'out_of_service']);
            $this->postJson("/api/hotel-bookings/$book2/checkin")->assertStatus(409);
            $this->postJson("/api/hotels/rooms/blocks/$block/release")->assertOk();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_checkin_rejects_arrivals_outside_business_dates_and_reader_role(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 10:00:00', 'Europe/Paris'));
        try {
            $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
            $this->admin($a, 'active');
            $hotel = $this->postJson('/api/hotels', [
                'code' => 'CHECKIN-DATE', 'name' => 'Hotel test', 'timezone' => 'Europe/Paris',
            ])->assertCreated()->json('id');
            $room = $this->postJson('/api/hotel-rooms', [
                'hotel_id' => $hotel, 'room_number' => '201', 'category' => 'Standard',
            ])->assertCreated()->json('id');
            $booking = $this->postJson('/api/hotel-bookings', [
                'room_id' => $room, 'reference' => 'CK-FUTURE',
                'guest_display_name' => 'Fictif', 'arrival_date' => '2026-12-11',
                'departure_date' => '2026-12-13',
            ])->assertCreated()->json('id');
            $this->postJson("/api/hotel-bookings/$booking/confirm")->assertOk();
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertStatus(409);
            $this->admin($a, 'reader', 'reader');
            $this->postJson("/api/hotel-bookings/$booking/checkin")->assertForbidden();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
