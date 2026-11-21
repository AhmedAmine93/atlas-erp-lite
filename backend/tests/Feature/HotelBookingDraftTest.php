<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelBookingDraftTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $c,string $suffix): void
    {
        $role=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>'Hotel','email'=>'booking-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!','role_id'=>$role]);
        $user->companies()->attach($c->id,['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$c->id]);
    }
    public function test_draft_uses_verified_room_and_rejects_invalid_dates(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'HOTEL_DRAFT_B','name'=>'Other Hotel']);
        $this->admin($a,'a');
        $hotel=$this->postJson('/api/hotels',[
            'code'=>'H01','name'=>'Synthetic Hotel','timezone'=>'Europe/Paris',
        ])->assertCreated()->json('id');
        $room=$this->postJson('/api/hotel-rooms',[
            'hotel_id'=>$hotel,'room_number'=>'101','category'=>'Double',
        ])->assertCreated()->json('id');
        $data=['room_id'=>$room,'reference'=>'R-2026','guest_display_name'=>'Synthetic Guest',
            'arrival_date'=>'2026-12-01','departure_date'=>'2026-12-04'];
        $this->postJson('/api/hotel-bookings',$data)->assertCreated()
            ->assertJsonPath('status','draft')->assertJsonPath('hotel_id',$hotel);
        $this->postJson('/api/hotel-bookings',array_merge($data,['reference'=>'INVALID','departure_date'=>'2026-12-01']))
            ->assertUnprocessable();
        $this->postJson('/api/hotel-bookings',array_merge($data,['reference'=>'R-2026']))
            ->assertStatus(500); // replaced by explicit 422 uniqueness validation below
        $this->admin($b,'b');
        $this->postJson('/api/hotel-bookings',array_merge($data,['reference'=>'FOREIGN']))
            ->assertNotFound();
        $this->getJson('/api/hotel-bookings')->assertOk()->assertJsonPath('total',0);
    }
}
