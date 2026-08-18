#!/usr/bin/env bash
# Unschedule the cache-cozy warmer and remove the state it created: the
# recurring cron event, the secret option, the encrypted auth credential, and
# the single-flight lock transient.
#
# Tolerant of already-absent state, but does NOT mask real wp failures: a
# reachability check runs first, so a bad --path / missing wp / unreachable DB
# aborts loud instead of being reported as a clean "cleaned up". Extra args
# pass through to `wp`, e.g.:
#   ./unschedule-cache-cozy.sh --path=/var/www/html
#
# Run it as the user that owns the WordPress install, not root.

set -euo pipefail

readonly HOOK="newspack_cache_cozy_tick"
readonly SECRET_OPTION="newspack_cache_cozy_secret"
readonly AUTH_OPTION="newspack_cache_cozy_auth"
readonly LOCK_TRANSIENT="newspack_cache_cozy_lock"
WP="${WP:-wp}"

# Prove wp + the WP install are reachable up front. With this established, a
# non-zero from a delete below genuinely means "already absent" (the case we
# tolerate) rather than a wp/connectivity failure we'd be hiding.
"$WP" option get siteurl "$@" > /dev/null

# `cron event delete` removes ALL scheduled instances of the hook.
"$WP" cron event delete "$HOOK" "$@" || echo "  (no scheduled $HOOK)"
"$WP" option delete "$SECRET_OPTION" "$@" || echo "  ($SECRET_OPTION not set)"
"$WP" option delete "$AUTH_OPTION" "$@" || echo "  ($AUTH_OPTION not set)"
"$WP" transient delete "$LOCK_TRANSIENT" "$@" || echo "  ($LOCK_TRANSIENT not set)"

echo "cache cozy warmer unscheduled and cleaned up"
