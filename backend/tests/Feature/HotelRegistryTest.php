<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function become(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => 'Registry '.$suffix,
            'email' => 'registry-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!',
            // Deliberately privileged legacy role: only tenant membership counts.
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_registry_rejects_unauthorized_roles_and_isolates_companies(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'HOTEL_B', 'name' => 'Registry B']);

        $this->getJson('/api/hotels')->assertUnauthorized();
        $this->become($a, 'reader', 'reader');
        $this->getJson('/api/hotels')->assertForbidden();
        $this->postJson('/api/hotels', ['code'=>'HOTEL-01','name'=>'Left Bank Demo','timezone'=>'Europe/Paris'])->assertForbidden();

        $this->become($a, 'admin', 'a');
        $hotelId = $this->postJson('/api/hotels', ['code'=>'HOTEL-01','name'=>'Left Bank Demo','timezone'=>'Europe/Paris'])->assertCreated()->json('id');
        $this->postJson('/api/hotel-rooms', ['hotel_id'=>$hotelId,'room_number'=>'101','category'=>'Double'])->assertCreated();
        $this->postJson('/api/hotel-rooms', ['hotel_id'=>$hotelId,'room_number'=>'101','category'=>'Twin'])->assertUnprocessable();
        $this->become($b, 'admin', 'b');
        $this->getJson('/api/hotels')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/hotel-rooms', ['hotel_id'=>$hotelId,'room_number'=>'101','category'=>'Double'])->assertNotFound();
        $h2 = $this->postJson('/api/hotels', ['code'=>'HOTEL-01','name'=>'Second Hotel','timezone'=>'Africa/Tunis'])->assertCreated()->json('id');
        $this->postJson('/api/hotel-rooms', ['hotel_id'=>$h2,'room_number'=>'101','category'=>'Twin'])->assertCreated();
        $this->getJson('/api/hotel-rooms')->assertOk()->assertJsonPath('total', 1);
    }
}
