# AGENTS.md — Newspack Cache Cozy

A small, standalone WordPress plugin that builds on the **newspack-nodes** substrate: a refresh-ahead cache warmer that keeps the homepage's caches hot out-of-band so no visitor pays the cold render. Extracted from `newspack-event-logger-nodes`; it is also the reference for a *minimal node-consuming plugin* (see `newspack-nodes/WRITING-A-PLUGIN.md`).

Two pieces, one job:

- **`Cache_Cozy_Tick_Node`** (`includes/class-cache-cozy-tick-node.php`) — the substrate side. A `Newspack_Nodes\Timer_Node` that hitchhikes the `_router` heartbeat and enqueues a `cache_cozy` job every `INTERVAL_SECONDS` (default 60s). It registers the handler on `newspack_nodes/job_handlers`; the handler drops stale jobs (older than one interval) and calls the warmer. Add it to a topology with one line: `make_node Cache_Cozy_Tick cache-cozy:tick`.
- **`01-newspack-cache-cozy.php`** (`mu-plugins/`) — the warmer itself, a self-contained drop-in (`Newspack_Cache_Cozy\Cache_Cozy` + `Cold_Read_Object_Cache`). It has **no dependency on any plugin** — on a secret-gated loopback it swaps the object cache for a cold-read decorator so allowlisted groups rebuild into live cache. Ships as a separate release asset; installs under `wp-content/mu-plugins/`, not `wp-content/plugins/`.

The tick node's `handle_job` calls `\Newspack_Cache_Cozy\Cache_Cozy::run_tick()`; if the drop-in isn't installed it no-ops loudly. The two halves are independent — the drop-in warms on its own cron even with no substrate; the tick node makes the cadence reliable (worker drain loop, not contended wp-cron).

## Substrate dependency

Hard-depends on **newspack-nodes** (plugin header `Requires Plugins: newspack-nodes`, which keeps the substrate active on WP 6.5+). `newspack-cache-cozy.php` defers its runtime wiring (namespace registration + `Cache_Cozy_Tick_Node::init()`) to `plugins_loaded` priority 11 — WordPress loads plugins alphabetically and `newspack-cache-cozy` sorts before `newspack-nodes`, so the substrate isn't loaded at file-load time. The deferred callback is gated on a `class_exists( '\Newspack_Nodes\Timer_Node' )` presence check (graceful no-op if the substrate isn't active). Tests bypass this — they require the substrate explicitly in `tests/bootstrap.php`.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Before every commit, main Claude runs `/code-review`.** Subagents do NOT commit.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist`): `snake_case`, Yoda conditions, `[]` arrays, tab indentation, spaces inside parens, PHP 8.2+. PHPStan level 10 + `phpstan-strict-rules` (`phpstan.neon.dist`). Conventional commits. One-line code comments.

## Build / Test

```bash
composer install          # vendor/ (phpunit, phpcs, phpstan) + installs git hooks via cghooks

cd tests && ../vendor/bin/phpunit --enforce-time-limit   # unit tests
npm run lint:php          # phpcs
npm run lint:phpstan      # phpstan (needs ../newspack-nodes checked out as a sibling)
```

PHPStan resolves substrate symbols (`Timer_Node`, `Core`, `Message`) via `scanDirectories: ../newspack-nodes/includes` — the substrate must be checked out alongside this plugin to lint/analyze.

## Versioning & Release

Version lives in three places: the `Version:` header + `NEWSPACK_CACHE_COZY_VERSION` constant in `newspack-cache-cozy.php`, and `"version"` in `package.json`. Don't hand-edit — the dndocker `tools/bump-cache-cozy-version.sh` rewrites all three.

Releases are automated by GitHub Actions (`.github/workflows/release.yml`): pushing a `v<major>.<minor>.<patch>` tag runs `npm run release:archive` (= `build-release.sh`), extracts the matching `CHANGELOG.md` section as the notes, and publishes the GitHub Release with **both** `release/newspack-cache-cozy.zip` and the `release/01-newspack-cache-cozy.php` mu-plugin drop-in attached. You only bump, changelog, commit, tag, push.

## Layout

| Path | What |
|------|------|
| `newspack-cache-cozy.php` | Plugin entry: constants, autoload, deferred loader (`plugins_loaded` @ 11, `class_exists` presence-gated) that registers the namespace + `Cache_Cozy_Tick_Node::init()` |
| `includes/class-cache-cozy-tick-node.php` | `Cache_Cozy_Tick_Node` — Timer subclass; enqueues `cache_cozy` jobs + the job handler |
| `mu-plugins/01-newspack-cache-cozy.php` | The self-contained warmer drop-in (`Cache_Cozy` + `Cold_Read_Object_Cache`) — separate release asset |
| `tests/` | PHPUnit suite (`CacheCozyTickTest`, `CacheCozyTest`, `ColdReadObjectCacheTest`, `SubstrateGuardTest`); `bootstrap.php` loads the sibling substrate + WP stubs |
| `build-release.sh` / `.distignore` | Build the plugin zip + copy the mu-plugin drop-in to `release/` |
| `scripts/pre-push` | Lint + (when a dev container is present) deploy + phpunit gate |

## References

- **Substrate**: `../newspack-nodes/` — the runtime this plugin depends on
- **Build-a-plugin guide**: `../newspack-nodes/WRITING-A-PLUGIN.md` — cache-cozy is the minimal worked example
