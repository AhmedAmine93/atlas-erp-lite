<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = (string) config('atlas.metrics_token');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->bearerToken()), 403);
        $metrics = [
            'atlas_orders_validated' => ['Validated orders', Order::withoutGlobalScopes()->where('status', 'validated')->count()],
            'atlas_invoices_outstanding_cents' => ['Outstanding issued invoices in euro cents', Invoice::withoutGlobalScopes()->where('status', 'issued')->sum('total_cents')],
            'atlas_products_low_stock' => ['Products at or below stock threshold', Product::withoutGlobalScopes()->whereColumn('stock', '<=', 'alert_threshold')->count()],
        ];
        $text = '';
        foreach ($metrics as $name => [$help,$value]) {
            $text .= "# HELP $name $help\n# TYPE $name gauge\n$name $value\n";
        }

        return response($text)->header('Content-Type', 'text/plain; version=0.0.4')->header('Cache-Control', 'no-store');
    }
}
