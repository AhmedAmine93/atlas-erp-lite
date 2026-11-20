<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(string $role): void
    {
        $user = User::create([
            'name' => 'Test '.$role,
            'email' => $role.'@organizations.example',
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user->companies()->attach($company->id);
        $this->actingAs($user);
    }

    public function test_admin_manages_companies_and_establishments_with_audit(): void
    {
        $this->asRole('admin');
        $company = $this->postJson('/api/companies', [
            'code' => 'atlas', 'name' => 'ATLAS Démo', 'email' => 'contact@atlas.example',
        ])->assertCreated()->assertJsonPath('code', 'ATLAS')->json('id');

        $this->getJson('/api/companies')->assertOk()->assertJsonCount(2);

        $establishment = $this->postJson('/api/establishments', [
            'company_id' => $company, 'code' => 'paris', 'name' => 'Agence Paris',
            'city' => 'Paris', 'active' => true,
        ])->assertCreated()->assertJsonPath('company.code', 'ATLAS')->json('id');

        $this->putJson('/api/companies/'.$company, [
            'code' => 'ATLAS', 'name' => 'ATLAS Démonstration',
        ])->assertOk()->assertJsonPath('name', 'ATLAS Démonstration');

        $this->putJson('/api/establishments/'.$establishment, [
            'company_id' => $company, 'code' => 'PARIS', 'name' => 'Agence Paris Centre',
            'city' => 'Paris', 'active' => false,
        ])->assertOk()->assertJsonPath('active', false);

        $this->assertDatabaseHas('companies', ['id' => $company, 'code' => 'ATLAS']);
        $this->assertDatabaseHas('establishments', [
            'id' => $establishment, 'company_id' => $company, 'active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.created', 'entity_id' => $company]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'establishment.updated', 'entity_id' => $establishment]);
        $this->assertDatabaseCount('audit_logs', 4);
        $this->getJson('/api/establishments?company_id='.$company)->assertOk()->assertJsonCount(1);
    }

    public function test_duplicate_codes_and_invalid_company_references_are_rejected(): void
    {
        $this->asRole('admin');
        $first = Company::create(['code' => 'A', 'name' => 'Entreprise A']);
        $second = Company::create(['code' => 'B', 'name' => 'Entreprise B']);

        $this->postJson('/api/companies', ['code' => 'a', 'name' => 'Doublon'])->assertUnprocessable();
        $this->postJson('/api/companies', ['code' => 'bad code', 'name' => 'Invalide'])->assertUnprocessable();
        $this->postJson('/api/establishments', [
            'company_id' => 999999, 'code' => 'PARIS', 'name' => 'Site sans entreprise',
        ])->assertUnprocessable();

        Establishment::create(['company_id' => $first->id, 'code' => 'PARIS', 'name' => 'Paris A']);
        $this->postJson('/api/establishments', [
            'company_id' => $first->id, 'code' => 'paris', 'name' => 'Doublon A',
        ])->assertUnprocessable();

        // Le même code est accepté dans une autre société.
        $this->postJson('/api/establishments', [
            'company_id' => $second->id, 'code' => 'PARIS', 'name' => 'Paris B',
        ])->assertCreated();
    }

    public function test_only_admin_can_access_organization_endpoints(): void
    {
        $company = Company::create(['code' => 'TEST', 'name' => 'Entreprise test']);
        $establishment = Establishment::create([
            'company_id' => $company->id, 'code' => 'SITE', 'name' => 'Établissement test',
        ]);

        foreach (['reader', 'commercial', 'stock'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/companies')->assertForbidden();
            $this->getJson('/api/establishments')->assertForbidden();
            $this->postJson('/api/companies', ['code' => 'BAD', 'name' => 'Refus'])->assertForbidden();
            $this->postJson('/api/establishments', [
                'company_id' => $company->id, 'code' => 'BAD', 'name' => 'Refus',
            ])->assertForbidden();
            $this->putJson('/api/companies/'.$company->id, ['code' => 'CHANGED', 'name' => 'Refus'])->assertForbidden();
            $this->putJson('/api/establishments/'.$establishment->id, [
                'company_id' => $company->id, 'code' => 'SITE', 'name' => 'Refus',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'code' => 'TEST']);
        $this->assertDatabaseHas('establishments', ['id' => $establishment->id, 'name' => 'Établissement test']);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/companies')->assertUnauthorized();
        $this->getJson('/api/establishments')->assertUnauthorized();
    }
}
