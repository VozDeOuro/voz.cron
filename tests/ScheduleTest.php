<?php
declare(strict_types=1);

final class ScheduleTest extends TestCase
{
    /** Upstream-shaped files: schedule.json keyed by path, plus hand-added startCustom.php lines. */
    private function legacyFixture(array $drop = []): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/fixtures/legacy-schedule.json'), true);
        foreach ($drop as $d) unset($data['/boot/config/plugins/user.scripts/scripts/' . $d . '/script']);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        foreach (array_keys($data) as $path) $this->makeScript(basename(dirname($path)));
        foreach (['ping-hosts', 'refresh-ddns', 'heartbeat', 'sync-photos', 'nightly-backup', 'broken19'] as $id) {
            if (!is_dir(Paths::scripts() . "/$id")) $this->makeScript($id);
        }
        file_put_contents($this->upstream(), str_replace('/boot/config/plugins/user.scripts', Paths::$flash, $json));
        file_put_contents(Paths::cronFile(), str_replace('/boot/config/plugins/user.scripts', Paths::$flash, file_get_contents(__DIR__ . '/fixtures/legacy-customSchedule.cron')));
    }

    private function upstream(): string { return Paths::$flash . '/schedule.json'; }

    private function config(): array { return json_decode((string)file_get_contents(Paths::configFile()), true); }

    private static function sorted(array $a): array { ksort($a); return $a; }

    public function testAllNormalizesWithDefaults(): void
    {
        $this->makeScript('a');
        $want = ['frequency' => 'disabled', 'custom' => '', 'notify' => 'off', 'failAfter' => 1, 'remindHours' => 24, 'overlap' => 'skip', 'timeout' => 0, 'hangAfter' => 3, 'exitCodes' => ''];
        $this->assertSame(self::sorted($want), self::sorted(Schedule::all()['a']));
    }

    public function testSetValidatesAndWritesConfigKeyedById(): void
    {
        $this->makeScript('a');
        Schedule::set('a', ['frequency' => 'custom', 'custom' => '*/5 * * * *', 'notify' => 'failure', 'failAfter' => 3, 'overlap' => 'queue', 'timeout' => 600, 'exitCodes' => '0=ok 1=warn']);
        $saved = $this->config()['a'];
        $this->assertSame(['custom', '*/5 * * * *', 3, '0=ok 1=warn'], [$saved['frequency'], $saved['custom'], $saved['failAfter'], $saved['exitCodes']]);
        $this->assertSame(['custom', 'exitCodes', 'failAfter', 'frequency', 'hangAfter', 'notify', 'overlap', 'remindHours', 'timeout'], array_keys(self::sorted($saved)));
        foreach ([['frequency' => 'yearly'], ['frequency' => 'custom', 'custom' => '* * *'], ['notify' => 'loud'], ['overlap' => 'x'], ['failAfter' => 0], ['timeout' => -1], ['remindHours' => 9999]] as $bad) {
            try { Schedule::set('a', $bad); $this->fail(json_encode($bad)); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testMigrationImportsScheduleJsonAndCronLinesOnce(): void
    {
        $this->legacyFixture(['ping-hosts', 'refresh-ddns']);
        $before = md5_file($this->upstream());
        $this->assertSame(14, Schedule::migrate(), '12 schedule.json entries + 2 hand-added cron lines');
        $this->assertSame(0, Schedule::migrate(), 'second run is a no-op');
        $all = Schedule::all();
        $this->assertSame(['custom', '* * * * *'], [$all['ping-hosts']['frequency'], $all['ping-hosts']['custom']]);
        $this->assertSame(['custom', '*/5 * * * *'], [$all['refresh-ddns']['frequency'], $all['refresh-ddns']['custom']]);
        $this->assertSame(['custom', '15 2 * * 6'], [$all['nightly-backup']['frequency'], $all['nightly-backup']['custom']]);
        $this->assertSame('boot', $all['mount-shares']['frequency']);
        $this->assertSame('disabled', $all['broken19']['frequency'], 'malformed #19 line must not import');
        $this->assertSame($before, md5_file($this->upstream()), 'schedule.json is left untouched for rollback');
    }

    public function testMigrationKeepsEveryOption(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        file_put_contents($this->upstream(), json_encode([
            Paths::scripts() . '/a/script' => ['script' => Paths::scripts() . '/a/script', 'frequency' => 'custom', 'id' => 'schedulea', 'custom' => '0 3 * * MON',
                'notify' => 'failure', 'failAfter' => 3, 'remindHours' => 12, 'overlap' => 'queue', 'timeout' => 600, 'hangAfter' => 5, 'extra' => 'dropped'],
            '/usr/local/emhttp/plugins/user.scripts/scripts/b/script' => ['frequency' => 'daily'],
        ]));
        Schedule::migrate();
        $this->assertSame(self::sorted(['frequency' => 'custom', 'custom' => '0 3 * * mon', 'notify' => 'failure', 'failAfter' => 3, 'remindHours' => 12, 'overlap' => 'queue', 'timeout' => 600, 'hangAfter' => 5, 'exitCodes' => '']),
            self::sorted(Schedule::get('a')));
        $this->assertSame('daily', Schedule::get('b')['frequency'], 'the pre-flash upstream path is understood too');
        $this->assertArrayNotHasKey('extra', $this->config()['a']);
    }

    public function testFirstReadMigratesWhenConfigIsMissing(): void
    {
        $this->legacyFixture();
        $this->assertFileDoesNotExist(Paths::configFile());
        $this->assertSame('15 2 * * 6', Schedule::get('nightly-backup')['custom']);
        $this->assertFileExists(Paths::configFile());
    }

    public function testMigrationLogsExpressionsUnraidWouldMisread(): void
    {
        $this->makeScript('a');
        file_put_contents($this->upstream(), json_encode([Paths::scripts() . '/a/script' => ['frequency' => 'custom', 'custom' => '0 0 * * 0-7']]));
        Schedule::migrate();
        $this->assertSame(['custom', '0 0 * * 0-7'], [Schedule::get('a')['frequency'], Schedule::get('a')['custom']], 'kept so the user can see and fix it');
        $this->assertStringContainsString("a: schedule \"0 0 * * 0-7\" kept but not scheduled: weekday ranges can't end in 7", (string)file_get_contents(Paths::$tmp . '/syslog'));
        Schedule::writeCron();
        $this->assertStringNotContainsString("'a'", file_get_contents(Paths::cronFile()));
    }

    public function testNoUpstreamFileIsWritten(): void
    {
        $this->legacyFixture();
        $before = md5_file($this->upstream());
        Schedule::apply(['refresh-ddns' => ['frequency' => 'disabled']]);
        Schedule::set('nightly-backup', ['notify' => 'always']);
        Schedule::prune();
        $this->assertSame($before, md5_file($this->upstream()));
        $this->assertFileDoesNotExist(Paths::$tmp . '/schedule.json');
    }

    public function testDisablingIsNotUndoneByOldCronLine(): void
    {
        $this->legacyFixture();
        Schedule::writeCron();
        Schedule::apply(['refresh-ddns' => ['frequency' => 'disabled']]);
        $this->assertSame('disabled', Schedule::get('refresh-ddns')['frequency']);
        $this->assertStringNotContainsString("'refresh-ddns'", file_get_contents(Paths::cronFile()));
        file_put_contents(Paths::cronFile(), "*/5 * * * * /usr/local/emhttp/plugins/user.scripts/startCustom.php " . Paths::scripts() . "/refresh-ddns/script\n", FILE_APPEND);
        Schedule::apply(['nightly-backup' => ['notify' => 'failure']]);
        $this->assertSame('disabled', Schedule::get('refresh-ddns')['frequency'], 'upstream lines are only read by the one-time migration');
    }

    public function testCronLineKeepsUtf8Bytes(): void
    {
        $old = setlocale(LC_CTYPE, 0);
        setlocale(LC_CTYPE, 'C'); // escapeshellarg() drops non-ASCII bytes in this locale
        try {
            $line = Schedule::cronLine('0 3 * * *', 'ação 100%');
        } finally {
            setlocale(LC_CTYPE, $old);
        }
        $this->assertSame("0 3 * * * " . Paths::$plugin . "/run.php --script 'ação 100%' --trigger custom > /dev/null 2>&1", $line);
    }

    public function testCronLineQuotesAndLeavesPercentAlone(): void
    {
        $line = Schedule::cronLine('0 3 * * *', "it's 100% done");
        $this->assertSame("0 3 * * * " . Paths::$plugin . "/run.php --script 'it'\\''s 100% done' --trigger custom > /dev/null 2>&1", $line);
    }

    public function testWriteCronOneLinePerCustomEntryWithOwnExpr(): void
    {
        $this->legacyFixture();
        Schedule::writeCron();
        $cron = file_get_contents(Paths::cronFile());
        $lines = array_values(array_filter(explode("\n", $cron), fn($l) => $l !== '' && $l[0] !== '#'));
        $custom = array_filter(Schedule::all(), fn($e) => $e['frequency'] === 'custom' && $e['custom'] !== '');
        $this->assertCount(count($custom) + 1, $lines, 'custom lines + history sync line');
        $this->assertStringContainsString("*/5 * * * * " . Paths::$plugin . "/run.php --script 'refresh-ddns' --trigger custom", $cron);
        $this->assertStringContainsString('run.php --sync-history', $cron);
        $this->assertStringNotContainsString('startCustom.php', $cron);
        foreach ($lines as $l) $this->assertTrue(Cron::valid(implode(' ', array_slice(preg_split('/\s+/', $l), 0, 5))), $l);
    }

    public function testSyncLineFollowsSettings(): void
    {
        $this->makeScript('a');
        Settings::set(['syncMinutes' => 15]);
        Schedule::writeCron();
        $this->assertStringContainsString('*/15 * * * * ' . Paths::$plugin . '/run.php --sync-history', file_get_contents(Paths::cronFile()));
    }

    public function testByFrequencySkipsDeletedScripts(): void
    {
        $this->makeScript('a'); $this->makeScript('b');
        Schedule::apply(['a' => ['frequency' => 'daily'], 'b' => ['frequency' => 'daily']]);
        Scripts::delete('b');
        $this->assertSame(['a'], Schedule::byFrequency('daily'));
    }

    public function testBadStoredEntriesFallBackToDefaults(): void
    {
        $this->makeScript('a'); $this->makeScript('b');
        file_put_contents(Paths::configFile(), json_encode([
            'a' => 'not an array',
            'b' => ['frequency' => 'daily', 'notify' => 'loud', 'overlap' => 'x', 'timeout' => -5, 'failAfter' => 'many', 'remindHours' => 48, 'exitCodes' => '1=maybe'],
        ]));
        $this->assertSame('disabled', Schedule::get('a')['frequency']);
        $b = Schedule::get('b');
        $this->assertSame(['daily', 'off', 'skip', 0, 1, 48, ''], [$b['frequency'], $b['notify'], $b['overlap'], $b['timeout'], $b['failAfter'], $b['remindHours'], $b['exitCodes']]);
        Schedule::set('b', ['frequency' => 'hourly']); // must not throw on the stored garbage
        $this->assertSame('hourly', Schedule::get('b')['frequency']);
    }

    public function testUnreadableScriptsFolderAbortsInsteadOfWipingSchedule(): void
    {
        $this->makeScript('a');
        Schedule::apply(['a' => ['frequency' => 'custom', 'custom' => '*/5 * * * *']]);
        $json = file_get_contents(Paths::configFile());
        $cron = file_get_contents(Paths::cronFile());
        Scripts::$scandir = fn() => false;
        try {
            try { Schedule::apply(['a' => ['frequency' => 'daily']]); $this->fail('apply did not throw'); }
            catch (RuntimeException $e) { $this->assertSame('cannot read scripts folder', $e->getMessage()); }
            try { Schedule::writeCron(); $this->fail('writeCron did not throw'); }
            catch (RuntimeException $e) { $this->assertSame('cannot read scripts folder', $e->getMessage()); }
        } finally {
            Scripts::$scandir = 'scandir';
        }
        $this->assertSame($json, file_get_contents(Paths::configFile()));
        $this->assertSame($cron, file_get_contents(Paths::cronFile()));
    }

    public function testUnreadableConfigIsNeverOverwritten(): void
    {
        $this->makeScript('a');
        Schedule::apply(['a' => ['frequency' => 'custom', 'custom' => '*/5 * * * *']]);
        $cron = file_get_contents(Paths::cronFile());
        file_put_contents(Paths::configFile(), '{"a": {"frequency": "custom", broken');
        foreach ([fn() => Schedule::apply(['a' => ['frequency' => 'daily']]), fn() => Schedule::writeCron(), fn() => Schedule::prune()] as $write) {
            try { $write(); $this->fail('a write went through'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('config.json is unreadable', $e->getMessage()); }
        }
        $this->assertSame('{"a": {"frequency": "custom", broken', file_get_contents(Paths::configFile()));
        $this->assertSame($cron, file_get_contents(Paths::cronFile()), 'the crontab keeps the last good schedule');
        $this->assertSame('disabled', Schedule::get('a')['frequency'], 'readers (a run, the list) fall back to defaults');
        $this->assertStringContainsString('config.json is unreadable, using default options', (string)file_get_contents(Paths::$tmp . '/syslog'));
    }

    public function testUnreadableUpstreamFileNeverCreatesConfig(): void
    {
        $this->makeScript('a');
        file_put_contents($this->upstream(), "\xEF\xBB\xBF" . '{"x": ');
        try { Schedule::migrate(); $this->fail('migrate accepted a garbage file'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('schedule.json', $e->getMessage()); }
        $this->assertFileDoesNotExist(Paths::configFile());
        $this->assertStringContainsString('cannot be read or decoded', (string)file_get_contents(Paths::$tmp . '/syslog'));
        // lazy non-strict read: defaults, nothing written, no crash
        $this->assertSame('disabled', Schedule::get('a')['frequency']);
        $this->assertFileDoesNotExist(Paths::configFile());
        // strict writer refuses
        try { Schedule::apply(['a' => ['frequency' => 'daily']]); $this->fail('a write went through'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('cannot be read or decoded', $e->getMessage()); }
        $this->assertFileDoesNotExist(Paths::configFile());
        // a later good file still imports
        file_put_contents($this->upstream(), json_encode([Paths::scripts() . '/a/script' => ['frequency' => 'daily', 'notify' => 'always']]));
        $this->assertGreaterThan(0, Schedule::migrate());
        $this->assertSame(['daily', 'always'], [Schedule::get('a')['frequency'], Schedule::get('a')['notify']]);
    }

    public function testNumericIdsStayObjectKeys(): void
    {
        $this->makeScript('0');
        $this->makeScript('2024');
        Schedule::apply(['0' => ['frequency' => 'daily'], '2024' => ['frequency' => 'daily']]);
        $this->assertStringStartsWith('{', file_get_contents(Paths::configFile()));
        $this->assertSame(['0', '2024'], array_map('strval', array_keys($this->config())));
        $this->assertSame(['0', '2024'], Schedule::byFrequency('daily'), 'string ids, not integers');
    }

    public function testCustomExpressionStoredAndWrittenLowercase(): void
    {
        $this->makeScript('a');
        Schedule::apply(['a' => ['frequency' => 'custom', 'custom' => '0 3 * * MON']]);
        $this->assertSame('0 3 * * mon', $this->config()['a']['custom']);
        $this->assertStringContainsString("0 3 * * mon " . Paths::$plugin . "/run.php --script 'a'", file_get_contents(Paths::cronFile()));
    }

    public function testWriteCronSkipsIdsWithControlCharacters(): void
    {
        $this->makeScript('good');
        $this->makeScript("bad\nid");
        Schedule::apply(['good' => ['frequency' => 'custom', 'custom' => '*/5 * * * *'], "bad\nid" => ['frequency' => 'custom', 'custom' => '* * * * *']]);
        $cron = file_get_contents(Paths::cronFile());
        $this->assertStringContainsString("--script 'good'", $cron);
        $this->assertStringNotContainsString('bad', $cron);
        $this->assertStringContainsString('control character', (string)@file_get_contents(Paths::$tmp . '/syslog'));
    }

    public function testWriteCronSkipsAndLogsExpressionsUnraidWouldMisread(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::configFile(), json_encode(['a' => ['frequency' => 'custom', 'custom' => '0 0 * * 0-7']]));
        Schedule::writeCron();
        $this->assertStringNotContainsString("'a'", file_get_contents(Paths::cronFile()));
        $this->assertStringContainsString("a: schedule \"0 0 * * 0-7\" not written: weekday ranges can't end in 7", (string)file_get_contents(Paths::$tmp . '/syslog'));
    }

    public function testNonStringCustomIsRejected(): void
    {
        $this->makeScript('a');
        foreach ([['* * * * *'], 5, null, true] as $bad) {
            try { Schedule::set('a', ['frequency' => 'daily', 'custom' => $bad]); $this->fail(json_encode($bad)); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testPruneDropsDeletedScripts(): void
    {
        $this->makeScript('a'); $this->makeScript('b');
        Schedule::apply(['a' => ['frequency' => 'daily'], 'b' => ['frequency' => 'hourly']]);
        Scripts::delete('b');
        Schedule::prune();
        $this->assertSame(['a'], array_keys($this->config()));
    }
}
