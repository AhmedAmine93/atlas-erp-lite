<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name'=>$suffix, 'email'=>'hotel-booking-'.$suffix.'@example.test',
            'password'=>'SyntheticPassword2026!',
            'role_id'=>Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id'=>Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    private function hotelRoom(string $code, string $room = '101'): array
    {
        $hotel = $this->postJson('/api/hotels', [
            'code'=>$code, 'name'=>'Demo Hotel '.$code, 'timezone'=>'Europe/Paris',
        ])->assertCreated()->json('id');
        $roomId = $this->postJson('/api/hotel-rooms', [
            'hotel_id'=>$hotel, 'room_number'=>$room, 'category'=>'Double',
        ])->assertCreated()->json('id');
        return [$hotel, $roomId];
    }

    private function booking(int $hotel, int $room, string $arrive, string $leave): array
    {
        return [
            'hotel_id'=>$hotel, 'room_id'=>$room, 'guest_name'=>'Synthetic Guest',
            'arrival_on'=>$arrive, 'departure_on'=>$leave,
        ];
    }

    public function test_overbooking_rescheduling_cancellation_and_adjacent_stays(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->asRole($a, 'admin', 'manager-a');
        [$hotel, $room] = $this->hotelRoom('PARIS-ONE');
        $first = $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-02-10','2027-02-12')
        )->assertCreated()->assertJsonPath('status','confirmed')->json('id');

        // Inclusive arrival, exclusive departure: same-day turnover permitted.
        $next = $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-02-12','2027-02-13')
        )->assertCreated()->json('id');
        $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-02-11','2027-02-13')
        )->assertStatus(409);
        $this->patchJson("/api/hotel-reservations/$next/dates", [
            'arrival_on'=>'2027-02-11','departure_on'=>'2027-02-13',
        ])->assertStatus(409);
        $this->postJson("/api/hotel-reservations/$first/cancel")
            ->assertOk()->assertJsonPath('status','cancelled');
        $this->postJson("/api/hotel-reservations/$first/cancel")->assertStatus(409);
        $this->patchJson("/api/hotel-reservations/$first/dates", [
            'arrival_on'=>'2027-02-11','departure_on'=>'2027-02-12',
        ])->assertStatus(409);
        $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-02-10','2027-02-12')
        )->assertCreated();
        $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-02-14','2027-02-14')
        )->assertUnprocessable();
        $this->postJson('/api/hotel-reservations', array_merge(
            $this->booking($hotel,$room,'2027-02-14','2027-02-15'),
            ['status'=>'checked_in']
        ))->assertUnprocessable();
    }

    public function test_room_and_company_scope_and_read_only_role(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'HOTEL_BOOKING_B','name'=>'Other company']);
        $this->asRole($a, 'admin', 'manager-other');
        [$hotel, $room] = $this->hotelRoom('HOTEL-A');
        $booking = $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-03-01','2027-03-02')
        )->assertCreated()->json('id');
        [$otherHotel, $otherRoom] = $this->hotelRoom('HOTEL-B');
        $this->postJson('/api/hotel-reservations',
            $this->booking($otherHotel,$room,'2027-03-01','2027-03-02')
        )->assertNotFound();
        $this->postJson('/api/hotel-reservations',
            $this->booking($otherHotel,$otherRoom,'2027-03-01','2027-03-02')
        )->assertCreated();

        $this->asRole($b, 'admin', 'manager-b');
        $this->getJson('/api/hotel-reservations')->assertOk()->assertJsonPath('total',0);
        $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-03-02','2027-03-03')
        )->assertNotFound();
        $this->postJson("/api/hotel-reservations/$booking/cancel")->assertNotFound();

        $this->asRole($a, 'reader', 'reader-a');
        $this->getJson('/api/hotel-reservations')->assertForbidden();
        $this->postJson('/api/hotel-reservations',
            $this->booking($hotel,$room,'2027-04-01','2027-04-02')
        )->assertForbidden();
    }
}
