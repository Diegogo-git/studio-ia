<?php
declare(strict_types=1);

// STUDIO_IA_FRONT_CONTROLLER_V1: managed entry point; no credentials live here.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$root = dirname(__DIR__);
$raw = @file_get_contents($root . '/current.json');
$current = is_string($raw) ? json_decode($raw, true) : null;
$release = is_array($current) ? ($current['release'] ?? null) : null;

if (!is_string($release) || preg_match('/\A[a-f0-9]{40}\z/D', $release) !== 1) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Studio IA: pendiente de activacion.');
}

$entry = $root . '/releases/' . $release . '/app/entry.php';
if (!is_file($entry)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Studio IA: version no disponible.');
}

define('STUDIO_RELEASE', $release);
require $entry;
