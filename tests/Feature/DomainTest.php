<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;

class DomainTest extends TestCase
{
    protected array $overrides = ['horizon.domain' => 'horizon.example.com'];

    public function test_the_scrape_route_inherits_the_dashboard_domain()
    {
        // horizon-prometheus.domain is present-but-null once the package
        // config is merged, so the fallback cannot be a config() default.
        $route = $this->app['router']->getRoutes()->getByName('horizon.prometheus');

        $this->assertSame('horizon.example.com', $route->getDomain());
    }

    public function test_the_scrape_route_is_not_reachable_on_another_host()
    {
        $this->get('http://public.example.com/horizon/prometheus')->assertNotFound();
        $this->get('http://horizon.example.com/horizon/prometheus')->assertOk();
    }
}
