<?php
declare(strict_types=1);

// Side effects that touch the OS, kept in one place so tests can stub them via env.
final class Sys
{
    /** @var list<list<string>> argv lists passed to launch() (test mode only) */
    public static array $launched = [];
    /** Test mode: launch() only records, starts nothing. */
    public static bool $dryRun = false;

    /** Overridable in tests. */
    public static string $localtime = '/etc/localtime';

    /** The IANA zone crond runs in: /etc/localtime's link target, else var.ini timeZone, else PHP's default. */
    public static function tz(): string
    {
        $link = @readlink(self::$localtime);
        $fromLink = is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $m) ? $m[1] : '';
        foreach ([$fromLink, (string)(self::var()['timeZone'] ?? '')] as $zone) {
            if ($zone === '') continue;
            try { new DateTimeZone($zone); return $zone; } catch (Exception) {}
        }
        return date_default_timezone_get();
    }

    public static function log(string $msg): void
    {
        if (getenv('US_TEST')) { file_put_contents(Paths::$tmp . '/syslog', $msg . "\n", FILE_APPEND); return; }
        openlog('voz.cron', 0, LOG_USER);
        syslog(LOG_INFO, $msg);
        closelog();
    }

    /** var.ini as an array; missing file means "array started, no parity check" (dev/test boxes). */
    public static function var(): array
    {
        $v = is_file(Paths::$varIni) ? @parse_ini_file(Paths::$varIni) : false;
        return is_array($v) ? $v : ['mdState' => 'STARTED', 'mdResyncPos' => '0'];
    }

    /** Creates $dir (and parents) or throws; mkdir's own warning is replaced by the exception. */
    public static function mkdirs(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException("cannot create $dir");
    }

    /** Writes via a synced temp file and rename, so readers and a power cut see old or new, never half. */
    public static function atomicWrite(string $file, string $data): void
    {
        $dir = dirname($file);
        self::mkdirs($dir);
        $tmp = $dir . '/.' . basename($file) . '.' . getmypid() . '.tmp';
        $h = @fopen($tmp, 'w');
        if ($h === false) throw new RuntimeException("cannot write $file");
        $ok = fwrite($h, $data) === strlen($data) && fflush($h) && fsync($h);
        fclose($h);
        if (!$ok || !@rename($tmp, $file)) {
            if (is_file($tmp)) unlink($tmp);
            throw new RuntimeException("cannot write $file");
        }
    }

    /** Single-quote for sh. Unlike escapeshellarg() this keeps non-ASCII bytes in the C locale. */
    public static function shq(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    /**
     * The php CLI. Under php-fpm (the API) PHP_BINARY is php-fpm itself, so use /usr/bin/php when it
     * exists; the PHP_BINARY fallback is for test containers that install php elsewhere.
     */
    public static function php(): string
    {
        return is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
    }

    /**
     * Starts $argv fully detached and returns at once: setsid -f forks into a new session, so the
     * caller (an emhttp event hook, a php-fpm request) neither waits for it nor takes it down when
     * it ends. stdio goes to /dev/null; the environment is inherited (tests pass their sandbox via US_*).
     * @param list<string> $argv
     */
    public static function launch(array $argv): bool
    {
        if (getenv('US_TEST')) {
            self::$launched[] = $argv;
            if (self::$dryRun) return true;
        }
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : 'setsid';
        $p = proc_open([$setsid, '-f', ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) return false;
        return proc_close($p) === 0;
    }

    /** Removes a file if it is there. */
    public static function rm(string $file): void
    {
        if (is_file($file)) unlink($file);
    }

    public static function updateCron(): void
    {
        if (is_executable(Paths::$updateCron)) exec(self::shq(Paths::$updateCron));
    }
}
