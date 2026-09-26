<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Fixtures;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RetryingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public $tries = 3;

    public function handle()
    {
        throw new Exception('Try again');
    }
}
