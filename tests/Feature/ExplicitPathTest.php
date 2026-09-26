<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;

class ExplicitPathTest extends TestCase
{
    protected array $overrides = ['horizon-prometheus.path' => 'metrics/horizon'];

    public function test_the_scrape_path_can_be_set_on_its_own()
    {
        $this->get('/metrics/horizon')->assertOk();
        $this->assertStringNotContainsString('# TYPE', $this->get('/horizon/prometheus')->getContent());
    }
}
