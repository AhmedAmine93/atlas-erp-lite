<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataCanonicalSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name'=>$suffix, 'email'=>'schema-'.$suffix.'@example.test',
            'password'=>'SyntheticPassword2026!',
            'role_id'=>Role::where('name','admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    private function payload(int $version = 1): array
    {
        return [
            'version'=>$version, 'name'=>'PMS booking v'.$version,
            'fields'=>[
                ['key'=>'booking_id','type'=>'string','required'=>true,'pii'=>false],
                ['key'=>'guest_name','type'=>'string','required'=>false,'pii'=>true],
                ['key'=>'total_cents','type'=>'money_cents','required'=>true,'pii'=>false],
            ],
        ];
    }

    public function test_schema_is_versioned_immutable_and_tenant_scoped(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'SCHEMA_B','name'=>'Other source tenant']);

        $this->getJson('/api/data/sources/1/schemas')->assertUnauthorized();
        $this->asRole($a,'admin','a');
        $source = $this->postJson('/api/data/sources', [
            'code'=>'PMS_A','name'=>'Synthetic PMS','source_type'=>'pms','classification'=>'confidential',
        ])->assertCreated()->json('id');
        $first = $this->postJson("/api/data/sources/$source/schemas", $this->payload())
            ->assertCreated()->assertJsonPath('version',1)->json();
        $this->assertSame(64, strlen($first['fingerprint']));
        $this->postJson("/api/data/sources/$source/schemas", $this->payload())
            ->assertUnprocessable();
        $this->postJson("/api/data/sources/$source/schemas", $this->payload(2))
            ->assertCreated();
        $this->getJson("/api/data/sources/$source/schemas")
            ->assertOk()->assertJsonPath('total',2);

        $this->asRole($b,'admin','b');
        $this->getJson("/api/data/sources/$source/schemas")->assertNotFound();
        $this->postJson("/api/data/sources/$source/schemas", $this->payload(3))
            ->assertNotFound();
        $this->assertDatabaseCount('data_canonical_schemas', 2);
    }

    public function test_public_source_pii_and_untrusted_schema_keys_are_rejected(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->asRole($a,'admin','admin');
        $source = $this->postJson('/api/data/sources', [
            'code'=>'OPEN_DATA','name'=>'Public synthetic feed',
            'source_type'=>'csv','classification'=>'public',
        ])->assertCreated()->json('id');

        $this->postJson("/api/data/sources/$source/schemas", $this->payload())
            ->assertUnprocessable();
        $safe = $this->payload();
        $safe['fields'][1]['pii'] = false;
        $safe['fields'][0]['expression'] = 'DROP TABLE users';
        $this->postJson("/api/data/sources/$source/schemas", $safe)
            ->assertUnprocessable();
        unset($safe['fields'][0]['expression']);
        $safe['fields'][1]['key'] = 'booking_id';
        $this->postJson("/api/data/sources/$source/schemas", $safe)
            ->assertUnprocessable();
        $safe['fields'][1]['key'] = 'guest';
        $safe['fields'][1]['type'] = 'script';
        $this->postJson("/api/data/sources/$source/schemas", $safe)
            ->assertUnprocessable();
        $safe['fields'][1]['type'] = 'string';

        $this->postJson("/api/data/sources/$source/schemas", $safe)
            ->assertCreated()->assertJsonPath('fields.1.key','guest');
        $this->assertDatabaseCount('data_canonical_schemas', 1);

        $this->asRole($a,'reader','reader');
        $this->getJson("/api/data/sources/$source/schemas")->assertForbidden();
        $this->postJson("/api/data/sources/$source/schemas", $this->payload(2))
            ->assertForbidden();
    }
}
