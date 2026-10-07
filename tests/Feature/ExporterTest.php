<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Feature;

use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\BasicJob;
use BoringO11y\HorizonPrometheusExporter\Tests\Fixtures\FailingJob;
use BoringO11y\HorizonPrometheusExporter\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Horizon;
use Mockery;

class ExporterTest extends TestCase
{
    public function test_job_and_queue_counters_are_exposed_in_the_text_exposition_format()
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        Queue::push(new FailingJob);

        $this->work(3);

        $response = $this->get('/horizon/prometheus');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain; version=0.0.4', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $job = $this->label(BasicJob::class);

        $this->assertStringContainsString('# TYPE horizon_job_processed_total counter', $body);
        $this->assertStringContainsString('horizon_job_processed_total{job_class="'.$job.'"} 2', $body);
        $this->assertStringContainsString('horizon_job_failed_total{job_class="'.$job.'"} 0', $body);
        $this->assertStringContainsString('horizon_job_retried_total{job_class="'.$job.'"} 0', $body);
        $this->assertStringContainsString('horizon_job_failed_total{job_class="'.$this->label(FailingJob::class).'"} 1', $body);
        $this->assertStringContainsString('# TYPE horizon_job_runtime_seconds gauge', $body);
        $this->assertStringContainsString('horizon_job_runtime_seconds{job_class="'.$job.'"} ', $body);
        $this->assertStringContainsString('# TYPE horizon_job_wait_seconds summary', $body);
        $this->assertStringContainsString('horizon_job_wait_seconds_count{job_class="'.$job.'"} 2', $body);
        $this->assertStringContainsString('horizon_job_wait_seconds_sum{job_class="'.$job.'"} ', $body);

        // "job" is Prometheus' own label for the scrape target, so exposing
        // the class under it would have every series come back as the scrape
        // config's job_name with the class hidden in "exported_job".
        $this->assertStringNotContainsString('{job="', $body);
        $this->assertStringNotContainsString('instance="', $body);

        $this->assertStringContainsString('horizon_queue_processed_total{queue="default"} 2', $body);
        $this->assertStringContainsString('horizon_queue_failed_total{queue="default"} 1', $body);
        $this->assertStringContainsString('horizon_queue_retried_total{queue="default"} 0', $body);
        $this->assertStringContainsString('horizon_queue_runtime_seconds{queue="default"} ', $body);
        $this->assertStringContainsString('horizon_queue_wait_seconds_count{queue="default"} 3', $body);
    }

    public function test_runtime_falls_back_to_the_last_snapshot_when_the_window_is_empty()
    {
        $metrics = app(MetricsRepository::class);

        $metrics->incrementJob(BasicJob::class, 1500);
        $metrics->incrementQueue('default', 1500);

        $this->assertStringContainsString('horizon_job_runtime_seconds{job_class="'.$this->label(BasicJob::class).'"} 1.5', $this->scrape());

        $metrics->snapshot();

        // No job has run in the new window, so the average is the snapshot's
        // rather than a zero.
        $body = $this->scrape();

        $this->assertStringContainsString('horizon_job_runtime_seconds{job_class="'.$this->label(BasicJob::class).'"} 1.5', $body);
        $this->assertStringContainsString('horizon_queue_runtime_seconds{queue="default"} 1.5', $body);
    }

    public function test_a_genuine_zero_runtime_is_not_masked_by_the_last_snapshot()
    {
        $connection = app(MetricsRepository::class)->connection();

        $connection->sadd('measured_queues', 'queue:default');
        $connection->zadd('snapshot:queue:default', 1, json_encode(['runtime' => 500]));
        $connection->hmset('queue:default', ['runtime' => 0, 'throughput' => 1]);

        $this->assertStringContainsString('horizon_queue_runtime_seconds{queue="default"} 0', $this->scrape());
    }

    public function test_a_class_horizon_measured_before_the_package_was_installed_starts_its_counters_at_zero()
    {
        app(MetricsRepository::class)->incrementJob(BasicJob::class, 10);

        $body = $this->scrape();
        $job = $this->label(BasicJob::class);

        $this->assertStringContainsString('horizon_job_processed_total{job_class="'.$job.'"} 0', $body);
        $this->assertStringContainsString('horizon_job_runtime_seconds{job_class="'.$job.'"} 0.01', $body);
    }

    public function test_a_class_with_no_runtime_anywhere_has_no_runtime_series()
    {
        Queue::push(new FailingJob);
        $this->work();

        // Horizon records runtime for completed jobs only, so a class that has
        // only ever failed has none — which is not the same as a zero.
        $this->assertStringNotContainsString('horizon_job_runtime_seconds{job_class="'.$this->label(FailingJob::class).'"}', $this->scrape());
    }

    public function test_queues_balanced_as_one_group_are_exported_per_queue()
    {
        $this->fakeSupervisors(['redis:high,default' => 6]);

        Queue::push(new BasicJob, '', 'high');
        Queue::push(new BasicJob, '', 'default');
        Queue::push(new BasicJob, '', 'default');

        $body = $this->scrape();

        $high = '{queue="high",connection="redis",group="high,default"}';
        $default = '{queue="default",connection="redis",group="high,default"}';

        $this->assertStringContainsString('horizon_queue_length'.$high.' 1', $body);
        $this->assertStringContainsString('horizon_queue_length'.$default.' 2', $body);
        $this->assertStringContainsString('horizon_queue_time_to_clear_seconds'.$high.' ', $body);
        $this->assertStringContainsString('horizon_queue_oldest_pending_seconds'.$default.' 0', $body);

        // The pool is shared, so both queues report all six processes.
        $this->assertStringContainsString('horizon_queue_processes'.$high.' 6', $body);
        $this->assertStringContainsString('horizon_queue_processes'.$default.' 6', $body);

        $this->assertStringContainsString('horizon_supervisor_processes{supervisor="host:supervisor-1",connection="redis",group="high,default"} 6', $body);
        $this->assertStringContainsString('horizon_processes 6', $body);

        // The group's name is never a "queue" label: it would not line up with
        // the throughput counters, which are always per concrete queue.
        $this->assertStringNotContainsString('queue="high,default"', $body);
    }

    public function test_a_queue_processed_on_its_own_is_its_own_group()
    {
        $this->fakeSupervisors(['redis:default' => 3]);

        $this->assertStringContainsString(
            'horizon_queue_processes{queue="default",connection="redis",group="default"} 3',
            $this->scrape()
        );
    }

    public function test_configured_queues_are_exported_while_no_supervisor_is_running()
    {
        config(['horizon.environments.testing.supervisor-1' => [
            'connection' => 'redis', 'queue' => ['high', 'default'], 'balance' => false, 'maxProcesses' => 3,
        ]]);

        Queue::push(new BasicJob);

        $body = $this->scrape();

        // Every worker is down, which is exactly when the backlog matters.
        $labels = '{queue="default",connection="redis",group="high,default"}';

        $this->assertStringContainsString('horizon_queue_length'.$labels.' 1', $body);
        $this->assertStringContainsString('horizon_queue_processes'.$labels.' 0', $body);
        $this->assertStringContainsString('horizon_up 0', $body);
    }

    public function test_the_oldest_pending_age_is_measured_from_when_the_job_became_ready()
    {
        $this->fakeSupervisors(['redis:default' => 1]);

        $id = Queue::push(new BasicJob);

        app(MetricsRepository::class)->connection()->hset($id, 'updated_at', (string) (microtime(true) - 30));

        $this->assertMatchesRegularExpression(
            '/horizon_queue_oldest_pending_seconds\{queue="default",connection="redis",group="default"\} (29|30|31)\n/',
            $this->scrape()
        );
    }

    public function test_the_time_to_clear_shares_of_a_group_add_up_to_the_group()
    {
        $this->fakeSupervisors(['redis:high,default' => 2]);

        app(MetricsRepository::class)->incrementQueue('high', 10000);
        app(MetricsRepository::class)->incrementQueue('default', 20000);

        Queue::push(new BasicJob, '', 'high');
        Queue::push(new BasicJob, '', 'default');
        Queue::push(new BasicJob, '', 'default');

        $body = $this->scrape();

        // 1 x 10s and 2 x 20s over two processes.
        $this->assertStringContainsString('horizon_queue_time_to_clear_seconds{queue="high",connection="redis",group="high,default"} 5', $body);
        $this->assertStringContainsString('horizon_queue_time_to_clear_seconds{queue="default",connection="redis",group="high,default"} 20', $body);
    }

    public function test_paused_queues_are_reported_where_laravel_supports_pausing()
    {
        if (! method_exists(app('queue'), 'isPaused')) {
            $this->markTestSkipped('Queue pausing needs a later Laravel.');
        }

        $this->fakeSupervisors(['redis:high,default' => 1]);

        app('queue')->pause('redis', 'default');

        $body = $this->scrape();

        $this->assertStringContainsString('horizon_queue_paused{queue="default",connection="redis",group="high,default"} 1', $body);
        $this->assertStringContainsString('horizon_queue_paused{queue="high",connection="redis",group="high,default"} 0', $body);
    }

    public function test_job_state_and_summary_metrics_are_exposed()
    {
        $body = $this->scrape();

        $this->assertStringContainsString('# TYPE horizon_jobs gauge', $body);

        foreach (['pending', 'completed', 'failed', 'silenced'] as $status) {
            $this->assertStringContainsString('horizon_jobs{status="'.$status.'"} ', $body);
        }

        $this->assertStringContainsString('horizon_recent_jobs ', $body);
        $this->assertStringContainsString('horizon_recent_failed_jobs ', $body);
        $this->assertStringContainsString('horizon_processes 0', $body);
        $this->assertStringContainsString('horizon_up 0', $body);
        $this->assertStringContainsString('horizon_info{name="', $body);
        $this->assertStringContainsString('horizon_scrape_duration_seconds ', $body);
    }

    public function test_master_supervisors_are_reported_with_their_pause_state()
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn([
            (object) ['name' => 'host-a', 'status' => 'running'],
            (object) ['name' => 'host-b', 'status' => 'paused'],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $body = $this->scrape();

        $this->assertStringContainsString('horizon_master_supervisor_paused{master="host-a"} 0', $body);
        $this->assertStringContainsString('horizon_master_supervisor_paused{master="host-b"} 1', $body);
        $this->assertStringContainsString('horizon_up 1', $body);

        // Stock Horizon does not record when a supervisor started, so there is
        // no uptime to export — and a made-up one would be worse than none.
        $this->assertStringNotContainsString('start_time_seconds', $body);
    }

    public function test_no_master_supervisors_is_down_whatever_the_repository_returns()
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn((fn () => yield from [])());
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $this->assertStringContainsString('horizon_up 0', $this->scrape());
    }

    public function test_scraping_does_not_write_to_redis()
    {
        $this->fakeSupervisors(['redis:default' => 1]);

        Queue::push(new BasicJob);
        $this->work();
        Queue::push(new BasicJob);

        $connection = app(MetricsRepository::class)->connection();
        $connection->del('last_snapshot_at');

        $before = $this->dump();

        $this->scrape();

        // Horizon's jobs-per-minute figure stores a timestamp when none is
        // present. A scrape must not move the window the dashboard measures
        // against, so that figure is left to PromQL's rate() instead.
        $this->assertNull($connection->get('last_snapshot_at'));
        $this->assertSame($before, $this->dump());
    }

    public function test_the_metric_prefix_is_configurable()
    {
        config(['horizon-prometheus.prefix' => 'queues']);

        $body = $this->scrape();

        $this->assertStringContainsString('queues_jobs{status="pending"} ', $body);
        $this->assertStringNotContainsString('horizon_jobs{', $body);
    }

    public function test_an_empty_prefix_falls_back_rather_than_clashing_with_prometheus()
    {
        config(['horizon-prometheus.prefix' => '']);

        $body = $this->scrape();

        // Unprefixed, these would collide with the series Prometheus adds to
        // every target itself.
        $this->assertStringContainsString('horizon_up ', $body);
        $this->assertDoesNotMatchRegularExpression('/^(up|scrape_duration_seconds) /m', $body);
    }

    public function test_an_application_metrics_repository_is_read_through_the_contract()
    {
        // MetricsRepository is a published extension point, so an application
        // binding its own implementation must still be scraped.
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('measuredJobs')->andReturn([BasicJob::class]);
        $metrics->shouldReceive('measuredQueues')->andReturn([]);
        $metrics->shouldReceive('runtimeForJob')->with(BasicJob::class)->andReturn(0.0);
        $metrics->shouldReceive('snapshotsForJob')->with(BasicJob::class)->andReturn([(object) ['runtime' => 250]]);
        $this->app->instance(MetricsRepository::class, $metrics);

        $this->assertStringContainsString(
            'horizon_job_runtime_seconds{job_class="'.$this->label(BasicJob::class).'"} 0.25',
            $this->scrape()
        );
    }

    public function test_scrapes_do_not_require_a_dashboard_user()
    {
        // A Prometheus server has no session to authenticate with, so the
        // endpoint must not be subject to the "viewHorizon" gate.
        Horizon::auth(fn () => false);

        $this->get('/horizon/prometheus')->assertOk();
    }

    /**
     * Stand in for running supervisors with the given pools.
     *
     * @param  array<string, int>  $processes
     * @return void
     */
    protected function fakeSupervisors(array $processes)
    {
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['name' => 'host:supervisor-1', 'status' => 'running', 'processes' => $processes],
        ]);

        $this->app->instance(SupervisorRepository::class, $supervisors);
    }

    /**
     * Read every key in the database with its type and contents.
     *
     * @return array<string, mixed>
     */
    protected function dump()
    {
        $client = app('redis')->connection()->client();
        $keys = $client->keys('*');
        sort($keys);

        $dump = [];

        foreach ($keys as $key) {
            $dump[$key] = $client->dump($key);
        }

        return $dump;
    }
}
