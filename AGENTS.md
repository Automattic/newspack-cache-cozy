# AGENTS.md — Newspack Cache Cozy

A small standalone WordPress plugin on the **newspack-nodes** substrate: a refresh-ahead cache warmer that keeps the homepage's caches hot out-of-band so no visitor pays the cold render. Extracted from `newspack-event-logger-nodes`, it doubles as the reference *minimal node-consuming plugin*.

Two pieces, one job:

- **`Cache_Cozy_Tick_Node`** (`includes/class-cache-cozy-tick-node.php`) — the substrate side. A `Newspack_Nodes\Timer_Node` hitchhiking the `_router` heartbeat, enqueueing a `cache_cozy` job every `INTERVAL_SECONDS` (default 60s). It registers the handler on `newspack_nodes/job_handlers`; the handler drops jobs older than one interval and calls the warmer. One line adds it to a topology: `make_node Cache_Cozy_Tick cache-cozy:tick`.
- **`01-newspack-cache-cozy.php`** (`mu-plugins/`) — the warmer itself, a self-contained drop-in (`Newspack_Cache_Cozy\Cache_Cozy` + `Cold_Read_Object_Cache`) depending on **no plugin**. On a secret-gated loopback it swaps the object cache for a cold-read decorator so allowlisted groups rebuild into live cache. Ships as a separate release asset, installed under `wp-content/mu-plugins/`, not `wp-content/plugins/`.

The tick node's `handle_job` calls `\Newspack_Cache_Cozy\Cache_Cozy::run_tick()`, and no-ops loudly when the drop-in is missing. The halves are independent: the drop-in warms on its own cron with no substrate, and the tick node makes the cadence reliable (worker drain loop, not contended wp-cron).

## Substrate dependency

Hard-depends on **newspack-nodes** (plugin header `Requires Plugins: newspack-nodes`, which keeps the substrate active on WP 6.5+). WordPress loads plugins alphabetically and `newspack-cache-cozy` sorts before `newspack-nodes`, so the substrate is absent at file-load time; `newspack-cache-cozy.php` therefore defers its runtime wiring (namespace registration + `Cache_Cozy_Tick_Node::init()`) to `plugins_loaded` priority 11, gated on `class_exists( '\Newspack_Nodes\Timer_Node' )` so it no-ops when the substrate is inactive. Tests bypass this, requiring the substrate explicitly in `tests/bootstrap.php`.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Before every commit, main Claude runs `/code-review`.** Subagents do NOT commit.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist`): `snake_case`, Yoda conditions, `[]` arrays, tab indentation, spaces inside parens, PHP 8.2+. PHPStan level 10 + `phpstan-strict-rules` (`phpstan.neon.dist`). Conventional commits. One-line code comments.

## Build / Test

```bash
composer install          # vendor/ (phpunit, phpcs, phpstan) + points git at scripts/ for hooks

cd tests && ../vendor/bin/phpunit --enforce-time-limit   # unit tests
npm run lint:php          # phpcs
npm run lint:phpstan      # phpstan (needs ../newspack-nodes checked out as a sibling)
```

After adding or renaming a Node class, regenerate the classmap that `make_node` and the console palette read: `composer build:autoloaders` (= `composer install --optimize-autoloader`) or `composer dump-autoload -o`.

PHPStan resolves substrate symbols (`Timer_Node`, `Core`, `Message`) via `scanDirectories: ../newspack-nodes/includes`.

### Git hooks

Hooks are the tracked files in `scripts/` (`pre-commit`, `commit-msg`, `pre-push`),
reached via `core.hooksPath`, which `composer install` sets:

```bash
git config core.hooksPath scripts    # what composer's post-install-cmd runs
```

A clone that has never run `composer install` has no hooks. `pre-commit` first
runs `scripts/sync-shared-scripts.sh`, which refreshes this plugin's copy of the
shared tooling from `../newspack-nodes/scripts/` when that sibling is checked
out — edit shared scripts THERE, not here.

## Versioning & Release

The version lives in three places: the `Version:` header and `NEWSPACK_CACHE_COZY_VERSION` constant in `newspack-cache-cozy.php`, and `"version"` in `package.json`. Never hand-edit them — `./scripts/bump-version.sh` rewrites all three.

GitHub Actions automates releases (`.github/workflows/release.yml`): pushing a `v<major>.<minor>.<patch>` tag runs `npm run release:archive` (= `build-release.sh`), extracts the matching `CHANGELOG.md` section as the notes, and publishes the GitHub Release with **both** `release/newspack-cache-cozy.zip` and the `release/01-newspack-cache-cozy.php` mu-plugin drop-in attached. You only bump, changelog, commit, tag, push.

## Layout

| Path | What |
|------|------|
| `newspack-cache-cozy.php` | Plugin entry: constants, autoload, deferred loader (`plugins_loaded` @ 11, `class_exists` presence-gated) registering the namespace + `Cache_Cozy_Tick_Node::init()` |
| `includes/class-cache-cozy-tick-node.php` | `Cache_Cozy_Tick_Node` — Timer subclass; enqueues `cache_cozy` jobs + the job handler |
| `mu-plugins/01-newspack-cache-cozy.php` | The self-contained warmer drop-in — separate release asset |
| `bin/` | Operator scripts (excluded from the release zip): `schedule-cache-cozy.sh` schedules the tick and optionally stores the loopback credential via `Cache_Cozy::store_auth`, reading it off stdin; `unschedule-cache-cozy.sh` deletes the cron event, secret/auth options, and lock transient |
| `tests/` | PHPUnit suite (`CacheCozyTickTest`, `CacheCozyTest`, `ColdReadObjectCacheTest`); `bootstrap.php` loads the sibling substrate + WP stubs |
| `build-release.sh` / `.distignore` | Build the plugin zip + copy the mu-plugin drop-in to `release/` |
| `scripts/pre-push` | Lint + (when a dev container is present) deploy + phpunit gate |

## References

- **Substrate**: `../newspack-nodes/` — the runtime this plugin depends on
- **Build-a-plugin guide**: `../newspack-nodes/WRITING-A-PLUGIN.md` — cache-cozy is the minimal worked example
