#!/bin/bash
# Builds archive/voz.cron-<version>-x86_64-1.txz and prints its md5.
# Uses makepkg (Slackware) when present, else falls back to tar+xz, which gives a layout upgradepkg accepts.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
version=${1:?usage: pkg_build.sh <version>}
archive="$(dirname "$DIR")/../archive"
mkdir -p "$archive"; archive=$(cd "$archive" && pwd)
out="$archive/voz.cron-$version-x86_64-1.txz"
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
(cd "$DIR" && find usr -type f ! -name sftp-config.json -print0 | xargs -0 cp --parents -t "$tmp")
find "$tmp" -type d -exec chmod 0755 {} +
find "$tmp" -type f -exec chmod 0644 {} +
find "$tmp" -type f \( -name '*.sh' -o -name 'run.php' -o -name 'start*.php' -o -path '*/scripts/*/script' \) -exec chmod 0755 {} +
for d in event cron; do
  find "$tmp/usr/local/emhttp/plugins/voz.cron/$d" -type f -exec chmod 0755 {} +
done
rm -f "$out"
if command -v makepkg >/dev/null && makepkg --help 2>&1 | grep -q linkadd; then  # Slackware makepkg, not Arch's
  (cd "$tmp" && makepkg -l y -c y "$out" >/dev/null)
else
  (cd "$tmp" && tar --owner=0 --group=0 --sort=name -cJf "$out" usr)
fi
md5sum "$out" | cut -d' ' -f1
