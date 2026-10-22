<?php
declare(strict_types=1);

// Reads the "#key=value" lines at the top of a script (upstream format), never fatals.
final class Vars
{
    public const array BOOLEANS = ['foregroundOnly', 'backgroundOnly', 'arrayStarted', 'clearLog', 'noParity', 'directPHP'];

    /** @return array<string,string> */
    public static function raw(string $file): array
    {
        $txt = $file !== '' && is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($txt === false) return [];
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $txt)) as $line) {
            if (str_starts_with($line, '#!') || trim($line) === '' || str_starts_with($line, '<?')) continue;
            if (!str_starts_with($line, '#')) break;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', ltrim(trim($line), '#'), 2);
            $k = trim($k);
            if ($k !== '') $out[$k] = trim($v);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function typed(string $file): array
    {
        $v = self::raw($file);
        foreach (self::BOOLEANS as $b) {
            if (array_key_exists($b, $v)) $v[$b] = filter_var($v[$b], FILTER_VALIDATE_BOOLEAN);
        }
        return $v;
    }
}
