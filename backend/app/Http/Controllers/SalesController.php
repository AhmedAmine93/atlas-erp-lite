<?php

namespace App\Http\Controllers;

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
        $r->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30', 'customer_id' => 'nullable|integer|exists:customers,id']);

        return $this->model($kind)::with('customer')->when($r->search, fn ($q) => $q->where('number', 'like', '%'.$r->search.'%'))
            ->when($r->status, fn ($q) => $q->where('status', $r->status))->when($r->customer_id, fn ($q) => $q->where('customer_id', $r->customer_id))->latest('id')->paginate(20);
    }

    public function show(string $kind, int $id)
    {
        return $this->model($kind)::with('items', 'customer')->findOrFail($id);
    }

    public function store(Request $r, string $kind, SalesService $service)
    {
        Gate::authorize('sales');
        $data = $r->validate(['customer_id' => 'required|integer|exists:customers,id', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.quantity' => 'required|integer|min:1|max:10000']);

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
