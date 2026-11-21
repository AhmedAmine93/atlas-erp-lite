<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** P071.02/.04/.05: trusted, versioned, per-user-per-company dashboard UI preferences. */
final class DashboardLayoutController extends Controller
{
    private const WIDGETS = [
        'revenue', 'validated-orders', 'outstanding', 'low-stock',
        'revenue-chart', 'top-products', 'recent-orders', 'stock-alerts',
    ];

    private function role(Request $request): int
    {
        return (int) (app(TenantRoles::class)->role($request->user())?->id
            ?: abort(403, 'Affiliation entreprise non autorisée.'));
    }

    public function show(Request $request)
    {
        $companyId = app(TenantContext::class)->requireId();
        $roleId = $this->role($request);
        $record = DB::table('dashboard_layout_preferences')
            ->where('company_id', $companyId)
            ->where('user_id', $request->user()->id)
            ->where('role_id', $roleId)
            ->first();

        return response()->json([
            'version' => 1,
            'layout' => $record ? json_decode($record->layout, true) : null,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'in:1'],
            'layout' => ['required', 'array', 'min:1', 'max:8'],
            'layout.*' => ['required', 'array:id,enabled'],
            'layout.*.id' => ['required', 'string', Rule::in(self::WIDGETS), 'distinct'],
            'layout.*.enabled' => ['required', 'boolean'],
        ]);
        $companyId = app(TenantContext::class)->requireId();
        $role = app(TenantRoles::class)->role($request->user());
        abort_unless($role, 403);

        // All dashboard preferences are presentation-only. Prevent a support
        // or inventory account from persisting future privileged widgets.
        $allowed = match ($role->name) {
            'admin', 'commercial', 'reader' => self::WIDGETS,
            'stock' => ['low-stock', 'stock-alerts'],
            'support_n2', 'support_n3' => [],
            default => [],
        };
        foreach ($data['layout'] as $item) {
            abort_unless(in_array($item['id'], $allowed, true), 403);
        }

        $saved = array_map(
            fn ($item) => ['id' => $item['id'], 'enabled' => (bool) $item['enabled']],
            $data['layout']
        );
        DB::table('dashboard_layout_preferences')->upsert([
            [
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'role_id' => $role->id,
                'schema_version' => 1,
                'layout' => json_encode($saved, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['company_id', 'user_id', 'role_id'], ['schema_version', 'layout', 'updated_at']);

        return response()->json(['version' => 1, 'layout' => $saved]);
    }
}
