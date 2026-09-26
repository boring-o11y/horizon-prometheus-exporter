<?php

namespace BoringO11y\HorizonPrometheusExporter\Listeners;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Laravel\Horizon\Events\JobFailed;

class RecordFailedJob
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
     * Count a job that failed for good.
     *
     * @param  \Laravel\Horizon\Events\JobFailed  $event
     * @return void
     */
    public function handle(JobFailed $event)
    {
        if (! $name = $event->payload->displayName()) {
            return;
        }

        $this->counters->failed($name, $event->queue ?? $event->job->getQueue());
    }
}
