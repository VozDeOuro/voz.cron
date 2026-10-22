<?php
declare(strict_types=1);

final class WrappersTest extends TestCase
{
    private const P = __DIR__ . '/../source/voz.cron/usr/local/emhttp/plugins/voz.cron';

    public function testEventAndCronWrappersCallValidRunPhpModes(): void
    {
        $files = [...glob(self::P . '/event/*'), ...glob(self::P . '/cron/*')];
        $this->assertCount(8, $files);
        foreach ($files as $f) {
            $calls = preg_match_all('~/run\.php (.*)$~m', (string)file_get_contents($f), $m);
            $this->assertGreaterThan(0, $calls, $f);
            foreach ($m[1] as $args) {
                $this->assertMatchesRegularExpression('~^(--seed|--sync-history|--frequency (hourly|daily|weekly|monthly|start|boot|stop))$~', trim($args), $f);
            }
        }
    }

    public function testRunPhpMigrateIsOneTime(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$flash . '/schedule.json', json_encode([Paths::scripts() . '/a/script' => ['frequency' => 'daily']]));
        $env = 'US_TEST=1 US_FLASH=' . escapeshellarg(Paths::$flash) . ' US_TMP=' . escapeshellarg(Paths::$tmp);
        exec("$env php " . self::P . '/run.php --migrate 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertSame('daily', json_decode(file_get_contents(Paths::configFile()), true)['a']['frequency']);
        file_put_contents(Paths::$flash . '/schedule.json', json_encode([Paths::scripts() . '/a/script' => ['frequency' => 'hourly']]));
        exec("$env php " . self::P . '/run.php --migrate 2>&1', $out, $rc);
        $this->assertSame('daily', json_decode(file_get_contents(Paths::configFile()), true)['a']['frequency'], 'config.json exists: nothing re-imported');
    }

    public function testRunPhpMigrateFailsLoudlyOnGarbage(): void
    {
        $this->makeScript('a');
        file_put_contents(Paths::$flash . '/schedule.json', '{"broken');
        $env = 'US_TEST=1 US_FLASH=' . escapeshellarg(Paths::$flash) . ' US_TMP=' . escapeshellarg(Paths::$tmp);
        exec("$env php " . self::P . '/run.php --migrate 2>&1', $out, $rc);
        $this->assertSame(2, $rc, implode("\n", $out));
        $this->assertFileDoesNotExist(Paths::configFile());
    }
}
