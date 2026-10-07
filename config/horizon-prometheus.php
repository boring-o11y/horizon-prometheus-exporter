<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Nothing happens until you turn this on: no route is registered and the
    | workers record nothing. Set HORIZON_PROMETHEUS_ENABLED=true on every
    | machine running the application — the web servers serve the endpoint,
    | and the Horizon workers keep the counters it reads.
    |
    */

    'enabled' => env('HORIZON_PROMETHEUS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Path and Domain
    |--------------------------------------------------------------------------
    |
    | Where the scrape endpoint is served from. When left empty the path
    | follows Horizon's own, i.e. "{horizon.path}/prometheus", and the domain
    | follows "horizon.domain", so moving or scoping the dashboard moves the
    | endpoint with it.
    |
    */

    'path' => env('HORIZON_PROMETHEUS_PATH'),

    'domain' => env('HORIZON_PROMETHEUS_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Addresses
    |--------------------------------------------------------------------------
    |
    | The endpoint has no user to authenticate, so it is not behind Horizon's
    | "viewHorizon" gate; an IP allowlist guards it instead, and out of the box
    | only the loopback addresses may scrape it. Entries may be exact addresses
    | or CIDR ranges, and the single entry "*" allows any address.
    |
    | Read this as "which addresses this application sees", not "which
    | machines may scrape". Behind a load balancer or reverse proxy — including
    | nginx in front of php-fpm or Octane on the same host — every request
    | arrives from the proxy, so the loopback default admits anyone who can
    | reach the proxy. Configure the application's trusted proxies so the real
    | client address is what gets checked, and treat the allowlist as one layer
    | rather than the whole of your access control.
    |
    */

    'allowed_ips' => explode(',', (string) env('HORIZON_PROMETHEUS_ALLOWED_IPS', '127.0.0.1,::1')),

    // Additional middleware to apply to the scrape endpoint. The IP allowlist
    // is always applied, ahead of anything listed here.
    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Metric Prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to every exported metric name.
    |
    */

    'prefix' => env('HORIZON_PROMETHEUS_PREFIX', 'horizon'),

];
