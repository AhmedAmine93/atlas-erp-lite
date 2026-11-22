<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesAccess
{
    public static function membership(): object
    {
        $company = app(TenantContext::class)->requireId();
        $key = 'm03_membership_'.$company.'_'.auth()->id();
        $attributes = request()->attributes;
        if (!$attributes->has($key)) {
            $member = DB::table('company_user')->where('company_id',$company)->where('user_id',auth()->id())->first();
            abort_unless($member, 403);
            $attributes->set($key,$member);
        }
        return $attributes->get($key);
    }

    public static function site(): ?int
    {
        return self::membership()->sales_establishment_id ?? null;
    }

    public static function resolveSite(?int $requested): ?int
    {
        $restricted = self::site();
        abort_if($restricted && $requested && $restricted !== $requested,403);
        $site = $restricted ?? $requested;
        if ($site) {
            abort_unless(DB::table('establishments')->where('id',$site)
                ->where('company_id',app(TenantContext::class)->requireId())->where('active',true)->exists(),404);
        }
        return $site;
    }

    public static function visible(string $table, Builder $query): Builder
    {
        $site = self::site();
        if (!$site) return $query;
        $company = app(TenantContext::class)->requireId();
        $in = fn (string $name) => DB::table($name)->where('company_id',$company)->select('id');
        $quotes = $in('quotes')->where('sales_establishment_id',$site);
        $orders = $in('orders')->where('sales_establishment_id',$site);
        $deliveries = $in('sales_deliveries')->whereIn('order_id',$orders);
        return match ($table) {
            'sales_contracts', 'sales_price_rules' => $query->where(fn ($q) => $q->whereNull('establishment_id')->orWhere('establishment_id',$site)),
            'sales_quote_revisions' => $query->whereIn('quote_id',$quotes),
            'sales_discount_approvals' => $query->whereIn('revision_id',$in('sales_quote_revisions')->whereIn('quote_id',$quotes)),
            'sales_deliveries','sales_reservations' => $query->whereIn('order_id',$orders),
            'sales_returns' => $query->whereIn('delivery_id',$deliveries),
            'sales_credit_notes' => $query->whereIn('return_id',$in('sales_returns')->whereIn('delivery_id',$deliveries)),
            'orders','quotes' => $query->where('sales_establishment_id',$site),
            default => throw new \LogicException('Unknown sales resource: '.$table),
        };
    }
}
