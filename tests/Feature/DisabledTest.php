<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Counters;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\BasicJob;
use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class DisabledTest extends TestCase
{
    protected array $overrides = ['horizon-prometheus.enabled' => false];

    public function test_no_endpoint_is_registered_until_the_exporter_is_enabled()
    {
        // The dashboard's catch-all answers instead of the exporter.
        $response = $this->get('/horizon/prometheus');

        $this->assertStringNotContainsString('# TYPE', $response->getContent());
        $this->assertNull($this->app['router']->getRoutes()->getByName('horizon.prometheus'));
    }

    public function test_workers_record_nothing_until_the_exporter_is_enabled()
    {
        Queue::push(new BasicJob);

        $this->work();

        $this->assertSame(['job' => [], 'queue' => []], app(Counters::class)->all());
    }
}
