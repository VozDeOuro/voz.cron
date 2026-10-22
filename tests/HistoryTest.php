<?php
declare(strict_types=1);

final class HistoryTest extends TestCase
{
    private function run1(int $start, int $exit = 0, string $status = 'ok', int $dur = 2): array
    {
        return ['start' => $start, 'end' => $start + $dur, 'exit' => $exit, 'trigger' => 'custom', 'status' => $status];
    }

    public function testCollapsesRepeatsAndTracksDurations(): void
    {
        $this->makeScript('a');
        $this->assertTrue(History::record('a', $this->run1(100, 0, 'ok', 2)));
        $this->assertFalse(History::record('a', $this->run1(160, 0, 'ok', 4)));
        $this->assertFalse(History::record('a', $this->run1(220, 0, 'ok', 6)));
        $h = History::read('a');
        $this->assertCount(1, $h);
        $this->assertSame(3, $h[0]['repeat']);
        $this->assertSame(100, $h[0]['first']);
        $this->assertSame(220, $h[0]['start']);
        $this->assertSame([2, 6, 4], [$h[0]['dmin'], $h[0]['dmax'], $h[0]['davg']]);
        $this->assertTrue(History::record('a', $this->run1(280, 1, 'failed')));
        $this->assertTrue(History::record('a', $this->run1(340, 2, 'failed')), 'new exit code is a change');
        $this->assertSame(['failed', 'failed', 'ok'], array_column(History::read('a'), 'status'));
    }

    public function testNoFlashWriteOnRecord(): void
    {
        $this->makeScript('a');
        History::record('a', $this->run1(100));
        $this->assertFileDoesNotExist(Paths::$flash . '/history/a.jsonl');
        $this->assertFileExists(Paths::ramHistory() . '/a.jsonl');
    }

    public function testCap(): void
    {
        $this->makeScript('a');
        Settings::set(['historyCap' => 10]);
        for ($i = 0; $i < 80; $i++) History::record('a', $this->run1(100 + $i * 60, $i % 2, $i % 2 ? 'failed' : 'ok'));
        $n = count(file(Paths::ramHistory() . '/a.jsonl'));
        $this->assertLessThanOrEqual(60, $n);
        $this->assertCount(10, History::read('a', 1000));
    }

    public function testSyncOnlyWhenChangedAndSeed(): void
    {
        $this->makeScript('a');
        History::record('a', $this->run1(100));
        $this->assertSame(1, History::sync());
        $this->assertSame(0, History::sync(), 'unchanged content is not rewritten');
        History::record('a', $this->run1(160));
        $this->assertSame(1, History::sync('a'));
        exec('rm -rf ' . escapeshellarg(Paths::ramHistory()));
        History::seed();
        $this->assertSame(2, History::read('a')[0]['repeat']);
        $this->assertSame(0, History::sync(), 'seeded content counts as synced');
    }

    public function testUnavailableDiskDirKeepsWorkingInRam(): void
    {
        $this->makeScript('a');
        Settings::set(['historyDir' => $this->root . '/mnt/user/system/us']);
        History::record('a', $this->run1(100));
        $this->assertNull(History::diskDir());
        $this->assertSame(0, History::sync());
        $this->assertDirectoryDoesNotExist($this->root . '/mnt/user/system/us');
        mkdir($this->root . '/mnt/user/system', 0777, true);
        $this->assertSame(1, History::sync());
        $this->assertFileExists($this->root . '/mnt/user/system/us/a.jsonl');
    }

    public function testConcurrentRecordsDoNotLoseLines(): void
    {
        $this->makeScript('a');
        $boot = __DIR__ . '/../source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php';
        $env = 'US_TEST=1 US_FLASH=' . escapeshellarg(Paths::$flash) . ' US_TMP=' . escapeshellarg(Paths::$tmp);
        $code = 'require ' . var_export($boot, true) . '; for($i=0;$i<40;$i++) History::record("a", ["start"=>$i,"end"=>$i,"exit"=>$i%2,"trigger"=>"x","status"=>$i%2?"failed":"ok"]);';
        exec("$env php -r " . escapeshellarg($code) . " & $env php -r " . escapeshellarg($code) . " & wait");
        $lines = file(Paths::ramHistory() . '/a.jsonl', FILE_IGNORE_NEW_LINES);
        // interleaved runs may share a status and collapse, so count runs via 'repeat' rather than lines
        $runs = 0;
        foreach ($lines as $l) {
            $e = json_decode($l, true);
            $this->assertIsArray($e);
            $runs += $e['repeat'] ?? 1;
        }
        $this->assertSame(80, $runs, 'no record lost or corrupted');
    }

    public function testDiskDirCreatesOnlyLastComponent(): void
    {
        $this->makeScript('a');
        Settings::set(['historyDir' => $this->root . '/a/b/c']);
        $this->assertNull(History::diskDir());
        $this->assertDirectoryDoesNotExist($this->root . '/a');
        mkdir($this->root . '/a/b', 0777, true);
        $this->assertSame($this->root . '/a/b/c', History::diskDir());
        $this->assertDirectoryExists($this->root . '/a/b/c');
    }

    public function testDiskDirRequiresMountForMnt(): void
    {
        $old = History::$mounts;
        try {
            $m = $this->root . '/mounts';
            file_put_contents($m, "rootfs / rootfs rw 0 0\n");
            History::$mounts = $m;
            Settings::set(['historyDir' => '/mnt/user/system/us']);
            $this->assertNull(History::diskDir());
            file_put_contents($m, "shfs /mnt/user fuse.shfs rw 0 0\n");
            $this->assertNull(History::diskDir(), 'parent /mnt/user/system does not exist here');
            $this->assertDirectoryDoesNotExist('/mnt/user/system/us');
        } finally {
            History::$mounts = $old;
        }
    }

    public function testSyncFollowsHistoryDirChangeAndRecreatesDeletedCopy(): void
    {
        $this->makeScript('a');
        History::record('a', $this->run1(100));
        $A = $this->root . '/A';
        $B = $this->root . '/B';
        mkdir($A);
        mkdir($B);
        Settings::set(['historyDir' => $A]);
        $this->assertSame(1, History::sync());
        Settings::set(['historyDir' => $B]);
        $this->assertGreaterThanOrEqual(1, History::sync());
        $this->assertFileExists("$B/a.jsonl");
        unlink("$B/a.jsonl");
        $this->assertSame(1, History::sync());
        $this->assertFileExists("$B/a.jsonl");
    }

    public function testSyncSurvivesUnwritableTarget(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        History::record('a', $this->run1(100));
        History::record('b', $this->run1(100));
        $dir = $this->root . '/flash/history';
        mkdir($dir, 0777, true);
        mkdir("$dir/b.jsonl");
        $this->assertSame(1, History::sync());
        $this->assertFileExists("$dir/a.jsonl");
        rmdir("$dir/b.jsonl");
        $this->assertSame(1, History::sync());
        $this->assertFileExists("$dir/b.jsonl");
    }

    public function testNonObjectLastLineCountsAsChange(): void
    {
        $this->makeScript('a');
        mkdir(Paths::ramHistory(), 0777, true);
        file_put_contents(Paths::ramHistory() . '/a.jsonl', "5\n");
        $this->assertTrue(History::record('a', $this->run1(100)));
        $this->assertSame('ok', History::last('a')['status']);
    }

    private function lines(string $file): array
    {
        return array_map(fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function testSkipsNeverTriggerAnImmediateSync(): void
    {
        $this->makeScript('a');
        $this->assertTrue(History::record('a', $this->run1(100)));
        $this->assertFalse(History::record('a', $this->run1(160, 0, 'skipped')), 'into skipped');
        $this->assertFalse(History::record('a', $this->run1(220)), 'out of skipped, same as last real run');
        $this->assertTrue(History::record('a', $this->run1(280, 1, 'failed')));
        $this->assertFalse(History::record('a', $this->run1(340, 0, 'skipped')));
        $this->assertTrue(History::record('a', $this->run1(400)), 'back to ok after a failure is a change');
        $this->assertSame(['ok', 'skipped', 'ok', 'failed', 'skipped', 'ok'], array_reverse(array_column(History::read('a'), 'status')), 'skips are still listed');
    }

    public function testSeedMergesOlderDiskLinesIntoRam(): void
    {
        $this->makeScript('a');
        $disk = $this->root . '/flash/history';
        mkdir($disk, 0777, true);
        file_put_contents("$disk/a.jsonl", json_encode($this->run1(100) + ['dur' => 2]) . "\n" . json_encode($this->run1(200, 1, 'failed') + ['dur' => 2]) . "\n" . json_encode($this->run1(300) + ['dur' => 2]) . "\n");
        file_put_contents("$disk/state.json", json_encode(['a' => ['health' => 'failing', 'fails' => 3], 'gone' => ['fails' => 1]]));
        mkdir(Paths::ramHistory(), 0777, true);
        // RAM already has newer runs (recorded before the pool was mounted) and its own state for 'a'
        file_put_contents(Paths::ramHistory() . '/a.jsonl', json_encode($this->run1(300) + ['dur' => 2]) . "\n" . json_encode($this->run1(400, 2, 'failed') + ['dur' => 2]) . "\n");
        file_put_contents(Paths::ramHistory() . '/state.json', json_encode(['a' => ['health' => 'ok', 'fails' => 0]]));
        History::seed();
        $this->assertSame([100, 200, 300, 400], array_column($this->lines(Paths::ramHistory() . '/a.jsonl'), 'start'));
        $state = json_decode(file_get_contents(Paths::ramHistory() . '/state.json'), true);
        $this->assertSame('ok', $state['a']['health'], 'RAM wins for keys it has');
        $this->assertSame(['fails' => 1], $state['gone'], 'disk-only keys are added');
        $this->assertSame(2, History::sync(), 'merged files differ from disk and are written back');
        $this->assertSame([100, 200, 300, 400], array_column($this->lines("$disk/a.jsonl"), 'start'));
    }

    public function testSeedMergeIsCappedAndIdempotent(): void
    {
        $this->makeScript('a');
        Settings::set(['historyCap' => 10]);
        $disk = $this->root . '/flash/history';
        mkdir($disk, 0777, true);
        $old = '';
        for ($i = 0; $i < 10; $i++) $old .= json_encode($this->run1($i * 10, $i % 2, $i % 2 ? 'failed' : 'ok')) . "\n";
        file_put_contents("$disk/a.jsonl", $old);
        mkdir(Paths::ramHistory(), 0777, true);
        file_put_contents(Paths::ramHistory() . '/a.jsonl', json_encode($this->run1(500)) . "\n" . json_encode($this->run1(600, 1, 'failed')) . "\n");
        History::seed();
        History::seed();
        $starts = array_column($this->lines(Paths::ramHistory() . '/a.jsonl'), 'start');
        $this->assertSame([20, 30, 40, 50, 60, 70, 80, 90, 500, 600], $starts);
    }

    public function testSyncNeverOverwritesANewlyAvailableDirBeforeSeeding(): void
    {
        $this->makeScript('a');
        $B = $this->root . '/B';
        mkdir($B);
        file_put_contents("$B/a.jsonl", json_encode($this->run1(100, 1, 'failed')) . "\n");
        file_put_contents("$B/state.json", json_encode(['old' => ['fails' => 2]]));
        History::record('a', $this->run1(200));
        State::update('a', fn($s) => $s);
        Settings::set(['historyDir' => $B]);
        History::sync();
        $this->assertSame([100, 200], array_column($this->lines("$B/a.jsonl"), 'start'), 'disk runs kept');
        $this->assertArrayHasKey('old', json_decode(file_get_contents("$B/state.json"), true));
        $this->assertArrayHasKey('a', json_decode(file_get_contents("$B/state.json"), true));
    }

    public function testForgetRemovesRamDiskAndState(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        History::record('a', $this->run1(100));
        History::record('b', $this->run1(100));
        State::update('a', fn($s) => ['fails' => 1] + $s);
        State::update('b', fn($s) => $s);
        History::sync();
        $disk = $this->root . '/flash/history';
        $this->assertFileExists("$disk/a.jsonl");
        History::forget('a');
        $this->assertFileDoesNotExist(Paths::ramHistory() . '/a.jsonl');
        $this->assertFileDoesNotExist("$disk/a.jsonl");
        $this->assertArrayNotHasKey('a', State::all());
        $this->assertArrayNotHasKey('a', json_decode(file_get_contents("$disk/state.json"), true));
        $this->assertArrayHasKey('b', State::all());
        $this->assertFileExists("$disk/b.jsonl");
    }

    public function testForgetWithNoHistoryAnywhereIsANoop(): void
    {
        Settings::set(['historyDir' => $this->root . '/missing/parent/h']);
        History::forget('never-ran');
        $this->assertDirectoryDoesNotExist(Paths::ramHistory());
        $this->assertSame([], glob(Paths::ramHistory() . '/*.jsonl') ?: []);
        $this->assertDirectoryDoesNotExist($this->root . '/missing');
    }

    public function testOutcomeIsRecordedAndDerivedForOldLines(): void
    {
        $this->makeScript('a');
        History::record('a', $this->run1(100, 1, 'failed') + ['outcome' => 'warn']);
        $this->assertSame('warn', History::last('a')['outcome']);
        file_put_contents(Paths::ramHistory() . '/a.jsonl', json_encode(['start' => 1, 'end' => 2, 'exit' => 0, 'trigger' => 'custom', 'status' => 'ok', 'dur' => 1]) . "\n"
            . json_encode(['start' => 3, 'end' => 4, 'exit' => 124, 'trigger' => 'custom', 'status' => 'timeout', 'dur' => 1]) . "\n");
        $this->assertSame(['fail', 'ok'], array_column(History::read('a'), 'outcome'));
    }

    public function testCollapseIdentityIncludesOutcome(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        $this->assertTrue(History::record('a', $this->run1(100, 1, 'failed') + ['outcome' => 'warn']));
        $this->assertFalse(History::record('a', $this->run1(160, 1, 'failed') + ['outcome' => 'warn']));
        $this->assertTrue(History::record('a', $this->run1(220, 1, 'failed') + ['outcome' => 'fail']), 'same exit, new outcome (rules changed) is a change');
        $this->assertSame([1, 2], array_map(fn($e) => (int)($e['repeat'] ?? 1), History::read('a')));
        // a line written before outcomes existed collapses with a new run of the same result
        file_put_contents(Paths::ramHistory() . '/b.jsonl', json_encode(['start' => 1, 'end' => 2, 'exit' => 0, 'trigger' => 'custom', 'status' => 'ok', 'dur' => 1]) . "\n");
        $this->assertFalse(History::record('b', $this->run1(300) + ['outcome' => 'ok']));
    }

    public function testStateMergeKeepsAJsonObject(): void
    {
        $disk = $this->root . '/flash/history';
        mkdir($disk, 0777, true);
        file_put_contents("$disk/state.json", '{}');
        mkdir(Paths::ramHistory(), 0777, true);
        file_put_contents(Paths::ramHistory() . '/state.json', '{}');
        History::seed();
        $this->assertSame('{}', file_get_contents(Paths::ramHistory() . '/state.json'));
    }

    public function testIncompleteSeedDoesNotLetSyncOverwriteTheFolder(): void
    {
        $this->makeScript('a');
        $disk = $this->root . '/flash/history';
        mkdir($disk, 0777, true);
        file_put_contents("$disk/a.jsonl", json_encode($this->run1(100, 1, 'failed')) . "\n");
        History::record('a', $this->run1(200));
        unlink(Paths::ramHistory() . '/a.jsonl.lock');
        mkdir(Paths::ramHistory() . '/a.jsonl.lock'); // the merge cannot lock the RAM file
        History::seed();
        $this->assertFileDoesNotExist(Paths::ramHistory() . '/.seeded');
        rmdir(Paths::ramHistory() . '/a.jsonl.lock');
        History::sync();
        $this->assertSame([100, 200], array_column($this->lines("$disk/a.jsonl"), 'start'), 'the disk run survived');
    }

    public function testDeleteWhileHistoryDirIsUnavailableDoesNotComeBack(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        $pool = $this->root . '/pool';
        mkdir("$pool/h", 0777, true);
        Settings::set(['historyDir' => "$pool/h"]);
        History::record('a', $this->run1(100, 1, 'failed'));
        History::record('b', $this->run1(100));
        State::update('a', fn($s) => ['fails' => 1] + $s);
        History::sync();
        $this->assertFileExists("$pool/h/a.jsonl");
        rename($pool, "$pool-off");   // array stopped: the pool path is gone
        Scripts::delete('a');
        History::forget('a');
        rename("$pool-off", $pool);   // array started again
        History::seed();
        $this->assertFileDoesNotExist(Paths::ramHistory() . '/a.jsonl');
        $this->assertFileDoesNotExist("$pool/h/a.jsonl", 'the old copy is removed once reachable');
        $this->assertArrayNotHasKey('a', State::all());
        History::sync();
        $this->assertArrayNotHasKey('a', json_decode(file_get_contents("$pool/h/state.json"), true));
        $this->assertFileExists("$pool/h/b.jsonl");
    }

    public function testSparkExpandsRepeatsSkipsSkippedAndCapsAt20(): void
    {
        $this->makeScript('a');
        History::record('a', $this->run1(100) + ['outcome' => 'ok']);
        History::record('a', $this->run1(160) + ['outcome' => 'ok']);
        History::record('a', $this->run1(220, 0, 'skipped'));
        History::record('a', $this->run1(280, 1, 'failed') + ['outcome' => 'warn']);
        $s = History::spark('a');
        $this->assertSame(['ok', 'ok', 'warn'], array_column($s, 'o'));
        $this->assertSame([160, 160, 280], array_column($s, 't'));
        $this->assertSame([100, 100, 280], array_column($s, 'first'));
        $this->assertSame([2, 2, 1], array_column($s, 'n'));
        for ($i = 0; $i < 30; $i++) History::record('a', $this->run1(400 + $i * 60, $i % 2, $i % 2 ? 'failed' : 'ok'));
        $this->assertCount(20, History::spark('a'));
        $this->assertSame('fail', History::spark('a')[19]['o'], 'newest last');
    }
}
