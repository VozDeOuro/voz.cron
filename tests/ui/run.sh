#!/bin/bash
# UI checks. php:8.4-cli serves the real VozCron.page body and api.php through tests/ui/harness (fake
# Unraid 7.3 shell, fixture scripts under /fx) and the official Playwright image runs tests/ui/*.spec.mjs
# against it on a private docker network. Run on Tower from /tmp/us-dev (bin/tt ui does this).
# Never touches the live plugin.
set -euo pipefail
PW_IMAGE=mcr.microsoft.com/playwright:v1.56.1-noble   # keep in step with tests/ui/package.json
NET=us-ui-net
APP=$(pwd)
P=$APP/source/voz.cron/usr/local/emhttp/plugins/voz.cron
docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
docker rm -f us-ui-php >/dev/null 2>&1 || true
trap 'docker rm -f us-ui-php >/dev/null 2>&1 || true' EXIT
docker run -d --name us-ui-php --network "$NET" -e PHP_CLI_SERVER_WORKERS=4 \
  -e US_TEST=1 -e US_FLASH=/fx/flash -e US_TMP=/fx/tmp -e US_VARINI=/fx/var.ini -e US_NOTIFY=/fx/notify -e US_UPDATE_CRON=/fx/none \
  -v "$P:/usr/local/emhttp/plugins/voz.cron:ro" -v "$APP/tests/ui/harness:/harness:ro" \
  php:8.4-cli bash -c 'ln -sf /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime && exec php -d short_open_tag=On -d date.timezone=America/Sao_Paulo -d display_errors=stderr -S 0.0.0.0:8080 /harness/router.php' >/dev/null
for _ in $(seq 30); do
  docker exec us-ui-php php -r 'exit(@file_get_contents("http://127.0.0.1:8080/__health") === "ok" ? 0 : 1);' && break
  sleep 1
done
docker run --rm --network "$NET" --ipc=host -e BASE_URL=http://us-ui-php:8080 \
  -v "$APP/tests/ui:/ui" -v us-ui-node:/ui/node_modules -w /ui "$PW_IMAGE" \
  bash -c 'npm install --no-audit --no-fund --no-package-lock --loglevel=error && npx playwright test "$@"' _ "$@" \
  || { rc=$?; echo '--- harness log (last 40 lines)'; docker logs us-ui-php 2>&1 | tail -40; exit $rc; }
