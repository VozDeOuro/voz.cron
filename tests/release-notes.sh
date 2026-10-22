#!/bin/bash
# Asserts bin/release-notes extracts exactly the bullets of the newest CHANGES section (no XML leakage).
cd "$(dirname "$0")/.."
plg=plugins/voz.cron.plg
v=$(grep -oP 'ENTITY version +"\K[^"]+' $plg)
got=$(bin/release-notes "$v" $plg)
want=$(sed -n "/^###$v\$/,/^<\/CHANGES>/p" $plg | sed "1d; /^###/,\$d" | grep '^- ')
fail=0
[ -n "$got" ] || { echo "FAIL empty notes"; fail=1; }
grep -q '</CHANGES>' <<<"$got" && { echo "FAIL contains </CHANGES>"; fail=1; }
grep -q '<FILE' <<<"$got" && { echo "FAIL contains <FILE"; fail=1; }
[ "$got" = "$want" ] || { echo "FAIL differs from bullet lines"; fail=1; }
[ $fail = 0 ] && echo "ok release notes ($(wc -l <<<"$got") lines)"
exit $fail
