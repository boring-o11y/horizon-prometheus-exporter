<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Counters;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\BasicJob;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\FailingJob;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\ReleasingJob;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\RetryingJob;
use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MetricsRepository;

class CountersTest extends TestCase
{
    public function test_completed_jobs_are_counted_per_class_and_per_queue()
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $this->work(2);

        $counters = app(Counters::class)->all();

        $this->assertSame(2, $counters['job'][BasicJob::class]['processed']);
        $this->assertSame(2, $counters['queue']['default']['processed']);
        $this->assertArrayNotHasKey('failed', $counters['job'][BasicJob::class]);
    }

    public function test_the_counters_keep_accumulating_across_horizon_snapshots()
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        $this->work(2);

        // The snapshot resets the window Horizon graphs. The exported counter
        // must not go backwards with it...
        app(MetricsRepository::class)->snapshot();

        $this->assertSame(0, app(MetricsRepository::class)->throughputForJob(BasicJob::class));
        $this->assertSame(2, app(Counters::class)->all()['job'][BasicJob::class]['processed']);

        Queue::push(new BasicJob);
        $this->work();

        // ...and what runs after it is added on top.
        $this->assertSame(3, app(Counters::class)->all()['job'][BasicJob::class]['processed']);
    }

    public function test_a_job_that_fails_is_counted_as_a_failure_not_as_throughput()
    {
        Queue::push(new FailingJob);

        $this->work();

        $counters = app(Counters::class)->all();

        $this->assertSame(1, $counters['job'][FailingJob::class]['failed']);
        $this->assertSame(1, $counters['queue']['default']['failed']);
        $this->assertArrayNotHasKey('processed', $counters['job'][FailingJob::class]);
    }

    public function test_a_release_after_an_exception_is_counted_as_a_retry()
    {
        Queue::push(new RetryingJob);

        $this->work();

        $counters = app(Counters::class)->all();

        $this->assertSame(1, $counters['job'][RetryingJob::class]['retried']);
        $this->assertSame(1, $counters['queue']['default']['retried']);
        $this->assertArrayNotHasKey('failed', $counters['job'][RetryingJob::class]);
        $this->assertArrayNotHasKey('processed', $counters['job'][RetryingJob::class]);
    }

    public function test_a_job_releasing_itself_is_not_a_retry()
    {
        // Rate limiting and polling release on purpose; counting those would
        // bury the jobs that are actually throwing.
        Queue::push(new ReleasingJob);

        $this->work();

        $counters = app(Counters::class)->all();

        $this->assertArrayNotHasKey('retried', $counters['job'][ReleasingJob::class]);
        $this->assertArrayNotHasKey('processed', $counters['job'][ReleasingJob::class]);
        $this->assertArrayNotHasKey('retried', $counters['queue']['default']);
    }

    public function test_the_wait_is_measured_from_when_the_job_became_ready()
    {
        $id = Queue::push(new BasicJob);

        // Stands in for five seconds on the queue. If Horizon's own listener
        // ran first, it would have overwritten this with the pickup time and
        // the wait would read as zero.
        app(Counters::class)->connection()->hset($id, 'updated_at', (string) (microtime(true) - 5));

        $this->work();

        $counters = app(Counters::class)->all();

        $this->assertSame(1, $counters['job'][BasicJob::class]['waits']);
        $this->assertEqualsWithDelta(5.0, $counters['job'][BasicJob::class]['wait_seconds'], 1.0);
        $this->assertSame(1, $counters['queue']['default']['waits']);
        $this->assertEqualsWithDelta(5.0, $counters['queue']['default']['wait_seconds'], 1.0);
    }

    public function test_a_job_without_a_hash_is_left_out_of_the_wait()
    {
        $id = Queue::push(new BasicJob);

        app(Counters::class)->connection()->del($id);

        $this->work();

        $counters = app(Counters::class)->all();

        // Counted as throughput, but not averaged in as a zero wait.
        $this->assertSame(1, $counters['job'][BasicJob::class]['processed']);
        $this->assertArrayNotHasKey('waits', $counters['job'][BasicJob::class]);
        $this->assertArrayNotHasKey('wait_seconds', $counters['job'][BasicJob::class]);
    }

    public function test_the_counters_live_under_horizons_prefix()
    {
        Queue::push(new BasicJob);
        $this->work();

        $keys = app(Counters::class)->connection()->client()->keys('*');

        $this->assertContains(config('horizon.prefix').'prometheus:job:processed', $keys);
    }

    public function test_the_clear_command_resets_every_counter()
    {
        Queue::push(new BasicJob);
        Queue::push(new FailingJob);
        $this->work(2);

        $this->artisan('horizon-prometheus:clear')->assertSuccessful();

        $this->assertSame(['job' => [], 'queue' => []], app(Counters::class)->all());
    }
}
