<?php
declare(strict_types=1);

final class AlertsTest extends TestCase
{
    private array $opts = ['notify' => 'failure', 'failAfter' => 1, 'remindHours' => 24, 'hangAfter' => 3];

    private function run1(int $start, int $exit, string $status): array
    {
        return ['start' => $start, 'end' => $start + 5, 'exit' => $exit, 'trigger' => 'custom', 'status' => $status];
    }

    private function play(array $events, array $opts): array
    {
        $s = State::BLANK; $sent = [];
        foreach ($events as [$t, $exit, $status]) {
            [$s, $n] = Alerts::decide($s, $this->run1($t, $exit, $status), $opts, 'Job', $t + 5);
            if ($n) $sent[] = $n['level'] . ':' . $n['subject'];
        }
        return [$s, $sent];
    }

    public function testFailAfterAbsorbsBlips(): void
    {
        [, $sent] = $this->play([[0, 1, 'failed'], [60, 0, 'ok'], [120, 1, 'failed'], [180, 1, 'failed'], [240, 1, 'failed']], ['failAfter' => 3] + $this->opts);
        $this->assertSame(['alert:Job failing: exit 1 (3 runs since 02:00)'], array_map(fn($x) => preg_replace('/since \d\d:\d\d/', 'since 02:00', $x), $sent));
    }

    public function testNoRepeatSameExitThenNewExitWarns(): void
    {
        [, $sent] = $this->play([[0, 1, 'failed'], [60, 1, 'failed'], [120, 1, 'failed'], [180, 2, 'failed']], $this->opts);
        $this->assertCount(2, $sent);
        $this->assertStringStartsWith('alert:Job failing: exit 1', $sent[0]);
        $this->assertSame('warning:Job now failing with exit 2', $sent[1]);
    }

    public function testReminderAfterRemindHours(): void
    {
        [, $sent] = $this->play([[0, 1, 'failed'], [3600, 1, 'failed'], [86400 + 10, 1, 'failed'], [86400 + 70, 1, 'failed']], $this->opts);
        $this->assertCount(2, $sent);
        $this->assertStringStartsWith('warning:Job still failing for 1d', $sent[1]);
        $this->assertStringContainsString('3 failed runs', $sent[1]);
    }

    public function testRecoveryOnlyAfterAlert(): void
    {
        [, $sent] = $this->play([[0, 1, 'failed'], [60, 0, 'ok']], ['failAfter' => 2] + $this->opts);
        $this->assertSame([], $sent);
        [$s, $sent] = $this->play([[0, 1, 'failed'], [3600, 0, 'ok']], $this->opts);
        $this->assertSame('normal:Job recovered after 1h 0m (1 failed run)', $sent[1]);
        $this->assertSame('ok', $s['health']);
        $this->assertSame(0, $s['fails']);
    }

    public function testAlwaysModeAndOffMode(): void
    {
        [, $sent] = $this->play([[0, 0, 'ok']], ['notify' => 'always'] + $this->opts);
        $this->assertSame(['normal:Job finished in 5s'], $sent);
        [$s, $sent] = $this->play([[0, 1, 'failed'], [60, 0, 'ok']], ['notify' => 'off'] + $this->opts);
        $this->assertSame([], $sent);
        $this->assertSame('ok', $s['health']);
    }

    public function testTimeoutCountsAsFailureManualAbortDoesNot(): void
    {
        [, $sent] = $this->play([[0, 124, 'timeout']], $this->opts);
        $this->assertSame(['alert:Job timed out (1 run since ' . date('H:i', 0) . ')'], $sent);
        [$s, $sent] = $this->play([[0, 137, 'aborted']], $this->opts);
        $this->assertSame([], $sent);
        $this->assertSame(0, $s['fails']);
    }

    public function testStuckAlertOncePerStuckRun(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$notify, "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        for ($i = 0; $i < 5; $i++) Alerts::onSkip('a', 'Job', $this->opts, 1000, 1000 + 7800 + $i * 60);
        $sent = file($this->root . '/sent');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Job looks stuck: running 2h 12m, 3 runs skipped', $sent[0]);
        Alerts::onStart('a');
        $this->assertSame(0, State::get('a')['skips']);
    }

    public function testMissingOptsUseDefaultsWithoutWarning(): void
    {
        $errs = [];
        set_error_handler(function ($no, $str) use (&$errs) { $errs[] = $str; return true; });
        try {
            [, $sent] = $this->play([[0, 1, 'failed'], [3600, 1, 'failed'], [86400 + 10, 1, 'failed']], ['notify' => 'failure']);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $errs);
        $this->assertCount(2, $sent);
        $this->assertStringStartsWith('alert:', $sent[0]);
        $this->assertStringContainsString('still failing', $sent[1]);
    }

    public function testStuckAlertsAgainAfterOnStart(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$notify, "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        for ($i = 0; $i < 3; $i++) Alerts::onSkip('a', 'Job', $this->opts, 1000, 9000 + $i);
        Alerts::onStart('a');
        for ($i = 0; $i < 3; $i++) Alerts::onSkip('a', 'Job', $this->opts, 20000, 29000 + $i);
        $this->assertCount(2, file($this->root . '/sent'));
    }

    public function testOnRunSendsViaNotifyBinaryWithLogTail(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$notify, "#!/bin/sh\nfor a in \"\$@\"; do echo \"[\$a]\"; done >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        Alerts::onRun('a', 'Job', $this->run1(0, 1, 'failed'), $this->opts, "line1\nline2");
        $out = file_get_contents($this->root . '/sent');
        $this->assertStringContainsString('[VOZ_CRON]', $out);
        $this->assertStringContainsString('[alert]', $out);
        $this->assertStringContainsString("[line1\nline2]", $out);
        $this->assertSame('failing', State::get('a')['health']);
    }

    public function testStateUpdatesAreSerialized(): void
    {
        $boot = __DIR__ . '/../source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php';
        $env = 'US_TEST=1 US_FLASH=' . escapeshellarg(Paths::$flash) . ' US_TMP=' . escapeshellarg(Paths::$tmp);
        $code = 'require ' . var_export($boot, true) . '; for($i=0;$i<50;$i++) State::update("a", function($s){$s["skips"]++; return $s;});';
        exec("$env php -r " . escapeshellarg($code) . " & $env php -r " . escapeshellarg($code) . " & wait");
        $this->assertSame(100, State::get('a')['skips']);
    }

    public function testSendCapsMessageStripsNulAndLogsFailure(): void
    {
        $sent = $this->root . '/sent';
        file_put_contents(Paths::$notify, "#!/bin/sh\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = -m ] && printf %s \"\$2\" > " . escapeshellarg($sent) . "; shift; done\nexit 0\n");
        chmod(Paths::$notify, 0755);
        Alerts::send(['level' => 'alert', 'subject' => "S\0", 'detail' => 'D'], "a\0b");
        $this->assertSame('ab', file_get_contents($sent));
        Alerts::send(['level' => 'alert', 'subject' => 'S', 'detail' => 'D'], str_repeat('é', 5000) . 'END');
        $m = file_get_contents($sent);
        $this->assertStringEndsWith('END', $m, 'the end of a log tail matters most');
        $this->assertLessThanOrEqual(4096, strlen($m));
        $this->assertTrue(mb_check_encoding($m, 'UTF-8'), 'cut on a character boundary');

        file_put_contents(Paths::$notify, "#!/bin/sh\nexit 3\n");
        Alerts::send(['level' => 'alert', 'subject' => 'Broken', 'detail' => 'D'], '');
        $this->assertStringContainsString('notify failed (exit 3): Broken', (string)file_get_contents(Paths::$tmp . '/syslog'));
    }

    public function testCorruptStateIsLoggedAndReset(): void
    {
        mkdir(Paths::ramHistory(), 0777, true);
        file_put_contents(Paths::ramHistory() . '/state.json', '{broken');
        $this->assertSame(State::BLANK, State::get('a'));
        $this->assertStringContainsString('state.json is unreadable', (string)file_get_contents(Paths::$tmp . '/syslog'));
    }

    /** @param list<array{int,int,string,string}> $events [start, exit, status, outcome] */
    private function playOutcomes(array $events, array $opts): array
    {
        $s = State::BLANK; $sent = [];
        foreach ($events as [$t, $exit, $status, $outcome]) {
            [$s, $n] = Alerts::decide($s, $this->run1($t, $exit, $status) + ['outcome' => $outcome], $opts, 'Job', $t + 5);
            if ($n) $sent[] = $n['level'] . ':' . $n['subject'];
        }
        return [$s, $sent];
    }

    public function testWarnNotifiesOnceThenReminds(): void
    {
        [$s, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'warn'], [1060, 1, 'failed', 'warn'], [1000 + 86400, 1, 'failed', 'warn']], $this->opts);
        $this->assertSame(['warning:Job warning: exit 1', 'warning:Job still warning for 1d 0h'], $sent);
        $this->assertSame(['ok', 0, false], [$s['health'], $s['fails'], $s['alerted']], 'a warning is not a failure');
        $this->assertSame(1000, $s['warnSince']);
    }

    public function testWarnLeavesTheFailureStreakAlone(): void
    {
        [$s, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'fail'], [1060, 2, 'failed', 'warn'], [1120, 1, 'failed', 'fail']], $this->opts);
        $this->assertSame(['alert:Job failing: exit 1 (1 run since ' . date('H:i', 1000) . ')', 'warning:Job warning: exit 2'], $sent, 'same exit as before the warning: no "now failing" message');
        $this->assertSame([2, 0], [$s['fails'], $s['warnSince']], 'a failure ends the warning episode');
    }

    public function testOkAfterWarnIsQuietUnlessAFailureWasAlerted(): void
    {
        [, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'warn'], [1060, 0, 'ok', 'ok']], $this->opts);
        $this->assertSame(['warning:Job warning: exit 1'], $sent);
        [$s, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'fail'], [1060, 2, 'failed', 'warn'], [1120, 0, 'ok', 'ok']], $this->opts);
        $this->assertSame('normal:Job recovered after 2m 5s (1 failed run)', $sent[2]);
        $this->assertSame(0, $s['warnSince']);
    }

    public function testIgnoreChangesNothing(): void
    {
        [$before] = $this->playOutcomes([[1000, 1, 'failed', 'fail']], $this->opts);
        [$after, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'fail'], [1060, 3, 'failed', 'ignore']], $this->opts);
        $this->assertSame($before, $after);
        $this->assertCount(1, $sent);
    }

    public function testWarnAgainAfterAFailureAndOffSendsNothing(): void
    {
        [, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'warn'], [1060, 1, 'failed', 'fail'], [1120, 1, 'failed', 'warn']], $this->opts);
        $this->assertSame(['warning:Job warning: exit 1', 'alert:Job failing: exit 1 (1 run since ' . date('H:i', 1060) . ')', 'warning:Job warning: exit 1'], $sent);
        [$s, $sent] = $this->playOutcomes([[1000, 1, 'failed', 'warn']], ['notify' => 'off'] + $this->opts);
        $this->assertSame([], $sent);
        $this->assertSame(1000, $s['warnSince']);
    }

    public function testTimeoutIsAFailureWhateverTheOutcomeSays(): void
    {
        [, $sent] = $this->playOutcomes([[1000, 124, 'timeout', 'ok']], $this->opts);
        $this->assertSame(['alert:Job timed out (1 run since ' . date('H:i', 1000) . ')'], $sent);
    }

    public function testDetailLineNamesTriggerDurationExitAndOutcome(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$notify, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        Alerts::onRun('a', 'Job', ['start' => 0, 'end' => 123, 'exit' => 1, 'trigger' => 'custom', 'status' => 'failed', 'outcome' => 'warn'], $this->opts, '');
        Alerts::onRun('b', 'Job', ['start' => 0, 'end' => 5, 'exit' => 124, 'trigger' => 'manual', 'status' => 'timeout', 'outcome' => 'fail'], $this->opts, '');
        $sent = file_get_contents($this->root . '/sent');
        $this->assertStringContainsString("\ncron run, 2m 3s, exit 1 (warn)\n", $sent);
        $this->assertStringContainsString("\nmanual run, 5s, timed out (fail)\n", $sent);
    }

    public function testSendReportsSuccess(): void
    {
        $this->assertFalse(Alerts::send(['level' => 'normal', 'subject' => 'S', 'detail' => 'D'], ''), 'no notify binary');
        file_put_contents(Paths::$notify, "#!/bin/sh\nexit 0\n");
        chmod(Paths::$notify, 0755);
        $this->assertTrue(Alerts::send(['level' => 'normal', 'subject' => 'S', 'detail' => 'D'], ''));
    }
}
