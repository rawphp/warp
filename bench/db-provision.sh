#!/usr/bin/env bash
# S2 gate: per-worker DB fixed cost, classic vs golden-snapshot provisioning,
# plus a 4-way multi-mysqld PoC (spec risk: macOS ports/sockets/ulimits).
# Usage: bench/db-provision.sh <laravel-app-path>
set -euo pipefail

APP="${1:?usage: db-provision.sh <laravel-app-path>}"
WARP="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$APP/tests/Feature/WarpBench"
trap 'rm -rf "$DIR"' EXIT

run() { # run <files> <env...> -> prints elapsed seconds
  local files="$1"; shift
  rm -rf "$DIR"
  php "$WARP/bench/gen-trivial-tests.php" "$DIR" "$files" 10 >/dev/null
  local t0 t1
  t0=$(php -r 'echo microtime(true);')
  (cd "$APP" && env "$@" ./vendor/bin/pest "$DIR" >/dev/null)
  t1=$(php -r 'echo microtime(true);')
  php -r "echo $t1 - $t0;"
}

echo "== classic fixed cost (WARP_MODE=1 WARP_DB=0) =="
CLASSIC=$(run 1 WARP_MODE=1 WARP_DB=0)
php -r "printf(\"%.2fs\n\", $CLASSIC);"

echo "== snapshot run 1 — includes golden build (WARP_DB=1) =="
BUILD=$(run 1 WARP_MODE=1 WARP_DB=1)
php -r "printf(\"%.2fs\n\", $BUILD);"

echo "== snapshot run 2 — clone + mysqld boot only =="
CLONE=$(run 1 WARP_MODE=1 WARP_DB=1)
php -r "printf(\"%.2fs\n\", $CLONE);"

echo "== 4-way parallel (multi-mysqld PoC) =="
rm -rf "$DIR"
php "$WARP/bench/gen-trivial-tests.php" "$DIR" 8 10 >/dev/null
T0=$(php -r 'echo microtime(true);')
(cd "$APP" && WARP_MODE=1 WARP_DB=1 ./vendor/bin/pest "$DIR" --parallel --processes=4 >/dev/null)
T1=$(php -r 'echo microtime(true);')
php -r "printf(\"%.2fs (4 workers, 4 mysqlds, 4 clones)\n\", $T1 - $T0);"

echo "== summary =="
php -r "printf(
    \"classic: %.2fs | golden build: %.2fs | warm clone: %.2fs | saved/worker/run: %.2fs\n\",
    $CLASSIC, $BUILD, $CLONE, $CLASSIC - $CLONE
);"
echo "spec target: warm-clone run ~2s of DB fixed cost vs ~14.4s classic"
