#!/usr/bin/env bash
# Provision the cache-cozy warmer.
#
# Stores the encrypted loopback credential. The wp-cron scheduling below is
# PARKED — the tick is driven elsewhere — so this script currently schedules
# nothing; re-enable that block to have it register the event again. Requires
# the 01-newspack-cache-cozy.php drop-in (it registers the
# `newspack_cache_cozy_minute` recurrence the parked block schedules against).
#
# Any extra args pass through to `wp`, so a site whose install is not the
# working directory names it, e.g.:
#   ./schedule-cache-cozy.sh --path=/var/www/html
#
# Run it as the user that owns the WordPress install. WP-CLI as root writes
# cron and option state that user cannot then rewrite.

set -euo pipefail

# Read only by the parked wp-cron block at the foot of this file.
# shellcheck disable=SC2034
readonly HOOK="newspack_cache_cozy_tick"
# shellcheck disable=SC2034
readonly RECURRENCE="newspack_cache_cozy_minute"
WP="${WP:-wp}"

# Optionally store an application password for the warm loopback so an edge /
# page cache forwards it to PHP for a real render instead of serving a cached
# homepage. Read silently; blank leaves any existing value untouched. The
# drop-in stores it encrypted (sodium, keyed off wp_salt); the plaintext rides
# stdin into `wp eval`, never a CLI arg, so it never lands in `ps`.
printf 'App password for the warm loopback (user:app-password), blank to skip: ' >&2
read -r -s cred || true
printf '\n' >&2
if [ -n "$cred" ]; then
    # The PHP is single-quoted deliberately: bash must not expand $c or $_.
    # shellcheck disable=SC2016
    printf '%s' "$cred" | "$WP" eval '
$c = trim( file_get_contents( "php://stdin" ) );
if ( ! class_exists( "Newspack_Cache_Cozy\\Cache_Cozy" ) ) {
    fwrite( STDERR, "cache-cozy drop-in not installed; cannot store auth\n" );
    exit( 1 );
}
Newspack_Cache_Cozy\Cache_Cozy::store_auth( $c );
' "$@"
    echo "stored encrypted loopback credential"
fi

# # Capture the event list in its own step so a wp failure (bad --path, wp
# # missing, DB down) aborts loud here under `set -e` instead of being read as
# # "hook not scheduled" and silently creating a duplicate.
# existing="$( "$WP" cron event list --field=hook "$@" )"
# 
# if grep -Fxq "$HOOK" <<< "$existing"; then
#     echo "already scheduled: $HOOK"
#     exit 0
# fi
# 
# "$WP" cron event schedule "$HOOK" now "$RECURRENCE" "$@"
# echo "scheduled $HOOK every minute ($RECURRENCE)"
