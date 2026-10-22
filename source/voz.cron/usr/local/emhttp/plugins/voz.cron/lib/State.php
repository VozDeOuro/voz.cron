<?php
declare(strict_types=1);

// Per-script alert state in RAM (synced to disk with history). All writes go through update().
final class State
{
    public const array BLANK = ['health' => 'ok', 'fails' => 0, 'firstFail' => 0, 'lastExit' => 0, 'lastAlert' => 0, 'alerted' => false, 'skips' => 0, 'stuckAlerted' => false, 'warnSince' => 0, 'lastWarnAlert' => 0];

    private static function file(): string { return Paths::ramHistory() . '/state.json'; }

    public static function all(): array
    {
        $f = self::file();
        if (!is_file($f)) return [];
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
        Sys::log('state.json is unreadable, alert state starts over');
        return [];
    }

    public static function get(string $id): array { return array_merge(self::BLANK, self::all()[$id] ?? []); }

    public static function forget(string $id): void
    {
        if (!is_file(self::file())) return;
        $lock = @fopen(self::file() . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('cannot open state lock');
        flock($lock, LOCK_EX);
        try {
            $all = self::all();
            if (!array_key_exists($id, $all)) return;
            unset($all[$id]);
            Sys::atomicWrite(self::file(), json_encode((object)$all));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function update(string $id, callable $fn): array
    {
        Sys::mkdirs(Paths::ramHistory());
        $lock = @fopen(self::file() . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('cannot open state lock');
        flock($lock, LOCK_EX);
        try {
            $all = self::all();
            $all[$id] = $fn(array_merge(self::BLANK, $all[$id] ?? []));
            Sys::atomicWrite(self::file(), json_encode($all));
            return $all[$id];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
