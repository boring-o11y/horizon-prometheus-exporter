<?php

namespace BoringO11y\HorizonPrometheusExporter\Listeners;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Laravel\Horizon\JobPayload;

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
     * @param  \Illuminate\Queue\Events\JobReleasedAfterException  $event
     * @return void
     */
    public function handle(JobReleasedAfterException $event)
    {
        if (! $name = (new JobPayload($event->job->getRawBody()))->displayName()) {
            return;
        }

        $this->counters->retried($name, $event->job->getQueue());
    }
}
