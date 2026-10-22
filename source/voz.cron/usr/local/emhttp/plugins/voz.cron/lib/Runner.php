<?php
declare(strict_types=1);

// The only code that executes scripts. One process per run; holds the script's flock while the script runs.
final class Runner
{
    public const array TRIGGERS = ['manual', 'background', 'hourly', 'daily', 'weekly', 'monthly', 'custom', 'start', 'boot', 'stop'];
    /** Frequencies started as a group by cron or an emhttp event ('custom' runs per script, 'disabled' never). */
    public const array EVENT_FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly', 'start', 'boot', 'stop'];
    private const int LOGS = 5;
    private const int GRACE = 5; // seconds between TERM and KILL
    private const int TERM = 15, KILL = 9; // SIGTERM/SIGKILL constants need pcntl, which php-cli images lack
    private const array ENV = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'USER' => 'root', 'LOGNAME' => 'root', 'SHELL' => '/bin/bash', 'TERM' => 'dumb'];

    private static function lockFile(string $id): string { return Paths::locks() . '/' . rawurlencode($id) . '.lock'; }
    private static function logFile(string $id, int $which = 0): string { return Paths::work($id) . ($which ? "/log.$which.txt" : '/log.txt'); }

    private static function lockHeld(string $id): bool
    {
        $f = self::lockFile($id);
        if (!is_file($f) || !($h = fopen($f, 'c'))) return false;
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) flock($h, LOCK_UN);
        fclose($h);
        return !$free;
    }

    public static function isRunning(string $id): bool
    {
        if (self::lockHeld($id) || self::legacyPid($id) !== null) return true;
        $pidFile = Paths::running() . "/$id";
        if (is_file($pidFile)) unlink($pidFile); // left by a crash
        return false;
    }

    public static function runningSince(string $id): ?int
    {
        $f = Paths::running() . "/$id";
        return self::isRunning($id) && is_file($f) ? (int)filemtime($f) : null;
    }

    /**
     * A run started by the old plugin (startBackground.php, before this fork was installed) holds no
     * flock. It is still running while running/<id> names a live process whose command line runs
     * the script's tmpScripts copy.
     */
    private static function legacyPid(string $id): ?int
    {
        $f = Paths::running() . "/$id";
        if (!is_file($f)) return null;
        $pid = self::pid($f);
        if ($pid <= 1 || self::proc($pid) === null) return null;
        $cmd = @file_get_contents("/proc/$pid/cmdline"); // may exit between the two reads
        return is_string($cmd) && str_contains(str_replace("\0", ' ', $cmd), "tmpScripts/$id/script") ? $pid : null;
    }

    private static function pid(string $file): int
    {
        return is_file($file) ? (int)trim((string)file_get_contents($file)) : 0;
    }

    /** @return array{ppid:int,pgrp:int}|null null when the process is gone or a zombie */
    private static function proc(int $pid): ?array
    {
        $st = is_file("/proc/$pid/stat") ? @file_get_contents("/proc/$pid/stat") : false;
        if (!is_string($st) || ($p = strrpos($st, ')')) === false) return null;
        $f = explode(' ', substr($st, $p + 2));
        return ($f[0] ?? 'Z') === 'Z' || ($f[0] ?? '') === 'X' ? null : ['ppid' => (int)$f[1], 'pgrp' => (int)$f[2]];
    }

    /** @return list<int> $pid and every live descendant */
    private static function tree(int $pid): array
    {
        $kids = [];
        foreach (glob('/proc/[0-9]*', GLOB_NOSORT) ?: [] as $d) {
            $p = self::proc((int)basename($d));
            if ($p) $kids[$p['ppid']][] = (int)basename($d);
        }
        $out = [];
        for ($todo = [$pid]; $todo; ) {
            $p = array_shift($todo);
            $out[] = $p;
            array_push($todo, ...($kids[$p] ?? []));
        }
        return $out;
    }

    private static function kill(int $pid, int $sig): bool
    {
        if (function_exists('posix_kill')) return posix_kill($pid, $sig);
        exec("/bin/kill -s $sig -- $pid 2>/dev/null", $o, $rc);
        return $rc === 0;
    }

    /** Ends a legacy run: its group if it leads one, else the pid and its descendants (never a foreign group). */
    private static function killLegacy(int $pid): void
    {
        if ((self::proc($pid)['pgrp'] ?? 0) === $pid) { self::killGroup($pid); return; }
        $pids = self::tree($pid);
        foreach ($pids as $p) self::kill($p, self::TERM);
        for ($i = 0; $i < self::GRACE * 5; $i++) {
            $pids = array_values(array_filter($pids, fn($p) => self::proc($p) !== null));
            if (!$pids) return;
            usleep(200000);
        }
        foreach ($pids as $p) self::kill($p, self::KILL);
    }

    /**
     * Starts every script scheduled at $f as its own detached run.php and returns at once: emhttp
     * event hooks (array start/stop) must not wait for scripts, and a long one must not delay the others.
     */
    public static function runFrequency(string $f): void
    {
        foreach (Schedule::byFrequency($f) as $id) {
            if (!Sys::launch([Sys::php(), dirname(__DIR__) . '/run.php', '--script', $id, '--trigger', $f])) Sys::log("$id: could not launch $f run");
        }
    }

    /**
     * Non-blocking exclusive lock. isRunning() probes the same lock for an instant, so a run that
     * collides with a UI status poll retries briefly instead of being skipped.
     * @param resource $h
     */
    private static function tryLock($h): bool
    {
        for ($i = 0; $i < 3; $i++) {
            if (flock($h, LOCK_EX | LOCK_NB)) return true;
            usleep(50000);
        }
        return false;
    }

    public static function run(string $id, string $trigger): string
    {
        $file = Scripts::file($id);
        $name = Scripts::name($id);
        $vars = Vars::typed($file);
        $opts = Schedule::get($id);
        $sys = Sys::var();
        if (!empty($vars['arrayStarted']) && ($sys['mdState'] ?? '') !== 'STARTED') { Sys::log("$name: array not started, not running"); return 'blocked'; }
        if (!empty($vars['noParity']) && (int)($sys['mdResyncPos'] ?? 0) > 0) { Sys::log("$name: parity check/rebuild in progress, not running"); return 'blocked'; }
        if (!empty($vars['foregroundOnly']) && $trigger !== 'manual') { Sys::log("$name: foreground only, $trigger run not started"); return 'blocked'; }

        Sys::mkdirs(Paths::locks());
        $lock = fopen(self::lockFile($id), 'c');
        if (!$lock) throw new RuntimeException('cannot open lock file');
        $got = self::tryLock($lock);
        // an old-plugin run holds no lock; nothing to queue behind, so queue mode skips it
        $legacy = $got && self::legacyPid($id) !== null;
        if ($legacy) flock($lock, LOCK_UN);
        if (!$got || $legacy) {
            $mode = $opts['overlap'];
            if ($mode === 'queue' && !$legacy) {
                // at most one waiter: whoever holds <id>.lock.queue waits, everyone else is skipped
                $queued = fopen(self::lockFile($id) . '.queue', 'c');
                if ($queued && flock($queued, LOCK_EX | LOCK_NB)) {
                    flock($lock, LOCK_EX);
                    flock($queued, LOCK_UN);
                    $mode = 'go';
                }
                if ($queued) fclose($queued);
            }
            if ($mode === 'parallel') { fclose($lock); $lock = null; }
            elseif ($mode !== 'go') {
                fclose($lock);
                $now = time();
                if (History::record($id, ['start' => $now, 'end' => $now, 'exit' => 0, 'trigger' => $trigger, 'status' => 'skipped'])) History::sync($id);
                Alerts::onSkip($id, $name, $opts, self::runningSince($id) ?? $now, $now);
                Sys::log("$name: still running, $trigger run skipped");
                return 'skipped';
            }
        }

        try {
            Alerts::onStart($id);
            return self::execute($id, $name, $file, $vars, $opts, $trigger, $lock);
        } finally {
            // Released when the script's leader exits, even if it left background children holding
            // fd 9: otherwise a stray daemon would block every later run with nothing left to abort.
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function failStart(string $id, string $name, array $opts, string $trigger, int $start, string $why): string
    {
        $run = ['start' => $start, 'end' => time(), 'exit' => -1, 'trigger' => $trigger, 'status' => 'failed', 'outcome' => 'fail'];
        if (is_dir(Paths::finished())) file_put_contents(Paths::finished() . "/$id", 'failed');
        History::record($id, $run);
        History::sync($id);
        Sys::log("$name: $why");
        Alerts::onRun($id, $name, $run, $opts, '');
        return 'failed';
    }

    /** @param resource|null $lock */
    private static function execute(string $id, string $name, string $file, array $vars, array $opts, string $trigger, $lock): string
    {
        $start = time();
        $work = Paths::work($id);
        $body = str_replace("\r", '', is_file($file) ? (string)file_get_contents($file) : '');
        if (!str_starts_with($body, '#!')) $body = "#!/bin/bash\n" . $body;
        try {
            foreach ([$work, Paths::running(), Paths::finished(), Paths::aborted()] as $d) Sys::mkdirs($d);
            Sys::rm(Paths::aborted() . "/$id");
            Sys::atomicWrite("$work/script", $body);
            if (!chmod("$work/script", 0755)) throw new RuntimeException('chmod failed');
        } catch (RuntimeException) {
            return self::failStart($id, $name, $opts, $trigger, $start, 'could not copy script');
        }
        self::rotate($id, !empty($vars['clearLog']));

        $log = self::logFile($id);
        file_put_contents($log, 'Script Starting ' . date('M d, Y  H:i.s') . "\n\nFull logs for this script are available at $log\n\n", FILE_APPEND);
        $pidFile = Paths::running() . "/$id";
        Sys::rm($pidFile);
        $args = preg_split('/\s+/', trim((string)($vars['argumentDefault'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $target = !empty($vars['directPHP']) ? ['/usr/bin/php', "$work/script"] : ["$work/script"];
        // setsid makes the script a session + process group leader (pid == pgid; proc_open's child is
        // never a group leader, so setsid execs in place instead of forking). The leader writes its
        // own pid before exec'ing the script, so running/<id> holds the pgid either way.
        $cmd = ['setsid', '-w', '/bin/bash', '-c', 'echo $$ > "$0"; shift; exec "$@"', $pidFile, '_', ...$target, ...$args];
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
        // The group inherits the lock: if run.php dies, the lock stays held until the script exits.
        if ($lock) $spec[9] = $lock;
        $proc = @proc_open($cmd, $spec, $pipes, is_dir('/root') ? '/root' : $work, self::ENV + ['LANG' => getenv('LANG') ?: 'C.UTF-8'] + array_filter(['TZ' => getenv('TZ') ?: null]));
        if (!is_resource($proc)) return self::failStart($id, $name, $opts, $trigger, $start, 'could not start');

        $pg = (int)proc_get_status($proc)['pid'];
        $status = null;
        $exit = 0;
        $timeout = (int)$opts['timeout'];
        $killAt = 0;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) { // first status after exit is the only one carrying the real code
                $exit = $st['signaled'] ? 128 + (int)$st['termsig'] : (int)$st['exitcode'];
                break;
            }
            $now = time();
            if ($timeout > 0 && $status === null && $now - $start >= $timeout) {
                $status = 'timeout';
                file_put_contents($log, "\nTimed out after {$timeout}s\n", FILE_APPEND);
                self::signal($pg, self::TERM);
                $killAt = $now + self::GRACE;
            } elseif ($killAt && $now >= $killAt) {
                self::signal($pg, self::KILL);
                $killAt = 0;
            }
            usleep(200000);
        }
        proc_close($proc);
        $aborted = is_file(Paths::aborted() . "/$id");
        // the leader is gone; take down whatever it left behind in its group
        if ($status === 'timeout' || $aborted) self::killGroup($pg);
        if ($status === 'timeout') $exit = 124;
        elseif ($aborted) $status = 'aborted';
        else $status = $exit === 0 ? 'ok' : 'failed';
        Sys::rm(Paths::aborted() . "/$id");
        Sys::rm($pidFile);
        file_put_contents(Paths::finished() . "/$id", $status);
        file_put_contents($log, "\nScript Finished " . date('M d, Y  H:i.s') . "\n\nFull logs for this script are available at $log\n\n", FILE_APPEND);

        $run = ['start' => $start, 'end' => time(), 'exit' => $exit, 'trigger' => $trigger, 'status' => $status];
        $run['outcome'] = ExitCodes::outcome((string)$opts['exitCodes'], $status, $exit);
        if (History::record($id, $run)) History::sync($id);
        Alerts::onRun($id, $name, $run, $opts, self::tail($id, 10));
        return $status;
    }

    private static function rotate(string $id, bool $clear): void
    {
        $cur = self::logFile($id);
        if ($clear) { Sys::rm($cur); return; }
        if (!is_file($cur) || filesize($cur) === 0) return;
        Sys::rm(self::logFile($id, self::LOGS));
        for ($i = self::LOGS - 1; $i >= 1; $i--) {
            if (is_file(self::logFile($id, $i))) rename(self::logFile($id, $i), self::logFile($id, $i + 1));
        }
        rename($cur, self::logFile($id, 1));
    }

    /** Signals a whole process group; refuses anything that is not a plausible script group. */
    private static function signal(int $pg, int $sig): bool
    {
        if ($pg <= 1) return false;
        if (function_exists('posix_getpgrp') && $pg === posix_getpgrp()) return false; // never our own group
        if (function_exists('posix_kill')) return posix_kill(-$pg, $sig);
        exec("/bin/kill -s $sig -- -$pg 2>/dev/null", $o, $rc);
        return $rc === 0;
    }

    /** TERM the group, give it GRACE seconds, then KILL whatever is left (TERM-ignoring children included). */
    private static function killGroup(int $pg): void
    {
        if (!self::signal($pg, self::TERM)) return;
        for ($i = 0; $i < self::GRACE * 5; $i++) {
            usleep(200000);
            if (!self::signal($pg, 0)) return;
        }
        self::signal($pg, self::KILL);
    }

    public static function abort(string $id): bool
    {
        Scripts::resolve($id);
        if (!self::isRunning($id)) return false;
        if (!is_dir(Paths::aborted())) mkdir(Paths::aborted(), 0777, true);
        file_put_contents(Paths::aborted() . "/$id", '1');
        file_put_contents(self::logFile($id), "\nExecution was aborted by user\n", FILE_APPEND);
        $pidFile = Paths::running() . "/$id";
        $legacy = self::lockHeld($id) ? null : self::legacyPid($id);
        if ($legacy !== null) {
            // no lock-holding run.php: an old-plugin run (or a parallel one), so clean up here
            self::killLegacy($legacy);
            if (is_file($pidFile)) unlink($pidFile);
            return true;
        }
        // a run that has only just taken the lock may not have written its pid yet
        for ($i = 0; $i < 20 && self::pid($pidFile) <= 1; $i++) usleep(100000);
        self::killGroup(self::pid($pidFile));
        return true;
    }

    /** @return array{text:string,size:int} */
    public static function log(string $id, int $which = 0, int $offset = 0): array
    {
        Scripts::resolve($id);
        $f = self::logFile($id, max(0, min(self::LOGS, $which)));
        if (!is_file($f)) return ['text' => '', 'size' => 0];
        $size = (int)filesize($f);
        $offset = max(0, min($offset, $size));
        $max = 262144;
        if ($size - $offset > $max) $offset = $size - $max;
        $text = (string)file_get_contents($f, false, null, $offset);
        return ['text' => mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 'size' => $size];
    }

    public static function clearLogs(string $id): void
    {
        Scripts::resolve($id);
        for ($i = 0; $i <= self::LOGS; $i++) Sys::rm(self::logFile($id, $i));
    }

    public static function tail(string $id, int $lines = 10): string
    {
        $f = self::logFile($id);
        if (!is_file($f)) return '';
        $all = array_filter(explode("\n", (string)file_get_contents($f, false, null, max(0, (int)filesize($f) - 16384))),
            fn($l) => !str_starts_with($l, 'Full logs for this script') && !str_starts_with($l, 'Script Finished') && trim($l) !== '');
        return implode("\n", array_slice($all, -$lines));
    }
}
