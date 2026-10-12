#!/usr/bin/env bash
# Sestaví screeno-<verze>.zip: balík + Stripe split (tento repozitář) + booking (Screeno-rezervace).
#
#   packages/screeno-bundle/build.sh <cesta k checkoutu Screeno-rezervace> [výstupní složka]
#
# Stripe split se bere z pracovního stromu tohoto repa (stav větve screeno/production),
# booking z předané složky (výchozí větev Screeno-rezervace).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
BOOKING_REPO="${1:?Zadej cestu k checkoutu Screeno-rezervace}"
OUT="${2:-$REPO/dist}"
VERSION="$(sed -n "s/^ \* Version: //p" "$HERE/screeno.php")"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/screeno/modules"

cp "$HERE/screeno.php" "$HERE/README.md" "$STAGE/screeno/"
cp -R "$REPO/packages/nkz-mp-stripe" "$STAGE/screeno/modules/nkz-woo-stripe-vendor-split"
cp -R "$BOOKING_REPO/nkz-studio-booking" "$STAGE/screeno/modules/nkz-studio-booking"

# uninstall.php modulů se v balíku nikdy nespustí (WordPress ho hledá jen v kořeni pluginu) – nebalit.
rm -f "$STAGE/screeno/modules/"*/uninstall.php
find "$STAGE/screeno" -type d \( -name .git -o -name node_modules -o -name tests \) -prune -exec rm -rf {} +
find "$STAGE/screeno" -type f \( -name .gitignore -o -name .gitattributes -o -name '*.log' -o -name .DS_Store \) -delete

find "$STAGE/screeno" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

mkdir -p "$OUT"
rm -f "$OUT/screeno-$VERSION.zip"
(cd "$STAGE" && zip -qr "$OUT/screeno-$VERSION.zip" screeno)
echo "$OUT/screeno-$VERSION.zip"
