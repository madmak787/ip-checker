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

function geo_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return 2 * $earth * asin(min(1.0, sqrt($a)));
}

function geo_is_coarse_point(array $point): bool
{
    $lat = (float) ($point['lat'] ?? 0);
    $lng = (float) ($point['lng'] ?? 0);
    $city = trim((string) ($point['city'] ?? ''));
    $accuracy = (float) ($point['accuracy_km'] ?? 0);

    $centroids = [
        [21.9974, 79.0011],
        [39.8283, -98.5795],
        [51.1657, 10.4515],
        [35.8617, 104.1954],
        [55.3781, -3.4360],
        [37.0902, -95.7129],
    ];
    foreach ($centroids as [$clat, $clng]) {
        if (geo_haversine_km($lat, $lng, $clat, $clng) < 8) {
            return true;
        }
    }

    return $city === '' && $accuracy >= 80;
}

function geo_http_multi(array $urls): array
{
    $results = array_fill_keys(array_keys($urls), null);
    if ($urls === []) {
        return $results;
    }

    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $name => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_USERAGENT => 'IPChecker/1.0',
            ]);
            $handles[$name] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $name => $ch) {
            $body = curl_multi_getcontent($ch);
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $results[$name] = $decoded;
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    }

    foreach ($urls as $name => $url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'header' => "User-Agent: IPChecker/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $results[$name] = $decoded;
            }
        }
    }

    return $results;
}

function geo_point(string $source, $lat, $lng, $city = null, $region = null, $country = null, $countryCode = null, $postal = null, $timezone = null, $isp = null, $accuracyKm = null): ?array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if ($lat === 0.0 && $lng === 0.0) {
        return null;
    }

    return [
        'source' => $source,
        'lat' => $lat,
        'lng' => $lng,
        'city' => $city ? (string) $city : null,
        'region' => $region ? (string) $region : null,
        'country' => $country ? (string) $country : null,
        'country_code' => $countryCode ? (string) $countryCode : null,
        'postal' => $postal ? (string) $postal : null,
        'timezone' => $timezone ? (string) $timezone : null,
        'isp' => $isp ? (string) $isp : null,
        'accuracy_km' => $accuracyKm !== null ? (float) $accuracyKm : null,
    ];
}

function geo_parse_remote_points(array $payloads): array
{
    $points = [];

    if (!empty($payloads['ipwho']['success'])) {
        $row = $payloads['ipwho'];
        $timezone = is_array($row['timezone'] ?? null) ? ($row['timezone']['id'] ?? null) : ($row['timezone'] ?? null);
        $isp = $row['connection']['isp'] ?? ($row['isp'] ?? null);
        $point = geo_point('ipwho.is', $row['latitude'] ?? null, $row['longitude'] ?? null, $row['city'] ?? null, $row['region'] ?? null, $row['country'] ?? null, $row['country_code'] ?? null, $row['postal'] ?? null, $timezone, $isp);
        if ($point) {
            $points[] = $point;
        }
    }

    if (($payloads['ipapi_com']['status'] ?? '') === 'success') {
        $row = $payloads['ipapi_com'];
        $point = geo_point('ip-api.com', $row['lat'] ?? null, $row['lon'] ?? null, $row['city'] ?? null, $row['regionName'] ?? null, $row['country'] ?? null, $row['countryCode'] ?? null, $row['zip'] ?? null, $row['timezone'] ?? null, $row['isp'] ?? null);
        if ($point) {
            $points[] = $point;
        }
    }

    if (empty($payloads['ipapi_co']['error'])) {
        $row = $payloads['ipapi_co'] ?? [];
        $point = geo_point('ipapi.co', $row['latitude'] ?? null, $row['longitude'] ?? null, $row['city'] ?? null, $row['region'] ?? null, $row['country_name'] ?? null, $row['country_code'] ?? null, $row['postal'] ?? null, $row['timezone'] ?? null, $row['org'] ?? null);
        if ($point) {
            $points[] = $point;
        }
    }

    if (!empty($payloads['ipinfo']['loc']) && is_string($payloads['ipinfo']['loc'])) {
        $row = $payloads['ipinfo'];
        [$lat, $lng] = array_pad(explode(',', $row['loc']), 2, null);
        $point = geo_point('ipinfo.io', $lat, $lng, $row['city'] ?? null, $row['region'] ?? null, $row['country'] ?? null, $row['country'] ?? null, $row['postal'] ?? null, $row['timezone'] ?? null, $row['org'] ?? null);
        if ($point) {
            $points[] = $point;
        }
    }

    if (isset($payloads['geojs']['latitude'], $payloads['geojs']['longitude'])) {
        $row = $payloads['geojs'];
        $point = geo_point('geojs.io', $row['latitude'], $row['longitude'], $row['city'] ?? null, $row['region'] ?? null, $row['country'] ?? null, $row['country_code'] ?? null, null, $row['timezone'] ?? null, $row['organization_name'] ?? null);
        if ($point) {
            $points[] = $point;
        }
    }

    return $points;
}

function geo_pick_best_point(array $points): ?array
{
    $usable = [];
    foreach ($points as $point) {
        if (!geo_is_coarse_point($point)) {
            $usable[] = $point;
        }
    }
    if ($usable === []) {
        $usable = $points;
    }
    if ($usable === []) {
        return null;
    }

    $best = null;
    $bestScore = -1;
    foreach ($usable as $candidate) {
        $neighbors = [];
        foreach ($usable as $other) {
            if (geo_haversine_km($candidate['lat'], $candidate['lng'], $other['lat'], $other['lng']) <= 40) {
                $neighbors[] = $other;
            }
        }
        $score = count($neighbors) * 10;
        if (!empty($candidate['city'])) {
            $score += 5;
        }
        if (!empty($candidate['postal'])) {
            $score += 3;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['center' => $candidate, 'neighbors' => $neighbors];
        }
    }

    if ($best === null) {
        return null;
    }

    $lat = 0.0;
    $lng = 0.0;
    foreach ($best['neighbors'] as $neighbor) {
        $lat += $neighbor['lat'];
        $lng += $neighbor['lng'];
    }
    $count = count($best['neighbors']);
    $lat /= $count;
    $lng /= $count;

    $radius = 1.0;
    foreach ($best['neighbors'] as $neighbor) {
        $radius = max($radius, geo_haversine_km($lat, $lng, $neighbor['lat'], $neighbor['lng']));
    }
    $radius = max(1, (int) round($radius));

    $fill = static function (string $key) use ($best) {
        foreach ($best['neighbors'] as $neighbor) {
            if (!empty($neighbor[$key])) {
                return $neighbor[$key];
            }
        }
        return $best['center'][$key] ?? null;
    };

    return [
        'lat' => $lat,
        'lng' => $lng,
        'city' => $fill('city'),
        'region' => $fill('region'),
        'country' => $fill('country'),
        'country_code' => $fill('country_code'),
        'postal' => $fill('postal'),
        'timezone' => $fill('timezone'),
        'isp' => $fill('isp'),
        'accuracy_km' => $radius,
        'sources' => array_values(array_unique(array_column($best['neighbors'], 'source'))),
    ];
}

function geo_lookup_remote(string $ip): array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
        return [];
    }

    $encoded = rawurlencode($ip);
    $payloads = geo_http_multi([
        'ipwho' => 'https://ipwho.is/' . $encoded,
        'ipapi_com' => 'http://ip-api.com/json/' . $encoded . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,query',
        'ipapi_co' => 'https://ipapi.co/' . $encoded . '/json/',
        'ipinfo' => 'https://ipinfo.io/' . $encoded . '/json',
        'geojs' => 'https://get.geojs.io/v1/ip/geo/' . $encoded . '.json',
    ]);

    return geo_parse_remote_points($payloads);
}

function geo_lookup_maxmind(string $ip): array
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
    $point = geo_point(
        'maxmind',
        $record->location->latitude,
        $record->location->longitude,
        $record->city->name,
        $subdivision->name,
        $record->country->name,
        $record->country->isoCode,
        $record->postal->code,
        $record->location->timeZone,
        $record->traits->isp,
        $record->location->accuracyRadius
    );

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
        'point' => $point,
    ];
}

function geo_lookup(string $ip): array
{
    $local = geo_lookup_maxmind($ip);
    $remote = geo_lookup_remote($ip);
    $points = $remote;
    if (!empty($local['point'])) {
        $points[] = $local['point'];
    }

    $best = geo_pick_best_point($points);
    if ($best === null) {
        return $local;
    }

    $base = $local['ok'] ? $local : [
        'ok' => true,
        'ip' => $ip,
        'continent' => null,
        'region_code' => null,
        'network' => null,
        'raw' => [],
    ];

    $base['ok'] = true;
    $base['city'] = $best['city'] ?: ($base['city'] ?? null);
    $base['region'] = $best['region'] ?: ($base['region'] ?? null);
    $base['country'] = $best['country'] ?: ($base['country'] ?? null);
    $base['country_code'] = $best['country_code'] ?: ($base['country_code'] ?? null);
    $base['postal'] = $best['postal'] ?: ($base['postal'] ?? null);
    $base['timezone'] = $best['timezone'] ?: ($base['timezone'] ?? null);
    $base['latitude'] = $best['lat'];
    $base['longitude'] = $best['lng'];
    $base['accuracy_radius_km'] = $best['accuracy_km'];
    if (!empty($best['isp']) && empty($base['network'])) {
        $base['isp'] = $best['isp'];
    } elseif (!empty($best['isp'])) {
        $base['isp'] = $best['isp'];
    }
    $base['sources'] = $best['sources'];
    unset($base['point']);

    return $base;
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
