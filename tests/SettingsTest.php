<?php
declare(strict_types=1);

final class SettingsTest extends TestCase
{
    public function testDefaultsAndClamping(): void
    {
        $this->assertSame(200, Settings::get()['historyCap']);
        $s = Settings::set(['historyCap' => 3, 'syncMinutes' => 7, 'evil' => 1]);
        $this->assertSame(10, $s['historyCap']);
        $this->assertSame(60, $s['syncMinutes']);
        $this->assertSame(Paths::$flash . '/history', $s['historyDir']);
        $this->assertArrayNotHasKey('evil', Settings::get());
    }

    public function testCustomHistoryDirPersists(): void
    {
        Settings::set(['historyDir' => '/mnt/user/system/voz.cron/', 'syncMinutes' => 15]);
        $this->assertSame('/mnt/user/system/voz.cron', Settings::get()['historyDir']);
        $this->assertSame(15, Settings::get()['syncMinutes']);
        Settings::set(['historyDir' => '/boot/config/us-history']);
        $this->assertSame('/boot/config/us-history', Settings::get()['historyDir']);
    }

    public function testHistoryDirOutsideBootOrMntIsRejected(): void
    {
        Settings::set(['historyDir' => '/mnt/cache/us']);
        foreach (['../etc', '/etc/cron.d', '/root', '/mnt', '/mnt/', '/boot', '/mnt/user/../../etc', "/mnt/a\nb", '/tmp/x', 'mnt/user'] as $bad) {
            try { Settings::set(['historyDir' => $bad]); $this->fail("accepted $bad"); }
            catch (InvalidArgumentException $e) { $this->assertStringContainsString('/boot/ or /mnt/', $e->getMessage()); }
        }
        $this->assertSame('/mnt/cache/us', Settings::get()['historyDir'], 'a rejected value changes nothing');
    }

    public function testHandEditedBadHistoryDirFallsBackToDefault(): void
    {
        file_put_contents(Paths::settingsFile(), json_encode(['historyDir' => '/etc']));
        $this->assertSame(Paths::$flash . '/history', Settings::get()['historyDir']);
    }
}
