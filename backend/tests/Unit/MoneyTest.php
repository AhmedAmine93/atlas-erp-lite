<?php

namespace Tests\Unit;

use App\Services\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_half_cent_rounds_up_and_totals_remain_exact(): void
    {
        $this->assertSame(['subtotal_cents' => 5, 'tax_cents' => 1, 'total_cents' => 6], Money::line(5, 1, 1000));
        $this->assertSame(['subtotal_cents' => 2997, 'tax_cents' => 165, 'total_cents' => 3162], Money::line(999, 3, 550));
    }

    public function test_zero_vat_and_large_values(): void
    {
        $this->assertSame(0, Money::line(100, 3, 0)['tax_cents']);
        $this->assertSame(1200000000000, Money::line(100000000, 10000, 2000)['total_cents']);
    }
}
