<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        Gate::authorize('dashboardRead');
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = $d['from'] ?? now()->startOfMonth()->toDateString();
        $to = $d['to'] ?? now()->toDateString();
        $invoices = Invoice::whereIn('status', ['issued', 'paid'])->whereDate('issued_at', '>=', $from)->whereDate('issued_at', '<=', $to);
        $orders = Order::where('status', 'validated')->whereDate('validated_at', '>=', $from)->whereDate('validated_at', '<=', $to);
        $daily = (clone $invoices)->selectRaw('CAST(issued_at AS DATE) as day, SUM(subtotal_cents) as revenue_cents')->groupByRaw('CAST(issued_at AS DATE)')->orderBy('day')->get();
        $tenant = app(TenantContext::class)->requireId();
        $top = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.company_id', $tenant)->where('orders.status', 'validated')
            ->whereDate('orders.validated_at', '>=', $from)->whereDate('orders.validated_at', '<=', $to)
            ->selectRaw('order_items.product_id, order_items.name, SUM(order_items.quantity) as quantity, SUM(order_items.subtotal_cents) as revenue_cents')
            ->groupBy('order_items.product_id', 'order_items.name')->orderByDesc('quantity')->limit(5)->get();

        return ['from' => $from, 'to' => $to, 'revenue_cents' => (int) (clone $invoices)->sum('subtotal_cents'),
            'outstanding_cents' => (int) (clone $invoices)->where('status', 'issued')->sum('total_cents'),
            'orders_count' => $orders->count(), 'low_stock_count' => Product::whereColumn('stock', '<=', 'alert_threshold')->count(),
            'low_stock' => Product::whereColumn('stock', '<=', 'alert_threshold')->orderBy('stock')->limit(10)->get(),
            'daily_revenue' => $daily, 'top_products' => $top, 'recent_orders' => Order::with('customer')->latest('id')->limit(5)->get()];
    }
}
