<?php

namespace BoringO11y\HorizonPrometheusExporter\Http\Middleware;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Restricts the Prometheus endpoint to the configured scrapers.
 *
 * The metrics endpoint is not behind the dashboard's `viewHorizon` gate — a
 * Prometheus server has no session to authenticate with — so the client's IP
 * address is what guards it. By default only the loopback addresses are
 * allowed, meaning the endpoint is unreachable from another host until it is
 * explicitly opened up.
 */
class AuthorizePrometheusScrapes
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, $next)
    {
        if (! $this->allows($request->ip())) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * Determine if the given IP address may scrape the metrics endpoint.
     *
     * Entries may be exact addresses or CIDR ranges (IPv4 and IPv6); the
     * single entry "*" allows any address.
     *
     * Note that the address checked here is the one Laravel resolves for the
     * request, so an installation behind a load balancer or reverse proxy must
     * have its trusted proxies configured for this to see the real client.
     *
     * @param  string|null  $ip
     * @return bool
     */
    protected function allows($ip)
    {
        $allowed = array_values(array_filter(
            array_map('trim', (array) config('horizon-prometheus.allowed_ips', ['127.0.0.1', '::1'])),
            fn ($entry) => $entry !== '',
        ));

        if (in_array('*', $allowed, true)) {
            return true;
        }

        if (empty($allowed) || is_null($ip)) {
            return false;
        }

        return IpUtils::checkIp($ip, $allowed);
    }
}
