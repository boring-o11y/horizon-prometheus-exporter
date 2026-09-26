<?php

namespace BoringO11y\HorizonPrometheusExporter;

use BoringO11y\HorizonPrometheusExporter\Http\Middleware\AuthorizePrometheusScrapes;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobFailed;
use Laravel\Horizon\Events\JobReserved;

class HorizonPrometheusExporterServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/horizon-prometheus.php', 'horizon-prometheus');

        $this->app->singleton(Counters::class);

        // Both are done from a booting callback rather than from boot(): by
        // then every provider has registered, so the configuration is merged,
        // but none has booted, so Horizon has declared neither its dashboard's
        // catch-all route, which would otherwise swallow the scrape path, nor
        // its own event listeners, which the wait listener has to run ahead of.
        $this->app->isBooted()
            ? $this->bootEnabled()
            : $this->app->booting(fn () => $this->bootEnabled());
    }

    /**
     * Bootstrap the package.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\ClearCommand::class]);

            $this->publishes([
                __DIR__.'/../config/horizon-prometheus.php' => $this->app->configPath('horizon-prometheus.php'),
            ], 'horizon-prometheus-config');

            $this->publishes([
                __DIR__.'/../resources/grafana/horizon-dashboard.json' => $this->app->resourcePath('grafana/horizon-dashboard.json'),
            ], 'horizon-prometheus-grafana');
        }
    }

    /**
     * Register the route and the listeners, when the exporter is enabled.
     *
     * @return void
     */
    protected function bootEnabled()
    {
        // Opt in only: an application that has not asked for a metrics endpoint
        // should not have one, nor pay for counters nobody reads.
        if (! config('horizon-prometheus.enabled', false)) {
            return;
        }

        $this->registerListeners();
        $this->registerRoutes();
    }

    /**
     * Register the listeners that keep the counters.
     *
     * @return void
     */
    protected function registerListeners()
    {
        $events = $this->app->make(Dispatcher::class);

        // Listeners run in the order they were registered, and this one reads
        // the timestamp Horizon's MarkJobAsReserved overwrites.
        $events->listen(JobReserved::class, Listeners\RecordJobWait::class);
        $events->listen(JobDeleted::class, Listeners\RecordProcessedJob::class);
        $events->listen(JobFailed::class, Listeners\RecordFailedJob::class);
        $events->listen(JobReleasedAfterException::class, Listeners\RecordRetriedJob::class);
    }

    /**
     * Register the Prometheus scrape route.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            // Not config(..., $default): the key is always present after the
            // package config is merged, so an unset HORIZON_PROMETHEUS_DOMAIN
            // resolves to null and would never reach a default argument. The
            // scrape endpoint must stay behind the same domain as the dashboard
            // unless it is given one of its own.
            'domain' => config('horizon-prometheus.domain') ?? config('horizon.domain'),
            'prefix' => $this->path(),
            'middleware' => array_merge(
                [AuthorizePrometheusScrapes::class],
                (array) config('horizon-prometheus.middleware', [])
            ),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/prometheus.php');
        });
    }

    /**
     * Get the URI path the scrape route is registered at.
     *
     * The default follows the dashboard's own path rather than hard coding
     * "horizon/": an application that moved the dashboard to keep Horizon off
     * a well known URL should not have a metrics endpoint appear back under
     * /horizon, where it would also shadow whatever the application serves
     * there.
     *
     * @return string
     */
    protected function path()
    {
        if ($path = config('horizon-prometheus.path')) {
            return $path;
        }

        return trim((string) config('horizon.path', 'horizon'), '/').'/prometheus';
    }
}
