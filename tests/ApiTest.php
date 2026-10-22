<?php
declare(strict_types=1);

final class ApiTest extends TestCase
{
    public function testListShape(): void
    {
        $this->makeScript('a', "#!/bin/bash\n#description=Hello\nexit 0\n");
        Schedule::apply(['a' => ['frequency' => 'custom', 'custom' => '*/5 * * * *']]);
        Runner::run('a', 'custom');
        $r = Api::handle(['action' => 'list']);
        $s = $r['scripts'][0];
        $this->assertSame(['id', 'name', 'description', 'missing', 'vars', 'schedule', 'cron', 'cronError', 'next', 'running', 'last', 'health', 'spark'], array_keys($s));
        $this->assertSame('every 5 minutes', $s['cron']);
        $this->assertSame('ok', $s['last']['status']);
        $this->assertIsInt($s['next']);
        $this->assertArrayHasKey('settings', $r);
    }

    public function testUnknownScriptIs404AndUnknownActionIs400(): void
    {
        foreach ([['action' => 'get', 'id' => '../../etc'], ['action' => 'delete', 'id' => 'nope']] as $in) {
            try { Api::handle($in); $this->fail(); } catch (ApiError $e) { $this->assertSame(404, $e->getCode()); }
        }
        try { Api::handle(['action' => 'rm -rf']); $this->fail(); } catch (ApiError $e) { $this->assertSame(400, $e->getCode()); }
    }

    public function testRunSpawnsDetachedAndRespectsLock(): void
    {
        $this->makeScript('a', "sleep 1\n");
        Api::handle(['action' => 'background', 'id' => 'a']);
        $this->assertSame([Sys::php(), Paths::$plugin . '/run.php', '--script', 'a', '--trigger', 'background'], Sys::$launched[0]);
        @mkdir(Paths::locks(), 0777, true);
        $h = fopen(Paths::locks() . '/a.lock', 'c'); flock($h, LOCK_EX);
        try { Api::handle(['action' => 'run', 'id' => 'a']); $this->fail(); } catch (ApiError $e) { $this->assertSame(409, $e->getCode()); }
        try { Api::handle(['action' => 'delete', 'id' => 'a']); $this->fail(); } catch (ApiError $e) { $this->assertSame(409, $e->getCode()); }
        flock($h, LOCK_UN);
    }

    public function testForegroundOnlyCannotBackground(): void
    {
        $this->makeScript('fg', "#foregroundOnly=true\n");
        try { Api::handle(['action' => 'background', 'id' => 'fg']); $this->fail(); } catch (ApiError $e) { $this->assertSame(400, $e->getCode()); }
    }

    public function testScheduleValidationIs400WithMessage(): void
    {
        $this->makeScript('a');
        try { Api::handle(['action' => 'schedule.set', 'id' => 'a', 'fields' => ['frequency' => 'custom', 'custom' => 'bogus']]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(400, $e->getCode()); $this->assertSame('invalid cron expression', $e->getMessage()); }
        $r = Api::handle(['action' => 'cron.check', 'expr' => '0 3 * * 1']);
        $this->assertTrue($r['valid']);
        $this->assertSame('Mon at 03:00', $r['text']);
        $r = Api::handle(['action' => 'cron.check', 'expr' => '0 0 * * 0-7']);
        $this->assertFalse($r['valid']);
        $this->assertSame("weekday ranges can't end in 7 on Unraid; use 0-6 or *", $r['error']);
        $this->assertNull(Api::handle(['action' => 'cron.check', 'expr' => '0 3 * * 1'])['error']);
    }

    public function testRowCarriesCronErrorForStoredBadExpression(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::configFile(), json_encode(['a' => ['frequency' => 'custom', 'custom' => '0 0 * * 0-7']]));
        $s = Api::handle(['action' => 'list'])['scripts'][0];
        $this->assertSame("weekday ranges can't end in 7 on Unraid; use 0-6 or *", $s['cronError']);
        $this->assertSame('', $s['cron']);
        $this->assertNull($s['next']);
    }

    public function testCrudRoundTrip(): void
    {
        $id = Api::handle(['action' => 'create', 'name' => 'New one'])['id'];
        Api::handle(['action' => 'save', 'id' => $id, 'body' => "#!/bin/bash\necho x\n"]);
        $this->assertSame("#!/bin/bash\necho x\n", Api::handle(['action' => 'get', 'id' => $id])['body']);
        Api::handle(['action' => 'describe', 'id' => $id, 'description' => 'D']);
        Api::handle(['action' => 'rename', 'id' => $id, 'name' => 'Renamed']);
        $this->assertSame('Renamed', Api::handle(['action' => 'list'])['scripts'][0]['name']);
        Api::handle(['action' => 'delete', 'id' => $id]);
        $this->assertSame([], Api::handle(['action' => 'list'])['scripts']);
    }

    public function testCreateStripsNewlines(): void
    {
        $this->assertStringNotContainsString("\n", Api::handle(['action' => 'create', 'name' => "x\ny"])['id']);
    }

    public function testNonStringInputIs400AndLeavesScriptAlone(): void
    {
        $this->makeScript('a', "orig\n");
        try { Api::handle(['action' => 'save', 'id' => 'a', 'body' => []]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(400, $e->getCode()); }
        $this->assertSame("orig\n", Api::handle(['action' => 'get', 'id' => 'a'])['body']);
    }

    public function testApplyWithUnknownIdChangesNothing(): void
    {
        $this->makeScript('a');
        try { Api::handle(['action' => 'apply', 'changes' => ['a' => ['frequency' => 'daily'], 'nope' => ['frequency' => 'daily']]]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(404, $e->getCode()); }
        $this->assertSame('disabled', Schedule::get('a')['frequency']);
    }

    public function testDeleteDropsScheduleEntry(): void
    {
        $this->makeScript('gone');
        $this->makeScript('kept');
        Schedule::apply(['gone' => ['frequency' => 'daily'], 'kept' => ['frequency' => 'hourly']]);
        Api::handle(['action' => 'delete', 'id' => 'gone']);
        $this->assertSame(['kept'], array_keys(json_decode(file_get_contents(Paths::configFile()), true)));
    }

    public function testDeleteClearsScheduleAndCronEvenIfHistoryCleanupFails(): void
    {
        $this->makeScript('gone');
        Schedule::apply(['gone' => ['frequency' => 'custom', 'custom' => '*/5 * * * *']]);
        State::update('gone', fn($s) => $s);
        unlink(Paths::ramHistory() . '/state.json.lock');
        mkdir(Paths::ramHistory() . '/state.json.lock'); // State::forget cannot lock
        try { Api::handle(['action' => 'delete', 'id' => 'gone']); $this->fail('expected the history cleanup to fail'); }
        catch (RuntimeException $e) { $this->assertSame('cannot open state lock', $e->getMessage()); }
        $this->assertStringNotContainsString("'gone'", file_get_contents(Paths::cronFile()));
        $this->assertArrayNotHasKey('gone', json_decode(file_get_contents(Paths::configFile()), true));
    }

    public function testDeleteForgetsHistoryAndState(): void
    {
        $this->makeScript('gone', "exit 1\n");
        $this->makeScript('kept');
        Runner::run('gone', 'manual');
        Runner::run('kept', 'manual');
        History::sync();
        $disk = $this->root . '/flash/history';
        $this->assertFileExists("$disk/gone.jsonl");
        $this->assertArrayHasKey('gone', State::all());
        Api::handle(['action' => 'delete', 'id' => 'gone']);
        $this->assertFileDoesNotExist(Paths::ramHistory() . '/gone.jsonl');
        $this->assertFileDoesNotExist("$disk/gone.jsonl");
        $this->assertArrayNotHasKey('gone', State::all());
        $this->assertArrayNotHasKey('gone', json_decode(file_get_contents("$disk/state.json"), true));
        $this->assertFileExists("$disk/kept.jsonl");
        // a new script with the same name starts clean
        $this->makeScript('gone');
        $this->assertSame([], Api::handle(['action' => 'history', 'id' => 'gone'])['runs']);
    }

    public function testNotifyTestSendsOneNormalNotification(): void
    {
        $this->makeScript('a', "exit 0\n", 'Nightly');
        file_put_contents(Paths::$notify, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> " . escapeshellarg($this->root . '/sent') . "\n");
        chmod(Paths::$notify, 0755);
        $this->assertSame(['sent' => true], Api::handle(['action' => 'notify.test', 'id' => 'a']));
        $lines = file($this->root . '/sent', FILE_IGNORE_NEW_LINES);
        $this->assertSame('Nightly: test notification from VOZ_CRON', $lines[array_search('-s', $lines, true) + 1]);
        $this->assertSame('normal', $lines[array_search('-i', $lines, true) + 1]);
        file_put_contents(Paths::$notify, "#!/bin/sh\nexit 1\n");
        try { Api::handle(['action' => 'notify.test', 'id' => 'a']); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(502, $e->getCode()); }
    }

    public function testListCarriesLayoutTzAndSparkline(): void
    {
        $this->makeScript('a', "exit 0\n");
        Runner::run('a', 'manual');
        Runner::run('a', 'manual');
        $r = Api::handle(['action' => 'list']);
        $this->assertSame(['scripts', 'settings', 'layout', 'tz'], array_keys($r));
        $this->assertSame([['type' => 'script', 'id' => 'a']], $r['layout']['items']);
        $this->assertSame(date_default_timezone_get(), $r['tz']);
        $this->assertCount(2, $r['scripts'][0]['spark']);
        $this->assertSame('ok', $r['scripts'][0]['spark'][1]['o']);
    }

    public function testLayoutSaveAndStaleConflict(): void
    {
        $this->makeScript('a');
        $r = Api::handle(['action' => 'layout.save', 'version' => 0, 'layout' => ['items' => [['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'scripts' => ['a']]]]]);
        $this->assertSame(1, $r['layout']['version']);
        try { Api::handle(['action' => 'layout.save', 'version' => 0, 'layout' => ['items' => []]]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(409, $e->getCode()); $this->assertSame('F', $e->extra['layout']['items'][0]['name']); }
        foreach ([['version' => '1', 'layout' => ['items' => []]], ['version' => 1, 'layout' => 'x'], ['version' => 1, 'layout' => ['items' => [['type' => 'script', 'id' => 'nope']]]]] as $in) {
            try { Api::handle(['action' => 'layout.save'] + $in); $this->fail(json_encode($in)); }
            catch (ApiError $e) { $this->assertSame(400, $e->getCode()); }
        }
        $this->assertSame(1, Api::handle(['action' => 'layout.get'])['layout']['version']);
    }

    public function testLayoutSaveWithAfter(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        $A = [['type' => 'script', 'id' => 'b'], ['type' => 'script', 'id' => 'a']];
        $B = [['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'icon' => '', 'scripts' => ['b', 'a']]];
        $this->assertSame(1, Api::handle(['action' => 'layout.save', 'version' => 0, 'layout' => ['items' => $A]])['layout']['version']);
        $this->assertSame(2, Api::handle(['action' => 'layout.save', 'version' => 0, 'after' => $A, 'layout' => ['items' => $B]])['layout']['version']);
        try { Api::handle(['action' => 'layout.save', 'version' => 1, 'after' => $A, 'layout' => ['items' => $A]]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(409, $e->getCode()); $this->assertSame($B, $e->extra['layout']['items']); }
        foreach (['x', [['type' => 'script', 'id' => 'nope']]] as $after) {
            try { Api::handle(['action' => 'layout.save', 'version' => 2, 'after' => $after, 'layout' => ['items' => $A]]); $this->fail(json_encode($after)); }
            catch (ApiError $e) { $this->assertSame(400, $e->getCode()); }
        }
        $this->assertSame(2, Api::handle(['action' => 'layout.get'])['layout']['version']);
    }
}
