<?php

namespace BoringO11y\HorizonPrometheusExporter\Tests;

use BoringO11y\HorizonPrometheusExporter\HorizonPrometheusExporterServiceProvider;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configuration applied on every application build.
     *
     * The route and the listeners are registered at boot, so a value set from
     * a test body is too late. Setting it here makes it part of the build.
     *
     * @var array<string, mixed>
     */
    protected array $overrides = [];

    protected function setUp(): void
    {
        $this->afterApplicationCreated(fn () => $this->flushRedis());
        $this->beforeApplicationDestroyed(function () {
            $this->flushRedis();
            Horizon::$authUsing = null;
        });

        parent::setUp();
    }

    /**
     * Empty both the queue's database and Horizon's.
     *
     * @return void
     */
    protected function flushRedis()
    {
        Redis::connection()->flushdb();
        Redis::connection('horizon')->flushdb();
    }

    protected function getPackageProviders($app)
    {
        return [
            HorizonServiceProvider::class,
            HorizonPrometheusExporterServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.options.prefix', '');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 6,
        ]);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
        ]);

        $app['config']->set('horizon.path', 'horizon');
        $app['config']->set('horizon.middleware', []);
        $app['config']->set('horizon-prometheus.enabled', true);

        foreach ($this->overrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Run the next job on the default queue, as a Horizon worker would.
     *
     * @param  int  $times
     * @return void
     */
    protected function work($times = 1)
    {
        $options = tap(new WorkerOptions, function ($options) {
            $options->sleep = 0;
            $options->maxTries = 1;
        });

        for ($i = 0; $i < $times; $i++) {
            app('queue.worker')->runNextJob('redis', 'default', $options);
        }
    }

    /**
     * Scrape the endpoint and return the body.
     *
     * @return string
     */
    protected function scrape()
    {
        return $this->get('/horizon/prometheus')->assertOk()->getContent();
    }

    /**
     * Request the endpoint from the given client address.
     *
     * @param  string  $ip
     * @return \Illuminate\Testing\TestResponse
     */
    protected function scrapeFrom($ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/horizon/prometheus');
    }

    /**
     * Escape a class name the way it appears in a label value.
     *
     * @param  string  $class
     * @return string
     */
    protected function label($class)
    {
        return str_replace('\\', '\\\\', $class);
    }
}
