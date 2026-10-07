<?php

namespace BoringO11y\HorizonPrometheusExporter\Listeners;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Laravel\Horizon\Events\JobReserved;

class RecordJobWait
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
     * Record how long the job waited to be picked up.
     *
     * This has to run before Horizon's own MarkJobAsReserved, which overwrites
     * the timestamp the wait is measured from. The service provider registers
     * it before Horizon registers its listeners for that reason.
     *
     * @param  \Laravel\Horizon\Events\JobReserved  $event
     * @return void
     */
    public function handle(JobReserved $event)
    {
        if (! $name = $event->payload->displayName()) {
            return;
        }

        $this->counters->waited($event->payload->id(), $name, $event->queue, $event->payload->decoded['pushedAt'] ?? null);
    }
}
