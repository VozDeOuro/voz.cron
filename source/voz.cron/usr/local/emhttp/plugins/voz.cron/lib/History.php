<?php
declare(strict_types=1);

// Run history: live in tmpfs, snapshotted to historyDir by sync() (limits flash writes).
final class History
{
    private static function ram(string $id): string { return Paths::ramHistory() . '/' . rawurlencode($id) . '.jsonl'; }

    /** Overridable in tests. */
    public static string $mounts = '/proc/mounts';

    private static function isMounted(string $path): bool
    {
        $txt = is_readable(self::$mounts) ? file_get_contents(self::$mounts) : false;
        if ($txt === false) return false;
        return array_any(explode("\n", $txt), fn(string $line) => str_replace('\\040', ' ', preg_split('/\s+/', $line)[1] ?? '') === $path);
    }

    /** historyDir if it can be written now, else null (array stopped, pool path missing). */
    public static function diskDir(): ?string
    {
        $dir = rtrim(Settings::get()['historyDir'], '/');
        // /mnt/<x> only exists as real storage while mounted; never write into an empty mount point on rootfs
        if (str_starts_with($dir, '/mnt/')) {
            $second = explode('/', substr($dir, 5), 2)[0];
            if ($second === '' || !self::isMounted("/mnt/$second")) return null;
        }
        if (is_dir($dir)) return $dir;
        // create only the last component, and only when its parent already exists
        if (!is_dir(dirname($dir))) return null;
        return @mkdir($dir, 0777) || is_dir($dir) ? $dir : null;
    }

    /** A history entry's outcome. Lines written before exit-code rules have none and take it from their status. */
    public static function outcomeOf(array $e): string
    {
        $o = $e['outcome'] ?? null;
        if (is_string($o) && $o !== '') return $o;
        return match ($e['status'] ?? '') { 'ok' => 'ok', 'failed', 'timeout' => 'fail', 'aborted' => 'aborted', default => 'skipped' };
    }

    /**
     * @return bool true when status, exit or outcome changed vs the last non-skipped entry. Skips are listed but
     * never count as a change, so a busy script flapping into and out of 'skipped' doesn't sync to flash.
     */
    public static function record(string $id, array $run): bool
    {
        Sys::mkdirs(Paths::ramHistory());
        $file = self::ram($id);
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) throw new RuntimeException("cannot open history lock for $id");
        flock($lock, LOCK_EX);
        try {
            $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $prev = $lines ? json_decode((string)end($lines), true) : null;
            if (!is_array($prev)) $prev = null; // a corrupt last line starts a fresh entry
            $dur = max(0, (int)$run['end'] - (int)$run['start']);
            $run = ['start' => (int)$run['start'], 'end' => (int)$run['end'], 'exit' => (int)$run['exit'], 'trigger' => (string)$run['trigger'], 'status' => (string)$run['status'], 'outcome' => self::outcomeOf($run)];
            $differs = fn(?array $e) => !$e || ($e['status'] ?? '') !== $run['status'] || (int)($e['exit'] ?? 0) !== $run['exit'] || self::outcomeOf($e) !== $run['outcome'];
            $newLine = $differs($prev);
            $changed = $run['status'] !== 'skipped' && $differs(self::lastReal($lines));
            if ($newLine) {
                $lines[] = json_encode($run + ['dur' => $dur]);
            } else {
                $n = (int)($prev['repeat'] ?? 1);
                $pd = (int)($prev['dur'] ?? 0);
                $merged = $run + [
                    'dur' => $dur,
                    'repeat' => $n + 1,
                    'first' => (int)($prev['first'] ?? $prev['start']),
                    'dmin' => min((int)($prev['dmin'] ?? $pd), $dur),
                    'dmax' => max((int)($prev['dmax'] ?? $pd), $dur),
                    'davg' => (int)round(((int)($prev['davg'] ?? $pd) * $n + $dur) / ($n + 1)),
                ];
                $lines[count($lines) - 1] = json_encode($merged);
            }
            $cap = Settings::get()['historyCap'];
            if (count($lines) > $cap + 50) $lines = array_slice($lines, -$cap);
            Sys::atomicWrite($file, implode("\n", $lines) . "\n");
            return $changed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<string> $lines */
    private static function lastReal(array $lines): ?array
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $e = json_decode($lines[$i], true);
            if (is_array($e) && ($e['status'] ?? '') !== 'skipped') return $e;
        }
        return null;
    }

    /** @return list<array> newest first, each with an outcome */
    public static function read(string $id, int $limit = 50): array
    {
        $file = self::ram($id);
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $lines = array_slice($lines, -min($limit, Settings::get()['historyCap']));
        $entries = array_filter(array_map(fn($l) => json_decode($l, true), array_reverse($lines)), is_array(...));
        return array_values(array_map(fn(array $e) => $e + ['outcome' => self::outcomeOf($e)], $entries));
    }

    public static function last(string $id): ?array { return self::read($id, 1)[0] ?? null; }

    /**
     * The last $max runs for the sparkline, oldest first. Skipped runs are left out; a collapsed line
     * expands to one tick per run, each carrying the line's time range (first … t) and run count n.
     * @return list<array{t:int,first:int,n:int,exit:int,o:string}>
     */
    public static function spark(string $id, int $max = 20): array
    {
        $out = [];
        foreach (self::read($id, 200) as $e) {
            if (($e['status'] ?? '') === 'skipped') continue;
            $n = max(1, (int)($e['repeat'] ?? 1));
            $tick = ['t' => (int)$e['start'], 'first' => (int)($e['first'] ?? $e['start']), 'n' => $n, 'exit' => (int)($e['exit'] ?? 0), 'o' => self::outcomeOf($e)];
            for ($i = 0; $i < $n && count($out) < $max; $i++) $out[] = $tick;
            if (count($out) >= $max) break;
        }
        return array_reverse($out);
    }

    private static function syncedFile(): string { return Paths::ramHistory() . '/.synced.json'; }

    /** Records which historyDir this boot's RAM copy has been completely merged with (see seedDir()). */
    private static function seededFile(): string { return Paths::ramHistory() . '/.seeded'; }

    /** Ids deleted while their historyDir copy could not be removed (array stopped): never merged back. */
    private static function forgottenFile(): string { return Paths::ramHistory() . '/.forgotten.json'; }

    /** @return list<string> */
    private static function forgotten(): array
    {
        $f = self::forgottenFile();
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) ? array_values(array_filter($j, is_string(...))) : [];
    }

    /** @param list<string> $ids */
    private static function setForgotten(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ($ids) Sys::atomicWrite(self::forgottenFile(), json_encode($ids)); else Sys::rm(self::forgottenFile());
    }

    private static function seeded(string $dir): bool
    {
        return is_file(self::seededFile()) && file_get_contents(self::seededFile()) === $dir;
    }

    /** @return array<string,string> name => md5 of what was last written to $dir */
    private static function hashes(string $dir): array
    {
        $f = self::syncedFile();
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) && ($j['dir'] ?? null) === $dir && is_array($j['files'] ?? null) ? $j['files'] : [];
    }

    private static function saveHashes(string $dir, array $hashes): void
    {
        Sys::atomicWrite(self::syncedFile(), json_encode(['dir' => $dir, 'files' => $hashes]));
    }

    /** Copies changed RAM files to historyDir; $id limits to one script (+ state). @return int files written */
    public static function sync(?string $id = null): int
    {
        $dir = self::diskDir();
        if ($dir === null) return 0;
        // never overwrite a folder whose history this boot hasn't merged in yet (pool mounted late,
        // historyDir changed): the disk copy may hold runs RAM doesn't have
        if (!self::seeded($dir)) self::seedDir($dir);
        if (!is_dir(Paths::ramHistory())) return 0;
        $names = $id === null
            ? array_values(array_filter(scandir(Paths::ramHistory()) ?: [], fn($f) => str_ends_with($f, '.jsonl') || $f === 'state.json'))
            : [basename(self::ram($id)), 'state.json'];
        $hashes = self::hashes($dir);
        $written = [];
        $n = 0;
        foreach ($names as $name) {
            $src = Paths::ramHistory() . "/$name";
            if (!is_file($src)) continue;
            $data = (string)file_get_contents($src);
            $h = md5($data);
            if (($hashes[$name] ?? '') === $h && is_file("$dir/$name")) continue;
            try {
                Sys::atomicWrite("$dir/$name", $data);
            } catch (\Throwable $e) {
                continue;
            }
            $hashes[$name] = $h;
            if ($name !== 'state.json') $written[] = rawurldecode(substr($name, 0, -6));
            $n++;
        }
        if ($n) {
            try {
                self::saveHashes($dir, $hashes);
                // a new script of a forgotten name has replaced the old copy: nothing left to drop
                if ($written && ($gone = self::forgotten())) self::setForgotten(array_diff($gone, $written));
            } catch (\Throwable $e) {}
        }
        return $n;
    }

    /** Boot (disks mounted): merge historyDir into RAM. */
    public static function seed(): void
    {
        $dir = self::diskDir();
        if ($dir !== null) self::seedDir($dir);
    }

    /**
     * Merges $dir into RAM: disk runs older than RAM's first entry are prepended (then capped), and
     * state.json gains the scripts only the disk knows. RAM wins wherever both have data, since it is
     * newer. Files whose merged content equals the disk copy count as synced. Copies of forgotten ids are
     * deleted instead of merged. Only a complete merge marks $dir as seeded, which is what allows sync()
     * to overwrite it.
     */
    private static function seedDir(string $dir): void
    {
        Sys::mkdirs(Paths::ramHistory());
        $hashes = self::hashes($dir);
        $tomb = self::forgotten();   // fixed for this pass: state.json is filtered by it whatever order files come in
        $gone = $tomb;               // shrinks as old copies are deleted
        $complete = true;
        foreach (@scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.jsonl') && $name !== 'state.json') continue;
            if (!is_file("$dir/$name")) continue;
            $id = $name === 'state.json' ? null : rawurldecode(substr($name, 0, -6));
            if ($id !== null && in_array($id, $gone, true)) {
                if (@unlink("$dir/$name")) $gone = array_values(array_diff($gone, [$id]));
                unset($hashes[$name]);
                continue;
            }
            $disk = @file_get_contents("$dir/$name");
            if ($disk === false) { $complete = false; continue; }
            $merge = $disk;
            if ($id === null && $tomb && is_array($d = json_decode($disk, true))) {
                foreach ($tomb as $g) unset($d[$g]);
                $merge = json_encode((object)$d);
            }
            $dst = Paths::ramHistory() . "/$name";
            try {
                $data = self::locked($dst, fn() => self::mergeInto($dst, $merge, $id === null));
            } catch (\Throwable $e) {
                $complete = false;
                continue;
            }
            if ($data === $disk) $hashes[$name] = md5($data); else unset($hashes[$name]);
        }
        // a tombstone whose old copy is no longer in this folder has nothing left to drop
        $gone = array_values(array_filter($gone, fn($g) => is_file("$dir/" . rawurlencode($g) . '.jsonl')));
        try {
            self::setForgotten($gone);
            self::saveHashes($dir, $hashes);
            if ($complete) Sys::atomicWrite(self::seededFile(), $dir); else Sys::rm(self::seededFile());
        } catch (\Throwable $e) {}
    }

    /** Runs $fn holding the same lock record()/State::update() use for $file. */
    private static function locked(string $file, callable $fn): mixed
    {
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) throw new RuntimeException("cannot lock $file");
        flock($lock, LOCK_EX);
        try { return $fn(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @return string the RAM file's content after merging $disk into it */
    private static function mergeInto(string $dst, string $disk, bool $isState): string
    {
        $ram = is_file($dst) ? (string)file_get_contents($dst) : null;
        if ($ram === null) {
            $out = $disk;
        } elseif ($isState) {
            $r = json_decode($ram, true);
            $d = json_decode($disk, true);
            if (!is_array($r) || !is_array($d)) return $ram;
            $out = json_encode((object)($r + $d)); // an empty result must stay a JSON object
        } else {
            $ramLines = array_values(array_filter(explode("\n", $ram), fn($l) => $l !== ''));
            $first = null;
            foreach ($ramLines as $l) { if (is_array($e = json_decode($l, true))) { $first = $e; break; } }
            // a collapsed entry began at 'first'; disk snapshots of it start later and are already covered
            $before = $first === null ? PHP_INT_MAX : min((int)($first['first'] ?? $first['start'] ?? 0), (int)($first['start'] ?? 0));
            $older = array_values(array_filter(explode("\n", $disk), fn($l) => is_array($e = json_decode($l, true)) && (int)($e['start'] ?? 0) < $before));
            if (!$older) return $ram;
            $lines = [...$older, ...$ramLines];
            $cap = Settings::get()['historyCap'];
            if (count($lines) > $cap) $lines = array_slice($lines, -$cap);
            $out = implode("\n", $lines) . "\n";
        }
        if ($out !== $ram) Sys::atomicWrite($dst, $out);
        return $out;
    }

    /** Drops a deleted script's history (RAM and historyDir) and alert state, so a new script of that name starts clean. */
    public static function forget(string $id): void
    {
        $dir = self::diskDir();
        if ($dir !== null && !self::seeded($dir)) self::seedDir($dir); // or a later merge would bring it back
        $name = basename(self::ram($id));
        if (is_file(self::ram($id))) {
            self::locked(self::ram($id), fn() => Sys::rm(self::ram($id)));
            Sys::rm(self::ram($id) . '.lock');
        }
        $diskGone = $dir !== null && (!is_file("$dir/$name") || @unlink("$dir/$name"));
        if (!$diskGone) {
            if ($dir !== null) Sys::log("$id: could not delete $dir/$name");
            // historyDir unreachable (array stopped) or read-only: drop the copy the next time it is merged
            if (is_dir(Paths::ramHistory())) self::setForgotten([...self::forgotten(), $id]);
        }
        State::forget($id);
        self::sync($id);
    }
}
