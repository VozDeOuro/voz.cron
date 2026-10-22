<?php
declare(strict_types=1);

final class RunnerTest extends TestCase
{
    public function testRunsAndRecordsExitAndLog(): void
    {
        $this->makeScript('ok', "#!/bin/bash\necho hello \$1\n#not a header\n");
        $this->makeScript('bad', "echo nope\nexit 3\n");
        $this->assertSame('ok', Runner::run('ok', 'manual'));
        $this->assertSame('failed', Runner::run('bad', 'custom'));
        $this->assertSame(3, History::last('bad')['exit']);
        $this->assertStringContainsString('hello', Runner::log('ok')['text']);
        $this->assertStringContainsString('nope', Runner::log('bad')['text']);
        $this->assertFalse(Runner::isRunning('ok'));
    }

    public function testArgumentDefaultAndNameWithSpacesAndPercent(): void
    {
        $this->makeScript('it is 100% ok', "#!/bin/bash\n#argumentDefault=one two\necho \"args=\$*\"\n");
        $this->assertSame('ok', Runner::run('it is 100% ok', 'custom'));
        $this->assertStringContainsString('args=one two', Runner::log('it is 100% ok')['text']);
    }

    public function testConditionsBlock(): void
    {
        file_put_contents(Paths::$varIni, "mdState=\"STOPPED\"\nmdResyncPos=\"0\"\n");
        $this->makeScript('arr', "#arrayStarted=true\necho x\n");
        $this->makeScript('fg', "#foregroundOnly=true\necho x\n");
        $this->assertSame('blocked', Runner::run('arr', 'daily'));
        $this->assertSame('blocked', Runner::run('fg', 'daily'));
        $this->assertSame('ok', Runner::run('fg', 'manual'));
        file_put_contents(Paths::$varIni, "mdState=\"STARTED\"\nmdResyncPos=\"12345\"\n");
        $this->makeScript('np', "#noParity=true\necho x\n");
        $this->assertSame('blocked', Runner::run('np', 'daily'));
        $this->assertNull(History::last('np'), 'blocked runs are not history');
    }

    public function testLogRotationKeepsFive(): void
    {
        $this->makeScript('r', "echo run \$RANDOM\n");
        for ($i = 0; $i < 7; $i++) Runner::run('r', 'custom');
        $this->assertFileExists(Paths::work('r') . '/log.5.txt');
        $this->assertFileDoesNotExist(Paths::work('r') . '/log.6.txt');
        $this->assertNotSame('', Runner::log('r', 5)['text']);
    }

    public function testClearLogVarSkipsRotation(): void
    {
        $this->makeScript('c', "#clearLog=true\necho x\n");
        Runner::run('c', 'custom'); Runner::run('c', 'custom');
        $this->assertFileDoesNotExist(Paths::work('c') . '/log.1.txt');
    }

    private const ENV = ['US_FLASH' => 'flash', 'US_TMP' => 'tmp', 'US_VARINI' => 'varIni', 'US_NOTIFY' => 'notify', 'US_UPDATE_CRON' => 'updateCron'];

    /** Detached runs are separate processes: hand them the sandbox through the environment. */
    private function exportPaths(): void
    {
        foreach (self::ENV as $env => $prop) putenv("$env=" . Paths::$$prop);
        Sys::$dryRun = false;
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $env) putenv($env);
        parent::tearDown();
    }

    /** Waits until each script has a history entry and has released its lock. */
    private function waitFor(array $ids, float $secs = 10): void
    {
        $end = microtime(true) + $secs;
        do {
            $done = array_filter($ids, fn($id) => History::last($id) !== null && !Runner::isRunning($id));
            if (count($done) === count($ids)) return;
            usleep(100000);
        } while (microtime(true) < $end);
        $this->fail('timed out waiting for ' . implode(', ', array_diff($ids, $done)));
    }

    private function runPhp(string $args): int
    {
        $this->exportPaths();
        $runPhp = dirname(__DIR__) . '/source/voz.cron/usr/local/emhttp/plugins/voz.cron/run.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runPhp) . " $args 2>/dev/null", $o, $rc);
        return $rc;
    }

    public function testFrequencyRunsScheduledScripts(): void
    {
        $this->exportPaths();
        $this->makeScript('d1', "echo a\n"); $this->makeScript('d2', "echo b\n"); $this->makeScript('h', "echo c\n");
        Schedule::apply(['d1' => ['frequency' => 'daily'], 'd2' => ['frequency' => 'daily'], 'h' => ['frequency' => 'hourly']]);
        Runner::runFrequency('daily');
        $this->waitFor(['d1', 'd2']);
        $this->assertSame('daily', History::last('d1')['trigger']);
        $this->assertNotNull(History::last('d2'));
        usleep(300000);
        $this->assertNull(History::last('h'));
    }

    public function testFrequencyLaunchesDetachedInParallel(): void
    {
        $this->exportPaths();
        $stamps = $this->root . '/starts';
        foreach (['s1', 's2'] as $id) $this->makeScript($id, "date +%s.%N >> " . escapeshellarg($stamps) . "\nsleep 2\n");
        Schedule::apply(['s1' => ['frequency' => 'daily'], 's2' => ['frequency' => 'daily']]);
        $t = microtime(true);
        Runner::runFrequency('daily');
        $this->assertLessThan(1.0, microtime(true) - $t, 'runFrequency must not wait for the scripts');
        $this->waitFor(['s1', 's2']);
        $starts = array_map('floatval', file($stamps, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(2, $starts);
        $this->assertLessThan(1.0, abs($starts[0] - $starts[1]), 'both scripts start together');
        $this->assertSame('ok', History::last('s1')['status']);
    }

    public function testRunPhpRejectsNonEventFrequencies(): void
    {
        foreach (['disabled', 'custom', 'bogus'] as $f) $this->assertSame(2, $this->runPhp("--frequency $f"), $f);
        $this->assertSame(0, $this->runPhp('--frequency daily'));
    }

    public function testSignalledScriptRecords128PlusSignal(): void
    {
        $this->makeScript('sig', "#!/bin/bash\nkill -9 \$\$\n");
        $this->assertSame('failed', Runner::run('sig', 'custom'));
        $this->assertSame(137, History::last('sig')['exit']);
    }

    public function testEnvironmentAndWorkingDirectory(): void
    {
        $this->makeScript('env', "#!/bin/bash\necho \"env=\$LOGNAME \$TERM \$PWD\"\n");
        Runner::run('env', 'custom');
        $this->assertStringContainsString('env=root dumb /root', Runner::log('env')['text']);
    }

    public function testLangDefaultsToUtf8AndPassesThroughWhenSet(): void
    {
        $this->makeScript('lang', "#!/bin/bash\necho \"lang=\$LANG\"\n");
        $old = getenv('LANG');
        try {
            putenv('LANG');
            Runner::run('lang', 'custom');
            $this->assertStringContainsString('lang=C.UTF-8', Runner::log('lang')['text']);
            putenv('LANG=pt_BR.UTF-8');
            Runner::run('lang', 'custom');
            $this->assertStringContainsString('lang=pt_BR.UTF-8', Runner::log('lang')['text']);
        } finally {
            putenv($old === false ? 'LANG' : "LANG=$old");
        }
    }

    public function testFailedStartWritesFinishedAndAlerts(): void
    {
        $this->makeScript('nostart', "echo x\n");
        Schedule::set('nostart', ['notify' => 'always']);
        file_put_contents(Paths::$notify, "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        @mkdir(Paths::work('nostart'), 0777, true);
        mkdir(Paths::work('nostart') . '/script'); // a directory where the copy must go: copy fails
        $this->assertSame('failed', Runner::run('nostart', 'custom'));
        $this->assertSame(-1, History::last('nostart')['exit']);
        $this->assertSame('failed', file_get_contents(Paths::finished() . '/nostart'));
        $this->assertFileExists($this->root . '/sent', 'failure alert sent');
    }

    public function testStatusChangeSyncsImmediately(): void
    {
        $this->makeScript('s', "exit 0\n");
        Runner::run('s', 'custom');
        $this->assertFileExists(Paths::$flash . '/history/s.jsonl', 'first entry is a change');
        $before = file_get_contents(Paths::$flash . '/history/s.jsonl');
        Runner::run('s', 'custom');
        $this->assertSame($before, file_get_contents(Paths::$flash . '/history/s.jsonl'), 'repeat stays in RAM');
    }

    /**
     * A run started by the old plugin (startBackground.php) holds no flock: only running/<id> and the
     * process's command line tell us it is alive. Here the fake is not a group leader and has a child.
     */
    private function startLegacy(string $id, int $secs): array
    {
        $script = Paths::work($id) . '/script';
        mkdir(dirname($script), 0777, true);
        file_put_contents($script, "#!/bin/bash\nsleep $secs\n");
        $p = proc_open(['bash', '-c', "sleep $secs; :", $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $pid = proc_get_status($p)['pid'];
        @mkdir(Paths::running(), 0777, true);
        file_put_contents(Paths::running() . "/$id", "$pid\n");
        for ($i = 0; $i < 50 && !$this->sleepers($secs); $i++) usleep(50000);
        return [$p, $pid];
    }

    /** @return list<int> pids of live "sleep $secs" processes */
    private function sleepers(int $secs): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*') as $d) {
            $c = @file_get_contents("$d/cmdline");
            $st = @file_get_contents("$d/stat");
            if ($c === "sleep\0$secs\0" && $st !== false && substr($st, strrpos($st, ')') + 2, 1) !== 'Z') $out[] = (int)basename($d);
        }
        return $out;
    }

    public function testLegacyRunIsRunningSkipsAndAborts(): void
    {
        $this->makeScript('leg', "echo new\n");
        [$p, $pid] = $this->startLegacy('leg', 313);
        try {
            $this->assertNotEmpty($this->sleepers(313), 'fake legacy child started');
            $this->assertTrue(Runner::isRunning('leg'));
            $this->assertSame(filemtime(Paths::running() . '/leg'), Runner::runningSince('leg'));
            $this->assertSame('skipped', Runner::run('leg', 'custom'), 'overlap guard sees the legacy run');
            $this->assertTrue(Runner::abort('leg'));
            $this->assertSame([], $this->sleepers(313), 'abort kills the legacy pid and its children');
            $this->assertFalse(Runner::isRunning('leg'));
            $this->assertFileDoesNotExist(Paths::running() . '/leg');
        } finally {
            posix_kill($pid, 9);
            foreach ($this->sleepers(313) as $s) posix_kill($s, 9);
            proc_close($p);
        }
    }

    public function testUnrelatedPidInRunningFileIsNotRunning(): void
    {
        $this->makeScript('stale', "echo x\n");
        mkdir(Paths::running(), 0777, true);
        file_put_contents(Paths::running() . '/stale', (string)getmypid()); // alive, but not this script
        $this->assertFalse(Runner::isRunning('stale'));
        $this->assertNull(Runner::runningSince('stale'));
        $this->assertSame('ok', Runner::run('stale', 'custom'));
    }

    public function testExitCodeMapDecidesTheOutcome(): void
    {
        $this->makeScript('w', "exit 1\n", 'Warny');
        Schedule::set('w', ['notify' => 'failure', 'exitCodes' => '0=ok 1=warn *=fail']);
        file_put_contents(Paths::$notify, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        $this->assertSame('failed', Runner::run('w', 'custom'));
        $last = History::last('w');
        $this->assertSame(['failed', 1, 'warn'], [$last['status'], $last['exit'], $last['outcome']]);
        $this->assertSame(0, State::get('w')['fails']);
        $sent = file_get_contents($this->root . '/sent');
        $this->assertStringContainsString("Warny warning: exit 1\n", $sent);
        $this->assertMatchesRegularExpression('/^cron run, \d+s, exit 1 \(warn\)$/m', $sent);
    }

    public function testTimeoutFailsEvenWhenTheMapSaysOk(): void
    {
        $this->makeScript('t', "sleep 5\n");
        Schedule::set('t', ['timeout' => 1, 'exitCodes' => '*=ok']);
        $this->assertSame('timeout', Runner::run('t', 'custom'));
        $this->assertSame('fail', History::last('t')['outcome']);
        $this->assertSame('failing', State::get('t')['health']);
    }

    public function testFailedStartIsAFailOutcome(): void
    {
        $this->makeScript('nostart2', "echo x\n");
        Schedule::set('nostart2', ['exitCodes' => '*=ignore']);
        @mkdir(Paths::work('nostart2'), 0777, true);
        mkdir(Paths::work('nostart2') . '/script');
        Runner::run('nostart2', 'custom');
        $this->assertSame(['failed', -1, 'fail'], [History::last('nostart2')['status'], History::last('nostart2')['exit'], History::last('nostart2')['outcome']]);
    }
}
