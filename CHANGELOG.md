# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.4] - 2026-07-13

### Added

- **`Cache_Cozy_Tick` node_schema arguments now carry descriptions** — `interval_seconds`, `path`, `cold_groups` — so the topology console shows a tooltip for each (consuming the substrate's `CtorField` tooltip wiring). A `NodeSchemaArgumentDescriptionsTest` gate fails if any argument lacks one.

## [0.2.3] - 2026-07-10

**Requires newspack-nodes ≥ 0.34.0** (the release carrying the `Core` coercion-helper family).

### Changed

- **`Cache_Cozy_Tick_Node`'s three read coercions fold onto the substrate's new `\Newspack_Nodes\Core` helpers** — the handler filter-map read onto `arr()`, the cold-groups read onto `str()`, and the stale-job interval read onto `num_int( …, self::INTERVAL_SECONDS )` — same semantics, defined once in the substrate this plugin already depends on.

## [0.2.2] - 2026-07-07

### Security

- **Direct-access guard on the first-party PHP files that lacked it.** Added `\defined( 'ABSPATH' ) || exit;` so no plugin PHP file runs on a direct web hit. (`uninstall.php` keeps its stricter `WP_UNINSTALL_PLUGIN` guard.)

## [0.2.1] - 2026-07-02

### Added

- **Uninstall cleanup.** Deleting the plugin now removes every `newspack_cache_cozy_` option row (the mu-plugin drop-in's `newspack_cache_cozy_secret` / `newspack_cache_cozy_auth`) and their transient variants, via a prefix-based `uninstall.php`. It runs only on delete (`WP_UNINSTALL_PLUGIN`), never on deactivate; previously these options were orphaned in the database on uninstall. Prefix-based so it stays complete as options come and go and catches `autoload=off` rows a hardcoded list would miss.

## [0.2.0] - 2026-06-12

### Added

- **`Cache_Cozy_Tick` warms an arbitrary path with an optional per-tick cold-group override.** The node now takes positional `make_node` args via the `Schema_Reflection` trait — `<interval_seconds> <path> <cold_groups>` (e.g. `make_node Cache_Cozy_Tick cache-cozy:tick 60 /category/news newspack_blocks,transient`). `path` and `cold_groups` thread through the enqueued `cache_cozy` job into the loopback: the warmer hits `home_url($path)` and, when groups are supplied, passes them as a secret-gated `cache_cozy_groups` query param that overrides the global `cold_groups()` for that render. Args are positional (interval first); omit them all for the previous default (60s, homepage, configured groups).

### Changed

- `Cache_Cozy_Tick_Node::arguments()` now parses via `parse_schema_args()` (the schema is the single source of truth for the arg list) instead of bespoke numeric-only validation; a non-numeric first token is coerced per the declared `int` type rather than throwing.

## [0.1.1] - 2026-06-12

### Fixed

- **`Cache_Cozy_Tick_Node` enqueues its `cache_cozy` job keyed by `k`, not `type`.** The substrate `Job_Worker_Node` now dispatches on the entry-level `k` field (the canonical jobs.log job-kind discriminator); the tick node hand-built its job entry with `type`, so its warm jobs would be dropped before reaching the handler. Now writes `'k' => 'job'`. Requires `newspack-nodes` with the `k`-reading `Job_Worker_Node`.

## [0.1.0] - 2026-06-11

### Added

- **Initial release — extracted from `newspack-event-logger-nodes`.** A focused, standalone refresh-ahead cache warmer for the `newspack-nodes` substrate:
  - **`Cache_Cozy_Tick_Node`** (`includes/`) — a `Timer_Node` that hitchhikes the substrate's `_router` heartbeat and enqueues a `cache_cozy` job every `INTERVAL_SECONDS` (default 60s, overridable via a numeric `make_node` arg). It registers the handler on the substrate's `newspack_nodes/job_handlers` filter; the handler drops jobs older than one interval and fires the warmer's single-flight loopback. Add it to any topology with a single line: `make_node Cache_Cozy_Tick cache-cozy:tick`.
  - **`01-newspack-cache-cozy.php`** (mu-plugin drop-in) — the self-contained warmer: on its own secret-gated loopback hit it swaps in a `Cold_Read_Object_Cache` decorator so reads on allowlisted "cold" groups (block cache, transients) miss and rebuild straight into live cache, while every write passes through. Carries its own 60s cron recurrence, an encrypted-at-rest basic-auth credential for edge-cache bypass, a single-flight lock, and stats-exclusion tagging. No dependency on any plugin.
- Requires the `newspack-nodes` substrate (`Requires Plugins: newspack-nodes`) and PHP 8.2. Runtime wiring is deferred to `plugins_loaded` priority 11 (alphabetical load order) and gated on a `class_exists` substrate-presence check.
- **Operator scripts `bin/schedule-cache-cozy.sh` and `bin/unschedule-cache-cozy.sh`** (`bin/` is excluded from the release zip). The schedule script schedules the `newspack_cache_cozy_tick` cron and optionally provisions the encrypted at-rest loopback credential via `\Newspack_Cache_Cozy\Cache_Cozy::store_auth()` (reading the plaintext off stdin so it never lands in `ps`); the unschedule script removes the cron event plus the secret/auth options and the lock transient.
- Cold cache groups are configurable via the `NEWSPACK_CACHE_COZY_COLD_GROUPS` wp-config constant (defaults cover the Newspack block cache and transients).
