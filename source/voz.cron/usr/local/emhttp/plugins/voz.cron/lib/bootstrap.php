<?php
declare(strict_types=1);

foreach (['Paths', 'Sys', 'Settings', 'Vars', 'Scripts', 'Cron', 'ExitCodes', 'Schedule', 'History', 'State', 'Alerts', 'Runner', 'Layout', 'Api', 'Import'] as $c) {
    if (is_file(__DIR__ . "/$c.php")) require_once __DIR__ . "/$c.php";
}
Paths::fromEnv();
