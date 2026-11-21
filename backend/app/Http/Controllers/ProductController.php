<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\TenantContext;
use App\Services\Audit;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('productsRead');
        $r->validate(['search' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'low_stock' => 'nullable|boolean']);

        return Product::query()->when($r->search, fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($r->search).'%'])->orWhereRaw('LOWER(reference) LIKE ?', ['%'.mb_strtolower($r->search).'%'])))
            ->when($r->category, fn ($q) => $q->where('category', $r->category))->when($r->boolean('low_stock'), fn ($q) => $q->whereColumn('stock', '<=', 'alert_threshold'))->orderBy('name')->paginate(20);
    }

    public function save(Request $r, ?int $id = null)
    {
        Gate::authorize('stock');
        $p = $id ? Product::findOrFail($id) : new Product;
        $data = $r->validate(['reference' => ['required', 'string', 'max:50', Rule::unique('products')->where('company_id', app(TenantContext::class)->requireId())->ignore($id)], 'name' => 'required|string|max:255', 'category' => 'required|string|max:100',
            'price_cents' => 'required|integer|min:0|max:100000000', 'vat_bps' => 'required|integer|min:0|max:10000', 'alert_threshold' => 'required|integer|min:0|max:1000000']);

        return DB::transaction(function () use ($p, $data, $id) {
            $p->fill($data)->save();
            Audit::record($id ? 'product.updated' : 'product.created', $p);

            return response()->json($p->refresh(), $id ? 200 : 201);
        });
    }

    public function destroy(int $id)
    {
        Gate::authorize('stock');

        return DB::transaction(function () use ($id) {
            $p = Product::findOrFail($id);
            $p->delete();
            Audit::record('product.deleted', $p);

            return response()->noContent();
        });
    }

    public function adjust(Request $r, int $id, StockService $service)
    {
        Gate::authorize('stock');
        $d = $r->validate(['quantity' => 'required|integer|not_in:0|min:-1000000|max:1000000', 'reason' => 'required|string|min:3|max:255']);

        return $service->adjust($id, $d['quantity'], $d['reason']);
    }

    public function movements(Request $r)
    {
        Gate::authorize('movementsRead');
        $r->validate(['product_id' => 'nullable|integer']);

        if ($r->product_id) {
            Product::findOrFail((int) $r->product_id);
        }

        return StockMovement::with('product', 'user')->when($r->product_id, fn ($q) => $q->where('product_id', $r->product_id))->latest('id')->paginate(20);
    }
}
