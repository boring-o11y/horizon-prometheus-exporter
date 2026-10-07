<?php

namespace BoringO11y\HorizonPrometheusExporter;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;

/**
 * The never-reset counters the exporter reads.
 *
 * Horizon's own metrics hashes are windows: `horizon:snapshot` reads and
 * deletes them every few minutes, so a counter built from them would drop to
 * zero on every snapshot, which Prometheus reads as a process restart. These
 * are kept alongside them instead, on Horizon's Redis connection, and are only
 * ever incremented.
 *
 * Each family is one hash keyed by job class or queue name, so a scrape reads a
 * fixed number of keys whatever the cardinality, and there is no registry to
 * keep in step with the data.
 */
class Counters
{
    /**
     * The key prefix inside Horizon's own prefix.
     */
    public const PREFIX = 'prometheus:';

    /**
     * The counter families kept per job class and per queue.
     *
     * @var array<int, string>
     */
    public const FAMILIES = ['processed', 'failed', 'retried', 'waits', 'wait_seconds'];

    /**
     * The scopes each family is kept for.
     *
     * @var array<int, string>
     */
    public const SCOPES = ['job', 'queue'];

    /**
     * Create a new counters instance.
     *
     * @return void
     */
    public function __construct(protected RedisFactory $redis)
    {
    }

    /**
     * Count a job that ran to completion.
     *
     * @param  string  $job
     * @param  string  $queue
     * @return void
     */
    public function processed($job, $queue)
    {
        $this->increment('processed', $job, $queue);
    }

    /**
     * Count a job that failed for good.
     *
     * @param  string  $job
     * @param  string  $queue
     * @return void
     */
    public function failed($job, $queue)
    {
        $this->increment('failed', $job, $queue);
    }

    /**
     * Count a job released back onto its queue after throwing.
     *
     * @param  string  $job
     * @param  string  $queue
     * @return void
     */
    public function retried($job, $queue)
    {
        $this->increment('retried', $job, $queue);
    }

    /**
     * Record how long a job that is being picked up waited for it.
     *
     * The wait is measured from the `updated_at` Horizon stamps on the job's
     * hash every time the job becomes ready — the push, a release without a
     * delay, the migration out of the delayed set — so it is this attempt's
     * wait rather than the time since the original dispatch. It must be read
     * before Horizon marks the job reserved, which overwrites that field; the
     * read and the increments are one script so the pickup costs one round
     * trip.
     *
     * @param  string  $id
     * @param  string  $job
     * @param  string  $queue
     * @return void
     */
    public function waited($id, $job, $queue)
    {
        $this->connection()->eval(
            LuaScripts::recordWait(), 5,
            $id,
            $this->key('job', 'waits'),
            $this->key('job', 'wait_seconds'),
            $this->key('queue', 'waits'),
            $this->key('queue', 'wait_seconds'),
            sprintf('%.6F', microtime(true)),
            $job,
            $queue
        );
    }

    /**
     * Increment one family for a job class and its queue in one round trip.
     *
     * @param  string  $family
     * @param  string  $job
     * @param  string  $queue
     * @return void
     */
    protected function increment($family, $job, $queue)
    {
        $this->connection()->pipeline(function ($pipe) use ($family, $job, $queue) {
            $pipe->hincrby($this->key('job', $family), $job, 1);
            $pipe->hincrby($this->key('queue', $family), $queue, 1);
        });
    }

    /**
     * Read every counter.
     *
     * @return array{job: array<string, array<string, float>>, queue: array<string, array<string, float>>}
     */
    public function all()
    {
        $families = $this->families();

        $raw = $this->connection()->pipeline(function ($pipe) use ($families) {
            foreach ($families as [$scope, $family]) {
                $pipe->hgetall($this->key($scope, $family));
            }
        });

        $result = ['job' => [], 'queue' => []];

        foreach ($families as $i => [$scope, $family]) {
            foreach ((array) ($raw[$i] ?: []) as $name => $value) {
                $result[$scope][$name][$family] = $family === 'wait_seconds' ? (float) $value : (int) $value;
            }
        }

        return $result;
    }

    /**
     * Delete every counter.
     *
     * @return void
     */
    public function clear()
    {
        // One DEL per key rather than one multi-key DEL: on a cluster the keys
        // share a slot only through Horizon's hash-tagged prefix, and a single
        // key never has to.
        $this->connection()->pipeline(function ($pipe) {
            foreach ($this->families() as [$scope, $family]) {
                $pipe->del($this->key($scope, $family));
            }
        });
    }

    /**
     * Get every scope and family pair a counter is kept for.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function families()
    {
        static $families;

        return $families ??= Arr::crossJoin(self::SCOPES, self::FAMILIES);
    }

    /**
     * Get the key of one counter family.
     *
     * @param  string  $scope
     * @param  string  $family
     * @return string
     */
    public function key($scope, $family)
    {
        return self::PREFIX.$scope.':'.$family;
    }

    /**
     * Get Horizon's Redis connection.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function connection()
    {
        return $this->redis->connection('horizon');
    }
}
