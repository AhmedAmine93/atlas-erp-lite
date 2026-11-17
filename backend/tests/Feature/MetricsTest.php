<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_require_a_dedicated_token_and_expose_gauges(): void
    {
        config(['atlas.metrics_token' => 'test-metrics-token']);
        $this->get('/metrics')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer wrong')->get('/metrics')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer test-metrics-token')->get('/metrics')->assertOk()
            ->assertSee('# TYPE atlas_products_low_stock gauge', false)->assertSee('atlas_products_low_stock 0', false);
    }

    public function test_metrics_are_disabled_without_token(): void
    {
        config(['atlas.metrics_token' => '']);
        $this->get('/metrics')->assertForbidden();
    }
}
