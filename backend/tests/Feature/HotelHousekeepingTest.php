<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HotelHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private function login(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => 'Hotel Test '.$suffix,
            'email' => 'hk-'.$suffix.'@example.test',
            'password' => 'TestingPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_inspection_is_required_and_does_not_override_physical_maintenance_block(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'HK_COMPANY_B', 'name' => 'Entreprise B fictive']);
        $date = today()->toDateString();
        $this->getJson('/api/hotels/housekeeping/tasks')->assertUnauthorized();
        $this->login($a, 'reader', 'reader');
        $this->getJson('/api/hotels/housekeeping/tasks')->assertForbidden();
        $this->login($a, 'admin', 'admina');
        $hotel = $this->postJson('/api/hotels', [
            'code' => 'HOTEL-HK', 'name' => 'Hôtel Fictif', 'timezone' => 'Europe/Paris',
        ])->assertCreated()->json('id');
        $room = $this->postJson('/api/hotel-rooms', [
            'hotel_id' => $hotel, 'room_number' => '101', 'category' => 'Standard',
        ])->assertCreated()->json('id');
        $task = $this->postJson('/api/hotels/housekeeping/tasks', [
            'room_id' => $room, 'business_date' => $date, 'company_id' => $b->id,
        ])->assertUnprocessable();
        $task = $this->postJson('/api/hotels/housekeeping/tasks', [
            'room_id' => $room, 'business_date' => $date,
        ])->assertCreated()->assertJsonPath('status', 'dirty')->json('id');
        $this->postJson('/api/hotels/housekeeping/tasks', [
            'room_id' => $room, 'business_date' => $date,
        ])->assertStatus(409);
        $this->getJson("/api/hotels/housekeeping/readiness?room_id=$room&business_date=$date")
            ->assertOk()->assertJsonPath('ready_for_sale', false);
        $this->postJson("/api/hotels/housekeeping/tasks/$task/transition", [
            'status' => 'inspected',
        ])->assertStatus(409);
        foreach (['in_progress', 'clean', 'inspected'] as $state) {
            $this->postJson("/api/hotels/housekeeping/tasks/$task/transition", [
                'status' => $state,
            ])->assertOk()->assertJsonPath('status', $state);
        }
        $this->postJson("/api/hotels/housekeeping/tasks/$task/transition", [
            'status' => 'dirty',
        ])->assertUnprocessable();
        $this->getJson("/api/hotels/housekeeping/readiness?room_id=$room&business_date=$date")
            ->assertOk()->assertJsonPath('ready_for_sale', true);

        DB::table('hotel_rooms')->where('id', $room)->update(['room_status' => 'out_of_service']);
        $this->getJson("/api/hotels/housekeeping/readiness?room_id=$room&business_date=$date")
            ->assertOk()->assertJsonPath('ready_for_sale', false)
            ->assertJsonPath('physical_status', 'out_of_service');
        $this->assertDatabaseCount('hotel_room_status_events', 4);

        $this->login($b, 'admin', 'adminb');
        $this->getJson('/api/hotels/housekeeping/tasks')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/hotels/housekeeping/tasks', [
            'room_id' => $room, 'business_date' => $date,
        ])->assertNotFound();
        $this->postJson("/api/hotels/housekeeping/tasks/$task/transition", [
            'status' => 'in_progress',
        ])->assertNotFound();
    }
}
