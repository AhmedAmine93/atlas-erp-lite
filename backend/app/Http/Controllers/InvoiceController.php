<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['status' => 'nullable|in:draft,issued,paid,cancelled']);

        return Invoice::with('order.customer')->when($r->status, fn ($q) => $q->where('status', $r->status))->latest('id')->paginate(20);
    }

    public function show(int $id)
    {
        return Invoice::with('order.customer')->findOrFail($id);
    }

    public function store(Request $r, InvoiceService $s)
    {
        Gate::authorize('sales');
        $d = $r->validate(['order_id' => 'required|integer|exists:orders,id']);

        return response()->json($s->create($d['order_id']), 201);
    }

    public function transition(Request $r, int $id, InvoiceService $s)
    {
        Gate::authorize('sales');
        $d = $r->validate(['status' => 'required|in:issued,paid,cancelled']);

        return $s->transition($id, $d['status']);
    }

    public function pdf(int $id)
    {
        $invoice = Invoice::findOrFail($id);

        return Pdf::loadView('invoice', compact('invoice'))->setPaper('a4')->download(($invoice->number ?? 'BROUILLON-'.$id).'.pdf');
    }
}
