# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-11

### Added

- **Initial release — extracted from `newspack-event-logger-nodes`.** A focused, standalone refresh-ahead cache warmer for the `newspack-nodes` substrate:
  - **`Cache_Cozy_Tick_Node`** (`includes/`) — a `Timer_Node` that hitchhikes the substrate's `_router` heartbeat and enqueues a `cache_cozy` job every `INTERVAL_SECONDS` (default 60s, overridable via a numeric `make_node` arg). It registers the handler on the substrate's `newspack_nodes/job_handlers` filter; the handler drops jobs older than one interval and fires the warmer's single-flight loopback. Add it to any topology with a single line: `make_node Cache_Cozy_Tick cache-cozy:tick`.
  - **`01-newspack-cache-cozy.php`** (mu-plugin drop-in) — the self-contained warmer: on its own secret-gated loopback hit it swaps in a `Cold_Read_Object_Cache` decorator so reads on allowlisted "cold" groups (block cache, transients) miss and rebuild straight into live cache, while every write passes through. Carries its own 60s cron recurrence, an encrypted-at-rest basic-auth credential for edge-cache bypass, a single-flight lock, and stats-exclusion tagging. No dependency on any plugin.
- Requires the `newspack-nodes` substrate (`Requires Plugins: newspack-nodes`) and PHP 8.2. Runtime wiring is deferred to `plugins_loaded` priority 11 (alphabetical load order) and gated on a `class_exists` substrate-presence check.
- **Operator scripts `bin/schedule-cache-cozy.sh` and `bin/unschedule-cache-cozy.sh`** (`bin/` is excluded from the release zip). The schedule script schedules the `newspack_cache_cozy_tick` cron and optionally provisions the encrypted at-rest loopback credential via `\Newspack_Cache_Cozy\Cache_Cozy::store_auth()` (reading the plaintext off stdin so it never lands in `ps`); the unschedule script removes the cron event plus the secret/auth options and the lock transient.
- Cold cache groups are configurable via the `NEWSPACK_CACHE_COZY_COLD_GROUPS` wp-config constant (defaults cover the Newspack block cache and transients).
