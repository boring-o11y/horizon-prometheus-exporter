<?php

namespace BoringO11y\HorizonPrometheusExporter\Console;

use BoringO11y\HorizonPrometheusExporter\Counters;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon-prometheus:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the counters exported to Prometheus';

    /**
     * Execute the console command.
     *
     * Prometheus reads a counter that drops as a process restart, so rate()
     * and increase() carry on correctly across this. It is not tied to
     * `horizon:clear-metrics` on purpose: that command clears the dashboard's
     * graphs, and a long-running series in Prometheus has no reason to reset
     * with them.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\Counters  $counters
     * @return int
     */
    public function handle(Counters $counters)
    {
        $counters->clear();

        $this->components->info('The Prometheus counters have been reset.');

        return self::SUCCESS;
    }
}
