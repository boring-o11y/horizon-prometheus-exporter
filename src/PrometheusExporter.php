<?php

namespace BoringO11y\HorizonPrometheusExporter;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Repositories\RedisMetricsRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * Renders Horizon's state for a Prometheus scrape.
 *
 * Throughput, failures, retries and waits come from this package's own
 * counters, which only ever go up: Horizon's metrics are windows that
 * `horizon:snapshot` resets, which Prometheus would read as a restart every few
 * minutes. Runtime is Horizon's own moving average, exported as a gauge. Queue
 * depth, the oldest waiting job and the worker pools are read live.
 *
 * Nothing here writes. Horizon's jobs-per-minute figure stores a timestamp when
 * it reads one that is missing, which would move the window the dashboard's own
 * figure is measured against, so it is left to PromQL:
 * `sum(rate(horizon_queue_processed_total[5m])) * 60`.
 */
class PrometheusExporter
{
    /**
     * Create a new exporter instance.
     *
     * @return void
     */
    public function __construct(
        protected Counters $counters,
        protected MetricsRepository $metrics,
        protected JobRepository $jobs,
        protected SupervisorRepository $supervisors,
        protected MasterSupervisorRepository $masters,
        protected QueueFactory $queue,
        protected WaitTimeCalculator $waitTime,
        protected RedisFactory $redis,
    ) {
    }

    /**
     * Render the current metrics in the Prometheus text exposition format.
     *
     * @return string
     */
    public function render()
    {
        $startedAt = microtime(true);

        $registry = new MetricRegistry(config('horizon-prometheus.prefix', 'horizon'));

        $counters = $this->counters->all();
        // Read once and walked twice, so a repository that yields its rows
        // must not be left exhausted after the first pass.
        $supervisors = [...$this->supervisors->all()];
        $pools = $this->pools($supervisors);

        $this->collectMeasurements($registry, 'job', 'job_class', $counters['job'], $this->metrics->measuredJobs());
        $this->collectMeasurements($registry, 'queue', 'queue', $counters['queue'], $this->metrics->measuredQueues());
        $this->collectWorkload($registry, $pools);
        $this->collectJobCounts($registry);
        $this->collectSupervisors($registry, $supervisors);
        $this->collectSummary($registry);

        $registry->gauge(
            'scrape_duration_seconds',
            'Time it took to collect the metrics for this scrape.',
            round(microtime(true) - $startedAt, 6)
        );

        return $registry->render();
    }

    /**
     * Collect the per-job-class or per-queue counters and averages.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\MetricRegistry  $registry
     * @param  string  $scope
     * @param  string  $label
     * @param  array<string, array<string, int|float>>  $counters
     * @param  array<int, string>  $measured
     * @return void
     */
    protected function collectMeasurements(MetricRegistry $registry, $scope, $label, array $counters, array $measured)
    {
        // A class Horizon has measured but this package has not counted yet
        // (it ran before the package was installed) still has a runtime worth
        // exporting, and its counters honestly start at zero.
        $names = array_unique([...array_map('strval', array_keys($counters)), ...$measured]);

        sort($names);

        $runtimes = $this->runtimes($scope, $names);

        $noun = $scope === 'job' ? 'jobs of this class' : 'jobs on this queue';

        foreach ($names as $name) {
            // Not "job": Prometheus reserves that label for the scrape config's
            // job_name and renames any exposed one to "exported_job", which
            // would collapse every class in the export onto the target's name.
            $labels = [$label => $name];
            $count = $counters[$name] ?? [];

            $registry->counter($scope.'_processed_total', "Total number of {$noun} that ran to completion.", $count['processed'] ?? 0, $labels);
            $registry->counter($scope.'_failed_total', "Total number of {$noun} that failed for good.", $count['failed'] ?? 0, $labels);
            $registry->counter($scope.'_retried_total', "Total number of {$noun} released back onto a queue after throwing an exception.", $count['retried'] ?? 0, $labels);
            $registry->summary($scope.'_wait_seconds', "Time {$noun} waited on the queue before a worker picked them up, in seconds. Divide rate(_sum) by rate(_count) for the average over a range.", $count['wait_seconds'] ?? 0.0, $count['waits'] ?? 0, $labels);
            $registry->gauge($scope.'_runtime_seconds', "Moving average runtime of {$noun} in seconds, as shown on Horizon's metrics screen.", $this->seconds($runtimes[$name] ?? null), $labels);
        }
    }

    /**
     * Read Horizon's average runtime for each name.
     *
     * The average lives in a window that `horizon:snapshot` deletes, so a window
     * that has recorded nothing yet falls back to the last snapshot rather
     * than reporting a spurious zero. The fallback is keyed on the field being
     * absent, not on it being zero: a job that genuinely takes no time has a
     * real average of zero.
     *
     * @param  string  $scope
     * @param  array<int, string>  $names
     * @return array<string, float|null>
     */
    protected function runtimes($scope, array $names)
    {
        if (empty($names)) {
            return [];
        }

        // Horizon's own repository only offers one read per name. Its keys are
        // read directly when it is the one bound, so a scrape costs one round
        // trip rather than two per job class.
        if (! $this->metrics instanceof RedisMetricsRepository) {
            return $this->runtimesThroughTheContract($scope, $names);
        }

        $raw = $this->metrics->connection()->pipeline(function ($pipe) use ($scope, $names) {
            foreach ($names as $name) {
                $pipe->hget($scope.':'.$name, 'runtime');
                $pipe->zrange('snapshot:'.$scope.':'.$name, -1, -1);
            }
        });

        $result = [];

        foreach ($names as $i => $name) {
            $current = $raw[$i * 2];
            $latest = json_decode(($raw[$i * 2 + 1][0] ?? null) ?: '{}', true);

            $result[$name] = match (true) {
                ! is_null($current) && $current !== false => (float) $current,
                isset($latest['runtime']) => (float) $latest['runtime'],
                default => null,
            };
        }

        return $result;
    }

    /**
     * Read the average runtimes through the MetricsRepository contract.
     *
     * An application that bound its own repository cannot say whether the
     * window is empty, so the window's figure is used and the snapshot only
     * when it reads zero.
     *
     * @param  string  $scope
     * @param  array<int, string>  $names
     * @return array<string, float|null>
     */
    protected function runtimesThroughTheContract($scope, array $names)
    {
        [$runtimeFor, $snapshotsFor] = $scope === 'job'
            ? [$this->metrics->runtimeForJob(...), $this->metrics->snapshotsForJob(...)]
            : [$this->metrics->runtimeForQueue(...), $this->metrics->snapshotsForQueue(...)];

        $result = [];

        foreach ($names as $name) {
            $runtime = $runtimeFor($name);

            if (! $runtime) {
                $snapshots = $snapshotsFor($name);

                $runtime = end($snapshots)->runtime ?? $runtime;
            }

            $result[$name] = is_null($runtime) ? null : (float) $runtime;
        }

        return $result;
    }

    /**
     * Collect the current workload of every queue being processed.
     *
     * Supervisors that balance several queues as one pool name the pool after
     * all of them ("high,default"), but a scrape has to be per queue for the
     * labels to line up with the throughput counters. So every pool is split
     * into its queues, each tagged with the pool it belongs to as "group".
     *
     * This reads the pools off the running supervisors itself rather than
     * through Horizon's workload repository, whose rows drop the connection a
     * queue belongs to.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\MetricRegistry  $registry
     * @param  array<string, int>  $pools
     * @return void
     */
    protected function collectWorkload(MetricRegistry $registry, array $pools)
    {
        foreach ($pools as $pool => $processes) {
            [$connection, $group] = $this->splitPool($pool);

            foreach (explode(',', $group) as $name) {
                $labels = ['queue' => $name, 'connection' => $connection, 'group' => $group];

                $registry->gauge('queue_length', 'Number of jobs ready to run on this queue.', $this->length($connection, $name), $labels);
                $registry->gauge('queue_oldest_pending_seconds', 'How long the job at the head of this queue has been ready to run, in seconds.', $this->oldestPendingAge($connection, $name), $labels);
                $registry->gauge('queue_processes', 'Number of worker processes able to pick up this queue\'s jobs. Queues sharing a process pool each report the whole pool, so deduplicate with "max by (group)" before totalling the fleet.', $processes, $labels);
                $registry->gauge('queue_paused', 'Whether this queue is paused with queue:pause (1) or being processed (0).', $this->paused($connection, $name), $labels);
                $registry->gauge('queue_time_to_clear_seconds', 'Estimated number of seconds needed to clear this queue at its current runtime. For a queue balanced in a group this is its share of the group estimate — its own backlog against the shared pool — so the group is "sum by (group)".', $this->timeToClear($connection, $name, $processes), $labels);
            }
        }
    }

    /**
     * Get the number of worker processes in each pool, across every supervisor.
     *
     * @param  array<int, \stdClass>  $supervisors
     * @return array<string, int>
     */
    protected function pools(array $supervisors)
    {
        $pools = [];

        foreach ($supervisors as $supervisor) {
            foreach ((array) $supervisor->processes as $pool => $count) {
                $pools[$pool] = ($pools[$pool] ?? 0) + (int) $count;
            }
        }

        ksort($pools);

        return $pools;
    }

    /**
     * Split a pool key into its connection and its group of queues.
     *
     * @param  string|int  $pool
     * @return array{0: string, 1: string}
     */
    protected function splitPool($pool)
    {
        return array_pad(explode(':', (string) $pool, 2), 2, '');
    }

    /**
     * Get the number of jobs ready to run on a queue.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @return int|null
     */
    protected function length($connection, $queue)
    {
        return $this->quietly(function () use ($connection, $queue) {
            $driver = $this->queue->connection($connection);

            return (int) (method_exists($driver, 'readyNow') ? $driver->readyNow($queue) : $driver->size($queue));
        });
    }

    /**
     * Get how long the job at the head of a queue has been ready to run.
     *
     * Measured from the `updated_at` Horizon stamps on the job's hash whenever
     * it becomes ready, so a job that spent an hour on a backoff has not been
     * waiting for an hour. A job whose hash has gone falls back to the time it
     * was pushed.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @return int|null
     */
    protected function oldestPendingAge($connection, $queue)
    {
        return $this->quietly(function () use ($connection, $queue) {
            $driver = $this->queue->connection($connection);

            if (! method_exists($driver, 'getConnection') || ! method_exists($driver, 'getQueue')) {
                return null;
            }

            $payload = $driver->getConnection()->lindex($driver->getQueue($queue), 0);

            if (! $payload) {
                return 0;
            }

            $payload = json_decode($payload, true);

            $readyAt = ($id = $payload['id'] ?? $payload['uuid'] ?? null)
                ? $this->redis->connection('horizon')->hget($id, 'updated_at')
                : null;

            $readyAt = $readyAt ?: ($payload['pushedAt'] ?? null);

            return $readyAt ? max(0, (int) round(microtime(true) - (float) $readyAt)) : null;
        });
    }

    /**
     * Determine whether a queue has been paused with `queue:pause`.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @return int|null
     */
    protected function paused($connection, $queue)
    {
        // Queue pausing arrived in a later Laravel than this package supports,
        // and an unknown answer is left out rather than reported as "running".
        if (! method_exists($this->queue, 'isPaused')) {
            return null;
        }

        return $this->quietly(fn () => (int) $this->queue->isPaused($connection, $queue));
    }

    /**
     * Get the estimated time to clear one queue of a pool.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  int  $processes
     * @return float|null
     */
    protected function timeToClear($connection, $queue, $processes)
    {
        return $this->quietly(fn () => (float) $this->waitTime->calculateTimeToClear($connection, $queue, $processes));
    }

    /**
     * Collect the number of jobs Horizon is retaining in each state.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\MetricRegistry  $registry
     * @return void
     */
    protected function collectJobCounts(MetricRegistry $registry)
    {
        $help = 'Number of jobs Horizon is currently retaining in the given state.';

        $registry->gauge('jobs', $help, (int) $this->jobs->countPending(), ['status' => 'pending']);
        $registry->gauge('jobs', $help, (int) $this->jobs->countCompleted(), ['status' => 'completed']);
        $registry->gauge('jobs', $help, (int) $this->jobs->countFailed(), ['status' => 'failed']);
        $registry->gauge('jobs', $help, (int) $this->jobs->countSilenced(), ['status' => 'silenced']);

        $registry->gauge('recent_jobs', 'Number of jobs processed within the recent jobs retention window.', (int) $this->jobs->countRecent());
        $registry->gauge('recent_failed_jobs', 'Number of jobs that failed within the recent failures retention window.', (int) $this->jobs->countRecentlyFailed());
    }

    /**
     * Collect the state of the master supervisors and their worker pools.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\MetricRegistry  $registry
     * @param  array<int, \stdClass>  $supervisors
     * @return void
     */
    protected function collectSupervisors(MetricRegistry $registry, array $supervisors)
    {
        $processes = 0;

        $masters = $this->masters->all();

        foreach ($masters as $master) {
            $registry->gauge(
                'master_supervisor_paused',
                'Whether this master supervisor is paused (1) or running (0).',
                (int) ($master->status === 'paused'),
                ['master' => $master->name]
            );
        }

        foreach ($supervisors as $supervisor) {
            $registry->gauge(
                'supervisor_paused',
                'Whether this supervisor is paused (1) or running (0).',
                (int) ($supervisor->status === 'paused'),
                ['supervisor' => $supervisor->name]
            );

            foreach ((array) $supervisor->processes as $pool => $count) {
                [$connection, $group] = $this->splitPool($pool);

                $processes += (int) $count;

                // The pool key is the group a supervisor balances as a unit,
                // so this is labelled "group" like the workload metrics — a
                // "queue" label is a single queue everywhere in the export.
                $registry->gauge(
                    'supervisor_processes',
                    'Number of worker processes a supervisor is running for a queue group.',
                    (int) $count,
                    ['supervisor' => $supervisor->name, 'connection' => $connection, 'group' => $group]
                );
            }
        }

        $registry->gauge('processes', 'Total number of worker processes running across all supervisors.', $processes);
        $registry->gauge('up', 'Whether at least one master supervisor is currently reporting in.', (int) ! empty($masters));
    }

    /**
     * Collect the application wide summary figures.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\MetricRegistry  $registry
     * @return void
     */
    protected function collectSummary(MetricRegistry $registry)
    {
        $registry->gauge(
            'info',
            'Static information about this Horizon installation.',
            1,
            ['name' => config('horizon.name') ?: config('app.name')]
        );
    }

    /**
     * Run a read that may fail for one queue without failing the scrape.
     *
     * A queue on a connection that no longer exists, or a cache store that is
     * down, should cost that one series, not every metric in the scrape.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function quietly(callable $callback)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Convert a millisecond measurement into seconds.
     *
     * @param  float|int|null  $milliseconds
     * @return float|null
     */
    protected function seconds($milliseconds)
    {
        return is_null($milliseconds) ? null : round($milliseconds / 1000, 6);
    }
}
