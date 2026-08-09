# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.7] - 2026-08-09

### Changed

- Generic types in docblocks carry no space after the comma, matching the other
  plugins. cache-cozy was not in the original sweep, so syncing the rule
  surfaced real spaced types here; the mu-plugin also takes the canonical method
  order the gate wanted once it was staged.

- Shared tooling synced from newspack-nodes: `collapse_generics` no longer
  rewrites prose whose angle brackets balance, nor lets an unbalanced `<` hide a
  real generic from the gate.

- **Blank-line runs are collapsed on commit.** `scripts/fix-blank-lines.php`
  joins the shared tooling and runs in `lint-staged` after the comment gate. It
  is token-aware: heredoc and string bodies keep their blank lines.

## [0.4.6] - 2026-08-06

### Changed

- **The shared comment gate is now `scripts/lint-comments.{php,mjs}`** (was
  `lint-comment-length`), because the PHP half no longer checks only length: at
  class-body level the only comment allowed is a docblock immediately preceding
  its declaration. Section headers, `//` notes where a docblock belongs, and
  docblocks whose method was deleted are all rejected. Comments inside a
  class-level initializer annotate their entry and stay exempt. Existing
  violations in this plugin are cleaned up here; no behavior changes.

## [0.4.5] - 2026-08-04

### Changed

- The newspaper-order method gate (`reorder-node-methods --check`) runs in
  lint-staged, matching the substrate. Tooling only; no runtime change.

### Fixed

- Dropped the vendored `test-reorder-node-methods.sh`. This plugin has no
  `src/`, so it never received the `reorder-node-methods.js` twin that most of
  that suite shells out to — the tests could only fail, and nothing ran them.
  `sync-shared-scripts.sh` skips the test wherever it already skips the twin.

## [0.4.4] - 2026-07-31

### Changed

- **`lint-docs.sh` is a shared pre-push gate.** The doc-drift lint was
  substrate-only; it now ships to every plugin via `sync-shared-scripts.sh` and
  runs from each `pre-push`. It caught three `make_node` examples in
  event-logger-nodes documenting a retention arg list the shipped topology never
  passes.


## [0.4.3] - 2026-07-31

### Changed

- **The vendored `reorder-node-methods` tooling now passes the comment-length
  gate.** Function-level prose moved into docblocks, inline prose condensed to
  one line; four algorithm notes that genuinely need the length carry
  `@longform`. No behavior change — the tool's own test still passes 38/38.


## [0.4.2] - 2026-07-31

### Added

- **Vendored copies of the substrate's shared tooling** (`scripts/bump-version.sh`
  + `scripts/lib/`, `reorder-node-methods`, the coverage and comment-length
  gates, `pre-commit`, `commit-msg`), so a standalone clone works without a
  sibling checkout. `scripts/sync-shared-scripts.sh` refreshes them from
  `../newspack-nodes/scripts/` on each `pre-commit` when that sibling exists —
  edit shared scripts THERE, not here.
- **`scripts/commit-msg`** — the conventional-commit gate, now a tracked hook.
  It skips cleanly where commitlint isn't installed.

### Changed

- **Git hooks come from `core.hooksPath`, not `.git/hooks`.** `composer install`
  now points git at `scripts/`, so the hooks are version controlled and reviewed
  with the code they gate. A clone that has never run `composer install` has no
  hooks at all.
- `scripts/bump-version.sh` replaces dndocker's `tools/bump-cache-cozy-version.sh`.
  Behavior is unchanged; the shared flow lives in `scripts/lib/bump-version.sh`
  and the wrapper is only the per-plugin knobs.

### Removed

- `brainmaestro/composer-git-hooks` — `core.hooksPath` does the job with no
  dependency, and cghooks-installed `.git/hooks` files are now dead files git
  ignores.


## [0.4.1] - 2026-07-31

### Changed

- The comment-length lint scans the whole tree rather than only staged files,
  so an unstaged violation can no longer reach a commit.

## [0.4.0] - 2026-07-24

### Added
- Substrate version handshake at boot: on a substrate older than 0.54.0 the
  plugin goes dormant with an admin notice (via the substrate's
  `Bootstrap::version_at_least()`) instead of fataling on a missing API.
  A substrate predating the handshake API (nodes < 0.54.0) also parks the
  plugin — the stack deploys as a unit, so a missing API means too old.

## [0.3.1] - 2026-07-16

### Changed

- **`Cache_Cozy_Tick_Node::handle_job()` now requires the full job envelope.** The sole producer (`fire()`) always emits `{queued_at, interval, path, cold_groups}`; the handler dropped its `?? default` acceptance of old/incomplete envelopes and now drops (with a rate-limited notice) any job missing a required field instead of warming with guessed defaults. No live dead-letter segments carry the old shape.

## [0.3.0] - 2026-07-16

### Changed

- **Migrated to the newspack-nodes token-array command contract.** `Cache_Cozy_Tick_Node::arguments()` now takes and returns a token array (`list<string>` argv) instead of a joined string, matching the substrate change (`<interval_seconds> <path> <cold_groups>` arrive as discrete tokens).

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
