<?php
declare(strict_types=1);

// Per-script schedule and run options in config.json, keyed by script id.
final class Schedule
{
    public const array FREQUENCIES = ['disabled', 'hourly', 'daily', 'weekly', 'monthly', 'custom', 'start', 'boot', 'stop'];
    public const array DEFAULTS = ['notify' => 'off', 'failAfter' => 1, 'remindHours' => 24, 'overlap' => 'skip', 'timeout' => 0, 'hangAfter' => 3, 'exitCodes' => ''];
    private const array INTS = ['failAfter' => [1, 1000], 'remindHours' => [0, 720], 'timeout' => [0, 604800], 'hangAfter' => [1, 1000]];
    private const array KEYS = ['frequency', 'custom', 'notify', 'failAfter', 'remindHours', 'overlap', 'timeout', 'hangAfter', 'exitCodes'];

    /**
     * Stored entries keyed by id. Imports upstream's files the first time (no config.json yet). A damaged
     * file: writers ($strict) throw, so it is never overwritten with defaults and the crontab keeps the last
     * good schedule; readers (a run, the list) log it and use defaults, so scripts still run.
     */
    private static function raw(bool $strict): array
    {
        $f = Paths::configFile();
        if (!is_file($f) && Import::pending()) {
            // never write a config.json of our own over an unfinished import: it is the "imported" marker
            $msg = 'the import from User Scripts did not finish; reinstall the plugin to retry (see syslog)';
            if ($strict) throw new RuntimeException($msg);
            Sys::log("using default options: $msg");
            return [];
        }
        if (!is_file($f)) {
            try { self::migrate(); }
            catch (RuntimeException $e) {
                if ($strict) throw $e;
                Sys::log('using default options until the upstream schedule import works: ' . $e->getMessage());
                return [];
            }
        }
        $txt = @file_get_contents($f);
        $d = $txt === false ? null : json_decode($txt, true);
        if (is_array($d)) return $d;
        if ($strict) throw new RuntimeException("config.json is unreadable; fix or delete $f");
        Sys::log("config.json is unreadable, using default options until it is fixed: $f");
        return [];
    }

    private static function blank(): array { return array_merge(self::DEFAULTS, ['frequency' => 'disabled', 'custom' => '']); }

    /**
     * @param bool $strict throw instead of falling back to defaults when config.json is damaged (writers)
     * @return array<string,array> keyed by id, every existing script present
     */
    public static function all(bool $strict = false): array
    {
        $raw = self::raw($strict);
        $out = [];
        foreach (Scripts::ids() as $id) {
            $e = $raw[$id] ?? [];
            $out[$id] = array_merge(self::blank(), is_array($e) ? array_intersect_key(self::sane($e), array_flip(self::KEYS)) : []);
            if (!in_array($out[$id]['frequency'], self::FREQUENCIES, true)) $out[$id]['frequency'] = 'disabled';
        }
        return $out;
    }

    /** Drops stored option values that validate() would reject (hand edits), so defaults apply instead. */
    private static function sane(array $e): array
    {
        $ok = ['notify' => ['off', 'failure', 'always'], 'overlap' => ['skip', 'queue', 'parallel']];
        foreach ($ok as $k => $allowed) if (isset($e[$k]) && !in_array($e[$k], $allowed, true)) unset($e[$k]);
        foreach (self::INTS as $k => [$lo, $hi]) {
            if (!isset($e[$k])) continue;
            $v = filter_var($e[$k], FILTER_VALIDATE_INT);
            if ($v === false || $v < $lo || $v > $hi) unset($e[$k]); else $e[$k] = $v;
        }
        foreach (['frequency', 'custom'] as $k) if (isset($e[$k]) && !is_string($e[$k])) unset($e[$k]);
        if (isset($e['exitCodes']) && (!is_string($e['exitCodes']) || !ExitCodes::valid($e['exitCodes']))) unset($e['exitCodes']);
        return $e;
    }

    public static function get(string $id): array
    {
        Scripts::resolve($id);
        return self::all()[$id];
    }

    private static function validate(array $e): array
    {
        if (!in_array($e['frequency'], self::FREQUENCIES, true)) throw new InvalidArgumentException('bad frequency');
        if (!is_string($e['custom'])) throw new InvalidArgumentException('bad cron expression');
        // Unraid's crond (dcron 4.5) only knows lowercase or Capitalised day/month names
        $e['custom'] = strtolower(trim($e['custom']));
        if ($e['frequency'] === 'custom' && !Cron::valid($e['custom'])) throw new InvalidArgumentException('invalid cron expression');
        if (!in_array($e['notify'], ['off', 'failure', 'always'], true)) throw new InvalidArgumentException('bad notify');
        if (!in_array($e['overlap'], ['skip', 'queue', 'parallel'], true)) throw new InvalidArgumentException('bad overlap');
        foreach (self::INTS as $k => [$lo, $hi]) {
            if (filter_var($e[$k], FILTER_VALIDATE_INT) === false || (int)$e[$k] < $lo || (int)$e[$k] > $hi) throw new InvalidArgumentException("$k must be $lo-$hi");
            $e[$k] = (int)$e[$k];
        }
        if (!is_string($e['exitCodes'])) throw new InvalidArgumentException('bad exit codes');
        $e['exitCodes'] = ExitCodes::normalize($e['exitCodes']);
        return $e;
    }

    public static function set(string $id, array $fields): array
    {
        Scripts::resolve($id);
        $all = self::all(true);
        $all[$id] = self::validate(array_merge($all[$id], array_intersect_key($fields, array_flip(self::KEYS))));
        self::save($all);
        return $all[$id];
    }

    /** @param array<string,array> $changes */
    public static function apply(array $changes): void
    {
        $all = self::all(true);
        foreach ($changes as $id => $fields) {
            Scripts::resolve((string)$id);
            $all[$id] = self::validate(array_merge($all[$id], array_intersect_key((array)$fields, array_flip(self::KEYS))));
        }
        self::save($all);
        self::writeCron();
    }

    /** Rewrites config.json without entries for scripts that no longer exist (call after a delete). */
    public static function prune(): void
    {
        self::save(self::all(true));
    }

    private static function save(array $byId): void
    {
        $out = [];
        foreach ($byId as $id => $e) $out[(string)$id] = array_intersect_key($e, array_flip(self::KEYS));
        // (object): ids like "0" or "2024" become integer keys, and a list-shaped array would encode as [...]
        Sys::atomicWrite(Paths::configFile(), json_encode((object)$out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public static function byFrequency(string $f): array
    {
        return array_map('strval', array_keys(array_filter(self::all(), fn($e) => $e['frequency'] === $f)));
    }

    /**
     * One-time import of upstream's schedule.json (keyed by script path) and of startCustom.php lines
     * hand-added to customSchedule.cron, both read from $from (default: our own flash dir; Import passes
     * the original plugin's). Runs only while config.json does not exist. Nothing in $from is written, so
     * reinstalling the upstream plugin finds its own files as they were.
     * @return int entries imported (0 when config.json already existed)
     */
    public static function migrate(?string $from = null): int
    {
        if (is_file(Paths::configFile())) return 0;
        $from = rtrim($from ?? Paths::$flash, '/');
        $old = $from . '/schedule.json';
        $raw = null;
        if (is_file($old)) {
            $txt = @file_get_contents($old);
            $raw = $txt === false ? null : json_decode($txt, true);
            if (!is_array($raw)) {
                $msg = "schedule import skipped: $old exists but cannot be read or decoded; config.json not written, fix or remove it and retry";
                Sys::log($msg);
                throw new RuntimeException($msg);
            }
        }
        $all = [];
        foreach (Scripts::ids() as $id) $all[$id] = self::blank();
        $n = 0;
        // keys are paths under $from/scripts; upstream itself only ever wrote its own flash path or its older emhttp one
        $dirs = array_unique([$from . '/scripts', Paths::scripts(), '/boot/config/plugins/user.scripts/scripts', '/usr/local/emhttp/plugins/user.scripts/scripts']);
        $prefix = '~^(?:' . implode('|', array_map(fn($d) => preg_quote($d, '~'), $dirs)) . ')/([^/]+)/script$~';
        foreach (is_array($raw) ? $raw : [] as $path => $e) {
            if (!is_array($e) || !preg_match($prefix, (string)$path, $m) || !isset($all[$m[1]])) continue;
            $id = $m[1];
            $all[$id] = array_merge($all[$id], array_intersect_key(self::sane($e), array_flip(self::KEYS)));
            if (!in_array($all[$id]['frequency'], self::FREQUENCIES, true)) $all[$id]['frequency'] = 'disabled';
            $all[$id]['custom'] = strtolower(trim($all[$id]['custom']));
            $n++;
        }
        $n += self::importCronLines($all, $from . '/customSchedule.cron');
        foreach ($all as $id => $e) {
            if ($e['frequency'] === 'custom' && ($why = Cron::error($e['custom'])) !== null) Sys::log("$id: schedule \"{$e['custom']}\" kept but not scheduled: $why");
        }
        self::save($all);
        return $n;
    }

    /** Upstream-format lines (startCustom.php <path>) that schedule.json does not already schedule. */
    private static function importCronLines(array &$all, string $f): int
    {
        if (!is_file($f)) return 0;
        $n = 0;
        foreach (explode("\n", (string)file_get_contents($f)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match('~^((?:\S+\s+){4}\S+)\s+\S*startCustom\.php\s+(.*?)/script(?:\s|$)~', $line, $m)) continue;
            $id = basename($m[2]);
            $expr = strtolower((string)preg_replace('/\s+/', ' ', $m[1]));
            if (!isset($all[$id]) || !Cron::valid($expr)) continue;
            if ($all[$id]['frequency'] === 'custom' && $all[$id]['custom'] !== '') continue;
            $all[$id]['frequency'] = 'custom';
            $all[$id]['custom'] = $expr;
            $n++;
        }
        return $n;
    }

    public static function cronLine(string $expr, string $id): string
    {
        // dcron hands the command to /bin/sh -c verbatim; '%' is not special there (unlike Vixie cron)
        return "$expr " . Paths::$plugin . '/run.php --script ' . Sys::shq($id) . ' --trigger custom > /dev/null 2>&1';
    }

    public static function writeCron(): void
    {
        $mins = Settings::get()['syncMinutes'];
        $sync = $mins === 60 ? '17 * * * *' : "*/$mins * * * *";
        $out = "# Generated cron schedule for voz.cron\n";
        foreach (self::all(true) as $id => $e) {
            $id = (string)$id;
            if ($e['frequency'] !== 'custom') continue;
            if (($why = Cron::error($e['custom'])) !== null) { Sys::log("$id: schedule \"{$e['custom']}\" not written: $why"); continue; }
            // a newline in a hand-made folder name would inject a second crontab entry
            if (preg_match('/[\x00-\x1f\x7f]/', $id)) { Sys::log("not scheduling script with a control character in its folder name: " . json_encode($id)); continue; }
            $out .= self::cronLine(strtolower($e['custom']), $id) . "\n";
        }
        $out .= "$sync " . Paths::$plugin . "/run.php --sync-history > /dev/null 2>&1\n";
        Sys::atomicWrite(Paths::cronFile(), $out);
        Sys::updateCron();
    }
}
