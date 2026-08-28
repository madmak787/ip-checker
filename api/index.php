<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/geo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    geo_json_headers();
    exit;
}

$rawIp = isset($_GET['ip']) ? (string) $_GET['ip'] : geo_client_ip();
$ip = geo_normalize_ip($rawIp);

if ($ip === null) {
    geo_respond([
        'ok' => false,
        'error' => 'invalid_ip',
        'message' => 'Provide a valid IPv4 or IPv6 address.',
    ], 400);
}

$result = geo_lookup($ip);
if (!$result['ok']) {
    $status = $result['error'] === 'not_found' ? 404 : 503;
    geo_respond($result, $status);
}

unset($result['raw']);
geo_respond($result);
