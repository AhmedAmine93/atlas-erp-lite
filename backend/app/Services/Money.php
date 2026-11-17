<?php

namespace App\Services;

class Money
{
    // Integer half-up rounding per line. No floating-point amounts in persistence.
    public static function line(int $price, int $quantity, int $vat): array
    {
        $net = $price * $quantity;
        $tax = intdiv($net * $vat + 5000, 10000);

        return ['subtotal_cents' => $net, 'tax_cents' => $tax, 'total_cents' => $net + $tax];
    }
}
