<?php
declare(strict_types=1);

final class LayoutConflict extends RuntimeException
{
    public function __construct(public readonly array $current) { parent::__construct('layout changed elsewhere', 409); }
}

// layout.json on flash: one level of folders plus the top-level order. The scripts folder is the source of
// truth; get() reconciles the file against it and never writes.
final class Layout
{
    private const string FOLDER_ID = '/^f-[A-Za-z0-9_-]{1,32}$/';

    private static function raw(): array
    {
        $f = Paths::layoutFile();
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    /** @return array<string,true> */
    private static function known(): array
    {
        $k = [];
        foreach (Scripts::ids() as $id) $k[(string)$id] = true;
        return $k;
    }

    private static function nameOk(mixed $n): bool { return is_string($n) && ($t = trim($n)) !== '' && strlen($t) <= 64 && !preg_match('/[\x00-\x1f\x7f]/', $t); }

    private static function iconOk(mixed $i): bool { return is_string($i) && strlen($i) <= 16 && !preg_match('/[\x00-\x1f\x7f]/', $i); }

    /** @return array{version:int,items:list<array>} */
    public static function get(): array
    {
        $raw = self::raw();
        $known = self::known();
        $seen = [];
        $fids = [];
        $items = [];
        foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $it) {
            if (!is_array($it)) continue;
            $type = $it['type'] ?? '';
            if ($type === 'script') {
                $id = $it['id'] ?? null;
                if (is_string($id) && isset($known[$id]) && !isset($seen[$id])) { $seen[$id] = true; $items[] = ['type' => 'script', 'id' => $id]; }
            } elseif ($type === 'folder' && is_string($it['id'] ?? null) && preg_match(self::FOLDER_ID, $it['id']) && !isset($fids[$it['id']]) && self::nameOk($it['name'] ?? null)) {
                $fids[$it['id']] = true;
                $kids = [];
                foreach (is_array($it['scripts'] ?? null) ? $it['scripts'] : [] as $id) {
                    if (is_string($id) && isset($known[$id]) && !isset($seen[$id])) { $seen[$id] = true; $kids[] = $id; }
                }
                $icon = $it['icon'] ?? '';
                $items[] = ['type' => 'folder', 'id' => $it['id'], 'name' => trim($it['name']), 'icon' => self::iconOk($icon) ? $icon : '', 'scripts' => $kids];
            }
        }
        $rest = [];
        foreach (Scripts::ids() as $id) if (!isset($seen[(string)$id])) $rest[] = (string)$id;
        usort($rest, fn(string $a, string $b) => strnatcasecmp(Scripts::name($a), Scripts::name($b)));
        foreach ($rest as $id) $items[] = ['type' => 'script', 'id' => $id];
        return ['version' => (int)($raw['version'] ?? 0), 'items' => $items];
    }

    /**
     * Writes a new layout when $version is the stored one. With $after (the items of a save the caller sent with
     * $version and may still be in flight; used when the page unloads with a second move queued), the save is also
     * accepted when the stored layout is exactly that save's result: version $version + 1 holding $after's items.
     * Any other state (another tab saved in between) is a conflict.
     */
    public static function save(array $doc, int $version, ?array $after = null): array
    {
        $items = self::validate($doc);
        $afterItems = $after === null ? null : self::validate(['items' => $after]);
        Sys::mkdirs(Paths::$tmp);
        $lock = @fopen(Paths::$tmp . '/layout.lock', 'c');
        if ($lock === false) throw new RuntimeException('cannot open layout lock');
        flock($lock, LOCK_EX);
        try {
            $current = (int)(self::raw()['version'] ?? 0);
            $ok = $version === $current
                || ($afterItems !== null && $current === $version + 1 && (self::raw()['items'] ?? null) === $afterItems);
            if (!$ok) throw new LayoutConflict(self::get());
            Sys::atomicWrite(Paths::layoutFile(), json_encode(['version' => $current + 1, 'items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::get();
    }

    /** @return list<array> */
    private static function validate(array $doc): array
    {
        $items = $doc['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) throw new InvalidArgumentException('layout items must be a list');
        $known = self::known();
        $seen = [];
        $fids = [];
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) throw new InvalidArgumentException('bad layout item');
            $type = $it['type'] ?? '';
            if ($type === 'script') {
                $out[] = ['type' => 'script', 'id' => self::scriptId($it['id'] ?? null, $known, $seen)];
                continue;
            }
            if ($type !== 'folder') throw new InvalidArgumentException('bad layout item type');
            $id = $it['id'] ?? null;
            if (!is_string($id) || !preg_match(self::FOLDER_ID, $id)) throw new InvalidArgumentException('bad folder id');
            if (isset($fids[$id])) throw new InvalidArgumentException("duplicate folder id $id");
            $fids[$id] = true;
            if (!self::nameOk($it['name'] ?? null)) throw new InvalidArgumentException('folder name must be 1-64 bytes');
            $icon = $it['icon'] ?? '';
            if (!self::iconOk($icon)) throw new InvalidArgumentException('folder icon must be at most 16 bytes');
            $kids = $it['scripts'] ?? [];
            if (!is_array($kids) || !array_is_list($kids)) throw new InvalidArgumentException('folder scripts must be a list');
            $ids = [];
            foreach ($kids as $k) $ids[] = self::scriptId($k, $known, $seen);
            $out[] = ['type' => 'folder', 'id' => $id, 'name' => trim($it['name']), 'icon' => $icon, 'scripts' => $ids];
        }
        return $out;
    }

    /** @param array<string,true> $seen */
    private static function scriptId(mixed $id, array $known, array &$seen): string
    {
        if (!is_string($id)) throw new InvalidArgumentException('unknown script in layout');
        Scripts::resolve($id); // throws InvalidArgumentException for an unknown or malformed id
        if (isset($seen[$id])) throw new InvalidArgumentException("script listed twice in layout: $id");
        $seen[$id] = true;
        return $id;
    }
}
