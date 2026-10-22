**VOZ_CRON** by Lucas Carvalho (VozDeOuro), based on User Scripts by Andrew Zawadzki (Squid)

Run and schedule your own scripts, with run history, live logs and failure notifications.
Built for Unraid 7.3.3 and newer.

Scripts run as root with a clean environment (PATH, HOME=/root, TZ, and LANG, which defaults to C.UTF-8); `~/.bashrc` is not sourced, so source it in the script if you need it.
