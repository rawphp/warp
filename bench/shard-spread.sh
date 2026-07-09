#!/usr/bin/env bash
# S3 gate: record real per-test timings on a suite, then compare count-based
# vs duration-balanced shard spread.
# Usage: bench/shard-spread.sh /path/to/app <shards> [suite-path]
# The app's phpunit.xml must register RawPHP\Warp\Timing\TimingExtension.
set -euo pipefail

WARP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP="${1:?usage: bench/shard-spread.sh /path/to/app <shards> [suite-path]}"
SHARDS="${2:?number of shards required}"
SUITE="${3:-tests}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
TIMINGS_DIR="${APP}/.warp/timings/run-${RUN_ID}"

cd "$APP"

echo "[warp-bench] recording timings: WARP_TIMINGS=1 WARP_TIMINGS_DIR=${TIMINGS_DIR} pest --parallel ${SUITE}"
set +e
WARP_TIMINGS=1 WARP_TIMINGS_DIR="$TIMINGS_DIR" ./vendor/bin/pest --parallel "$SUITE"
PEST_EXIT=$?
set -e

if [[ "$PEST_EXIT" -ne 0 ]]; then
  if ! find "$TIMINGS_DIR" -type f -name '*.json' -print -quit 2>/dev/null | grep -q .; then
    exit "$PEST_EXIT"
  fi

  echo "[warp-bench] pest exited ${PEST_EXIT}; continuing because timing artifacts were recorded" >&2
fi

echo
php "$WARP_DIR/bench/shard-spread.php" "$TIMINGS_DIR" "$SHARDS" "$SUITE"
