#!/usr/bin/env bash
# Marginal per-test framework tax, classic vs warm.
# Usage: bench/warm-tax.sh /path/to/laravel-app
set -euo pipefail

APP="${1:?usage: warm-tax.sh <laravel-app-path>}"
WARP="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$APP/tests/Feature/WarpBench"
trap 'rm -rf "$DIR"' EXIT

run() { # run <WARP_WARM value> <file count> -> prints elapsed seconds
  rm -rf "$DIR"
  php "$WARP/bench/gen-trivial-tests.php" "$DIR" "$2" 10 >/dev/null
  local t0 t1
  t0=$(php -r 'echo microtime(true);')
  (cd "$APP" && WARP_WARM="$1" ./vendor/bin/pest "$DIR" >/dev/null)
  t1=$(php -r 'echo microtime(true);')
  php -r "echo $t1 - $t0;"
}

for MODE in 0 1; do
  SMALL=$(run "$MODE" 20)   # 200 tests
  LARGE=$(run "$MODE" 80)   # 800 tests
  php -r "printf(\"WARP_WARM=%s  marginal %.2f ms/test  (200 tests: %.2fs, 800 tests: %.2fs)\n\", '$MODE', (($LARGE - $SMALL) / 600) * 1000, $SMALL, $LARGE);"
done
