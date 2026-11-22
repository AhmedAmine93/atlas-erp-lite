<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HumanResourcesMenuTest extends TestCase
{
    use RefreshDatabase;

    private int $serial = 0;
    private function key(): string { return 'HR-TEST-'.(++$this->serial); }

    private function login(Company $company, string $role, string $suffix): User
    {
        $legacy = Role::where('name','admin')->value('id');
        $user = User::create([
            'name'=>'Fictif '.$suffix,'email'=>'rh-'.$suffix.'@example.test',
            'password'=>'NotRealPassword2026!', 'role_id'=>$legacy,
        ]);
        $user->companies()->attach($company->id,[
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
        return $user;
    }
    private function site(Company $company,string $code): int
    {
        return DB::table('establishments')->insertGetId([
            'company_id'=>$company->id,'code'=>$code,'name'=>'Site fictif '.$code,
            'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    private function create(string $resource,array $data): int
    {
        return $this->postJson('/api/hr/'.$resource, $data+['request_key'=>$this->key()])
            ->assertCreated()->json('id');
    }
    private function action(string $resource,int $id,string $to,int $version=1,?string $reason=null)
    {
        return $this->postJson("/api/hr/$resource/$id/transition",[
            'request_key'=>$this->key(),'status'=>$to,'version'=>$version,
        ]+($reason?['reason'=>$reason]:[]));
    }

    public function test_six_hr_sections_and_replay_and_site_isolation(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'HR_B','name'=>'Fictive B']);
        $site=$this->site($a,'HR01');
        $other=$this->site($b,'HR99');
        $this->getJson('/api/hr/employees')->assertUnauthorized();
        $this->login($a,'reader','reader');
        $this->getJson('/api/hr/employees')->assertForbidden();
        $this->login($a,'admin','admin');

        $base=['code'=>'HR-001','given_name'=>'Lina','family_name'=>'Fictive',
            'establishment_id'=>$site,'hired_on'=>'2026-09-01'];
        $this->postJson('/api/hr/employees',$base+[
            'company_id'=>$b->id,'request_key'=>$this->key(),
        ])->assertUnprocessable();
        $this->postJson('/api/hr/employees',array_merge($base,[
            'establishment_id'=>$other,'request_key'=>$this->key(),
        ]))->assertNotFound();
        $id=$this->create('employees',$base);
        $key='REPLAY-SAME-EMPLOYEE';
        $repeat=$base+['request_key'=>$key];
        $this->postJson('/api/hr/employees',$repeat)->assertCreated();
        $this->postJson('/api/hr/employees',$repeat)->assertOk();
        $this->postJson('/api/hr/employees',array_merge($repeat,['code'=>'ANOTHER']))
            ->assertStatus(409);
        $team=$this->create('teams',['code'=>'RH-01','name'=>'Équipe fictive',
            'manager_employee_id'=>$id,'effective_from'=>'2026-09-01']);
        $this->create('assignments',['employee_id'=>$id,'establishment_id'=>$site,
            'team_id'=>$team,'starts_on'=>'2026-09-01','allocation_percent'=>100]);
        $planning=$this->create('schedules',['employee_id'=>$id,
            'establishment_id'=>$site,'starts_at'=>'2027-01-10T09:00:00+01:00',
            'ends_at'=>'2027-01-10T17:00:00+01:00','timezone'=>'Europe/Paris']);
        $skill=$this->create('skills',['code'=>'ELEC-01','name'=>'Électricité',
            'safety_critical'=>true]);
        $cert=$this->create('certifications',['employee_id'=>$id,'skill_id'=>$skill,
            'obtained_on'=>'2026-01-01','expires_on'=>'2028-01-01',
            'issuer'=>'Organisme fictif']);
        $this->action('certifications',$cert,'approved')->assertCreated();
        $this->action('schedules',$planning,'published')->assertCreated();

        $this->getJson('/api/hr/summary')->assertOk()
            ->assertJsonPath('employees',2)->assertJsonPath('teams',1);
        $this->getJson('/api/hr/events')->assertOk()->assertJsonStructure(['data']);

        $this->login($b,'hr','hrb');
        $this->getJson('/api/hr/employees')->assertOk()->assertJsonPath('total',0);
        $this->getJson("/api/hr/employees/$id")->assertNotFound();
        $this->postJson('/api/hr/assignments',[
            'employee_id'=>$id,'establishment_id'=>$other,'starts_on'=>'2026-09-01',
            'allocation_percent'=>50,'request_key'=>$this->key(),
        ])->assertNotFound();
    }

    public function test_leave_balance_is_atomic_conflicts_with_published_schedule_and_time_not_double_counted(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $site=$this->site($a,'HR02');
        $admin=$this->login($a,'admin','requester');
        $employee=$this->create('employees',[
            'code'=>'HR-002','given_name'=>'Noé','family_name'=>'Exemple',
            'establishment_id'=>$site,'hired_on'=>'2026-01-01']);
        $this->create('assignments',['employee_id'=>$employee,'establishment_id'=>$site,
            'starts_on'=>'2026-01-01','allocation_percent'=>100]);
        $this->create('balances',['employee_id'=>$employee,'year'=>2027,'allocated_days'=>12]);
        $leave=$this->create('leaves',['employee_id'=>$employee,'starts_on'=>'2027-02-01',
            'ends_on'=>'2027-02-05','days'=>5]);
        $this->action('leaves',$leave,'submitted')->assertCreated();
        $this->action('leaves',$leave,'approved',2)->assertForbidden();

        $this->login($a,'hr','approver');
        $this->action('leaves',$leave,'approved',2)->assertCreated();
        $this->getJson("/api/hr/leaves/$leave")->assertJsonPath('status','approved');
        $this->assertDatabaseHas('hr_leave_balances',[
            'company_id'=>$a->id,'employee_id'=>$employee,'used_days'=>'5.00',
        ]);
        $this->action('leaves',$leave,'approved',2)->assertStatus(409);

        $scheduled=$this->create('schedules',[
            'employee_id'=>$employee,'establishment_id'=>$site,
            'starts_at'=>'2027-02-02T08:00:00+01:00',
            'ends_at'=>'2027-02-02T16:00:00+01:00',
            'timezone'=>'Europe/Paris',
        ]);
        $this->action('schedules',$scheduled,'published')->assertStatus(409);

        $entry=$this->create('timesheets',[
            'employee_id'=>$employee,'work_on'=>'2027-02-08',
            'duration_minutes'=>300,'project_ref'=>'PRJ-1',
        ]);
        $this->create('timesheets',[
            'employee_id'=>$employee,'work_on'=>'2027-02-08',
            'duration_minutes'=>120,'project_ref'=>'PRJ-2',
        ]);
        $this->postJson('/api/hr/timesheets',[
            'employee_id'=>$employee,'work_on'=>'2027-02-08',
            'duration_minutes'=>1200,'project_ref'=>'PRJ-3',
            'request_key'=>$this->key(),
        ])->assertStatus(409);
        $this->action('timesheets',$entry,'submitted')->assertCreated();
        $this->action('timesheets',$entry,'approved',2)->assertCreated();
        $this->action('timesheets',$entry,'transferred',3)->assertCreated();
        $this->action('timesheets',$entry,'submitted',4)->assertStatus(409);
        $this->action('leaves',$leave,'cancelled',3,'Annulation acceptée')
            ->assertCreated();
        $this->assertDatabaseHas('hr_leave_balances',[
            'company_id'=>$a->id,'employee_id'=>$employee,'used_days'=>'0.00',
        ]);
    }
}
