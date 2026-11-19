<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SupportIncidentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('metrics', MetricsController::class)->middleware('throttle:60,1');
Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => response()->json(['token' => csrf_token()]));
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', fn (Request $r) => $r->user()->load('role'));
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('dashboard', DashboardController::class);
        Route::get('stock-movements', [ProductController::class, 'movements']);
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'save']);
        Route::put('products/{id}', [ProductController::class, 'save'])->whereNumber('id');
        Route::delete('products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
        Route::post('products/{id}/stock', [ProductController::class, 'adjust'])->whereNumber('id');
        Route::get('{kind}', [ContactController::class, 'index'])->whereIn('kind', ['customers', 'suppliers']);
        Route::get('{kind}/{id}', [ContactController::class, 'show'])->whereIn('kind', ['customers', 'suppliers'])->whereNumber('id');
        Route::post('{kind}', [ContactController::class, 'save'])->whereIn('kind', ['customers', 'suppliers']);
        Route::put('{kind}/{id}', [ContactController::class, 'save'])->whereIn('kind', ['customers', 'suppliers'])->whereNumber('id');
        Route::delete('{kind}/{id}', [ContactController::class, 'destroy'])->whereIn('kind', ['customers', 'suppliers'])->whereNumber('id');
        Route::get('{salesKind}', [SalesController::class, 'index'])->whereIn('salesKind', ['quotes', 'orders']);
        Route::post('{salesKind}', [SalesController::class, 'store'])->whereIn('salesKind', ['quotes', 'orders']);
        Route::get('{salesKind}/{id}', [SalesController::class, 'show'])->whereIn('salesKind', ['quotes', 'orders'])->whereNumber('id');
        Route::post('quotes/{id}/convert', [SalesController::class, 'convert'])->whereNumber('id');
        Route::post('orders/{id}/validate', [SalesController::class, 'validateOrder'])->whereNumber('id');
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        Route::patch('invoices/{id}', [InvoiceController::class, 'transition'])->whereNumber('id');
        Route::get('invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id');
        Route::get('users', [AdminController::class, 'users']);
        Route::get('roles', [AdminController::class, 'roles']);
        Route::post('users', [AdminController::class, 'save']);
        Route::put('users/{id}', [AdminController::class, 'save'])->whereNumber('id');
        Route::delete('users/{id}', [AdminController::class, 'destroy'])->whereNumber('id');
        Route::get('audit-logs',[AdminController::class, 'audit']);
        Route::get('support/incidents', [SupportIncidentController::class, 'index']);
        Route::post('support/incidents', [SupportIncidentController::class, 'store']);
        Route::get('support/incidents/{id}', [SupportIncidentController::class, 'show'])->whereNumber('id');
        Route::patch('support/incidents/{id}', [SupportIncidentController::class, 'transition'])->whereNumber('id');
    });
});
