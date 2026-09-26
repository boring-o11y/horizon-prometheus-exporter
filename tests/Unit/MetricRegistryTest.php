<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Unit;

use BoringO11y\HorizonPrometheusExporter\MetricRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MetricRegistryTest extends TestCase
{
    public function test_samples_of_the_same_metric_share_one_help_and_type_header()
    {
        $registry = new MetricRegistry('horizon');

        $registry->gauge('queue_length', 'Jobs waiting.', 3, ['queue' => 'default']);
        $registry->gauge('queue_length', 'Jobs waiting.', 7, ['queue' => 'emails']);

        $this->assertSame(implode("\n", [
            '# HELP horizon_queue_length Jobs waiting.',
            '# TYPE horizon_queue_length gauge',
            'horizon_queue_length{queue="default"} 3',
            'horizon_queue_length{queue="emails"} 7',
        ])."\n", $registry->render());
    }

    public function test_label_values_are_escaped()
    {
        $registry = new MetricRegistry('horizon');

        $registry->counter('job_processed_total', 'Processed.', 1, ['job' => 'App\\Jobs\\Send"Mail'."\n"]);

        $this->assertStringContainsString(
            'horizon_job_processed_total{job="App\\\\Jobs\\\\Send\\"Mail\\n"} 1',
            $registry->render()
        );
    }

    public function test_null_valued_samples_are_omitted()
    {
        $registry = new MetricRegistry('horizon');

        $registry->gauge('queue_time_to_clear_seconds', 'ETA.', null, ['queue' => 'default']);

        $this->assertTrue($registry->isEmpty());
        $this->assertSame('', $registry->render());
    }

    public function test_empty_labels_are_dropped_but_zero_is_kept()
    {
        $registry = new MetricRegistry('horizon');

        $registry->gauge('queue_length', 'Jobs waiting.', 1, ['queue' => '0', 'connection' => null, 'pool' => '']);

        $this->assertStringContainsString('horizon_queue_length{queue="0"} 1', $registry->render());
    }

    public function test_values_are_rendered_in_a_format_prometheus_accepts()
    {
        $registry = new MetricRegistry('horizon');

        $registry->gauge('a', 'Help.', 1.5);
        $registry->gauge('b', 'Help.', 0.0005);
        $registry->gauge('c', 'Help.', 2.0);
        $registry->gauge('d', 'Help.', true);
        $registry->gauge('e', 'Help.', INF);
        $registry->gauge('f', 'Help.', NAN);

        $rendered = $registry->render();

        $this->assertStringContainsString("horizon_a 1.5\n", $rendered);
        $this->assertStringContainsString("horizon_b 0.0005\n", $rendered);
        $this->assertStringContainsString("horizon_c 2\n", $rendered);
        $this->assertStringContainsString("horizon_d 1\n", $rendered);
        $this->assertStringContainsString("horizon_e +Inf\n", $rendered);
        $this->assertStringContainsString("horizon_f NaN\n", $rendered);
    }

    public function test_the_prefix_may_be_omitted()
    {
        $registry = new MetricRegistry('');

        $registry->gauge('queue_length', 'Jobs waiting.', 1);

        $this->assertStringContainsString("\nqueue_length 1\n", $registry->render());
    }

    public function test_invalid_metric_names_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        (new MetricRegistry('horizon'))->gauge('queue-length', 'Jobs waiting.', 1);
    }

    public function test_a_summary_renders_its_sum_and_count_under_one_header()
    {
        $registry = new MetricRegistry('horizon');

        $registry->summary('queue_wait_seconds', 'Wait.', 1.5, 3, ['queue' => 'default']);
        $registry->summary('queue_wait_seconds', 'Wait.', 0, 0, ['queue' => 'emails']);

        $this->assertSame(implode("\n", [
            '# HELP horizon_queue_wait_seconds Wait.',
            '# TYPE horizon_queue_wait_seconds summary',
            'horizon_queue_wait_seconds_sum{queue="default"} 1.5',
            'horizon_queue_wait_seconds_count{queue="default"} 3',
            'horizon_queue_wait_seconds_sum{queue="emails"} 0',
            'horizon_queue_wait_seconds_count{queue="emails"} 0',
        ])."\n", $registry->render());
    }
}
