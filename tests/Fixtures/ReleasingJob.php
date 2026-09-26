<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ReleasingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public $tries = 3;

    public function handle()
    {
        $this->release();
    }
}
