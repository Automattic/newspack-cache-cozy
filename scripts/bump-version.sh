#!/bin/sh
#
# bump-version.sh — update the version across this plugin.
#
# Usage:
#   ./scripts/bump-version.sh <new-version>
#
# The shared flow lives in scripts/lib/bump-version.sh; this file is only the
# per-plugin knobs.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd || exit 1)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck disable=SC2034  # consumed by the sourced lib
SUBSTRATE_DIR="$PLUGIN_DIR/../newspack-nodes"
# shellcheck disable=SC2034  # consumed by the sourced lib
PLUGIN_FILE="newspack-cache-cozy.php"
# shellcheck disable=SC2034
VERSION_CONST="NEWSPACK_CACHE_COZY_VERSION"

# The drop-in ships as its own release asset and a site can hold a copy that
# came from anywhere, so its header has to move with the plugin or nobody can
# tell which one they have. `CacheCozyTest` fails if the two ever disagree.
# shellcheck disable=SC2034
DROP_IN="mu-plugins/01-newspack-cache-cozy.php"

# shellcheck source=/dev/null
. "$SCRIPT_DIR/lib/bump-version.sh"
