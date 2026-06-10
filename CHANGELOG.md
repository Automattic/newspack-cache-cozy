# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Operator scripts `bin/schedule-cache-cozy.sh` and `bin/unschedule-cache-cozy.sh`** (ported from the legacy event-logger cache-warmer; `bin/` is excluded from the release zip). The schedule script schedules the `newspack_cache_cozy_tick` cron and optionally provisions the encrypted at-rest loopback credential via `\Newspack_Cache_Cozy\Cache_Cozy::store_auth()` (reading the plaintext off stdin so it never lands in `ps`) — restoring the provisioning entry point the initial extraction dropped, where only the `NEWSPACK_CACHE_COZY_AUTH` constant worked. The unschedule script removes the cron event plus the secret/auth options and the lock transient.

### Changed

- Configure Cache Cozy's cold cache groups with the `NEWSPACK_CACHE_COZY_COLD_GROUPS` wp-config constant instead of the removed `newspack_cache_cozy_cold_groups` filter.
- Raise Cache Cozy's declared PHP floor to 8.2, matching its required `newspack-nodes` substrate, and remove obsolete PHPUnit reflection accessibility calls that PHP 8.5 deprecates.

## [0.1.0] - 2026-06-10

### Added

- **Initial release — extracted from `newspack-event-logger-nodes`.** A focused, standalone refresh-ahead cache warmer for the `newspack-nodes` substrate:
  - **`Cache_Cozy_Tick_Node`** (`includes/`) — a `Timer_Node` that hitchhikes the substrate's `_router` heartbeat and enqueues a `cache_cozy` job every `INTERVAL_SECONDS` (default 60s, overridable via a numeric `make_node` arg). It registers the handler on the substrate's `newspack_nodes/job_handlers` filter; the handler drops jobs older than one interval and fires the warmer's single-flight loopback. Add it to any topology with a single line: `make_node Cache_Cozy_Tick cache-cozy:tick`.
  - **`01-newspack-cache-cozy.php`** (mu-plugin drop-in) — the self-contained warmer: on its own secret-gated loopback hit it swaps in a `Cold_Read_Object_Cache` decorator so reads on allowlisted "cold" groups (block cache, transients) miss and rebuild straight into live cache, while every write passes through. Carries its own 60s cron recurrence, an encrypted-at-rest basic-auth credential for edge-cache bypass, a single-flight lock, and stats-exclusion tagging. No dependency on any plugin.
- Requires the `newspack-nodes` substrate (`Requires Plugins: newspack-nodes`). Runtime wiring is deferred to `plugins_loaded` priority 11 (alphabetical load order) and gated on a `class_exists` substrate-presence check.
