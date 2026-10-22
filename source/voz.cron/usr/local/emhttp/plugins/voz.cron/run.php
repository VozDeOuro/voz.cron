#!/usr/bin/php
<?php
declare(strict_types=1);

// CLI entry point for every execution path: cron, emhttp events, the UI and the .plg install.
require_once __DIR__ . '/lib/bootstrap.php';

$o = getopt('', ['script:', 'trigger:', 'frequency:', 'abort:', 'sync-history', 'seed', 'migrate', 'import']);
try {
    if (isset($o['sync-history'])) { History::sync(); exit(0); }
    if (isset($o['migrate'])) {
        $n = Schedule::migrate();
        if ($n > 0) Sys::log("imported $n schedule settings from the upstream file into config.json");
        exit(0);
    }
    if (isset($o['import'])) {
        Import::carryBootMarker();
        $r = Import::fromOld();
        echo match ($r) { 'imported' => "Imported scripts and settings from " . Paths::$oldFlash . " (left unchanged)\n", 'already' => '', default => "Nothing to import from " . Paths::$oldFlash . "\n" };
        exit(0);
    }
    if (isset($o['seed'])) { History::seed(); Schedule::writeCron(); exit(0); }
    if (isset($o['abort'])) { exit(Runner::abort((string)$o['abort']) ? 0 : 1); }
    if (isset($o['frequency'])) {
        if (!in_array($o['frequency'], Runner::EVENT_FREQUENCIES, true)) throw new InvalidArgumentException('bad frequency');
        Runner::runFrequency((string)$o['frequency']);
        exit(0);
    }
    if (isset($o['script'])) {
        $trigger = (string)($o['trigger'] ?? 'manual');
        if (!in_array($trigger, Runner::TRIGGERS, true)) throw new InvalidArgumentException('bad trigger');
        $status = Runner::run((string)$o['script'], $trigger);
        exit($status === 'ok' || $status === 'blocked' || $status === 'skipped' ? 0 : 1);
    }
    fwrite(STDERR, "usage: run.php --script <id> [--trigger t] | --frequency f | --abort id | --sync-history | --seed | --migrate | --import\n");
    exit(2);
} catch (Throwable $e) {
    Sys::log('run.php: ' . $e->getMessage());
    exit(2);
}
