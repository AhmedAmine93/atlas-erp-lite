<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(string $role): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user = User::create([
            'name' => 'Form '.$role,
            'email' => 'form-'.$role.'@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_only_tenant_admin_can_validate_a_declarative_form(): void
    {
        $data = [
            'version' => 1,
            'name' => 'Inspection maintenance',
            'fields' => [['key' => 'asset_code', 'label' => 'Code équipement', 'type' => 'text', 'required' => true]],
        ];
        $this->postJson('/api/builders/forms/validate', $data)->assertUnauthorized();
        $this->asRole('reader');
        $this->postJson('/api/builders/forms/validate', $data)->assertForbidden();
        $this->asRole('admin');
        $this->postJson('/api/builders/forms/validate', $data)
            ->assertOk()->assertJsonPath('schema.fields.0.key', 'asset_code');
    }

    public function test_unknown_executable_keys_and_unsafe_shapes_are_rejected(): void
    {
        $this->asRole('admin');
        foreach ([
            [['key' => 'x', 'label' => 'X', 'type' => 'script', 'required' => false]],
            [['key' => 'x', 'label' => 'X', 'type' => 'text', 'required' => false, 'html' => '<script>']],
            [['key' => 'x', 'label' => 'X', 'type' => 'select', 'required' => true]],
            [['key' => 'x', 'label' => 'X', 'type' => 'text', 'required' => false],
             ['key' => 'x', 'label' => 'Duplicate', 'type' => 'text', 'required' => false]],
        ] as $fields) {
            $this->postJson('/api/builders/forms/validate', [
                'version' => 1, 'name' => 'Inspection', 'fields' => $fields,
            ])->assertUnprocessable();
        }
    }
}
