#!/usr/bin/env bash
#
# Compares phpgraph and graphify on a PHP project, without any LLM, both in Docker: the build times, the calls between
# methods of the project (compare.py), and the callers found for the methods whose name is declared once (recall.py).
# The comparison of the README, Kimai 2.70.2:
#
#   tools/graphify/benchmark.sh
#
# Another project or other versions: PROJECT_URL, PROJECT_REF, PHPGRAPH_IMAGE, GRAPHIFY_VERSION. The checkout, the
# graphs and the differences to check by hand are left in build/graphify-benchmark/ (WORK).

set -euo pipefail

PROJECT_URL=${PROJECT_URL:-https://github.com/kimai/kimai.git}
PROJECT_REF=${PROJECT_REF:-2.70.2}
PHPGRAPH_IMAGE=${PHPGRAPH_IMAGE:-ghcr.io/hbenabdallah/phpgraph:1.5.2}
GRAPHIFY_VERSION=${GRAPHIFY_VERSION:-0.9.93}
RUNS=${RUNS:-3}

TOOLS=$(cd "$(dirname "$0")" && pwd)
WORK=${WORK:-$(cd "$TOOLS/../.." && pwd)/build/graphify-benchmark}
PROJECT="$WORK/project"
GRAPHIFY_IMAGE="phpgraph-benchmark/graphify:$GRAPHIFY_VERSION"
mkdir -p "$WORK"

if [ ! -d "$PROJECT" ]; then
    git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$PROJECT_REF" "$PROJECT_URL" "$PROJECT"
fi
# phpgraph reads the signatures of vendor/; graphify skips it (.gitignore). Neither runs code of the project.
# Host network: containers cannot reach a local DNS resolver such as systemd-resolved (127.0.0.53).
if [ ! -d "$PROJECT/vendor" ]; then
    docker run --rm --network host -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -e COMPOSER_NO_SECURITY_BLOCKING=1 \
        -v "$PROJECT":/app -w /app composer:2 \
        install --no-scripts --no-plugins --no-interaction --no-progress --ignore-platform-reqs --quiet </dev/null
fi
printf 'FROM python:3.12-slim\nRUN pip install --no-cache-dir graphifyy==%s\n' "$GRAPHIFY_VERSION" \
    | docker build --network host --quiet -t "$GRAPHIFY_IMAGE" - >/dev/null

# Each run writes into an empty directory: phpgraph keeps a cache next to its graph, graphify a manifest.
seconds() {
    local start
    start=$(date +%s.%N)
    "$@" >"$WORK/last-run.log" 2>&1 || { cat "$WORK/last-run.log" >&2; return 1; }
    awk -v start="$start" -v end="$(date +%s.%N)" 'BEGIN { print end - start }'
}
for run in $(seq "$RUNS"); do
    rm -rf "$WORK/phpgraph" "$WORK/graphify"
    mkdir -p "$WORK/phpgraph" "$WORK/graphify"
    phpgraph=$(seconds docker run --rm -u "$(id -u):$(id -g)" -v "$PROJECT":/project:ro -v "$WORK/phpgraph":/out \
        "$PHPGRAPH_IMAGE" build /project -o /out)
    graphify=$(seconds docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PROJECT":/project:ro -v "$WORK/graphify":/out \
        "$GRAPHIFY_IMAGE" graphify extract /project --code-only --out /out)
    printf 'Run %s: phpgraph %.1f s, graphify %.1f s\n' "$run" "$phpgraph" "$graphify"
done

python() {
    docker run --rm -u "$(id -u):$(id -g)" -v "$TOOLS":/tools:ro -v "$WORK":/work -w /work "$GRAPHIFY_IMAGE" python3 "$@"
}
python /tools/compare.py phpgraph/graph.json graphify/graphify-out/graph.json differences.json
python /tools/recall.py project phpgraph/graph.json graphify/graphify-out/graph.json recall.json
echo "Differences to check in the code: $WORK/differences.json, $WORK/recall.json"
