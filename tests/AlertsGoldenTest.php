<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Frozen record of what notifications look like before exit-code outcomes changed Alerts. Each row replays a run
 * sequence through Alerts::decide and pins every notification (level + subject) and the alert-state
 * fields that existed then. Rule: never edit this file; new behavior only adds tests elsewhere.
 */
final class AlertsGoldenTest extends TestCase
{
    private const array KEYS = ['health', 'fails', 'firstFail', 'lastExit', 'lastAlert', 'alerted', 'skips', 'stuckAlerted'];
    private const array OPTS = ['notify' => 'failure', 'failAfter' => 1, 'remindHours' => 24, 'hangAfter' => 3];
    private const array OK = ['health' => 'ok', 'fails' => 0, 'firstFail' => 0, 'lastExit' => 0, 'lastAlert' => 0, 'alerted' => false, 'skips' => 0, 'stuckAlerted' => false];
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    /** @param list<array{int,int,string}> $events [start, exit, status]; each run lasts 5 s and is decided when it ends */
    private function replay(array $events, array $opts): array
    {
        $s = State::BLANK;
        $sent = [];
        foreach ($events as [$t, $exit, $status]) {
            [$s, $n] = Alerts::decide($s, ['start' => $t, 'end' => $t + 5, 'exit' => $exit, 'trigger' => 'custom', 'status' => $status], $opts, 'Job', $t + 5);
            if ($n) $sent[] = $n['level'] . ':' . $n['subject'];
        }
        return [array_intersect_key($s, array_flip(self::KEYS)), $sent];
    }

    private static function failing(int $fails, int $firstFail, int $lastExit, int $lastAlert, bool $alerted = true): array
    {
        return ['health' => 'failing', 'fails' => $fails, 'firstFail' => $firstFail, 'lastExit' => $lastExit, 'lastAlert' => $lastAlert, 'alerted' => $alerted, 'skips' => 0, 'stuckAlerted' => false];
    }

    public static function rows(): array
    {
        $o = self::OPTS;
        $thousand = array_map(fn($i) => [$i * 60, 1, 'failed'], range(0, 999));
        return [
            'single failure alerts' => [[[0, 1, 'failed']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)'], self::failing(1, 0, 1, 5)],
            'recovery after an alert' => [[[0, 1, 'failed'], [3600, 0, 'ok']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'normal:Job recovered after 1h 0m (1 failed run)'], self::OK],
            'failAfter absorbs a blip, alerts on the third' => [[[0, 1, 'failed'], [60, 0, 'ok'], [120, 1, 'failed'], [180, 1, 'failed'], [240, 1, 'failed']], ['failAfter' => 3] + $o,
                ['alert:Job failing: exit 1 (3 runs since 00:02)'], self::failing(3, 120, 1, 245)],
            'same exit stays quiet, a new exit warns' => [[[0, 1, 'failed'], [60, 1, 'failed'], [120, 2, 'failed']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'warning:Job now failing with exit 2'], self::failing(3, 0, 2, 125)],
            'reminder after remindHours' => [[[0, 1, 'failed'], [86400, 1, 'failed']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'warning:Job still failing for 1d 0h, 2 failed runs'], self::failing(2, 0, 1, 86405)],
            'second reminder a day after the first' => [[[0, 1, 'failed'], [86400, 1, 'failed'], [90000, 1, 'failed'], [172800, 1, 'failed']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'warning:Job still failing for 1d 0h, 2 failed runs', 'warning:Job still failing for 2d 0h, 4 failed runs'], self::failing(4, 0, 1, 172805)],
            'remindHours 0 never reminds' => [[[0, 1, 'failed'], [200000, 1, 'failed']], ['remindHours' => 0] + $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)'], self::failing(2, 0, 1, 5)],
            'timeout alerts as timed out' => [[[0, 124, 'timeout']], $o,
                ['alert:Job timed out (1 run since 00:00)'], self::failing(1, 0, 124, 5)],
            'timeout after a failure says now timing out' => [[[0, 1, 'failed'], [60, 124, 'timeout']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'warning:Job now timing out'], self::failing(2, 0, 124, 65)],
            'aborted and skipped change nothing' => [[[0, 137, 'aborted'], [60, 0, 'skipped']], $o, [], self::OK],
            'an abort keeps the failing streak' => [[[0, 1, 'failed'], [60, 137, 'aborted']], $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)'], self::failing(1, 0, 1, 5)],
            'always: success sends finished' => [[[0, 0, 'ok']], ['notify' => 'always'] + $o, ['normal:Job finished in 5s'], self::OK],
            'always: recovery replaces finished' => [[[0, 1, 'failed'], [60, 0, 'ok']], ['notify' => 'always'] + $o,
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'normal:Job recovered after 1m 5s (1 failed run)'], self::OK],
            'always: an un-alerted blip then success says finished' => [[[0, 1, 'failed'], [60, 0, 'ok']], ['notify' => 'always', 'failAfter' => 3] + $o,
                ['normal:Job finished in 5s'], self::OK],
            'off: counts the streak, sends nothing' => [[[0, 1, 'failed'], [60, 2, 'failed']], ['notify' => 'off'] + $o, [], self::failing(2, 0, 2, 0, false)],
            'off: success after failures resets quietly' => [[[0, 1, 'failed'], [60, 0, 'ok']], ['notify' => 'off'] + $o, [], self::OK],
            'failAfter 2: one failure then success is silent' => [[[0, 1, 'failed'], [60, 0, 'ok']], ['failAfter' => 2] + $o, [], self::OK],
            'large counts: raw count in the alert, separators in the reminder' => [[...$thousand, [146340, 1, 'failed']], ['failAfter' => 1000] + $o,
                ['alert:Job failing: exit 1 (1000 runs since 00:00)', 'warning:Job still failing for 1d 16h, 1,001 failed runs'], self::failing(1001, 0, 1, 146345)],
            'missing options fall back to defaults' => [[[0, 1, 'failed'], [86400, 1, 'failed']], ['notify' => 'failure'],
                ['alert:Job failing: exit 1 (1 run since 00:00)', 'warning:Job still failing for 1d 0h, 2 failed runs'], self::failing(2, 0, 1, 86405)],
        ];
    }

    #[DataProvider('rows')]
    public function testDecide(array $events, array $opts, array $wantSent, array $wantState): void
    {
        [$state, $sent] = $this->replay($events, $opts);
        $this->assertSame($wantSent, $sent);
        $this->assertSame($wantState, $state);
    }

    private function captureNotify(): string
    {
        $file = $this->root . '/sent';
        file_put_contents(Paths::$notify, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done >> " . escapeshellarg($file) . "\n");
        chmod(Paths::$notify, 0755);
        return $file;
    }

    /** @return array<string,string> notify flag => value for the last call */
    private function lastCall(string $file): array
    {
        $args = explode("\0", rtrim((string)file_get_contents($file), "\0"));
        $out = [];
        for ($i = 0; $i < count($args) - 1; $i++) if (preg_match('/^-[a-z]$/', $args[$i])) $out[$args[$i]] = $args[$i + 1];
        return $out;
    }

    public function testOnRunPassesEventSubjectLevelAndLogTail(): void
    {
        $this->makeScript('a');
        $file = $this->captureNotify();
        Alerts::onRun('a', 'Job', ['start' => 0, 'end' => 5, 'exit' => 1, 'trigger' => 'custom', 'status' => 'failed'], self::OPTS, "line1\nline2");
        $call = $this->lastCall($file);
        $this->assertSame('VOZ_CRON', $call['-e']);
        $this->assertSame('Job failing: exit 1 (1 run since 00:00)', $call['-s']);
        $this->assertSame('alert', $call['-i']);
        $this->assertSame("line1\nline2", $call['-m']);
    }

    public function testStuckNotification(): void
    {
        $this->makeScript('a');
        $file = $this->captureNotify();
        for ($i = 0; $i < 3; $i++) Alerts::onSkip('a', 'Job', self::OPTS, 1000, 1000 + 7800 + $i * 60);
        $call = $this->lastCall($file);
        $this->assertSame('Job looks stuck: running 2h 12m, 3 runs skipped', $call['-s']);
        $this->assertSame('warning', $call['-i']);
        $this->assertSame('still running since 00:16', $call['-d']);
    }
}
