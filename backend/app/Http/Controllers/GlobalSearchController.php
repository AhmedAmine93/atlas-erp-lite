<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\SupportIncident;
use App\Services\TenantRoles;
use Illuminate\Http\Request;

/**
 * P066.03 — bounded, tenant-scoped, server-authorized search.
 * No raw SQL from the request; no global-scope bypass; no cross-tenant cache.
 */
final class GlobalSearchController extends Controller
{
    private const CATEGORIES_BY_ROLE = [
        'admin' => ['customers', 'suppliers', 'products', 'quotes', 'orders', 'invoices', 'support-incidents'],
        'commercial' => ['customers', 'suppliers', 'products', 'quotes', 'orders', 'invoices'],
        'stock' => ['products'],
        'reader' => ['customers', 'suppliers', 'products', 'quotes', 'orders', 'invoices'],
        'support_n2' => ['support-incidents'],
        'support_n3' => ['support-incidents'],
    ];

    public function __invoke(Request $request)
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $term = trim($input['q']);
        if (mb_strlen($term) < 2) {
            return response()->json(['message' => 'Deux caractères minimum.'], 422);
        }

        // Derive the role from the verified membership of the active tenant,
        // NEVER from the global users.role_id or an HTTP-supplied company ID.
        $role = app(TenantRoles::class)->role(
            $request->user(),
            (int) $request->attributes->get('atlas_company_id')
        )?->name;
        abort_unless($role && isset(self::CATEGORIES_BY_ROLE[$role]), 403);

        // The ESCAPE operator treats '%' and '_' as literal user input.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));
        $pattern = '%'.$escaped.'%';

        $definitions = [
            'customers' => [Customer::class, ['name', 'email'], 'Clients'],
            'suppliers' => [Supplier::class, ['name', 'email'], 'Fournisseurs'],
            'products' => [Product::class, ['name', 'reference'], 'Produits'],
            'quotes' => [Quote::class, ['number'], 'Devis'],
            'orders' => [Order::class, ['number'], 'Commandes'],
            'invoices' => [Invoice::class, ['number'], 'Factures'],
            'support-incidents' => [SupportIncident::class, ['reference', 'title'], 'Incidents'],
        ];

        $groups = [];
        foreach (self::CATEGORIES_BY_ROLE[$role] as $type) {
            [$model, $fields, $label] = $definitions[$type];
            $query = $model::query()->where(function ($q) use ($fields, $pattern) {
                foreach ($fields as $index => $field) {
                    $clause = "LOWER({$field}) LIKE ? ESCAPE '!'";
                    if ($index === 0) {
                        $q->whereRaw($clause, [$pattern]);
                    } else {
                        $q->orWhereRaw($clause, [$pattern]);
                    }
                }
            });

            // Models use BelongsToTenant global scope. No withTrashed(),
            // withoutGlobalScopes(), owner role or joined foreign-company rows.
            $rows = $query->orderByDesc('id')->limit(5)->get();
            $items = $rows->map(function ($row) use ($type, $label) {
                $title = match ($type) {
                    'customers', 'suppliers', 'products' => $row->name,
                    'support-incidents' => $row->reference.' · '.$row->title,
                    'invoices' => $row->number ?: 'Brouillon #'.$row->id,
                    default => $row->number,
                };
                return [
                    'id' => (int) $row->id,
                    'type' => $type,
                    'title' => $title,
                    'category' => $label,
                ];
            })->all();

            if ($items !== []) {
                $groups[] = [
                    'type' => $type,
                    'label' => $label,
                    'items' => $items,
                ];
            }
        }

        return response()->json([
            'query' => $term,
            'groups' => $groups,
        ]);
    }
}
