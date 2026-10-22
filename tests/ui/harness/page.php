<?php
// Renders VozCron.page the way Unraid's webGUI does: the body after the "---" header line, with
// $var (csrf_token) and $display (theme) in scope and autov() echoing the asset path.
$theme = ($_GET['theme'] ?? 'white') === 'black' ? 'black' : 'white';
$var = ['csrf_token' => 'test-token'];
$display = ['theme' => $theme];
function autov(string $path): void { echo $path; }
$src = (string)file_get_contents('/usr/local/emhttp/plugins/voz.cron/VozCron.page');
$body = substr($src, strpos($src, "\n---\n") + 5);
$tmp = tempnam(sys_get_temp_dir(), 'page');
file_put_contents($tmp, $body);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>VOZ_CRON (harness)</title><link rel="icon" href="data:,"><link rel="stylesheet" href="/theme.css?theme=<?= $theme ?>"></head>
<body><div id="header">UNRAID harness</div>
<div id="displaybox"><div class="content"><?php include $tmp; unlink($tmp); ?></div></div>
<div id="footer">footer</div></body></html>
