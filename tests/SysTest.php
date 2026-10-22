<?php
declare(strict_types=1);

final class SysTest extends TestCase
{
    public function testLaunchDetachesAndReturnsAtOnce(): void
    {
        Sys::$dryRun = false;
        $out = $this->root . '/launched';
        $t = microtime(true);
        $this->assertTrue(Sys::launch(['sh', '-c', 'sleep 1; echo "$US_PROBE $(ps -o sid= -p $$ | tr -d " ")" > "$0"', $out]));
        $this->assertLessThan(0.5, microtime(true) - $t, 'launch must not wait for the command');
        $this->assertSame(['sh', '-c'], array_slice(Sys::$launched[0], 0, 2), 'test mode records argv');
        for ($i = 0; $i < 50 && !is_file($out); $i++) usleep(100000);
        [$probe, $sid] = explode(' ', trim((string)file_get_contents($out))) + [1 => ''];
        $this->assertSame('inherited', $probe, 'environment is inherited');
        $this->assertNotSame((string)posix_getsid(0), $sid, 'runs in its own session');
    }

    public function testDryRunOnlyRecords(): void
    {
        $this->assertTrue(Sys::launch(['touch', $this->root . '/never']));
        usleep(300000);
        $this->assertFileDoesNotExist($this->root . '/never');
        $this->assertSame([['touch', $this->root . '/never']], Sys::$launched);
    }

    public function testPhpPrefersUsrBinPhp(): void
    {
        $this->assertSame(is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY, Sys::php());
    }

    protected function setUp(): void { parent::setUp(); putenv('US_PROBE=inherited'); }
    protected function tearDown(): void { putenv('US_PROBE'); parent::tearDown(); }

    public function testAtomicWriteThrowsCleanlyWhenDirCannotBeMade(): void
    {
        file_put_contents($this->root . '/afile', 'x');
        $this->expectException(RuntimeException::class);
        Sys::atomicWrite($this->root . '/afile/sub/f.json', '{}');
    }

    public function testAtomicWriteLeavesNoTempFileWhenRenameFails(): void
    {
        mkdir($this->root . '/target/occupied', 0777, true); // rename onto a non-empty directory fails
        try { Sys::atomicWrite($this->root . '/target', 'x'); $this->fail('no exception'); } catch (RuntimeException) {}
        $this->assertSame(['target'], array_values(array_diff(scandir($this->root), ['.', '..', 'flash', 'tmp'])));
    }

    public function testAtomicWriteReplacesContent(): void
    {
        Sys::atomicWrite($this->root . '/new/dir/f', 'one');
        Sys::atomicWrite($this->root . '/new/dir/f', 'two');
        $this->assertSame('two', file_get_contents($this->root . '/new/dir/f'));
        $this->assertSame(['f'], array_values(array_diff(scandir($this->root . '/new/dir'), ['.', '..'])));
    }

    public function testTzFollowsTheSystemZone(): void
    {
        $this->assertSame(date_default_timezone_get(), Sys::tz(), 'no /etc/localtime and no var.ini: PHP default');
        symlink('/usr/share/zoneinfo/America/Sao_Paulo', Sys::$localtime);
        $this->assertSame('America/Sao_Paulo', Sys::tz());
        unlink(Sys::$localtime);
        symlink('/usr/share/zoneinfo/Not/AZone', Sys::$localtime);
        file_put_contents(Paths::$varIni, "timeZone=\"Asia/Tokyo\"\n");
        $this->assertSame('Asia/Tokyo', Sys::tz(), 'an unknown link target falls back to var.ini');
    }
}
