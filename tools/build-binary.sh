#!/usr/bin/env bash
#
# Builds build/phpgraph-<os>-<arch> (.exe on Windows): one file that runs without PHP installed. It is a static PHP
# runtime, the "micro" runtime of static-php-cli compiled with the extensions phpgraph needs only, with
# build/phpgraph.phar appended to it.
#
#   composer phar && tools/build-binary.sh
#
# On Linux the runtime is compiled in an Alpine container (musl, fully static): Docker is needed. On macOS and
# Windows, static-php-cli installs what it needs itself (Homebrew, Visual Studio build tools).

set -euo pipefail

SPC_VERSION=2.8.5
PHP_VERSION=8.4
# tokenizer and ctype for the parser, mbstring, dom and xml for the configuration files, phar to run the archive,
# zlib and iconv for the dependencies; outside Windows, pcntl and posix for the console (signals, the current user).
EXTENSIONS=ctype,dom,iconv,mbstring,phar,tokenizer,xml,zlib

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK="$ROOT/build/static-php"
PHAR="$ROOT/build/phpgraph.phar"

[ -f "$PHAR" ] || { echo "build/phpgraph.phar is missing: run composer phar first." >&2; exit 1; }

case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=macos ;;
    MINGW* | MSYS* | CYGWIN*) os=windows ;;
    *) echo "Unsupported system: $(uname -s)" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64 | amd64 | AMD64) arch=x86_64 ;;
    aarch64 | arm64) arch=aarch64 ;;
    *) echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac
if [ "$os" != windows ]; then
    EXTENSIONS="$EXTENSIONS,pcntl,posix"
fi

# On Linux the runtime is built against musl, in Alpine: this script runs again in a container.
if [ "$os" = linux ] && [ ! -f /etc/alpine-release ]; then
    exec docker run --rm -e GITHUB_TOKEN -v "$ROOT":/phpgraph -w /phpgraph alpine:3.20 \
        sh -c "apk add --no-cache bash curl >/dev/null && tools/build-binary.sh && chown -R $(id -u):$(id -g) build"
fi

mkdir -p "$WORK"
cd "$WORK"

if [ "$os" = windows ]; then
    spc=./spc.exe
    [ -f "$spc" ] || curl -fsSL -o "$spc" "https://github.com/crazywhalecc/static-php-cli/releases/download/$SPC_VERSION/spc-windows-x64.exe"
    micro=buildroot/bin/micro.sfx
    output="$ROOT/build/phpgraph-windows-x86_64.exe"
else
    spc=./spc
    if [ ! -f "$spc" ]; then
        spc_os=$([ "$os" = macos ] && echo macos || echo linux)
        curl -fsSL "https://github.com/crazywhalecc/static-php-cli/releases/download/$SPC_VERSION/spc-$spc_os-$arch.tar.gz" | tar xz
        chmod +x "$spc"
    fi
    micro=buildroot/bin/micro.sfx
    output="$ROOT/build/phpgraph-$os-$arch"
fi

# The mirrors static-php-cli downloads from fail now and then: the steps that download are tried three times.
retry() {
    for attempt in 1 2 3; do
        "$@" && return 0
        [ "$attempt" = 3 ] || { echo "Failed, retrying in 20 s: $*" >&2; sleep 20; }
    done
    return 1
}

retry "$spc" doctor --auto-fix
retry "$spc" download --with-php="$PHP_VERSION" --for-extensions="$EXTENSIONS" --prefer-pre-built
"$spc" build "$EXTENSIONS" --build-micro

# A PHAR appended to the micro runtime runs as the main script.
cat "$micro" "$PHAR" > "$output"
chmod +x "$output"
echo "Built $output ($(du -h "$output" | cut -f1))"
