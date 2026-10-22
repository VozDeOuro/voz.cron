#!/bin/bash
# Runs the .plg's first inline script (old package cleanup) against a temp dir: it must exit 0 and keep only the current txz.
cd "$(dirname "$0")/.."
plg=plugins/voz.cron.plg
v=$(grep -oP 'ENTITY version +"\K[^"]+' $plg)
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
sed -n '0,/<INLINE>/d; /<\/INLINE>/q; p' $plg \
  | sed -e 's/&amp;/\&/g' -e "s#/boot/config/plugins/&name;#$tmp#g" -e 's/&name;/voz.cron/g' -e "s/&version;/$v/g" > "$tmp/cleanup.sh"
cur="voz.cron-$v-x86_64-1.txz"
fail=0
check() { # name, files to create, files expected after
  rm -f "$tmp"/*.txz; for f in $2; do touch "$tmp/$f"; done
  bash "$tmp/cleanup.sh" || { echo "FAIL $1: exit $?"; fail=1; }
  got=$(cd "$tmp" && ls *.txz 2>/dev/null | xargs)
  [ "$got" = "$3" ] || { echo "FAIL $1: left '$got', want '$3'"; fail=1; }
}
check "no packages" "" ""
check "only current" "$cur" "$cur"
check "old and current" "voz.cron-2025.06.21-x86_64-1.txz $cur" "$cur"
check "only old" "voz.cron-2025.06.21-x86_64-1.txz" ""
[ $fail = 0 ] && echo "ok plg cleanup"
exit $fail
