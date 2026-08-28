<?php

declare(strict_types=1);

require_once __DIR__ . '/../maxmind-geoip/vendor/autoload.php';

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;

function geo_is_ipv4(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

function geo_candidate_ips(): array
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    $ips = [];

    foreach ($headers as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        foreach (preg_split('/\s*,\s*/', (string) $_SERVER[$header]) as $part) {
            $ip = geo_normalize_ip($part);
            if ($ip !== null) {
                $ips[] = $ip;
            }
        }
    }

    return array_values(array_unique($ips));
}

function geo_client_ip(): string
{
    $ips = geo_candidate_ips();
    foreach ($ips as $ip) {
        if (geo_is_ipv4($ip)) {
            return $ip;
        }
    }

    return $ips[0] ?? '';
}

function geo_client_ipv6(): ?string
{
    foreach (geo_candidate_ips() as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
    }

    return null;
}

function geo_normalize_ip(?string $ip): ?string
{
    $ip = trim((string) $ip);
    if ($ip === '') {
        return null;
    }

    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
        $ip = $m[1];
    }

    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return null;
}

function geo_lookup(string $ip): array
{
    $db = __DIR__ . '/../maxmind-geoip/databases/GeoLite2-City.mmdb';
    if (!is_readable($db)) {
        return [
            'ok' => false,
            'error' => 'database_missing',
            'message' => 'GeoLite2 City database is not installed.',
            'ip' => $ip,
        ];
    }

    try {
        $reader = new Reader($db);
        $record = $reader->city($ip);
    } catch (AddressNotFoundException $e) {
        return [
            'ok' => false,
            'error' => 'not_found',
            'message' => 'No location found for this address. Private, reserved, or unlisted IPs are not in the database.',
            'ip' => $ip,
        ];
    } catch (InvalidDatabaseException $e) {
        return [
            'ok' => false,
            'error' => 'database_invalid',
            'message' => 'The GeoIP database could not be read.',
            'ip' => $ip,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'lookup_failed',
            'message' => 'Lookup failed.',
            'ip' => $ip,
        ];
    }

    $subdivision = $record->mostSpecificSubdivision;

    return [
        'ok' => true,
        'ip' => $record->traits->ipAddress,
        'city' => $record->city->name,
        'region' => $subdivision->name,
        'region_code' => $subdivision->isoCode,
        'country' => $record->country->name,
        'country_code' => $record->country->isoCode,
        'continent' => $record->continent->name,
        'postal' => $record->postal->code,
        'timezone' => $record->location->timeZone,
        'latitude' => $record->location->latitude,
        'longitude' => $record->location->longitude,
        'accuracy_radius_km' => $record->location->accuracyRadius,
        'network' => $record->traits->network,
        'raw' => $record->jsonSerialize(),
    ];
}

function geo_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Cache-Control: no-store');
}

function geo_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    geo_json_headers();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function geo_display($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return (string) $value;
}
