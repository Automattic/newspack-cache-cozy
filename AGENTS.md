# AGENTS.md — Newspack Cache Cozy

A small standalone WordPress plugin on the **newspack-nodes** substrate: a refresh-ahead cache warmer that keeps the homepage's caches hot out-of-band so no visitor pays the cold render. The substrate's own guide names it the minimal, fully-rigged *node-consuming plugin* — one node plus a drop-in, carrying each release essential as a real file to copy.

Two pieces, one job:

- **`Cache_Cozy_Tick_Node`** (`includes/class-cache-cozy-tick-node.php`) — the substrate side. A `Newspack_Nodes\Timer_Node` hitchhiking the `_router` heartbeat, enqueueing a `cache_cozy` job every `INTERVAL_SECONDS` (default 60). It registers the handler on `newspack_nodes/job_handlers`; the handler drops malformed jobs, drops jobs already a full interval old, and calls the warmer. One line adds it to a topology: `make_node Cache_Cozy_Tick cache-cozy:tick`.
- **`01-newspack-cache-cozy.php`** (`mu-plugins/`) — the warmer itself, a self-contained drop-in (`Newspack_Cache_Cozy\Cache_Cozy` + `Cold_Read_Object_Cache`) depending on **no plugin**. On a secret-gated loopback it swaps the object cache for a cold-read decorator so allowlisted groups rebuild into live cache. Ships as a separate release asset, installed under `wp-content/mu-plugins/`, not `wp-content/plugins/`.

The tick node's `handle_job` calls `\Newspack_Cache_Cozy\Cache_Cozy::run_tick()`, and no-ops loudly when the drop-in is missing. The halves are independent: the drop-in warms on its own cron with no substrate, and the tick node makes the cadence reliable (worker drain loop, not contended wp-cron).

## Substrate dependency

Hard-depends on **newspack-nodes**, in two layers.

The plugin header `Requires Plugins: newspack-nodes` keeps the substrate active on WP 6.5+, but says nothing about which version. WordPress loads plugins alphabetically and `newspack-cache-cozy` sorts before `newspack-nodes`, so the substrate is absent at file-load time; `newspack-cache-cozy.php` therefore defers its runtime wiring (namespace registration + `Cache_Cozy_Tick_Node::init()`) to `plugins_loaded` priority 11, behind two gates:

1. `class_exists( '\Newspack_Nodes\Timer_Node' )` — no-op when the substrate is inactive.
2. `\Newspack_Nodes\Bootstrap::version_at_least( '0.54.0', 'Newspack Cache Cozy' )` — the version handshake. Too old and the plugin stays dormant while the substrate raises an admin notice naming both versions. The `method_exists` guard ahead of it covers substrates predating the notice API.

Tests bypass the whole loader, requiring the substrate explicitly in `tests/bootstrap.php`.

`scripts/check-substrate-floor.sh` proves that floor is high enough: PHPStan collects every substrate API this plugin calls, resolved to its declaring class, and the script walks the substrate's tags for the earliest one where all of them exist. A floor set too LOW is the failure it exists for — the handshake passes, the plugin wires itself up, and then fatals on a method the older substrate lacks. No hook here runs it: neither `pre-commit` nor `pre-push` invokes it, so after calling a newer substrate API you run it by hand or nothing checks the floor at all. What `pre-push` does run is `scripts/lint-docs.sh`, which fails the push when prose names a floor the loader does not enforce.

## Workflow discipline (mandatory)

Every code-writing turn — main Claude AND every subagent — MUST:

1. **Invoke `superpowers:test-driven-development` BEFORE writing any code.** No production code without a failing test first.
2. **Before every commit, main Claude runs `/code-review`.** Subagents do NOT commit.

## Code Style

WordPress VIP Go (enforced by `phpcs.xml.dist` over `includes/`, `mu-plugins/` and the plugin root): `snake_case`, Yoda conditions, `[]` arrays, tab indentation, spaces inside parens, PHP 8.2+. `VariableAnalysis` is re-raised to an error for unused locals, which VIP Go silences and PHPStan cannot see at any level.

PHPStan runs level 10 plus `phpstan-strict-rules` from `phpstan.neon.dist`, with the same four WordPress-idiom exemptions the substrate config carries (`disallowedEmpty`, `booleansInConditions`, `booleansInLoopConditions`, `disallowedShortTernary`) so a node-consuming plugin lints identically.

Conventional commits, enforced by `commitlint` from the `commit-msg` hook. Inline comments are one line and at most 80 columns; a comment whose full length is necessary opens with `@longform` on its first line. `scripts/lint-comments.php` and `scripts/lint-comments.mjs` are the two halves of that gate.

## Build / Test

```bash
composer install          # vendor/ (phpunit, phpcs, phpstan) + points git at scripts/ for hooks

cd tests && ../vendor/bin/phpunit --enforce-time-limit   # unit tests
cd tests && ./run-coverage.sh                            # + clover under $TEST_TMP
```

`package.json` declares six lint scripts. Every plugin declares the same names whether or not it owns that file type, so the shared hooks can call them uniformly: `pre-push` runs `lint:php`, `lint:js` and `lint:scss`, each scoped to the file types the push touched, and `lint-staged` runs `lint:phpstan` on staged PHP and `shellcheck` on staged shell.

| Script | Runs |
|---|---|
| `lint:php` | `phpcs`, then `php scripts/lint-comments.php .` |
| `lint:js` | `node scripts/lint-comments.mjs .`, then `node scripts/lint-contract.mjs` — this plugin ships no JS, so both gates read only the ADR contract and comment shape |
| `lint:scss` | `true` — a no-op placeholder; no SCSS here |
| `lint:shell` | `shellcheck scripts/pre-push scripts/*.sh` — the whole-tree pass; no hook calls it |
| `lint:phpstan` | `vendor/bin/phpstan analyse -c phpstan-deadcode.neon` — the dead-code layer, which `includes` `phpstan.neon.dist` |
| `lint:deadcode` | An alias for `lint:phpstan` |

`build`, `format`, `fix:js`, `fix:scss`, `test:js` and `test:js:coverage` are `true` for the same reason: names the shared hooks can call unconditionally. `fix:php` is `phpcbf`.

`phpstan-deadcode.neon` adds `shipmonk/dead-code-detector`, which composer's extension-installer deliberately does not auto-load. Its detector really loads the substrate's classes through `.phpstan/load-substrate.php`, because reflection-based usage providers invoke the actual autoloader — `scanDirectories` alone is not enough. Two `ignoreErrors` entries name what the substrate dispatches dynamically (`__construct`, `fill`, `fire`, `arguments`, `node_schema`, `dump_config`) and what WordPress dispatches through `$GLOBALS['wp_object_cache']` (every `Cold_Read_Object_Cache` method). **Anything outside those two lists is a real finding** — naming them rather than muting the rule is the point.

PHPStan resolves substrate symbols (`Timer_Node`, `Core`, `Message`) via `scanDirectories: ../newspack-nodes/includes`, and `NEWSPACK_NODES_VERSION` via `scanFiles: ../newspack-nodes/newspack-nodes.php`. Both need a sibling checkout.

After adding or renaming a Node class, regenerate the classmap that `make_node` and the console palette read: `composer build:autoloaders` (= `composer install --optimize-autoloader`) or `composer dump-autoload -o`.

### Git hooks

Hooks are the tracked files in `scripts/` (`pre-commit`, `commit-msg`, `pre-push`),
reached via `core.hooksPath`, which `composer install` sets:

```bash
git config core.hooksPath scripts    # what composer's post-install-cmd runs
```

A clone that has never run `composer install` has no hooks. `pre-commit` first
runs `scripts/sync-shared-scripts.sh`, which refreshes this plugin's copy of the
shared tooling from `../newspack-nodes/scripts/` when that sibling is checked
out — edit shared scripts THERE, not here. It then runs `lint-staged`: phpcs, the
node-method order check, `lint:phpstan`, the comment gate, and the blank-line fixer.

`pre-push` runs `lint-docs.sh` on every push, lints only the file types the push
touched, and — when the `<host>-pyrobase1-1` container is up — deploys, runs the
coverage suite inside it, and fails the push if any class falls below 90%
statement coverage. Without the container it skips those steps, which is the
right behavior for a standalone clone.

## Versioning & Release

The version lives in four places: the `Version:` header and `NEWSPACK_CACHE_COZY_VERSION` constant in `newspack-cache-cozy.php`, `"version"` in `package.json` (and its lock, via `npm version`), and the `Version:` header of the `mu-plugins/01-newspack-cache-cozy.php` drop-in. Never hand-edit them — `./scripts/bump-version.sh` rewrites all four, the drop-in through the shared flow's `bump_extra` hook, and `CacheCozyTest` fails if the plugin and drop-in headers ever disagree. The drop-in ships as its own asset and a site can hold a copy from anywhere, so a frozen header leaves nobody able to tell which one they have.

This plugin imports no `@newspack-nodes/*` build alias, so it carries no substrate pin in `release.yml` and has no release-ordering constraint.

GitHub Actions automates releases (`.github/workflows/release.yml`): pushing a tag matching `v<major>.<minor>.<patch>` exactly — a non-strict tag exits as a successful no-op — runs `npm run release:archive` (= `build-release.sh`), extracts the matching `CHANGELOG.md` section as the notes, and publishes the GitHub Release with **both** `release/newspack-cache-cozy.zip` and the `release/01-newspack-cache-cozy.php` mu-plugin drop-in attached. You only bump, changelog, commit, tag, push.

## Layout

| Path | What |
|------|------|
| `newspack-cache-cozy.php` | Plugin entry: constants, autoload, deferred loader (`plugins_loaded` @ 11, presence- and version-gated) registering the namespace + `Cache_Cozy_Tick_Node::init()` |
| `includes/class-cache-cozy-tick-node.php` | `Cache_Cozy_Tick_Node` — Timer subclass; enqueues `cache_cozy` jobs + the job handler |
| `includes/uninstall-cleanup.php` | `delete_prefixed_options()` + `uninstall_cleanup()` — prefix-based option deletion, multisite-aware. Kept out of the autoloader so it costs nothing at runtime |
| `uninstall.php` | Runs on plugin DELETE only (`WP_UNINSTALL_PLUGIN`), never deactivate; deletes every `newspack_cache_cozy_` option and transient row |
| `mu-plugins/01-newspack-cache-cozy.php` | The self-contained warmer drop-in — separate release asset |
| `bin/` | Operator scripts (excluded from the release zip): `schedule-cache-cozy.sh` stores the loopback credential via `Cache_Cozy::store_auth`, reading it off stdin so it never lands in `ps` — its wp-cron scheduling block is commented out, since the tick is driven from a topology; `unschedule-cache-cozy.sh` deletes the cron event, secret/auth options, and lock transient |
| `tests/` | PHPUnit suite: `CacheCozyTest` (the drop-in), `ColdReadObjectCacheTest` (the decorator), `CacheCozyTickTest` (the node), `NodeSchemaArgumentDescriptionsTest` (every schema argument carries the tooltip the console renders), `UninstallCleanupTest` (the option-cleanup seam); `bootstrap.php` loads the sibling substrate + WP stubs; `run-coverage.sh` runs the suite under xdebug and writes clover |
| `phpcs.xml.dist` / `phpstan.neon.dist` / `phpstan-deadcode.neon` | The three static-analysis configs; `.phpstan/load-substrate.php` real-loads substrate classes for the dead-code layer |
| `build-release.sh` / `.distignore` | Build the plugin zip + copy the mu-plugin drop-in to `release/` |
| `scripts/` | Git hooks + the tooling vendored from `../newspack-nodes/scripts/` |

## Runtime surface

Everything a site or an operator touches.

**Cache_Cozy_Tick_Node** — public constants `INTERVAL_SECONDS` (60) and `JOB_HANDLER` (`cache_cozy`). Positional topology arguments, parsed by `Schema_Reflection`: `<interval_seconds> <path> <cold_groups>`, defaulting to 60, `/` and the drop-in's own `cold_groups()`. `fire()` debounces the ~5s heartbeat down to the interval and mints one `TM_STRUCT` job message; `handle_job()` runs it in the Job Worker; `init()` / `register_handler()` wire the handler; `node_schema()` describes it to the topology console. It does not accept `fill` (`'accepts_fill' => false`).

**Hooks and filters:**

| Hook | Where | Why |
|---|---|---|
| `plugins_loaded` (11) | plugin entry | Deferred wiring, after the substrate loads |
| `newspack_nodes/job_handlers` | `Cache_Cozy_Tick_Node::init()` | Registers the `cache_cozy` handler |
| `newspack_cache_cozy_tick` | drop-in `register()` | Cron hook for `run_tick()`; NOT auto-scheduled |
| `cron_schedules` | drop-in `register()` | Adds the self-owned 60s `newspack_cache_cozy_minute` recurrence, so scheduling never depends on another plugin |
| `rest_request_before_callbacks` | drop-in `register()` | `trim_autosave_fields()` sets `_fields` on `/autosaves` requests that carry none |
| `password_protected_is_active` | warm request only | Lets the loopback reach the real homepage rather than an access gate |
| `determine_current_user` (`PHP_INT_MAX`) | warm request only | Forces the warm render logged-OUT, or Newspack disables block caching and we rebuild but cache none |

**Options and transients** (all deleted by `uninstall.php`): `newspack_cache_cozy_secret` (the loopback secret, minted on first read, non-autoloaded), `newspack_cache_cozy_auth` (the HTTP Basic credential, sodium-encrypted under a key derived from `wp_salt('auth')` and marked `$enc$`), and the `newspack_cache_cozy_lock` transient (single-flight, 60s, so a slow render cannot be lapped).

**wp-config constants:** `NEWSPACK_CACHE_COZY_COLD_GROUPS` overrides the cold-group list (default `newspack_blocks`, `transient`, `site-transient`); `NEWSPACK_CACHE_COZY_SSLVERIFY` opts a self-signed dev host out of TLS verification; `NEWSPACK_CACHE_COZY_AUTH` supplies the loopback credential from config rather than the DB, and wins over the option. `NEWSPACK_CACHE_COZY_SKIP_BOOT` is the tests' seam for loading the drop-in's classes without booting it.

**Query parameters** on the warm loopback: `cache_cozy_warm` (the secret, compared with `hash_equals`; an empty stored secret never matches, so an unconfigured site cannot be tricked) and `cache_cozy_groups` (a comma-separated cold-group override, stripped to `[a-z0-9_-]`).

`Cold_Read_Object_Cache` cools a group named exactly OR any `{group}-…` variant of it, because Newspack's block cache splits into `newspack_blocks-post-{ID}` and `newspack_blocks-feed`. The `-` separator keeps an unrelated `newspack_blocksX` warm. Writes pass through and mark their key warm for the rest of that render; every other method and property delegates to the real cache.

## References

- **Substrate**: `../newspack-nodes/` — the runtime this plugin depends on
- **Build-a-plugin guide**: `../newspack-nodes/docs/writing-a-plugin.md` — §8 names cache-cozy as the minimal worked example
