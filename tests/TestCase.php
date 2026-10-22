<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
putenv('US_TEST=1');
require_once __DIR__ . '/../source/voz.cron/usr/local/emhttp/plugins/voz.cron/lib/bootstrap.php';

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/us-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/flash/scripts', 0777, true);
        mkdir($this->root . '/tmp', 0777, true);
        Paths::$flash = $this->root . '/flash';
        Paths::$tmp = $this->root . '/tmp';
        Paths::$oldFlash = $this->root . '/old-flash'; // the original User Scripts plugin's dirs (Import)
        Paths::$oldTmp = $this->root . '/old-tmp';
        Paths::$varIni = $this->root . '/var.ini';
        Paths::$notify = $this->root . '/notify';
        Paths::$updateCron = $this->root . '/update_cron';
        Sys::$localtime = $this->root . '/no-localtime'; // tests use PHP's default zone unless they set one
        Sys::$launched = [];
        Sys::$dryRun = true; // tests that need real detached runs turn this off
    }

    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }

    protected function makeScript(string $id, string $body = "#!/bin/bash\necho hi\n", ?string $name = null): string
    {
        $dir = Paths::scripts() . '/' . $id;
        mkdir($dir, 0777, true);
        file_put_contents("$dir/script", $body);
        if ($name !== null) file_put_contents("$dir/name", $name);
        return $dir;
    }
}
