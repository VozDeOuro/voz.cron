<?php
declare(strict_types=1);

// Dev-only harness for `php -S`: the real VozCron.page body inside a fake Unraid 7.3 shell, the plugin's
// static files, the real api.php, and a few test endpoints. Never shipped (not under source/).
$P = '/usr/local/emhttp/plugins/voz.cron';
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/__health') { echo 'ok'; return true; }
if ($uri === '/__reset' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require __DIR__ . '/fixtures.php';
    us_fixtures();
    echo 'ok';
    return true;
}
if ($uri === '/__notified') { header('Content-Type: text/plain'); echo (string)@file_get_contents('/fx/sent'); return true; }
if ($uri === '/plugins/voz.cron/api.php') { require "$P/api.php"; return true; }
if (str_starts_with($uri, '/plugins/voz.cron/')) {
    $f = realpath($P . substr($uri, strlen('/plugins/voz.cron')));
    if ($f === false || !str_starts_with($f, "$P/") || !is_file($f) || str_ends_with($f, '.php')) { http_response_code(404); return true; }
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'png' => 'image/png'];
    header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($f);
    return true;
}
if ($uri === '/theme.css') {
    header('Content-Type: text/css');
    readfile(__DIR__ . '/theme-' . (($_GET['theme'] ?? 'white') === 'black' ? 'black' : 'white') . '.css');
    return true;
}
if ($uri === '/' || $uri === '/Settings/VozCron') { require __DIR__ . '/page.php'; return true; }
http_response_code(404);
return true;
