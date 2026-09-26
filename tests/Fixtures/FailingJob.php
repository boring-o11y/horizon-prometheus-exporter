<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Fixtures;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public $tries = 1;

    public function handle()
    {
        throw new Exception('Job Failed');
    }
}
