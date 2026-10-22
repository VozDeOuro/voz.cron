<?php
declare(strict_types=1);

// Standard 5-field cron, read the way Unraid's crond (dcron 4.5, Slackware build) reads it.
final class Cron
{
    private const array RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    private const array DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    private const array DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    /** Whether Unraid's dcron build checks the month field (Slackware patch af4076e). Verified on Unraid 7.3's crond (dcron-4.5-5_LT). */
    private const bool MONTHS_HONORED = true;

    public static function valid(string $expr): bool { return self::error($expr) === null; }

    /** null when crond will run $expr as written, else why not. */
    public static function error(string $expr): ?string
    {
        try { self::parse($expr); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    }

    /** @return array{0:list<int>,1:list<int>,2:list<int>,3:list<int>,4:list<int>} minute, hour, dom, month, dow sets */
    public static function parse(string $expr): array
    {
        // Reject tabs, newlines and other control chars anywhere (not just at the ends): the expression
        // ends up in a crontab line, so an embedded newline would inject a second entry.
        if (preg_match('/[\x00-\x1f\x7f]/', $expr)) throw new InvalidArgumentException('control character in cron');
        $f = preg_split('/\s+/', trim($expr));
        if (!$f || count($f) !== 5) throw new InvalidArgumentException('cron needs 5 fields');
        $sets = [];
        foreach ($f as $i => $field) $sets[$i] = self::field(strtolower($field), $i);
        // dcron's FixDayDow: day-of-month counts as "*" only when its table is full including index 0,
        // i.e. it was written as a bare star (or */1). Index 0 is never a real day.
        $domStar = count($sets[2]) === 32;
        $sets[2] = array_values(array_filter($sets[2], fn($d) => $d >= 1));
        if (!$sets[2]) throw new InvalidArgumentException('day-of-month step leaves no day');
        $sets[4] = array_values(array_unique(array_map(fn($d) => $d === 7 ? 0 : $d, $sets[4])));
        sort($sets[4]);
        // a restricted dom plus a restricted dow is "Nth weekday of the month" in dcron, not Vixie's OR
        if (!$domStar && count($sets[4]) !== 7) {
            throw new InvalidArgumentException("day-of-month and day-of-week can't both be set (not supported by Unraid's cron)");
        }
        if (!self::MONTHS_HONORED && count($sets[3]) !== 12) throw new InvalidArgumentException("the month field must be * on Unraid's cron");
        return $sets;
    }

    /** @return list<int> */
    private static function field(string $field, int $i): array
    {
        [$lo, $hi] = self::RANGES[$i];
        $names = $i === 3 ? self::MONTHS : ($i === 4 ? self::DAYS : []);
        $out = [];
        foreach (explode(',', $field) as $part) {
            if (!preg_match('~^(\*|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$~', $part, $m)) throw new InvalidArgumentException("bad field $field");
            $step = isset($m[2]) ? (int)$m[2] : 1;
            if ($step < 1) throw new InvalidArgumentException('bad step');
            // a step needs '*' or a range in front of it ("5/10" is an error)
            if (isset($m[2]) && $m[1] !== '*' && !str_contains($m[1], '-')) throw new InvalidArgumentException("bad step in $part");
            if ($m[1] === '*') {
                // dcron fills '*' from index 0 of its table: day-of-month 0..31 (so */2 is days 2, 4, 6 …),
                // day-of-week 0..6
                $a = $i === 2 ? 0 : $lo;
                $b = $i === 4 ? 6 : $hi;
            } else {
                $ends = explode('-', $m[1]);
                // dcron takes the range end modulo 7: 0-7 is Sunday only and 5-7 wraps round to Sunday
                if ($i === 4 && isset($ends[1]) && ctype_digit($ends[1]) && (int)$ends[1] === 7) throw new InvalidArgumentException("weekday ranges can't end in 7 on Unraid; use 0-6 or *");
                $a = self::value($ends[0], $names, $lo, $hi);
                $b = isset($ends[1]) ? self::value($ends[1], $names, $lo, $hi) : (isset($m[2]) ? $hi : $a);
                if ($a > $b) throw new InvalidArgumentException('bad range');
            }
            for ($v = $a; $v <= $b; $v += $step) $out[] = $v;
        }
        sort($out);
        return array_values(array_unique($out));
    }

    private static function value(string $s, array $names, int $lo, int $hi): int
    {
        if (isset($names[$s])) return $names[$s];
        if (!ctype_digit($s)) throw new InvalidArgumentException("bad value $s");
        $v = (int)$s;
        if ($v < $lo || $v > $hi) throw new InvalidArgumentException("out of range $s");
        return $v;
    }

    /**
     * First run strictly after $from in crond's timezone (Sys::tz()). A wall-clock time that does not exist on
     * a spring-forward DST day is skipped for that day; crond may instead run it right after the jump,
     * so the displayed "next run" can differ from the real one on that one day.
     */
    public static function next(string $expr, int $from): ?int
    {
        [$min, $hour, $dom, $mon, $dow] = self::parse($expr);
        $t = (new DateTimeImmutable('@' . ($from - $from % 60 + 60)))->setTimezone(new DateTimeZone(Sys::tz()));
        $end = $from + 366 * 86400;
        while ($t->getTimestamp() <= $end) {
            if (!in_array((int)$t->format('n'), $mon, true)) { $t = $t->modify('first day of next month')->setTime(0, 0); continue; }
            // parse() guarantees at least one of dom/dow is the full set, so AND is exact
            $dayOk = in_array((int)$t->format('j'), $dom, true) && in_array((int)$t->format('w'), $dow, true);
            if (!$dayOk) { $t = $t->modify('+1 day')->setTime(0, 0); continue; }
            if (!in_array((int)$t->format('G'), $hour, true)) { $t = $t->setTime((int)$t->format('G'), 0)->modify('+1 hour'); continue; }
            if (!in_array((int)$t->format('i'), $min, true)) { $t = $t->modify('+1 minute'); continue; }
            return $t->getTimestamp();
        }
        return null;
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    }

    /** Plain-English text for common shapes; anything else comes back as the raw expression, which callers must escape. */
    public static function describe(string $expr): string
    {
        if (!self::valid($expr)) return $expr;
        [$mi, $h, $dom, $mon, $dow] = preg_split('/\s+/', strtolower(trim($expr)));
        if ($mon !== '*') return $expr;
        $num = ctype_digit(...);
        $step = fn(string $f): ?int => preg_match('~^\*(?:/(\d+))?$~', $f, $m) ? (int)($m[1] ?? 1) : null;
        $hm = fn(int $hour) => sprintf('%02d:%02d', $hour, (int)$mi);
        $sets = self::parse($expr);
        if ($dom === '*' && $dow === '*') {
            $sm = $step($mi);
            $sh = $step($h);
            if ($sm !== null && $sh === 1) {
                if ($sm === 1) return 'every minute';
                // a step that does not divide 60 restarts at :00 every hour, so list the minutes
                return 60 % $sm === 0 ? "every $sm minutes" : 'hourly at ' . implode(', ', array_map(fn($v) => sprintf(':%02d', $v), $sets[0]));
            }
            if ($num($mi) && $sh === 1) return sprintf('hourly at :%02d', (int)$mi);
            if ($num($mi) && $sh !== null) {
                return 24 % $sh === 0 ? sprintf('every %d hours at :%02d', $sh, (int)$mi) : 'at ' . implode(', ', array_map($hm, $sets[1]));
            }
            if ($num($mi) && $num($h)) return 'daily at ' . $hm((int)$h);
        }
        if ($num($mi) && $num($h) && $dow === '*' && preg_match('~^\*/(\d+)$~', $dom, $dm)) {
            if ((int)$dm[1] === 1) return 'daily at ' . $hm((int)$h);
            $days = $sets[2];
            $list = count($days) <= 4 ? implode(', ', $days) : implode(', ', array_slice($days, 0, 3)) . ' …';
            return 'every ' . self::ordinal((int)$dm[1]) . " day ($list) at " . $hm((int)$h);
        }
        if ($num($mi) && $num($h) && $dom === '*' && preg_match('~^[a-z0-9]+(,[a-z0-9]+)*$~', $dow)) {
            $days = array_map(fn($d) => self::DAY_NAMES[(self::DAYS[$d] ?? (int)$d) % 7], explode(',', $dow));
            return implode(', ', $days) . ' at ' . $hm((int)$h);
        }
        if ($num($mi) && $num($h) && $num($dom) && $dow === '*') return "monthly on day $dom at " . $hm((int)$h);
        return $expr;
    }
}
