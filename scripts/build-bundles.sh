#!/usr/bin/env bash
# Build script pro NKZ Marketplace bundle ZIPy.
#
# Generuje:
#   dist/nkz-marketplace-aoz-<version>.zip       (core + adapter + storefront + addons)
#   dist/nkz-marketplace-screeno-<version>.zip   (core + adapter only)  [TODO]
#
# Zdrojový kód žije pod packages/, tento skript ho kopíruje do build-dir,
# přidá bundle wrapper, ZIPuje. Žádné kopie nezůstávají v repu.
#
# Použití:
#   ./scripts/build-bundles.sh

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
PACKAGES_DIR="$REPO_ROOT/packages"
DIST_DIR="$REPO_ROOT/dist"
BUILD_DIR="$(mktemp -d -t nkzmp-build-XXXXXX)"

# Read AOZ bundle version from plugin header.
BUNDLE_VERSION=$(grep -m1 '^ \* Version:' "$PACKAGES_DIR/nkz-mp-aoz-bundle/nkz-mp-aoz-bundle.php" | awk -F': *' '{print $2}' | tr -d ' \r')

if [[ -z "$BUNDLE_VERSION" ]]; then
	echo "ERROR: nemohu vyčíst Version z nkz-mp-aoz-bundle.php" >&2
	exit 1
fi

echo "Building bundles, version=$BUNDLE_VERSION"

mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/nkz-marketplace-aoz-"*.zip

# ── AOZ bundle ────────────────────────────────────────────────────────
AOZ_DIR="$BUILD_DIR/nkz-marketplace-aoz"
mkdir -p "$AOZ_DIR/modules"

cp -r "$PACKAGES_DIR/nkz-marketplace"            "$AOZ_DIR/modules/nkz-marketplace"
cp -r "$PACKAGES_DIR/nkz-mp-stripe"              "$AOZ_DIR/modules/nkz-woo-stripe-vendor-split"
cp -r "$PACKAGES_DIR/nkz-mp-storefront"          "$AOZ_DIR/modules/nkz-mp-storefront"
cp -r "$PACKAGES_DIR/nkz-mp-vendor-registration" "$AOZ_DIR/modules/nkz-mp-vendor-registration"
cp -r "$PACKAGES_DIR/nkz-mp-vendor-dashboard"    "$AOZ_DIR/modules/nkz-mp-vendor-dashboard"
cp -r "$PACKAGES_DIR/nkz-mp-shipping"            "$AOZ_DIR/modules/nkz-mp-shipping"
cp -r "$PACKAGES_DIR/nkz-mp-vendor-billing"      "$AOZ_DIR/modules/nkz-mp-vendor-billing"
cp -r "$PACKAGES_DIR/nkz-mp-packeta"             "$AOZ_DIR/modules/nkz-mp-packeta"
cp -r "$PACKAGES_DIR/nkz-mp-platform-fee"        "$AOZ_DIR/modules/nkz-mp-platform-fee"
cp     "$PACKAGES_DIR/nkz-mp-aoz-bundle/nkz-mp-aoz-bundle.php" "$AOZ_DIR/"
cp     "$PACKAGES_DIR/nkz-mp-aoz-bundle/README.md"             "$AOZ_DIR/"

# Strip vendor/ dirs (composer dependencies) — žádné nemáme, ale safety.
find "$AOZ_DIR" -name '.DS_Store' -delete
find "$AOZ_DIR" -type d -name 'node_modules' -prune -exec rm -rf {} + 2>/dev/null || true

OUTPUT="$DIST_DIR/nkz-marketplace-aoz-$BUNDLE_VERSION.zip"
(cd "$BUILD_DIR" && zip -rq "$OUTPUT" "nkz-marketplace-aoz" -x '*.DS_Store')
echo "  $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"

# Cleanup
rm -rf "$BUILD_DIR"

echo "Done."
