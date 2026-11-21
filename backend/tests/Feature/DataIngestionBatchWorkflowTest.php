<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataIngestionBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $c,string $suffix): void
    {
        $role=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>'Data','email'=>'data-batch-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!','role_id'=>$role]);
        $user->companies()->attach($c->id,['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$c->id]);
    }
    public function test_batches_are_idempotent_and_bound_to_validated_tenant_source(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'DATA_BATCH_B','name'=>'Other data']);
        $this->admin($a,'a');
        $source=$this->postJson('/api/data/sources',[
            'code'=>'PMS_A','name'=>'Synthetic PMS','source_type'=>'csv','classification'=>'internal',
        ])->assertCreated()->json('id');
        $body=['source_id'=>$source,'batch_key'=>'batch-001',
            'sha256'=>str_repeat('a',64),'expected_rows'=>25];
        $this->postJson('/api/data/ingestion-batches',$body)->assertStatus(409);
        $this->postJson("/api/data/sources/$source/validate")->assertOk()
            ->assertJsonPath('status','validated');
        $this->postJson("/api/data/sources/$source/validate")->assertStatus(409);
        $id=$this->postJson('/api/data/ingestion-batches',$body)->assertCreated()
            ->assertJsonPath('status','registered')->json('id');
        $this->postJson('/api/data/ingestion-batches',$body)->assertOk()->assertJsonPath('id',$id);
        $this->postJson('/api/data/ingestion-batches',array_merge($body,['sha256'=>str_repeat('b',64)]))
            ->assertStatus(409);
        $this->assertDatabaseCount('data_ingestion_batches',1);
        $this->admin($b,'b');
        $this->postJson('/api/data/ingestion-batches',$body)->assertNotFound();
    }
}
