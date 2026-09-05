# Newspack Cache Cozy

A refresh-ahead cache warmer for the [newspack-nodes](../newspack-nodes/) substrate. It keeps the homepage's caches hot out-of-band, so no real visitor ever pays for a cold render.

## How it works

Cache Cozy is two cooperating pieces:

1. **The warmer drop-in** (`01-newspack-cache-cozy.php`, an `mu-plugin`). On each tick it fires a single secret-gated loopback request to the warmed path. During *that* request — and only that request — it swaps the object cache for a `Cold_Read_Object_Cache` decorator: reads on allowlisted "cold" groups (the block cache, transients) miss, forcing WordPress to rebuild them, while every write passes straight through to the real cache. The freshly rendered entries land in live cache under their own keys with a fresh timestamp. No key replication, no cold window. A 60-second lock transient keeps a slow render from being lapped. The drop-in depends on no plugin: WordPress loads it on every request, and it registers its own `newspack_cache_cozy_minute` cron recurrence so scheduling never waits on another plugin's interval.

2. **The tick node** (`Cache_Cozy_Tick_Node`, a newspack-nodes `Timer_Node`). wp-cron is an unreliable trigger — it competes with every other minute-cron for a slot. Instead, this node rides a long-lived worker's drain loop: it hitchhikes the substrate's `_router` heartbeat and enqueues a `cache_cozy` job every interval. The job is dispatched on the substrate's `Job_Worker`, which isolates the blocking warm render (its own request id, GC cycle, timeout headroom) and keeps the worker's loop moving. Cadence is immune to cron contention.

The halves are independent, and neither schedules anything by itself. The tick node calls the drop-in's warm directly from the Job Worker, so a site running the substrate needs no cron event at all. Without the substrate, the drop-in still warms on wp-cron — but only once you schedule `newspack_cache_cozy_tick` yourself. The drop-in registers the handler and the recurrence; it never registers the event.

The drop-in also trims the REST autosaves endpoint, which is unrelated to warming and worth knowing about before you meet it in a stack trace. `WP_REST_Revisions_Controller` applies `the_content` per autosave whenever `content.rendered` is among the requested fields, and the block editor reads `content.raw`. On a post with eleven autosaves that render cost 2.3 seconds apiece *inside* the page response, because `edit-form-blocks.php` preloads `{type}/{id}/autosaves?context=edit` through `rest_do_request()`. `Cache_Cozy::trim_autosave_fields()` sets `_fields` to the twelve the editor actually consumes on any `/autosaves` request carrying none. An explicit `_fields` still wins.

## Requirements

- PHP 8.2+
- WordPress 6.5+
- The **newspack-nodes** plugin, 0.54.0 or newer, active. `Requires Plugins: newspack-nodes` keeps the substrate on, and `Bootstrap::version_at_least( '0.54.0', … )` gates the wiring — too old a substrate leaves Cache Cozy dormant behind an admin notice, not a fatal. The drop-in depends on no plugin and warms without any of this.

## Install

Cache Cozy ships as **two** artifacts on each release:

- `newspack-cache-cozy.zip` — the plugin. Install it like any plugin (`wp plugin install --activate newspack-cache-cozy.zip`, or upload via the admin).
- `01-newspack-cache-cozy.php` — the warmer drop-in. Copy it into `wp-content/mu-plugins/` (create the directory if it doesn't exist). WordPress auto-loads must-use plugins on every request.

Then add the tick node to a topology so a worker drives the cadence:

```
make_node Cache_Cozy_Tick cache-cozy:tick
```

Its positional arguments are `<interval_seconds> <path> <cold_groups>`, defaulting to 60, `/`, and the drop-in's own cold groups. `make_node Cache_Cozy_Tick cache-cozy:tick 120 /news` warms `/news` every two minutes.

On a site with no substrate, schedule the drop-in's cron event instead — the recurrence is its own, so nothing else need be loaded:

```bash
wp cron event schedule newspack_cache_cozy_tick now newspack_cache_cozy_minute
```

`bin/unschedule-cache-cozy.sh` reverses that, deleting the event, the secret, the stored credential and the lock transient. Deleting the plugin runs `uninstall.php`, which removes every `newspack_cache_cozy_` option and transient row.

## Configuration

The warmer is zero-config by default. For tuning:

- **Cold groups**: define `NEWSPACK_CACHE_COZY_COLD_GROUPS` as an array of cache-group names whose reads must miss during the warm render. It defaults to `newspack_blocks`, `transient` and `site-transient`, and each entry also cools the `{group}-…` variants Newspack's block cache splits into.
- **TLS verification**: define `NEWSPACK_CACHE_COZY_SSLVERIFY` as `false` for self-signed dev hosts.
- **Edge-cache bypass auth**: if your page cache serves anonymous homepages from the edge, the loopback never reaches PHP. Define `NEWSPACK_CACHE_COZY_AUTH` as `user:app password`, or store it encrypted at rest by running `bin/schedule-cache-cozy.sh` — it reads the credential silently off stdin, so the plaintext never lands in `ps`, and persists it via `\Newspack_Cache_Cozy\Cache_Cozy::store_auth()`. The constant wins when both are set. (That script's cron-scheduling block is parked: the tick comes from a topology.)

Example `wp-config.php` constants:

```php
define( 'NEWSPACK_CACHE_COZY_COLD_GROUPS', [ 'newspack_blocks', 'transient', 'site-transient' ] );
define( 'NEWSPACK_CACHE_COZY_SSLVERIFY', false );
define( 'NEWSPACK_CACHE_COZY_AUTH', 'svc-cache-cozy:abcd 1234 efgh ijkl' );
```

The warm render is forced logged-out (so block caching stays enabled and populates the anonymous cache real visitors read), bypasses password protection for its own secret-gated request, and tags itself so it's excluded from any timing stats.

## Development

```bash
composer install
cd tests && ../vendor/bin/phpunit --enforce-time-limit
npm run lint:php
npm run lint:phpstan   # requires ../newspack-nodes checked out as a sibling
```

See `AGENTS.md` for the full layout and conventions, and `../newspack-nodes/docs/writing-a-plugin.md` for the guide to building a plugin on the substrate (Cache Cozy is the minimal worked example).

## License

GPL-2.0-or-later.
