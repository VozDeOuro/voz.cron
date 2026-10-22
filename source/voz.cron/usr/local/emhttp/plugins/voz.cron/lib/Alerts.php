<?php
declare(strict_types=1);

// Decides which runs deserve a notification (repeat failures, exit-code outcomes) and sends them via Unraid notify.
final class Alerts
{
    public static function human(int $s): string
    {
        if ($s < 60) return "{$s}s";
        if ($s < 3600) return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        if ($s < 86400) return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
        return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
    }

    private static function runs(int $n): string { return number_format($n) . ($n === 1 ? ' failed run' : ' failed runs'); }

    /** A run's outcome; a timeout always fails, entries without one take it from their status. */
    private static function outcome(array $run): string
    {
        return $run['status'] === 'timeout' ? 'fail' : History::outcomeOf($run);
    }

    /** @return array{0:array,1:?array} new state, notification or null */
    public static function decide(array $s, array $run, array $opts, string $name, int $now): array
    {
        $s = array_merge(State::BLANK, $s);
        $remind = (int)($opts['remindHours'] ?? 24);
        $on = ($opts['notify'] ?? 'off') !== 'off';
        $status = $run['status'];
        if ($status === 'aborted' || $status === 'skipped') return [$s, null];
        $outcome = self::outcome($run);
        if ($outcome === 'ignore') return [$s, null];
        if ($outcome === 'warn') return self::warn($s, $run, $on, $remind, $name, $now);

        if ($outcome === 'ok') {
            $n = null;
            if ($s['alerted'] && $on) {
                $n = ['level' => 'normal', 'subject' => "$name recovered after " . self::human($now - $s['firstFail']) . ' (' . self::runs($s['fails']) . ')', 'detail' => ''];
            } elseif (($opts['notify'] ?? 'off') === 'always') {
                $n = ['level' => 'normal', 'subject' => "$name finished in " . self::human(max(0, $run['end'] - $run['start'])), 'detail' => ''];
            }
            return [array_merge(State::BLANK, ['skips' => 0]), $n];
        }

        // fail: failed, timeout, or an exit code the rules map to fail
        $s['warnSince'] = 0;
        $s['lastWarnAlert'] = 0;
        $s['fails']++;
        if ($s['fails'] === 1) $s['firstFail'] = (int)$run['start'];
        $s['health'] = 'failing';
        $exit = (int)$run['exit'];
        $what = $status === 'timeout' ? 'timed out' : "failing: exit $exit";
        $n = null;
        if (!$s['alerted']) {
            if ($s['fails'] >= (int)($opts['failAfter'] ?? 1) && $on) {
                $n = ['level' => 'alert', 'subject' => "$name $what ({$s['fails']} run" . ($s['fails'] === 1 ? '' : 's') . ' since ' . date('H:i', $s['firstFail']) . ')', 'detail' => ''];
                $s['alerted'] = true;
                $s['lastAlert'] = $now;
            }
        } elseif ($exit !== $s['lastExit'] && $on) {
            $n = ['level' => 'warning', 'subject' => $status === 'timeout' ? "$name now timing out" : "$name now failing with exit $exit", 'detail' => ''];
            $s['lastAlert'] = $now;
        } elseif ($remind > 0 && $now - $s['lastAlert'] >= $remind * 3600 && $on) {
            $n = ['level' => 'warning', 'subject' => "$name still failing for " . self::human($now - $s['firstFail']) . ', ' . self::runs($s['fails']), 'detail' => ''];
            $s['lastAlert'] = $now;
        }
        $s['lastExit'] = $exit;
        return [$s, $n];
    }

    /** A warning neither counts toward nor resets the failure streak: one notice per episode, then reminders. */
    private static function warn(array $s, array $run, bool $on, int $remind, string $name, int $now): array
    {
        $n = null;
        if ($s['warnSince'] === 0) {
            $s['warnSince'] = (int)$run['start'];
            if ($on) {
                $n = ['level' => 'warning', 'subject' => "$name warning: exit " . (int)$run['exit'], 'detail' => ''];
                $s['lastWarnAlert'] = $now;
            }
        } elseif ($on && $remind > 0 && $now - $s['lastWarnAlert'] >= $remind * 3600) {
            $n = ['level' => 'warning', 'subject' => "$name still warning for " . self::human($now - $s['warnSince']), 'detail' => ''];
            $s['lastWarnAlert'] = $now;
        }
        return [$s, $n];
    }

    /** "cron run, 2m 3s, exit 1 (warn)" */
    private static function detail(array $run): string
    {
        $trigger = $run['trigger'] === 'custom' ? 'cron' : (string)$run['trigger'];
        $result = $run['status'] === 'timeout' ? 'timed out' : 'exit ' . (int)$run['exit'];
        return "$trigger run, " . self::human(max(0, $run['end'] - $run['start'])) . ", $result (" . self::outcome($run) . ')';
    }

    public static function onRun(string $id, string $name, array $run, array $opts, string $logTail): void
    {
        $note = null;
        State::update($id, function (array $s) use ($run, $opts, $name, &$note) {
            [$s, $note] = self::decide($s, $run, $opts, $name, time());
            return $s;
        });
        if ($note) {
            $note['detail'] = self::detail($run);
            self::send($note, $logTail);
        }
    }

    public static function onSkip(string $id, string $name, array $opts, int $runningSince, int $now): void
    {
        $send = false;
        $s = State::update($id, function (array $s) use ($opts, &$send) {
            $s['skips']++;
            if ($s['skips'] >= (int)($opts['hangAfter'] ?? 3) && !$s['stuckAlerted'] && ($opts['notify'] ?? 'off') !== 'off') {
                $s['stuckAlerted'] = true;
                $send = true;
            }
            return $s;
        });
        if ($send) {
            self::send(['level' => 'warning', 'subject' => "$name looks stuck: running " . self::human($now - $runningSince) . ", {$s['skips']} runs skipped",
                'detail' => 'still running since ' . date('H:i', $runningSince)], '');
        }
    }

    public static function onStart(string $id): void
    {
        State::update($id, function (array $s) { $s['skips'] = 0; $s['stuckAlerted'] = false; return $s; });
    }

    /** One normal notification, whatever the script's notify setting, to check the delivery path (Unraid notification agents). */
    public static function test(string $name): bool
    {
        return self::send(['level' => 'normal', 'subject' => "$name: test notification from VOZ_CRON", 'detail' => 'sent from the Run options drawer'], '');
    }

    /** Notification body limit; the end of a log tail is kept. */
    private const int MAX_MESSAGE = 4096;

    /** @return bool whether Unraid's notify accepted it */
    public static function send(array $n, string $message): bool
    {
        if (!is_executable(Paths::$notify)) { Sys::log("notify unavailable: {$n['subject']}"); return false; }
        $arg = fn(string $s) => Sys::shq(str_replace("\0", '', $s)); // a NUL would make exec() throw
        $message = str_replace("\0", '', $message);
        // keep the last MAX_MESSAGE bytes, dropping a UTF-8 character cut in half at the front
        if (strlen($message) > self::MAX_MESSAGE) $message = (string)preg_replace('/^[\x80-\xBF]+/', '', substr($message, -self::MAX_MESSAGE));
        $cmd = $arg(Paths::$notify) . ' -e ' . $arg('VOZ_CRON') . ' -s ' . $arg($n['subject'])
            . ' -d ' . $arg($n['detail']) . ' -i ' . $arg($n['level'])
            . ($message !== '' ? ' -m ' . $arg($message) : '');
        exec($cmd . ' >/dev/null 2>&1', $out, $rc);
        if ($rc !== 0) Sys::log("notify failed (exit $rc): {$n['subject']}");
        return $rc === 0;
    }
}
