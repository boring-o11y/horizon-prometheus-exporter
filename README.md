# Horizon Prometheus Exporter

A Prometheus scrape endpoint for Laravel Horizon, with a matching Grafana dashboard.

It exports the numbers you'd look at on Horizon's dashboard, in a form Prometheus can graph and alert on: counters for throughput, failures and retries per queue and per job class, the time jobs wait before a worker picks them up, how deep each queue is and how long its oldest job has been ready, and how many workers each supervisor runs.

```
# TYPE horizon_queue_processed_total counter
horizon_queue_processed_total{queue="default"} 18423
# TYPE horizon_job_wait_seconds summary
horizon_job_wait_seconds_sum{job_class="App\\Jobs\\SendInvoice"} 912.4
horizon_job_wait_seconds_count{job_class="App\\Jobs\\SendInvoice"} 3310
# TYPE horizon_queue_length gauge
horizon_queue_length{queue="high",connection="redis",group="high,default"} 12
```

## Install

```bash
composer require boring-o11y/horizon-prometheus-exporter
```

Then turn it on. Do this on every machine running the application, **including the ones running Horizon**: the web servers serve the endpoint, and the workers keep the counters it reads.

```dotenv
HORIZON_PROMETHEUS_ENABLED=true
```

The endpoint is at `/horizon/prometheus`, or wherever `horizon.path` has moved the dashboard. It is on the dashboard's domain too, if `horizon.domain` sets one.

To change anything else:

```bash
php artisan vendor:publish --tag=horizon-prometheus-config
```

## Access

A Prometheus server has no user session, so the endpoint is not behind Horizon's `viewHorizon` gate. An IP allowlist protects it instead. By default only loopback can scrape it:

```dotenv
HORIZON_PROMETHEUS_ALLOWED_IPS=127.0.0.1,::1,10.0.0.0/8
```

Entries may be exact addresses or CIDR ranges, and a single `*` allows any address. The allowlist checks the address Laravel sees. Behind a load balancer or reverse proxy, including nginx in front of php-fpm on the same host, every request comes from the proxy, so configure your trusted proxies. Treat the allowlist as one layer of access control, not all of it. You can add your own middleware under `middleware` in the config.

## Scrape config

```yaml
scrape_configs:
  - job_name: horizon
    metrics_path: /horizon/prometheus
    static_configs:
      - targets: ['app.internal:80']
```

Scrape **one** web server, not all of them. Every instance reads the same Redis, so scraping several returns identical series.

## Metrics

All names start with `horizon_` (set `HORIZON_PROMETHEUS_PREFIX` to change it).

| Metric | Type | Labels |
|---|---|---|
| `job_processed_total`, `queue_processed_total` | counter | `job_class` / `queue` |
| `job_failed_total`, `queue_failed_total` | counter | `job_class` / `queue` |
| `job_retried_total`, `queue_retried_total` | counter | `job_class` / `queue` |
| `job_wait_seconds`, `queue_wait_seconds` | summary (`_sum`, `_count`) | `job_class` / `queue` |
| `job_runtime_seconds`, `queue_runtime_seconds` | gauge | `job_class` / `queue` |
| `queue_length` | gauge | `queue`, `connection`, `group` |
| `queue_oldest_pending_seconds` | gauge | `queue`, `connection`, `group` |
| `queue_time_to_clear_seconds` | gauge | `queue`, `connection`, `group` |
| `queue_processes` | gauge | `queue`, `connection`, `group` |
| `queue_paused` | gauge | `queue`, `connection`, `group` (on Laravel versions with `queue:pause`) |
| `jobs` | gauge | `status` (pending, completed, failed, silenced) |
| `recent_jobs`, `recent_failed_jobs` | gauge | |
| `supervisor_processes` | gauge | `supervisor`, `connection`, `group` |
| `supervisor_paused` | gauge | `supervisor` |
| `master_supervisor_paused` | gauge | `master` |
| `processes`, `up`, `info`, `scrape_duration_seconds` | gauge | |

Things to know about the labels:

- **Job classes are labelled `job_class`, not `job`.** Prometheus reserves `job` for the scrape config's `job_name`, and a target's own `job` label would come back renamed to `exported_job`.
- **Every `queue` label names one queue.** A supervisor that balances `high,default` as one pool is exported as two queues, each labelled with `group="high,default"`, so they line up with the per-queue counters. `queue_processes` repeats the pool's size on each of its queues, so use `max by (group)` before summing across the fleet. `queue_time_to_clear_seconds` is each queue's share of the pool's estimate, so `sum by (group)` gives the pool's total.
- **One queue can belong to two pools.** In that case it has two series, so collapse them with `max by (queue, connection)` before summing.

Useful queries:

```promql
# jobs per minute
sum(rate(horizon_queue_processed_total[5m])) * 60

# average wait per queue over the last five minutes
sum by (queue) (rate(horizon_queue_wait_seconds_sum[5m]))
  / sum by (queue) (rate(horizon_queue_wait_seconds_count[5m]))

# failure ratio per job class
sum by (job_class) (rate(horizon_job_failed_total[15m]))
  / (sum by (job_class) (rate(horizon_job_processed_total[15m])) + sum by (job_class) (rate(horizon_job_failed_total[15m])))
```

There is no jobs-per-minute gauge. When Horizon reads its own jobs-per-minute figure and finds no stored timestamp, it writes one, which would shift the window the dashboard measures against. A scrape should never write, so use `rate()` for that figure instead.

## How the counters are kept

Horizon's own metrics are windows: every `horizon:snapshot` reads them and resets them. A counter built from them would drop to zero every few minutes, and Prometheus reads a counter going down as a restart. So the package keeps its own counters, which only ever go up. They live on Horizon's Redis connection under `prometheus:`, one hash per metric keyed by job class or queue:

- **processed**: Horizon's `JobDeleted` event, for jobs that did not fail. This is the same event and the same check Horizon's throughput figure uses.
- **failed**: Horizon's `JobFailed` event.
- **retried**: Laravel's `JobReleasedAfterException`. A job that calls `$this->release()` itself (for rate limiting, polling or waiting on a lock) is not counted as a retry.
- **wait**: Horizon's `JobReserved` event. The wait is measured from the `updated_at` Horizon records on the job each time it becomes ready: when it is pushed, released without a delay, or migrated off a backoff. So it is the wait for this attempt, not the time since the job was first dispatched. It has to be read before Horizon's own listener overwrites the field, so the package registers its listener ahead of Horizon's.

Each outcome costs one Redis round trip in the worker, and each pickup costs one more for the wait.

Runtime is Horizon's own moving average, the figure on its metrics screen. When the current window has recorded nothing yet, the last snapshot's value is used, so the gauge doesn't drop to zero after every snapshot.

To reset the counters:

```bash
php artisan horizon-prometheus:clear
```

`horizon:clear-metrics` does not reset them, on purpose. It clears the dashboard's graphs, and a long-running Prometheus series has no reason to reset with them.

## Grafana

```bash
php artisan vendor:publish --tag=horizon-prometheus-grafana
```

This copies `resources/grafana/horizon-dashboard.json` into your application, ready to import into Grafana. It has an overview, per-queue and per-job-class rows, and supervisors.

## Requirements

PHP 8.4+, Laravel 10–13, Horizon 5.24+.

## Testing

```bash
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm -e REDIS_CLIENT=predis app vendor/bin/phpunit
```

## License

MIT
