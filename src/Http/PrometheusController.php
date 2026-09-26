<?php

namespace BoringO11y\HorizonPrometheusExporter\Http;

use BoringO11y\HorizonPrometheusExporter\PrometheusExporter;
use Illuminate\Routing\Controller;

/**
 * Serves Horizon's metrics for a Prometheus scrape.
 *
 * This deliberately does not go through Horizon's Authenticate middleware: the
 * dashboard's `viewHorizon` gate assumes an authenticated user, which a
 * scraper does not have. Access is restricted by the IP allowlist applied to
 * the route instead.
 */
class PrometheusController extends Controller
{
    /**
     * Render the metrics in the Prometheus text exposition format.
     *
     * @param  \BoringO11y\HorizonPrometheusExporter\PrometheusExporter  $exporter
     * @return \Illuminate\Http\Response
     */
    public function index(PrometheusExporter $exporter)
    {
        return response($exporter->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
