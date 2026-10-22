#!/bin/bash
# handover.sh against a fake root: retires an installed User Scripts plugin without touching its flash dir,
# its RAM dir or a script it is running; does nothing when that plugin is not there.
cd "$(dirname "$0")/.."
H=$(pwd)/source/voz.cron/usr/local/emhttp/plugins/voz.cron/handover.sh
R=$(mktemp -d); trap 'kill "$runpid" 2>/dev/null; rm -rf "$R"' EXIT
fail=0
check() { if [ "$2" = "$3" ]; then echo "ok $1"; else echo "FAIL $1: got '$2', want '$3'"; fail=1; fi; }
tree() { (cd "$1" && find . -type f -exec md5sum {} + | sort) ; }

mkdir -p "$R"/var/log/plugins "$R"/var/log/packages "$R"/boot/config/plugins/user.scripts/scripts/a "$R"/tmp/user.scripts/running \
  "$R"/etc/cron.{hourly,daily,weekly,monthly} "$R"/bin
echo '<PLUGIN/>' > "$R"/boot/config/plugins/user.scripts.plg
ln -s /boot/config/plugins/user.scripts.plg "$R"/var/log/plugins/user.scripts.plg
echo 'echo hi' > "$R"/boot/config/plugins/user.scripts/scripts/a/script
echo '* * * * * x' > "$R"/boot/config/plugins/user.scripts/customSchedule.cron
for f in hourly daily weekly monthly; do echo x > "$R/etc/cron.$f/user.script.start.$f.sh"; echo x > "$R/etc/cron.$f/other.sh"; done
touch "$R"/var/log/packages/user.scripts-2026.10.12-x86_64-1 "$R"/var/log/packages/user.scripts.extra-1.0-x86_64-1
printf '#!/bin/bash\necho "$1" >> %q; rm -f %q/var/log/packages/"$1"\n' "$R/removed" "$R" > "$R"/bin/removepkg
printf '#!/bin/bash\necho run >> %q\n' "$R/cron-updated" > "$R"/bin/update_cron
chmod +x "$R"/bin/*
# a long-running script the old plugin started, its file under the old RAM dir
printf '#!/bin/bash\nexec sleep 300\n' > "$R"/tmp/user.scripts/running-script; chmod +x "$R"/tmp/user.scripts/running-script
"$R"/tmp/user.scripts/running-script >/dev/null 2>&1 & runpid=$!
echo $runpid > "$R"/tmp/user.scripts/running/a
flash_before=$(tree "$R"/boot/config/plugins/user.scripts); tmp_before=$(tree "$R"/tmp/user.scripts)

out=$(VOZ_ROOT="$R" VOZ_REMOVEPKG="$R"/bin/removepkg VOZ_UPDATE_CRON="$R"/bin/update_cron bash "$H"); rc=$?
check "exit 0" "$rc" 0
check "symlink gone" "$([ -L "$R"/var/log/plugins/user.scripts.plg ] && echo yes || echo no)" no
check "plg moved to plugins-removed" "$(ls "$R"/boot/config/plugins-removed)" user.scripts.plg
check "plg no longer in plugins" "$([ -e "$R"/boot/config/plugins/user.scripts.plg ] && echo yes || echo no)" no
check "old wrappers gone, others kept" "$(cd "$R"/etc && ls cron.*/ | grep -c user.script)/$(cd "$R"/etc && ls cron.*/ | grep -c other.sh)" 0/4
check "only the user.scripts package removed" "$(cat "$R"/removed)" user.scripts-2026.10.12-x86_64-1
check "update_cron ran" "$(cat "$R"/cron-updated)" run
check "old flash dir untouched" "$(tree "$R"/boot/config/plugins/user.scripts)" "$flash_before"
check "old RAM dir untouched" "$(tree "$R"/tmp/user.scripts)" "$tmp_before"
check "running script still alive" "$(kill -0 $runpid 2>/dev/null && echo alive || echo dead)" alive
grep -q 'Retiring' <<<"$out"; check "says what it did" $? 0

rm -f "$R"/removed "$R"/cron-updated
out=$(VOZ_ROOT="$R" VOZ_REMOVEPKG="$R"/bin/removepkg VOZ_UPDATE_CRON="$R"/bin/update_cron bash "$H"); rc=$?
check "second run: exit 0" "$rc" 0
check "second run: silent no-op" "$out$([ -e "$R"/removed ] && echo removed)$([ -e "$R"/cron-updated ] && echo cron)" ""

E=$(mktemp -d)
out=$(VOZ_ROOT="$E" VOZ_REMOVEPKG=false VOZ_UPDATE_CRON=false bash "$H"); rc=$?
check "never installed: no-op" "$rc:$out:$(find "$E" -mindepth 1 | wc -l)" "0::0"
rm -rf "$E"
[ $fail = 0 ] && echo "ok handover"
exit $fail
