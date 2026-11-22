<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferReceipt;
use App\Models\Product;
use App\Services\Audit;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class InventoryTransferController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('movementsRead');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        return InventoryTransfer::query()->latest('id')->paginate(20);
    }

    public function store(Request $r, InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v = $r->validate([
            'reference' => 'required|string|max:80',
            'product_id' => 'required|integer|min:1',
            'lot_id' => 'nullable|integer|min:1',
            'source_location_id' => 'required|integer|min:1|different:target_location_id',
            'target_location_id' => 'required|integer|min:1',
            'quantity_requested' => 'required|integer|min:1|max:1000000',
            'company_id' => 'prohibited', 'status' => 'prohibited',
        ]);
        return DB::transaction(function () use ($v, $stock) {
            $p = Product::query()->findOrFail($v['product_id']);
            $stock->location($v['source_location_id'], true);
            $stock->location($v['target_location_id'], true);
            $lot = $stock->lot($p, $v['lot_id'] ?? null);
            abort_if($lot && in_array($lot->status, ['blocked', 'recalled'], true),
                409, 'Le lot est bloqué ou rappelé.');
            $transfer = InventoryTransfer::create($v + [
                'requested_by' => auth()->id(), 'status' => 'requested',
            ]);
            Audit::record('inventory.transfer.requested', $transfer);
            return response()->json($transfer, 201);
        }, 3);
    }

    public function ship(Request $r, int $id, InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v = $r->validate(['operation_key' => 'required|uuid', 'company_id' => 'prohibited']);
        $hash = hash('sha256', json_encode([$id, $v['operation_key']], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($id, $stock, $v, $hash) {
            $transferForProduct = InventoryTransfer::query()->findOrFail($id);
            $p = Product::query()->lockForUpdate()->findOrFail($transferForProduct->product_id);
            $t = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($t->shipment_key === $v['operation_key']) {
                abort_unless(hash_equals((string) $t->shipment_hash, $hash), 409,
                    'Rejeu contradictoire du transfert.');
                return response()->json($t);
            }
            abort_unless($t->status === 'requested', 409, 'Transfert déjà expédié ou annulé.');
            $source = $stock->location($t->source_location_id, true);
            $stock->location($t->target_location_id, true);
            $lot = $stock->lot($p, $t->lot_id);
            abort_if($lot && in_array($lot->status, ['blocked', 'recalled'], true),
                409, 'Lot bloqué.');
            $balance = $stock->balance($p, $source, $lot);
            abort_unless((int) $balance->quantity >= (int) $t->quantity_requested, 409,
                'Quantité insuffisante à cet emplacement.');
            $stock->change($balance, -(int) $t->quantity_requested, 'transfer_ship',
                $t->reference, 'Départ vers emplacement '.$t->target_location_id);
            $t->update([
                'quantity_shipped' => $t->quantity_requested, 'status' => 'in_transit',
                'shipment_key' => $v['operation_key'], 'shipment_hash' => $hash,
                'shipped_by' => auth()->id(),
            ]);
            Audit::record('inventory.transfer.shipped', $t);
            return response()->json($t);
        }, 3);
    }

    public function receive(Request $r, int $id, InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v = $r->validate([
            'reference' => 'required|string|max:80',
            'quantity' => 'required|integer|min:1|max:1000000',
            'operation_key' => 'required|uuid',
            'company_id' => 'prohibited',
        ]);
        $hash = hash('sha256', json_encode([$id, $v], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($id, $stock, $v, $hash) {
            $transferForProduct = InventoryTransfer::query()->findOrFail($id);
            $p = Product::query()->lockForUpdate()->findOrFail($transferForProduct->product_id);
            $t = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
            $previous = InventoryTransferReceipt::query()
                ->where('operation_key', $v['operation_key'])->first();
            if ($previous) {
                abort_unless(hash_equals((string) $previous->operation_hash, $hash), 409,
                    'Clé de réception utilisée pour une opération différente.');
                return response()->json($t);
            }
            abort_unless($t->status === 'in_transit', 409, 'Le transfert n’est pas en transit.');
            $pending = (int) $t->quantity_shipped - (int) $t->quantity_received - (int) $t->quantity_lost;
            abort_unless($v['quantity'] <= $pending, 409, 'Réception supérieure au transit restant.');
            $target = $stock->location($t->target_location_id, true);
            $lot = $stock->lot($p, $t->lot_id);
            $stock->change($stock->balance($p, $target, $lot), $v['quantity'],
                'transfer_receive', $v['reference'], 'Réception transfert '.$t->reference);
            InventoryTransferReceipt::create([
                'transfer_id' => $t->id, 'reference' => $v['reference'],
                'quantity' => $v['quantity'], 'operation_key' => $v['operation_key'],
                'operation_hash' => $hash, 'received_by' => auth()->id(),
            ]);
            $t->quantity_received += $v['quantity'];
            $t->status = $t->quantity_received + $t->quantity_lost === $t->quantity_shipped
                ? 'received' : 'in_transit';
            $t->save();
            Audit::record('inventory.transfer.received', $t, ['quantity' => $v['quantity']]);
            return response()->json($t);
        }, 3);
    }

    public function declareLoss(Request $r, int $id, InventoryStockService $stock)
    {
        Gate::authorize('admin');
        $v = $r->validate([
            'quantity' => 'required|integer|min:1|max:1000000',
            'reason' => 'required|string|min:5|max:500',
            'operation_key' => 'required|uuid',
            'company_id' => 'prohibited',
        ]);
        $hash = hash('sha256', json_encode([$id, $v], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($id, $stock, $v, $hash) {
            $preflight = InventoryTransfer::query()->findOrFail($id);
            $product = Product::query()->lockForUpdate()->findOrFail($preflight->product_id);
            $t = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($t->loss_key === $v['operation_key']) {
                abort_unless(hash_equals((string) $t->loss_hash, $hash), 409, 'Déclaration contradictoire.');
                return response()->json($t);
            }
            abort_unless($t->status === 'in_transit' && $t->loss_key === null, 409,
                'La perte doit être déclarée une seule fois pendant le transit.');
            $pending = (int) $t->quantity_shipped - (int) $t->quantity_received - (int) $t->quantity_lost;
            abort_unless($v['quantity'] <= $pending, 409, 'Perte supérieure au transit restant.');
            // Stock global includes in-transit items; this *only* debits global
            // stock and the legacy ledger. Source local stock was debited on ship.
            app(\App\Services\StockService::class)->move($product, -$v['quantity'],
                'Perte approuvée transfert '.$t->reference, null, false);
            $t->quantity_lost += $v['quantity'];
            $t->loss_key = $v['operation_key'];
            $t->loss_hash = $hash;
            $t->loss_reason = $v['reason'];
            $t->lost_by = auth()->id();
            $t->status = $t->quantity_received + $t->quantity_lost === $t->quantity_shipped
                ? 'received' : 'in_transit';
            $t->save();
            Audit::record('inventory.transfer.loss_approved', $t, [
                'quantity' => $v['quantity'], 'reason' => $v['reason'],
            ]);
            return response()->json($t);
        }, 3);
    }

    public function cancel(int $id)
    {
        Gate::authorize('stock');
        return DB::transaction(function () use ($id) {
            $t = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
            abort_unless($t->status === 'requested', 409, 'Le transfert ne peut plus être annulé.');
            $t->update(['status' => 'cancelled']);
            Audit::record('inventory.transfer.cancelled', $t);
            return response()->json($t);
        }, 3);
    }
}
