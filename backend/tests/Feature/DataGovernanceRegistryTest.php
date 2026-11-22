<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataGovernanceRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function become(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name'=>'Data Governance '.$suffix,
            'email'=>'data-governance-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!',
            'role_id'=>Role::where('name','admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    public function test_five_registries_are_tenant_scoped_and_idempotent(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'DATA_GOV_B','name'=>'Other Data Tenant']);
        foreach (['pipelines','quality-rules','lineage','reconciliations','lakehouse'] as $index=>$domain) {
            $path='/api/data/governance/'.$domain;
            $payload=['code'=>'SYNTHETIC_'.($index+1),'name'=>'Synthetic item','version'=>1,
                'configuration'=>['owner'=>'Demo steward']];
            $this->become($a,'reader','reader-'.$index);
            $this->getJson($path)->assertForbidden();
            $this->postJson($path,$payload)->assertForbidden();
            $this->become($a,'admin','admin-'.$index);
            $this->postJson($path,array_merge($payload,['company_id'=>$b->id]))->assertUnprocessable();
            $this->postJson($path,array_merge($payload,['credentials'=>'secret']))->assertUnprocessable();
            // Schema differs per registry; pick valid configuration for each.
            $key=['pipelines'=>'owner','quality-rules'=>'owner','lineage'=>'source_code',
                'reconciliations'=>'justification','lakehouse'=>'owner'][$domain];
            $payload['configuration']=[$key=>'Fictional'];
            $this->postJson($path,$payload)->assertCreated()->assertJsonPath('status','draft');
            $this->postJson($path,$payload)->assertOk();
            $modified=$payload;$modified['name']='Changed';
            $this->postJson($path,$modified)->assertStatus(409);
            $this->getJson($path)->assertOk()->assertJsonPath('total',1);
            $this->become($b,'admin','other-'.$index);
            $this->getJson($path)->assertOk()->assertJsonPath('total',0);
            $this->postJson($path,$payload)->assertCreated();
            $this->getJson($path)->assertOk()->assertJsonPath('total',1);
        }
    }
}
