<?php

namespace BoringO11y\HorizonPrometheusExporter\Listeners;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Str;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;

class RecordRetriedJob
{
    /**
     * Create a new listener instance.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\Counters  $counters
     * @return void
     */
    public function __construct(public Counters $counters)
    {
    }

    /**
     * Count a job released back onto its queue after throwing.
     *
     * Only a release caused by an exception is a retry. A job calling
     * `$this->release()` itself — rate limiting, polling, waiting on a lock —
     * is doing what it was written to do, and counting those would bury the
     * retries in noise.
     *
     * This is Laravel's event rather than Horizon's, so it also fires for jobs
     * on other drivers; only Horizon's are counted, under the queue name
     * Horizon's own events carry.
     *
     * @param  \Illuminate\Queue\Events\JobReleasedAfterException  $event
     * @return void
     */
    public function handle(JobReleasedAfterException $event)
    {
        $job = $event->job;

        if (! $job instanceof RedisJob || ! $job->getRedisQueue() instanceof RedisQueue) {
            return;
        }

        if (! $name = (new JobPayload($job->getRawBody()))->displayName()) {
            return;
        }

        $queue = Str::replaceFirst('queues:', '', $job->getRedisQueue()->getQueue($job->getQueue()));

        $this->counters->retried($name, $queue);
    }
}
