<?php
declare(strict_types=1);

/**
 * First-install import from the original User Scripts plugin (upstream, or this plugin's own earlier
 * releases under that id). Paths::$oldFlash and Paths::$oldTmp are only ever read: the old plugin's folder
 * stays exactly as it was, so reinstalling it is a full rollback. config.json is written last and is the
 * "already imported" marker; while an import is unfinished (.import-pending exists) Schedule does not create
 * config.json on its own, so a failed or interrupted import is retried by the next install.
 */
final class Import
{
    private static function pendingFile(): string { return Paths::$flash . '/.import-pending'; }

    /** An import started and did not finish. */
    public static function pending(): bool { return is_file(self::pendingFile()); }

    /** Old files copied as they are (state.json normally lives in history/, a top-level one is kept too). */
    private const array FILES = ['layout.json', 'state.json'];

    /** @return string 'imported', 'nothing' (no old data) or 'already' (config.json exists) */
    public static function fromOld(): string
    {
        if (is_file(Paths::configFile()) && !self::pending()) return 'already';
        $old = rtrim(Paths::$oldFlash, '/');
        $hasScripts = is_dir("$old/scripts") && array_diff(scandir("$old/scripts") ?: [], ['.', '..']);
        if (!is_dir($old) || realpath($old) === realpath(Paths::$flash) || (!$hasScripts && !is_file("$old/config.json"))) {
            Sys::rm(self::pendingFile()); // the old folder is gone since a failed attempt: nothing left to wait for
            return 'nothing'; // (schedules without scripts import nothing)
        }

        Sys::mkdirs(Paths::scripts());
        file_put_contents(self::pendingFile(), "$old\n");
        $n = is_dir("$old/scripts") ? self::copyTree("$old/scripts", Paths::scripts()) : 0;
        foreach (self::FILES as $f) {
            if (is_file("$old/$f") && !is_file(Paths::$flash . "/$f")) $n += self::copyFile("$old/$f", Paths::$flash . "/$f");
        }
        self::importSettings($old);
        if (is_dir("$old/history")) $n += self::copyTree("$old/history", Paths::$flash . '/history');
        // RAM history the old plugin had not synced to flash yet; History::seed() merges the flash copy into it
        $ramOld = rtrim(Paths::$oldTmp, '/') . '/history';
        foreach (@scandir($ramOld) ?: [] as $name) {
            if (!str_ends_with($name, '.jsonl') && $name !== 'state.json') continue;
            Sys::mkdirs(Paths::ramHistory());
            if (is_file("$ramOld/$name") && !is_file(Paths::ramHistory() . "/$name")) $n += self::copyFile("$ramOld/$name", Paths::ramHistory() . "/$name");
        }

        if (is_file("$old/config.json")) {
            $txt = @file_get_contents("$old/config.json");
            if ($txt === false) throw new RuntimeException("cannot read $old/config.json");
            Sys::atomicWrite(Paths::configFile(), $txt); // atomic: a half-written marker would stop every retry
            $n++;
        } else {
            if (!is_file(Paths::configFile())) $n += Schedule::migrate($old); // upstream: its schedule file and customSchedule.cron, read in place
        }
        Sys::rm(self::pendingFile());
        Sys::log("imported $n items from $old (that folder was not changed)");
        return 'imported';
    }

    /**
     * The old plugin already ran this boot's "At First Array Start Only" scripts when its marker exists;
     * carrying it over keeps the next array start from running them a second time.
     */
    public static function carryBootMarker(): void
    {
        $src = rtrim(Paths::$oldTmp, '/') . '/booted';
        $dst = Paths::$tmp . '/booted';
        if (!is_file($src) || is_file($dst)) return;
        Sys::mkdirs(Paths::$tmp);
        file_put_contents($dst, "booted\n");
    }

    /** settings.json, with a history folder inside the old flash dir moved to the same place in ours. */
    private static function importSettings(string $old): void
    {
        $src = "$old/settings.json";
        if (!is_file($src) || is_file(Paths::settingsFile())) return;
        $s = json_decode((string)file_get_contents($src), true);
        if (!is_array($s)) { Sys::log("$src is unreadable, settings start from defaults"); return; }
        $dir = rtrim((string)($s['historyDir'] ?? ''), '/');
        if ($dir === $old || str_starts_with($dir, "$old/")) $s['historyDir'] = Paths::$flash . substr($dir, strlen($old));
        Sys::atomicWrite(Paths::settingsFile(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** Copies $src into $dst recursively, never replacing a file that already exists. @return int files copied */
    private static function copyTree(string $src, string $dst): int
    {
        Sys::mkdirs($dst);
        $n = 0;
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $from = "$src/$name";
            $to = "$dst/$name";
            if (is_link($from)) continue;
            if (is_dir($from)) $n += self::copyTree($from, $to);
            elseif (is_file($from) && !file_exists($to)) $n += self::copyFile($from, $to);
        }
        return $n;
    }

    private static function copyFile(string $from, string $to): int
    {
        Sys::mkdirs(dirname($to));
        if (!@copy($from, $to)) throw new RuntimeException("cannot copy $from to $to");
        @chmod($to, fileperms($from) & 0777);
        @touch($to, (int)filemtime($from));
        return 1;
    }
}
