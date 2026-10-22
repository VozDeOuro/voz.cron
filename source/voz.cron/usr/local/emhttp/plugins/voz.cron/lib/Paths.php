<?php
declare(strict_types=1);

// Every filesystem location the plugin touches. Tests point these at a sandbox via env.
final class Paths
{
    public static string $flash = '/boot/config/plugins/voz.cron';
    public static string $tmp = '/tmp/voz.cron';
    public static string $plugin = '/usr/local/emhttp/plugins/voz.cron';
    public static string $varIni = '/var/local/emhttp/var.ini';
    public static string $notify = '/usr/local/emhttp/webGui/scripts/notify';
    public static string $updateCron = '/usr/local/sbin/update_cron';
    /** The original User Scripts plugin's flash and RAM dirs: read once by Import, never written. */
    public static string $oldFlash = '/boot/config/plugins/user.scripts';
    public static string $oldTmp = '/tmp/user.scripts';

    private const array ENV = [
        'flash' => 'US_FLASH', 'tmp' => 'US_TMP', 'plugin' => 'US_PLUGIN',
        'varIni' => 'US_VARINI', 'notify' => 'US_NOTIFY', 'updateCron' => 'US_UPDATE_CRON',
        'oldFlash' => 'US_OLD_FLASH', 'oldTmp' => 'US_OLD_TMP',
    ];

    public static function fromEnv(): void
    {
        foreach (self::ENV as $prop => $env) {
            $v = getenv($env);
            if ($v !== false && $v !== '') self::$$prop = $v;
        }
    }

    public static function scripts(): string { return self::$flash . '/scripts'; }
    public static function configFile(): string { return self::$flash . '/config.json'; }
    public static function layoutFile(): string { return self::$flash . '/layout.json'; }
    public static function cronFile(): string { return self::$flash . '/customSchedule.cron'; }
    public static function settingsFile(): string { return self::$flash . '/settings.json'; }
    public static function ramHistory(): string { return self::$tmp . '/history'; }
    public static function locks(): string { return self::$tmp . '/locks'; }
    public static function running(): string { return self::$tmp . '/running'; }
    public static function finished(): string { return self::$tmp . '/finished'; }
    public static function aborted(): string { return self::$tmp . '/aborted'; }
    public static function work(string $id): string { return self::$tmp . '/tmpScripts/' . $id; }
}
