<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class SalesPricing
{
    /** Resolve the last effective amendment, including future-dated amendments. */
    public function contract(int $id, int $customer, ?int $site): array
    {
        $company = app(TenantContext::class)->requireId();
        $contract = DB::table('sales_contracts')->where('company_id',$company)->where('id',$id)->lockForUpdate()->first();
        abort_unless($contract && (int)$contract->customer_id === $customer,404);
        abort_if($contract->establishment_id !== null && (int)$contract->establishment_id !== $site,403);
        abort_unless($contract->status === 'active' && $contract->currency === 'EUR',409,'Le contrat doit être actif et libellé dans la devise du document (EUR).');
        $today = now()->toDateString();
        $version = DB::table('sales_contract_versions')->where('company_id',$company)->where('contract_id',$id)
            ->where('effective_on','<=',$today)->orderByDesc('effective_on')->orderByDesc('version_no')->first();
        abort_unless($version,409,'Le contrat ne prend pas encore effet.');
        $snapshot = json_decode($version->snapshot,true,512,JSON_THROW_ON_ERROR);
        abort_if($snapshot['expires_on'] < $today,409,'Le contrat a expiré.');
        $prices = $snapshot['prices'] ?? [];
        return [
            'id'=>$id, 'version'=>$version->version_no, 'expires_on'=>$snapshot['expires_on'],
            'receipt_required'=>(bool)($snapshot['receipt_required'] ?? false),
            'prices'=>is_string($prices) ? json_decode($prices,true,512,JSON_THROW_ON_ERROR) : $prices,
        ];
    }

    /** EUR ERP documents use integer cents, then line VAT rounded half up. */
    public function line(Product $product, int $quantity, int $discount, ?int $site, ?array $contract): array
    {
        $today = now()->toDateString();
        $rule = DB::table('sales_price_rules')->where('company_id',$product->company_id)->where('product_id',$product->id)
            ->where('currency','EUR')->where('status','published')->where('starts_on','<=',$today)->where('ends_on','>=',$today)
            ->where(fn ($q) => $q->whereNull('establishment_id')->when($site,fn ($s) => $s->orWhere('establishment_id',$site)))
            ->orderBy('priority')->orderByRaw('CASE WHEN establishment_id IS NULL THEN 1 ELSE 0 END')->orderBy('id')->first();
        $contractPrices = array_column($contract['prices'] ?? [],'price_cents','product_id');
        $base = $contractPrices[$product->id] ?? $rule->price_cents ?? $product->price_cents;
        $promotion = $rule->discount_bps ?? 0;
        $unit = intdiv($base * (10000 - $promotion) + 5000,10000);
        $unit = intdiv($unit * (10000 - $discount) + 5000,10000);
        $vat = (int)($rule->vat_bps ?? $product->vat_bps);
        return [
            'item'=>array_merge(Money::line($unit,$quantity,$vat),[
                'product_id'=>$product->id,'reference'=>$product->reference,'name'=>$product->name,
                'quantity'=>$quantity,'price_cents'=>$unit,'vat_bps'=>$vat,
            ]),
            'basis'=>['product_id'=>$product->id,'price_rule_id'=>$rule->id ?? null,
                'base_cents'=>$base,'promotion_bps'=>$promotion,'commercial_bps'=>$discount],
        ];
    }
}
