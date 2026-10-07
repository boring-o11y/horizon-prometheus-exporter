<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_the_route_wins_over_the_dashboard_catch_all()
    {
        // Horizon answers every path under its own with the dashboard shell,
        // so a route registered after it would never be reached.
        $response = $this->get('/horizon/prometheus');

        $response->assertOk();
        $this->assertStringContainsString('# TYPE', $response->getContent());
        $this->assertStringNotContainsString('<html', $response->getContent());
    }

    public function test_requests_from_addresses_outside_the_allowlist_are_forbidden()
    {
        $this->scrapeFrom('10.0.0.7')->assertForbidden();
    }

    public function test_loopback_is_allowed_by_default()
    {
        $this->scrapeFrom('127.0.0.1')->assertOk();

        $this->scrapeFrom('::1')->assertOk();
    }

    public function test_cidr_ranges_may_be_allowed()
    {
        config(['horizon-prometheus.allowed_ips' => ['10.0.0.0/24']]);

        $this->scrapeFrom('10.0.0.7')->assertOk();

        $this->scrapeFrom('10.0.1.7')->assertForbidden();
    }

    public function test_a_wildcard_allows_any_address()
    {
        config(['horizon-prometheus.allowed_ips' => ['*']]);

        $this->scrapeFrom('203.0.113.9')->assertOk();
    }

    public function test_an_empty_allowlist_forbids_every_address()
    {
        config(['horizon-prometheus.allowed_ips' => []]);

        $this->scrapeFrom('127.0.0.1')->assertForbidden();
    }

    public function test_the_shipped_configuration_is_closed_by_default()
    {
        $config = require __DIR__.'/../../config/horizon-prometheus.php';

        $this->assertFalse($config['enabled']);
        $this->assertSame(['127.0.0.1', '::1'], $config['allowed_ips']);
    }
}
