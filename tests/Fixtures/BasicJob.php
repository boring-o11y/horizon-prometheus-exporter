<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class BasicJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle()
    {
        //
    }
}
