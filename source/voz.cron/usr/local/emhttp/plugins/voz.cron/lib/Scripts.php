<?php
declare(strict_types=1);

// A script is a directory under Paths::scripts(); its id is the directory name.
final class Scripts
{
    // upstream's forbidden characters for names
    private const array BAD = ['\\', '/', '*', '?', '.', "'", ';', ':', ',', '|', '+', '=', '<', '>', '[', ']', '"', '!', '(', ')', '&'];

    /** @var callable(string): (list<string>|false) directory lister, swappable so tests can make it fail */
    public static $scandir = 'scandir';

    /**
     * An unreadable scripts folder throws instead of looking empty: callers rewrite config.json and the
     * crontab from this list, and an empty list would wipe every schedule.
     * @return list<string>
     */
    public static function ids(): array
    {
        $root = Paths::scripts();
        if (!is_dir($root)) return [];
        $entries = @(self::$scandir)($root);
        if ($entries === false) throw new RuntimeException('cannot read scripts folder');
        $ids = array_values(array_filter($entries, fn($d) => $d !== '.' && $d !== '..' && is_dir("$root/$d")));
        natcasesort($ids);
        return array_values($ids);
    }

    public static function resolve(string $id): string
    {
        if ($id === '' || str_contains($id, '/') || str_contains($id, "\0") || !in_array($id, self::ids(), true)) {
            throw new InvalidArgumentException('unknown script');
        }
        return Paths::scripts() . '/' . $id;
    }

    public static function file(string $id): string { return self::resolve($id) . '/script'; }

    public static function name(string $id): string
    {
        $dir = self::resolve($id);
        $n = is_file("$dir/name") ? trim((string)file_get_contents("$dir/name")) : '';
        return $n !== '' ? $n : ((Vars::raw("$dir/script")['name'] ?? $id) ?: $id);
    }

    public static function description(string $id): string
    {
        $dir = self::resolve($id);
        $d = is_file("$dir/description") ? trim(str_replace("\r", '', (string)file_get_contents("$dir/description"))) : '';
        return $d !== '' ? $d : (Vars::raw("$dir/script")['description'] ?? '');
    }

    public static function sanitize(string $name): string
    {
        // control characters (newline!) would end up in ids, paths and crontab lines
        $n = (string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', html_entity_decode($name, ENT_QUOTES));
        $n = str_replace(self::BAD, '', $n);
        $n = trim((string)preg_replace('/ {2,}/', ' ', $n));
        return trim(mb_strcut($n, 0, 200));
    }

    public static function create(string $name): string
    {
        $id = self::sanitize($name);
        if ($id === '') throw new InvalidArgumentException('empty name');
        $base = $id;
        while (file_exists(Paths::scripts() . '/' . $id)) $id = $base . '-' . mt_rand(1000, 9999);
        Sys::mkdirs(Paths::scripts() . '/' . $id);
        Sys::atomicWrite(Paths::scripts() . "/$id/script", "#!/bin/bash\n");
        Sys::atomicWrite(Paths::scripts() . "/$id/name", $base);
        return $id;
    }

    public static function rename(string $id, string $name): void
    {
        $n = self::sanitize($name);
        if ($n === '') throw new InvalidArgumentException('empty name');
        Sys::atomicWrite(self::resolve($id) . '/name', $n);
    }

    public static function describe(string $id, string $desc): void
    {
        Sys::atomicWrite(self::resolve($id) . '/description', trim(str_replace("\r", '', $desc)));
    }

    /** Removes the script dir in PHP (no shell: escapeshellarg() drops non-ASCII bytes in the C locale). */
    public static function delete(string $id): void
    {
        self::removeTree(self::resolve($id));
    }

    /** Recursive delete that never follows symlinks: a link (even to a dir) is unlinked, never descended. */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if ((file_exists($path) || is_link($path)) && !@unlink($path)) throw new RuntimeException("cannot delete $path");
            return;
        }
        $entries = @scandir($path);
        if ($entries === false) throw new RuntimeException("cannot read $path");
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') self::removeTree("$path/$e");
        }
        if (!@rmdir($path)) throw new RuntimeException("cannot delete $path");
    }

    public static function read(string $id): string
    {
        $f = self::file($id);
        return is_file($f) ? str_replace("\r", '', (string)file_get_contents($f)) : '';
    }

    public static function save(string $id, string $body): void
    {
        Sys::atomicWrite(self::file($id), str_replace("\r", '', $body));
    }
}
