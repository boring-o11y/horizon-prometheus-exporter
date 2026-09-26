<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;

class CustomPathTest extends TestCase
{
    protected array $overrides = ['horizon.path' => 'internal/ops/queues'];

    public function test_the_scrape_path_follows_the_dashboard_path()
    {
        $this->get('/internal/ops/queues/prometheus')->assertOk();
    }

    public function test_nothing_is_served_under_the_default_horizon_path()
    {
        // An application that moved the dashboard to keep Horizon off a well
        // known URL must not get a metrics endpoint back at /horizon.
        $this->get('/horizon/prometheus')->assertNotFound();
    }
}
