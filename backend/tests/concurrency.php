<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

// Integration test on a disposable PostgreSQL database ending in _test.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    fwrite(STDERR, "Use a disposable PostgreSQL database ending in _test.\n");
    exit(1);
}
if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::where('email', 'concurrency@test.example')->firstOrFail());
    try {
        if ($argv[2] === 'convert-revision') {
            $request=\Illuminate\Http\Request::create('/api/sales-v5/revisions/'.$argv[3].'/convert','POST');
            $request->headers->set('Idempotency-Key',$argv[4]);
            $request->setUserResolver(fn () => Auth::user());
            app(\App\Http\Controllers\SalesCommerceController::class)->convertQuote($request,(int)$argv[3]);
        } elseif ($argv[2] === 'validate') {
            app(SalesService::class)->validateOrder((int) $argv[3]);
        } else {
            app(SalesService::class)->create('orders', ['customer_id' => (int) $argv[3], 'items' => [['product_id' => (int) $argv[4], 'quantity' => 1]]]);
        }
        exit(0);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode()===409) exit(4);
        throw $e;
    } catch (ValidationException $e) {
        exit(3);
    }
}
Artisan::call('migrate:fresh', ['--force' => true]);
$user = User::create(['name' => 'Concurrent test', 'email' => 'concurrency@test.example', 'password' => 'Concurrency2026!', 'role_id' => Role::where('name', 'admin')->value('id')]);
Auth::login($user);
$customer = Customer::create(['name' => 'Concurrent customer', 'email' => 'concurrent@test.example']);
$product = Product::create(['reference' => 'CONCURRENT', 'name' => 'Last unit', 'category' => 'Test', 'price_cents' => 100, 'vat_bps' => 2000, 'stock' => 1]);
$orders = [];
foreach (range(1, 2) as $_) {
    $orders[] = app(SalesService::class)->create('orders', ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->id;
}
function parallel(array $arguments): array
{
    $processes = [];
    foreach ($arguments as $args) {
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start worker');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $codes = [];
    foreach ($processes as [$process,$pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($out || $err) {
            echo $out.$err;
        }$codes[] = $code;
    }sort($codes);

    return $codes;
}
$codes = parallel(array_map(fn ($id) => ['validate', (string) $id], $orders));
if ($codes !== [0, 3] || $product->fresh()->stock !== 0 || StockMovement::count() !== 1 || Order::where('status', 'validated')->count() !== 1) {
    throw new RuntimeException('Stock race assertion failed');
}
echo "PASS: two concurrent orders competing for one unit: exactly one succeeds.\n";
$codes = parallel(array_fill(0, 4, ['create', (string) $customer->id, (string) $product->id]));
if ($codes !== [0, 0, 0, 0] || Order::count() !== 6 || Order::distinct()->count('number') !== 6) {
    throw new RuntimeException('Number race assertion failed');
}
echo "PASS: four concurrent document creations: all numbers unique.\n";

// Distinct processes and PostgreSQL connections compete to convert the same accepted revision.
$quote=app(SalesService::class)->create('quotes',['customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>2]]]);
$commerce=app(\App\Http\Controllers\SalesCommerceController::class);
$requestFor=function (array $data=[]) use ($user) {
    $request=\Illuminate\Http\Request::create('/api/sales-v5','POST',$data);
    $request->setUserResolver(fn () => $user);
    $request->headers->set('Idempotency-Key',bin2hex(random_bytes(12)));
    return $request;
};
$revision=$commerce->reviseQuote($requestFor(['discount_bps'=>0,'valid_until'=>now()->addWeek()->toDateString()]),$quote->id)->getData()->id;
$commerce->quoteTransition($requestFor(),$revision,'send');
$commerce->quoteTransition($requestFor(),$revision,'accept');
$codes=parallel([['convert-revision',(string)$revision,'parallel-m03-key1'],['convert-revision',(string)$revision,'parallel-m03-key2']]);
if ($codes!==[0,4] || Order::where('quote_id',$quote->id)->count()!==1 || $quote->fresh()->status!=='converted') {
    throw new RuntimeException('M03 concurrent conversion did not produce exactly one order.');
}
echo "PASS: concurrent M03 conversion creates one order and one conflict.\n";
