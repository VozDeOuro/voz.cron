#!/bin/bash
# Retires the original User Scripts plugin (Squid's, or this plugin's earlier releases under the id
# user.scripts) after voz.cron has imported its scripts, so no script is ever scheduled by both.
#
# It does NOT run that plugin's own remove method: both versions of it `rm -rf /tmp/user.scripts`, and the
# fork's also deletes /boot/config/plugins/user.scripts/customSchedule.cron. Instead it does what
# `plugin remove` does minus that script, each step undone by reinstalling the old .plg:
#   - drops /var/log/plugins/user.scripts.plg, so update_cron stops reading its customSchedule.cron
#   - removes its /etc/cron.{hourly,daily,weekly,monthly}/user.script.start.*.sh wrappers
#   - moves /boot/config/plugins/user.scripts.plg to plugins-removed, so it is not reinstalled at boot
#   - removepkg's its package, so its page and its emhttp event hooks (array start/stop runs) are gone
# It never writes to /boot/config/plugins/user.scripts or /tmp/user.scripts and never signals a process:
# scripts the old plugin started keep running to their end (removepkg only unlinks files; a running bash
# or php process keeps what it has open).
# VOZ_ROOT, VOZ_REMOVEPKG and VOZ_UPDATE_CRON exist for tests/handover.sh.
set -u
R=${VOZ_ROOT:-}
removepkg=${VOZ_REMOVEPKG:-/sbin/removepkg}
update_cron=${VOZ_UPDATE_CRON:-/usr/local/sbin/update_cron}
old=user.scripts

link=$R/var/log/plugins/$old.plg
plg=$R/boot/config/plugins/$old.plg
wrappers=()
for f in hourly daily weekly monthly; do
  w=$R/etc/cron.$f/user.script.start.$f.sh
  if [ -e "$w" ]; then wrappers+=("$w"); fi
done
pkgs=()
for p in "$R"/var/log/packages/$old-[0-9]*; do
  if [ -e "$p" ]; then pkgs+=("$(basename "$p")"); fi
done

if [ ! -L "$link" ] && [ ! -e "$link" ] && [ ! -e "$plg" ] && [ ${#wrappers[@]} = 0 ] && [ ${#pkgs[@]} = 0 ]; then
  exit 0
fi

echo "Retiring the User Scripts plugin ($old): its scripts and settings now live in voz.cron."
rm -f "$link"
"$update_cron"
if [ ${#wrappers[@]} -gt 0 ]; then rm -f "${wrappers[@]}"; fi
if [ -e "$plg" ]; then
  mkdir -p "$R/boot/config/plugins-removed"
  mv -f "$plg" "$R/boot/config/plugins-removed/"
fi
for p in "${pkgs[@]}"; do "$removepkg" "$p" >/dev/null 2>&1 || echo "WARNING: removepkg $p failed"; done
echo "Left untouched for rollback: /boot/config/plugins/$old and /tmp/$old (scripts it started keep running)."
exit 0
