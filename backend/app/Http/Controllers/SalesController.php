<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Order;
use App\Models\Quote;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SalesController extends Controller
{
    private function model(string $kind): string
    {
        return $kind === 'quotes' ? Quote::class : Order::class;
    }

    public function index(Request $r, string $kind)
    {
        Gate::authorize('salesRead');
        $r->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30', 'customer_id' => 'nullable|integer']);

        if ($r->customer_id) {
            Customer::findOrFail((int) $r->customer_id);
        }

        return $this->model($kind)::with('customer')->when($r->search, fn ($q) => $q->where('number', 'like', '%'.$r->search.'%'))
            ->when($r->status, fn ($q) => $q->where('status', $r->status))->when($r->customer_id, fn ($q) => $q->where('customer_id', $r->customer_id))->latest('id')->paginate(20);
    }

    public function show(string $kind, int $id)
    {
        Gate::authorize('salesRead');
        $doc=$this->model($kind)::with('items','customer')->findOrFail($id);
        if ($kind==='orders' && $doc->fulfillment_mode==='reservation_v2') {
            $reservations=\Illuminate\Support\Facades\DB::table('sales_reservations')->where('company_id',$doc->company_id)
                ->where('order_id',$doc->id)->get()->keyBy('order_item_id');
            foreach ($doc->items as $item) {
                $res=$reservations->get($item->id);
                $item->shipped_quantity=$res->quantity_shipped ?? 0;
                $item->remaining_quantity=$res ? $res->quantity_reserved-$res->quantity_released-$res->quantity_shipped : 0;
            }
        }
        return $doc;
    }

    public function store(Request $r, string $kind, SalesService $service)
    {
        Gate::authorize('sales');
        $data = $r->validate(['customer_id' => 'required|integer|exists:customers,id', 'establishment_id'=>'nullable|integer|min:1', 'fulfillment_mode'=>'sometimes|in:reservation_v2', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.quantity' => 'required|integer|min:1|max:10000']);

        $customer = Customer::findOrFail((int) $data['customer_id']);
        abort_if($customer->archived_at !== null, 409, 'Impossible de créer une nouvelle vente pour un client archivé.');
        $productCount = Product::whereIn('id', array_column($data['items'], 'product_id'))->count();
        abort_unless($productCount === count($data['items']), 404, 'Produit inaccessible.');

        return response()->json($service->create($kind, $data), 201);
    }

    public function convert(int $id, SalesService $service)
    {
        Gate::authorize('sales');

        return response()->json($service->convert($id), 201);
    }

    public function validateOrder(int $id, SalesService $service)
    {
        Gate::authorize('sales');

        return $service->validateOrder($id);
    }
}
