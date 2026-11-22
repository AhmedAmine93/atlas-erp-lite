<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('invoicesRead');
        $r->validate(['status' => 'nullable|in:draft,issued,paid,cancelled']);

        return Invoice::with('order.customer')->when($r->status, fn ($q) => $q->where('status', $r->status))->latest('id')->paginate(20);
    }

    public function show(int $id)
    {
        Gate::authorize('invoicesRead');
        return Invoice::with('order.customer')->findOrFail($id);
    }

    public function store(Request $r, InvoiceService $s)
    {
        Gate::authorize('sales');
        $d = $r->validate(['order_id' => 'required|integer|exists:orders,id']);

        Order::findOrFail((int) $d['order_id']);

        return response()->json($s->create($d['order_id']), 201);
    }

    public function transition(Request $r, int $id, InvoiceService $s)
    {
        Gate::authorize('sales');
        $d = $r->validate(['status' => 'required|in:issued,paid,cancelled', 'due_at' => 'sometimes|date_format:Y-m-d']);

        abort_if(isset($d['due_at']) && $d['status'] !== 'issued', 422, 'Date d’échéance modifiable uniquement à émission.');

        return $s->transition($id, $d['status'], $d['due_at'] ?? null);
    }

    public function pdf(int $id)
    {
        Gate::authorize('invoicesRead');
        $invoice = Invoice::findOrFail($id);

        return Pdf::loadView('invoice', compact('invoice'))->setPaper('a4')->download(($invoice->number ?? 'BROUILLON-'.$id).'.pdf');
    }
}
