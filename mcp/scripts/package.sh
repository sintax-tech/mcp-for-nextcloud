#!/bin/sh
# Builds build/mcp-<version>.tar.gz with a single mcp/ root holding only production files.
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/mcp" build
cp -R appinfo lib templates README.md "$stage/mcp/"
COPYFILE_DISABLE=1 tar -C "$stage" -czf "build/mcp-$version.tar.gz" mcp
echo "build/mcp-$version.tar.gz"
