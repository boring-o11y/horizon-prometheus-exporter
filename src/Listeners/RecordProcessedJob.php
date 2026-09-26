<?php

namespace BoringO11y\HorizonPrometheusExporter\Listeners;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Laravel\Horizon\Events\JobDeleted;

class RecordProcessedJob
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
     * Count a job that ran to completion.
     *
     * The same event and the same test Horizon's own throughput figure uses: a
     * job is deleted from the reserved set when it finishes and when it fails
     * for good, and only the first is throughput. A job that releases itself
     * never reaches here, so a rate limited job is not counted each time it is
     * turned away.
     *
     * @param  \Laravel\Horizon\Events\JobDeleted  $event
     * @return void
     */
    public function handle(JobDeleted $event)
    {
        if ($event->job->hasFailed() || ! $name = $event->payload->displayName()) {
            return;
        }

        $this->counters->processed($name, $event->queue ?? $event->job->getQueue());
    }
}
