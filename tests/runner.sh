#!/bin/bash
# Integration tests for run.php: abort, timeout, overlap modes, stale lock. Needs setsid, pkill, ps.
set -u
ROOT=$(mktemp -d); trap 'rm -rf "$ROOT"' EXIT
export US_TEST=1 US_FLASH="$ROOT/flash" US_TMP="$ROOT/tmp" US_VARINI="$ROOT/var.ini" US_NOTIFY="$ROOT/notify" US_UPDATE_CRON="$ROOT/none"
RUN="php $(pwd)/source/voz.cron/usr/local/emhttp/plugins/voz.cron/run.php"
mkdir -p "$US_FLASH/scripts" "$US_TMP"
printf '#!/bin/sh\necho "$@" >> %s/sent\n' "$ROOT" > "$US_NOTIFY"; chmod +x "$US_NOTIFY"
fail=0
mk() { mkdir -p "$US_FLASH/scripts/$1"; printf '%b' "$2" > "$US_FLASH/scripts/$1/script"; }
opt() { php -r "require '$(pwd)/source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php'; Schedule::set('$1', json_decode('$2', true));"; }
last() { tail -n1 "$US_TMP/history/$(php -r "echo rawurlencode('$1');").jsonl" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["'"$2"'"];'; }
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected '$3' got '$2'"; fail=1; fi; }
wait_running() { for _ in $(seq 50); do [ -s "$US_TMP/running/$1" ] && return; sleep 0.1; done; }
isrun() { php -r "require '$(pwd)/source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php'; echo Runner::isRunning('$1') ? 'running' : 'free';"; }

# abort kills the whole group, including a TERM-ignoring background child
mk abortme '#!/bin/bash\ntrap "" TERM\n(trap "" TERM; sleep 300) &\nsleep 300\n'
$RUN --script abortme --trigger background & R=$!
wait_running abortme
PG=$(cat "$US_TMP/running/abortme")
check "running/<id> holds the pgid" "$(ps -o pgid= -p "$PG" | tr -d ' ')" "$PG"
check "script leads its own session" "$(ps -o sid= -p "$PG" | tr -d ' ')" "$PG"
$RUN --abort abortme
wait $R
check "abort status" "$(last abortme status)" aborted
check "abort leaves no sleep" "$(pgrep -f 'sleep 300' | wc -l | tr -d ' ')" 0
check "abort of idle script fails" "$($RUN --abort abortme; echo $?)" 1

# timeout
mk slow '#!/bin/bash\nsleep 30\n'
opt slow '{"timeout":2}'
$RUN --script slow --trigger custom
check "timeout status" "$(last slow status)" timeout
check "timeout exit" "$(last slow exit)" 124

# timeout also takes down a TERM-ignoring background child once the leader is gone
mk slow2 '#!/bin/bash\n(trap "" TERM; sleep 301) &\nsleep 30\n'
opt slow2 '{"timeout":1}'
$RUN --script slow2 --trigger custom
check "timeout kills group" "$(pgrep -f 'sleep 301' | wc -l | tr -d ' ')" 0

# a background child left behind by a finished script does not keep the lock
mk daemon '#!/bin/bash\n(sleep 302) &\necho started\n'
$RUN --script daemon --trigger custom
check "leftover child: run finished ok" "$(last daemon status)" ok
check "leftover child does not hold the lock" "$(isrun daemon)" free
pkill -f 'sleep 302'

# overlap: skip (default) records skipped and alerts stuck at hangAfter
mk long '#!/bin/bash\nsleep 4\n'
opt long '{"notify":"failure","hangAfter":2}'
$RUN --script long --trigger custom & R=$!
wait_running long
$RUN --script long --trigger custom; $RUN --script long --trigger custom
check "skip recorded" "$(last long status)" skipped
check "stuck alert sent" "$(grep -c 'looks stuck' "$ROOT/sent" 2>/dev/null)" 1
wait $R
check "first run ok" "$(last long status)" ok

# overlap: queue runs exactly one waiter
mk q '#!/bin/bash\necho run >> '"$ROOT"'/q.count\nsleep 2\n'
opt q '{"overlap":"queue"}'
$RUN --script q --trigger custom & A=$!
wait_running q
$RUN --script q --trigger custom & B=$!
sleep 0.3
$RUN --script q --trigger custom   # third: skipped because one is already waiting
wait $A $B
check "queue ran twice" "$(wc -l < "$ROOT/q.count" | tr -d ' ')" 2

# overlap: parallel runs both
mk p '#!/bin/bash\necho run >> '"$ROOT"'/p.count\nsleep 2\n'
opt p '{"overlap":"parallel"}'
$RUN --script p --trigger custom & A=$!
wait_running p
$RUN --script p --trigger custom
wait $A
check "parallel ran twice" "$(wc -l < "$ROOT/p.count" | tr -d ' ')" 2

# a kill -9 of runner and script leaves no stale lock
mk stale '#!/bin/bash\nsleep 300\n'
$RUN --script stale --trigger custom & R=$!
wait_running stale
PG=$(cat "$US_TMP/running/stale"); kill -9 "$R" 2>/dev/null; wait "$R" 2>/dev/null; sleep 0.3
check "lock survives runner kill -9 (held by script fd 9)" "$(isrun stale)" running
$RUN --script stale --trigger custom
check "orphaned script still blocks overlap" "$(last stale status)" skipped
kill -9 -- "-$PG" 2>/dev/null; sleep 0.5
check "no stale lock" "$(isrun stale)" free

# a run left by the old plugin (no flock, not a group leader) is running, skipped over and abortable
mk leg '#!/bin/bash\necho new\n'
mkdir -p "$US_TMP/tmpScripts/leg" "$US_TMP/running"
bash -c 'sleep 314; :' "$US_TMP/tmpScripts/leg/script" & L=$!
echo "$L" > "$US_TMP/running/leg"
sleep 0.2
check "legacy run is running" "$(isrun leg)" running
$RUN --script leg --trigger custom
check "legacy run blocks overlap" "$(last leg status)" skipped
check "abort of legacy run succeeds" "$($RUN --abort leg; echo $?)" 0
wait "$L" 2>/dev/null
check "legacy abort leaves no sleep" "$(pgrep -f 'sleep 314' | wc -l | tr -d ' ')" 0
check "legacy run no longer running" "$(isrun leg)" free
check "runner.sh's own group survived" "$(kill -0 $$ 2>/dev/null && echo alive)" alive

# an id with '%' runs from its generated cron line (dcron hands the command to /bin/sh -c verbatim)
mk 'pct 100%' '#!/bin/bash\necho pct\n'
mkdir -p "$ROOT/plugin"
printf '#!/bin/sh\nexec php %s/source/voz.cron/usr/local/emhttp/plugins/voz.cron/run.php "$@"\n' "$(pwd)" > "$ROOT/plugin/run.php"
chmod +x "$ROOT/plugin/run.php"
LINE=$(US_PLUGIN="$ROOT/plugin" php -r "require '$(pwd)/source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php'; echo Schedule::cronLine('* * * * *', 'pct 100%');")
/bin/sh -c "$(echo "$LINE" | cut -d' ' -f6-)"
check "cron line runs an id containing %" "$(last 'pct 100%' status)" ok

exit $fail
