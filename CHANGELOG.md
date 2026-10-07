# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A failing counter write in a worker is reported instead of thrown, so it can no longer leave a job reserved or skip Horizon's own listeners.
- Queues in Horizon's configuration are exported while no supervisor is running, so backlog alerts still fire when every worker is down.
- A job whose Horizon hash has expired is measured from when it was pushed, so the longest waits are no longer left out of the wait summary.
- Retries are only counted for Horizon's Redis jobs, under the same queue name as the other counters.
- The oldest pending age reads the right key on a Redis Cluster under newer Laravel versions.
- `horizon_up` reports 0 when a custom repository returns no master supervisors as a generator or collection.
- An empty `HORIZON_PROMETHEUS_PREFIX` falls back to `horizon` instead of exporting series that collide with Prometheus's own `up` and `scrape_duration_seconds`.

### Changed

- The time to clear is computed from the queue length and runtime already read for the scrape, instead of reading both again per queue.

### Removed

- Support for PHP 8.1, 8.2 and 8.3. The package now requires PHP 8.4 or later, and CI runs on PHP 8.4 and 8.5.
