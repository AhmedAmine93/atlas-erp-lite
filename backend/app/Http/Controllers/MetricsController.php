<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = (string) config('atlas.metrics_token');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->bearerToken()), 403);
        // Cross-tenant monitoring needs a fixed aggregate. The restricted
        // runtime role cannot bypass RLS or inspect underlying rows.
        if (DB::getDriverName() === 'pgsql') {
            $totals = DB::selectOne('SELECT * FROM public.atlas_f20_global_metrics()');
            $orders = (int) $totals->orders_validated;
            $outstanding = (int) $totals->invoices_outstanding_cents;
            $lowStock = (int) $totals->products_low_stock;
        } else {
            // SQLite remains a lightweight local/test fallback, not RLS.
            $orders = Order::withoutGlobalScopes()->where('status', 'validated')->count();
            $outstanding = Invoice::withoutGlobalScopes()->where('status', 'issued')->sum('total_cents');
            $lowStock = Product::withoutGlobalScopes()->whereColumn('stock', '<=', 'alert_threshold')->count();
        }
        $metrics = [
            'atlas_orders_validated' => ['Validated orders', $orders],
            'atlas_invoices_outstanding_cents' => ['Outstanding issued invoices in euro cents', $outstanding],
            'atlas_products_low_stock' => ['Products at or below stock threshold', $lowStock],
        ];
        $text = '';
        foreach ($metrics as $name => [$help,$value]) {
            $text .= "# HELP $name $help\n# TYPE $name gauge\n$name $value\n";
        }

        return response($text)->header('Content-Type', 'text/plain; version=0.0.4')->header('Cache-Control', 'no-store');
    }
}
