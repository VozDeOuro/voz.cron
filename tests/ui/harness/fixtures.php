<?php
declare(strict_types=1);

// Builds the UI-test sandbox under /fx: scripts, schedules, history with every outcome, one folder, fake notify.
function us_fixtures(): void
{
    $fx = '/fx';
    exec('rm -rf ' . escapeshellarg($fx));
    foreach (["$fx/flash/scripts", "$fx/tmp/history"] as $d) mkdir($d, 0777, true);
    file_put_contents("$fx/var.ini", "csrf_token=\"test-token\"\nmdState=\"STARTED\"\nmdResyncPos=\"0\"\n");
    file_put_contents("$fx/notify", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> $fx/sent\n");
    chmod("$fx/notify", 0755);
    $scripts = [
        'nightly-backup' => ['Weekly backup of appdata', "#!/bin/bash\necho backup\n"],
        'db-dump' => ['Database backup', "#!/bin/bash\necho db\n"],
        'ping-hosts' => ['Pings the hosts on the LAN', "#!/bin/bash\necho up\n"],
        'refresh-ddns' => ['Updates the dynamic DNS record', "#!/bin/bash\necho dns\n"],
        'tidy temp' => ['', "#!/bin/bash\necho tidy\n"],
        'fg-only' => ['Foreground only, so it has no Background button', "#!/bin/bash\n#foregroundOnly=true\necho fg\n"],
        'long-name' => ['A long description that has to wrap on a phone screen without pushing any of the buttons off the right edge', "#!/bin/bash\necho long\n"],
    ];
    foreach ($scripts as $id => [$desc, $body]) {
        mkdir("$fx/flash/scripts/$id");
        file_put_contents("$fx/flash/scripts/$id/script", $body);
        if ($desc !== '') file_put_contents("$fx/flash/scripts/$id/description", $desc);
    }
    file_put_contents("$fx/flash/scripts/long-name/name", 'A script with a really long name that wraps on phones');
    file_put_contents("$fx/flash/config.json", json_encode([
        'nightly-backup' => ['frequency' => 'custom', 'custom' => '0 3 * * 1', 'notify' => 'failure'],
        'ping-hosts' => ['frequency' => 'custom', 'custom' => '* * * * *'],
        'refresh-ddns' => ['frequency' => 'custom', 'custom' => '*/5 * * * *'],
        'tidy temp' => ['frequency' => 'daily'],
    ], JSON_PRETTY_PRINT));
    file_put_contents("$fx/flash/layout.json", json_encode(['version' => 1, 'items' => [
        ['type' => 'folder', 'id' => 'f-backups', 'name' => 'Backups', 'icon' => '💾', 'scripts' => ['nightly-backup', 'db-dump']],
        ['type' => 'script', 'id' => 'ping-hosts'],
    ]], JSON_UNESCAPED_UNICODE));
    $now = time();
    $line = fn(int $ago, int $exit, string $status, string $outcome, array $extra = []) => json_encode(
        ['start' => $now - $ago, 'end' => $now - $ago + 4, 'exit' => $exit, 'trigger' => 'custom', 'status' => $status, 'outcome' => $outcome, 'dur' => 4] + $extra);
    $history = [
        'nightly-backup' => [
            $line(9 * 86400, 0, 'ok', 'ok'),
            $line(7 * 86400, 0, 'ok', 'ok', ['repeat' => 3, 'first' => $now - 8 * 86400, 'dmin' => 4, 'dmax' => 4, 'davg' => 4]),
            $line(5 * 86400, 1, 'failed', 'fail'),
            $line(4 * 86400, 2, 'failed', 'warn'),
            $line(3 * 86400, 137, 'aborted', 'aborted'),
            $line(2 * 86400, 3, 'failed', 'ignore'),
        ],
        'db-dump' => [$line(3600, 0, 'ok', 'ok')],
        'refresh-ddns' => [$line(60, 0, 'ok', 'ok', ['repeat' => 50, 'first' => $now - 3000, 'dmin' => 1, 'dmax' => 3, 'davg' => 2])],
        'ping-hosts' => [$line(30, 0, 'ok', 'ok')],
    ];
    foreach ($history as $id => $lines) file_put_contents("$fx/tmp/history/" . rawurlencode($id) . '.jsonl', implode("\n", $lines) . "\n");
    file_put_contents("$fx/tmp/history/state.json", json_encode(['nightly-backup' => [
        'health' => 'failing', 'fails' => 1, 'firstFail' => $now - 5 * 86400, 'lastExit' => 1, 'lastAlert' => $now - 5 * 86400, 'alerted' => true,
    ]]));
}
