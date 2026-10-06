#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
SLUG="woo-toman-toolkit"
[ -d "$SLUG" ] || { echo "Missing $SLUG/" >&2; exit 1; }
mkdir -p dist
rm -f "dist/$SLUG.zip"
zip -qr "dist/$SLUG.zip" "$SLUG" -x "*/.*" "*/.*/*" "*.DS_Store"
echo "Built dist/$SLUG.zip"
