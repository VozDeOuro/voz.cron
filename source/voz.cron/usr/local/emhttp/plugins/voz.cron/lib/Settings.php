<?php
declare(strict_types=1);

final class Settings
{
    public static function defaults(): array
    {
        return ['historyCap' => 200, 'historyDir' => Paths::$flash . '/history', 'syncMinutes' => 60];
    }

    public static function get(): array
    {
        $raw = is_file(Paths::settingsFile()) ? json_decode((string)file_get_contents(Paths::settingsFile()), true) : null;
        return self::clean(array_merge(self::defaults(), is_array($raw) ? $raw : []));
    }

    public static function set(array $in): array
    {
        if (array_key_exists('historyDir', $in) && !self::validDir(rtrim((string)$in['historyDir'], '/'))) {
            throw new InvalidArgumentException('History folder must be under /boot/ or /mnt/');
        }
        $s = self::clean(array_merge(self::get(), array_intersect_key($in, self::defaults())));
        Sys::atomicWrite(Paths::settingsFile(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $s;
    }

    /** History is written as root: only the flash drive or array/pool storage, never system paths. */
    private static function validDir(string $dir): bool
    {
        if (str_contains($dir, '..') || preg_match('/[\x00-\x1f\x7f]/', $dir)) return false;
        $roots = ['/boot/', '/mnt/'];
        if (getenv('US_TEST')) $roots[] = dirname(Paths::$flash) . '/'; // the test sandbox
        return array_any($roots, fn(string $r) => str_starts_with($dir, $r) && strlen($dir) > strlen($r));
    }

    private static function clean(array $s): array
    {
        $s['historyCap'] = max(10, min(5000, (int)$s['historyCap']));
        $s['syncMinutes'] = in_array((int)$s['syncMinutes'], [15, 30, 60], true) ? (int)$s['syncMinutes'] : 60;
        $dir = rtrim((string)$s['historyDir'], '/');
        $s['historyDir'] = self::validDir($dir) ? $dir : self::defaults()['historyDir'];
        return $s;
    }
}
