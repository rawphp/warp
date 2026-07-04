#!/usr/bin/env bash
# Classic-vs-warm outcome parity on one suite path.
# Usage: bench/parity.sh <laravel-app-path> <suite-relative-path>
set -euo pipefail

APP="${1:?usage: parity.sh <app-path> <suite-rel-path>}"
SUITE="${2:?usage: parity.sh <app-path> <suite-rel-path>}"
WARP="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mktemp -d)"

echo "== classic =="
(cd "$APP" && WARP_WARM=0 ./vendor/bin/pest "$SUITE" --log-junit "$OUT/classic.xml") || true
echo "== warm =="
(cd "$APP" && WARP_WARM=1 ./vendor/bin/pest "$SUITE" --log-junit "$OUT/warm.xml") || true

php "$WARP/bench/compare-junit.php" "$OUT/classic.xml" "$OUT/warm.xml"
