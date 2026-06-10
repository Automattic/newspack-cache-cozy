#!/bin/bash
#
# Build the release zip for the newspack-cache-cozy plugin.
#
# Output:
#   release/newspack-cache-cozy.zip — the plugin dir at the archive root,
#     ready for: wp plugin install --force --activate <url>.zip
#   release/01-newspack-cache-cozy.php — the warmer drop-in, published as a
#     standalone mu-plugin asset (installs under mu-plugins/, not plugins/).
#
set -euo pipefail

# Keep macOS from emitting AppleDouble (._foo) sidecars into the archive.
export COPYFILE_DISABLE=1

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RELEASE_DIR="${SCRIPT_DIR}/release"
STAGING_DIR="${SCRIPT_DIR}/.release-staging"
PLUGIN="newspack-cache-cozy"

rm -rf "${RELEASE_DIR}" "${STAGING_DIR}"
mkdir -p "${RELEASE_DIR}"

echo "=== Staging plugin files ==="
mkdir -p "${STAGING_DIR}/${PLUGIN}"
rsync -a --exclude-from="${SCRIPT_DIR}/.distignore" "${SCRIPT_DIR}/" "${STAGING_DIR}/${PLUGIN}/"

# Production autoloader is built in the staging copy, so the dev vendor/
# (phpunit etc.) is never disturbed.
echo "=== Building production autoloader in staging ==="
(cd "${STAGING_DIR}/${PLUGIN}" && composer install --no-dev --optimize-autoloader --quiet)

find "${STAGING_DIR}/${PLUGIN}" \( -name '._*' -o -name '.DS_Store' \) -delete
rm -f "${STAGING_DIR}/${PLUGIN}"/composer.*

echo "=== Creating release zip ==="
echo "  ${PLUGIN}.zip"
(cd "${STAGING_DIR}" && zip -rqX "${RELEASE_DIR}/${PLUGIN}.zip" "${PLUGIN}" --exclude '*/._*' --exclude '*/.DS_Store')

# The deploy script fetches this mu-plugin from the release URL; it installs
# under mu-plugins/, not wp-content/plugins/. WordPress auto-loads it every
# request, so the warmer runs regardless of which plugins are active.
cp "${SCRIPT_DIR}/mu-plugins/01-newspack-cache-cozy.php" "${RELEASE_DIR}/"

rm -rf "${STAGING_DIR}"

echo ""
echo "=== Release artifacts ==="
ls -lh "${RELEASE_DIR}"/*
