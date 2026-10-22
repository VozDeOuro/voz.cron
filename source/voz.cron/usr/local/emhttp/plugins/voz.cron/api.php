<?php
declare(strict_types=1);

// HTTP wrapper around Api::handle(). Unraid's local_prepend already rejects POSTs with a bad
// csrf_token; we check again so the endpoint is safe even if that ever changes.
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'error' => 'POST only']);
// header only: Unraid's local_prepend.php validates and then unsets both $_POST['csrf_token'] and
// $_SERVER['HTTP_X_CSRF_TOKEN'], so re-read the raw request header (getallheaders() is not affected).
$headers = function_exists('getallheaders') ? array_change_key_case((array)getallheaders()) : [];
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($headers['x-csrf-token'] ?? '');
$var = Sys::var();
if (!isset($var['csrf_token']) || !hash_equals((string)$var['csrf_token'], (string)$token)) respond(403, ['ok' => false, 'error' => 'bad csrf token']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
try {
    respond(200, ['ok' => true] + Api::handle($in));
} catch (ApiError $e) {
    respond($e->getCode() ?: 400, ['ok' => false, 'error' => $e->getMessage()] + $e->extra);
} catch (Throwable $e) {
    Sys::log('api: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'internal error']);
}
