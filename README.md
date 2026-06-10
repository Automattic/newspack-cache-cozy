# Newspack Cache Cozy

A refresh-ahead cache warmer for the [newspack-nodes](../newspack-nodes/) substrate. It keeps the homepage's caches hot out-of-band, so no real visitor ever pays for a cold render.

## How it works

Cache Cozy is two cooperating pieces:

1. **The warmer drop-in** (`01-newspack-cache-cozy.php`, an `mu-plugin`). On a periodic tick it fires a single secret-gated loopback request to the homepage. During *that* request — and only that request — it swaps the object cache for a `Cold_Read_Object_Cache` decorator: reads on allowlisted "cold" groups (the block cache, transients) miss, forcing WordPress to rebuild them, while every write passes straight through to the real cache. The freshly rendered entries land in live cache under their own keys with a fresh timestamp. No key replication, no cold window. The drop-in is fully self-contained — it carries its own 60-second cron recurrence and runs regardless of which plugins are active.

2. **The tick node** (`Cache_Cozy_Tick_Node`, a newspack-nodes `Timer_Node`). wp-cron is an unreliable trigger — it competes with every other minute-cron for a slot. Instead, this node rides a long-lived worker's drain loop: it hitchhikes the substrate's `_router` heartbeat and enqueues a `cache_cozy` job every interval. The job is dispatched on the substrate's `Job_Worker`, which isolates the blocking warm render (its own request id, GC cycle, timeout headroom) and keeps the worker's loop moving. Cadence is immune to cron contention.

The drop-in works on its own cron even with no substrate; the tick node is what makes the cadence reliable when newspack-nodes is running.

## Requirements

- PHP 8.2+
- WordPress 6.0+
- The **newspack-nodes** plugin (>= 0.15.0), active. Cache Cozy is gated on it — if the substrate is missing or too old you'll get an admin notice, not a fatal.

## Install

Cache Cozy ships as **two** artifacts on each release:

- `newspack-cache-cozy.zip` — the plugin. Install it like any plugin (`wp plugin install --activate newspack-cache-cozy.zip`, or upload via the admin).
- `01-newspack-cache-cozy.php` — the warmer drop-in. Copy it into `wp-content/mu-plugins/` (create the directory if it doesn't exist). WordPress auto-loads must-use plugins on every request.

Then add the tick node to a topology so a worker drives the cadence:

```
make_node Cache_Cozy_Tick cache-cozy:tick
```

(An optional numeric argument overrides the 60-second interval, e.g. `make_node Cache_Cozy_Tick cache-cozy:tick 120`.)

## Configuration

The warmer is zero-config by default. For tuning:

- **Cold groups**: define `NEWSPACK_CACHE_COZY_COLD_GROUPS` as an array of cache-group names whose reads must miss during the warm render. Defaults cover the Newspack block cache and transients.
- **TLS verification**: define `NEWSPACK_CACHE_COZY_SSLVERIFY` as `false` for self-signed dev hosts.
- **Edge-cache bypass auth**: if your page cache serves anonymous homepages from the edge, the loopback never reaches PHP. Define `NEWSPACK_CACHE_COZY_AUTH` as `user:app password`, or store the credential with the plugin option so it is encrypted at rest.

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

See `AGENTS.md` for the full layout and conventions, and `../newspack-nodes/WRITING-A-PLUGIN.md` for the guide to building a plugin on the substrate (Cache Cozy is the minimal worked example).

## License

GPL-2.0-or-later.
