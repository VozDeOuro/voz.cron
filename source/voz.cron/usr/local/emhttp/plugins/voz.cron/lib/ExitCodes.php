<?php
declare(strict_types=1);

// Per-script exit-code rules, e.g. "0=ok 1,3-5=warn 137=ignore *=fail". First matching rule wins.
final class ExitCodes
{
    public const string DEFAULT = '0=ok *=fail';
    public const array OUTCOMES = ['ok', 'warn', 'ignore', 'fail'];

    /** @return list<array{codes: ?list<array{0:int,1:int}>, outcome: string}> codes null means "*" */
    public static function parse(string $rules): array
    {
        $rules = trim($rules);
        if ($rules === '') $rules = self::DEFAULT;
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $rules)) throw new InvalidArgumentException('control character in exit codes');
        $out = [];
        $star = false;
        foreach (preg_split('/\s+/', $rules) as $tok) {
            if (!preg_match('/^([^=]+)=([a-z]+)$/', $tok, $m)) throw new InvalidArgumentException("bad rule \"$tok\": use codes=outcome, e.g. 1=warn");
            if (!in_array($m[2], self::OUTCOMES, true)) throw new InvalidArgumentException("unknown outcome \"{$m[2]}\" in \"$tok\": use ok, warn, ignore or fail");
            if ($m[1] === '*') {
                if ($star) throw new InvalidArgumentException("\"*\" used twice (\"$tok\")");
                $star = true;
                $out[] = ['codes' => null, 'outcome' => $m[2]];
                continue;
            }
            $codes = [];
            foreach (explode(',', $m[1]) as $c) {
                if (!preg_match('/^(\d+)(?:-(\d+))?$/', $c, $r)) throw new InvalidArgumentException("bad code \"$c\" in \"$tok\"");
                $a = (int)$r[1];
                $b = isset($r[2]) ? (int)$r[2] : $a;
                if ($a > 255 || $b > 255) throw new InvalidArgumentException("code \"$c\" is outside 0-255");
                if ($a > $b) throw new InvalidArgumentException("range \"$c\" is reversed");
                $codes[] = [$a, $b];
            }
            $out[] = ['codes' => $codes, 'outcome' => $m[2]];
        }
        return $out;
    }

    public static function valid(string $rules): bool
    {
        try { self::parse($rules); return true; } catch (InvalidArgumentException) { return false; }
    }

    /** The stored form: '' for the default, else the rules with single spaces. Throws on invalid rules. */
    public static function normalize(string $rules): string
    {
        self::parse($rules);
        $t = trim($rules);
        return $t === '' ? '' : implode(' ', preg_split('/\s+/', $t));
    }

    /**
     * ok|warn|ignore|fail for a finished run; aborted and skipped runs keep their status. Timeouts and
     * exits outside 0-255 (a run that never started records -1) always fail, whatever the rules say.
     */
    public static function outcome(string $rules, string $status, int $exit): string
    {
        if ($status === 'aborted' || $status === 'skipped') return $status;
        if ($status === 'timeout' || $exit < 0 || $exit > 255) return 'fail';
        try { $parsed = self::parse($rules); } catch (InvalidArgumentException) { $parsed = self::parse(self::DEFAULT); }
        foreach ($parsed as $r) {
            if ($r['codes'] === null) return $r['outcome'];
            foreach ($r['codes'] as [$a, $b]) if ($exit >= $a && $exit <= $b) return $r['outcome'];
        }
        return 'fail';
    }
}
