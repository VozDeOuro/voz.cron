<?php
declare(strict_types=1);

// VOZ_CRON targets Unraid 7.3.3+ only: upstream's entry points and file formats are gone.
final class LegacyTest extends TestCase
{
    private const P = __DIR__ . '/../source/voz.cron/usr/local/emhttp/plugins/voz.cron';

    public function testUpstreamEntryPointsAreGone(): void
    {
        foreach (['startBackground.php', 'startCustom.php', 'startSchedule.php', 'exec.php', 'startScript.sh', 'backgroundScript.sh', 'helpers.php'] as $f) {
            $this->assertFileDoesNotExist(self::P . "/$f");
        }
        $this->assertFalse(method_exists(Scripts::class, 'idFromPath'));
        $this->assertFalse(method_exists(Schedule::class, 'migrateCron'));
        $this->assertFalse(method_exists(Paths::class, 'scheduleFile'));
    }

    public function testOnlyTheMigrationMentionsScheduleJson(): void
    {
        $files = [...glob(self::P . '/*.php'), ...glob(self::P . '/lib/*.php'), ...glob(self::P . '/ui/*.js'), ...glob(self::P . '/event/*'), ...glob(self::P . '/cron/*')];
        foreach ($files as $f) {
            if (basename($f) === 'Schedule.php') continue;
            $this->assertStringNotContainsString('schedule.json', (string)file_get_contents($f), $f);
        }
        $src = (string)file_get_contents(self::P . '/lib/Schedule.php');
        $this->assertSame(1, substr_count($src, "'/schedule.json'"), 'one path literal, inside migrate()');
    }

    public function testOneLauncherNoAtQueue(): void
    {
        $this->assertFalse(method_exists(Sys::class, 'spawn'), 'Sys::launch replaced the at-based spawn');
        $files = [...glob(self::P . '/*.php'), ...glob(self::P . '/lib/*.php'), ...glob(self::P . '/event/*'), ...glob(self::P . '/cron/*')];
        foreach ($files as $f) $this->assertDoesNotMatchRegularExpression('~\|\s*at\b|\bat -M\b~', (string)file_get_contents($f), $f);
    }
}
