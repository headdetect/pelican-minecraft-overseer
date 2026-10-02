#!/usr/bin/env bash
# Builds dist/overseer-<version>.zip in the layout Pelican's "Import from file" expects:
# one top-level folder named after the plugin id, with plugin.json inside it.
# Usage: bin/build-zip.sh [version]   (defaults to the version in plugin.json)
set -euo pipefail

cd "$(dirname "$0")/.."

id=$(php -r 'echo json_decode(file_get_contents("plugin.json"), true)["id"];')
version=${1:-$(php -r 'echo json_decode(file_get_contents("plugin.json"), true)["version"];')}
version=${version#v}

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/$id"

# Only what the panel needs at runtime; tests, CI files and build scripts stay out.
for path in plugin.json README.md config database lang resources routes src; do
    [ -e "$path" ] && cp -R "$path" "$stage/$id/"
done

# Stamp the release version into the packaged plugin.json.
VERSION="$version" php -r '
    $file = $argv[1];
    $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $data["version"] = getenv("VERSION");
    unset($data["meta"]);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$stage/$id/plugin.json"

find "$stage" -name '.DS_Store' -delete

mkdir -p dist
out="$PWD/dist/$id-$version.zip"
rm -f "$out"
(cd "$stage" && zip -qr -X "$out" "$id")
echo "$out"
