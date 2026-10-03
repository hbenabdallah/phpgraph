#!/bin/sh
#
# Installs the phpgraph executable, which needs no PHP: the binary for this system, checked against its SHA-256,
# in ~/.local/bin.
#
#   curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh                  latest release
#   curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- 0.2.0     a given release
#   curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- dev-main  the main branch
#
# The version can also come from PHPGRAPH_VERSION; PHPGRAPH_INSTALL_DIR=/usr/local/bin installs elsewhere. Running
# the script again updates phpgraph.

set -eu

repository=hbenabdallah/phpgraph
directory=${PHPGRAPH_INSTALL_DIR:-"$HOME/.local/bin"}
version=${1:-${PHPGRAPH_VERSION:-latest}}

case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=macos ;;
    *) echo "No phpgraph binary for $(uname -s). On Windows, in PowerShell: irm https://raw.githubusercontent.com/$repository/main/install.ps1 | iex" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64 | amd64) arch=x86_64 ;;
    aarch64 | arm64) arch=aarch64 ;;
    *) echo "No phpgraph binary for $(uname -m)." >&2; exit 1 ;;
esac

asset="phpgraph-$os-$arch"
case "$version" in
    latest) url="https://github.com/$repository/releases/latest/download/$asset" ;;
    # Rebuilt on every push to main and published as the pre-release "edge".
    dev-main | main | edge) url="https://github.com/$repository/releases/download/edge/$asset" ;;
    v*) url="https://github.com/$repository/releases/download/$version/$asset" ;;
    *) url="https://github.com/$repository/releases/download/v$version/$asset" ;;
esac

temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT

echo "Downloading $url"
curl -fsSL -o "$temporary/$asset" "$url" || {
    echo "No phpgraph binary at $url: is $version a release of https://github.com/$repository/releases?" >&2
    exit 1
}
curl -fsSL -o "$temporary/$asset.sha256" "$url.sha256"

cd "$temporary"
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum -c "$asset.sha256" >/dev/null
else
    shasum -a 256 -c "$asset.sha256" >/dev/null
fi
cd - >/dev/null

mkdir -p "$directory"
mv "$temporary/$asset" "$directory/phpgraph"
chmod +x "$directory/phpgraph"
# macOS: a downloaded file is quarantined until approved; this one was checked above.
if [ "$os" = macos ]; then
    xattr -d com.apple.quarantine "$directory/phpgraph" 2>/dev/null || true
fi

echo "Installed $("$directory/phpgraph" --version) in $directory/phpgraph"
case ":$PATH:" in
    *":$directory:"*) ;;
    *) echo "Add $directory to your PATH, for example: echo 'export PATH=\"$directory:\$PATH\"' >> ~/.profile" ;;
esac
