#!/usr/bin/env bash
# Prints the next release tag: the newest v* tag with its patch number bumped, or the
# plugin.json version if that is higher (so raising it by hand starts a new minor/major).
set -euo pipefail

cd "$(dirname "$0")/.."

manifest=$(php -r 'echo json_decode(file_get_contents("plugin.json"), true)["version"];')
manifest=${manifest#v}
latest=$(git tag -l 'v[0-9]*' | grep -v -- - | sed 's/^v//' | sort -V | tail -n1 || true)

if [[ -z "$latest" ]]; then
    echo "v$manifest"
    exit 0
fi

IFS=. read -r major minor patch <<< "$latest"
bumped="$major.$minor.$((patch + 1))"

if [[ "$(printf '%s\n%s\n' "$bumped" "$manifest" | sort -V | tail -n1)" == "$manifest" ]]; then
    echo "v$manifest"
else
    echo "v$bumped"
fi
