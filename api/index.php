<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/geo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    geo_json_headers();
    exit;
}

$rawIp = isset($_GET['ip']) ? trim((string) $_GET['ip']) : geo_client_ip();
$resolved = geo_resolve_target($rawIp);

if (!$resolved['ok']) {
    geo_respond([
        'ok' => false,
        'error' => $resolved['error'] ?? 'invalid_query',
        'message' => $resolved['message'] ?? 'Provide a valid IPv4, IPv6, or hostname.',
    ], 400);
}

$result = geo_lookup($resolved['ip']);
if (!$result['ok']) {
    $status = $result['error'] === 'not_found' ? 404 : 503;
    geo_respond($result, $status);
}

$result['query'] = $resolved['query'];
$result['resolved_from'] = $resolved['resolved_from'];
unset($result['raw']);
geo_respond($result);
