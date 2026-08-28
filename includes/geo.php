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

function geo_normalize_host(string $query): ?string
{
    $host = trim($query);
    $host = preg_replace('#^https?://#i', '', $host) ?? $host;
    $host = explode('/', $host)[0];
    $host = explode('?', $host)[0];
    if (preg_match('/^([a-z0-9.-]+):\d+$/i', $host, $m)) {
        $host = $m[1];
    }
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || strlen($host) > 253) {
        return null;
    }
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) {
        return null;
    }

    return $host;
}

function geo_resolve_target(string $raw): array
{
    $ip = geo_normalize_ip($raw);
    if ($ip !== null) {
        return ['ok' => true, 'ip' => $ip, 'query' => $raw, 'resolved_from' => null];
    }

    $host = geo_normalize_host($raw);
    if ($host === null) {
        return [
            'ok' => false,
            'error' => 'invalid_query',
            'message' => 'Enter a valid IPv4, IPv6, or hostname.',
        ];
    }

    $ip = null;
    $aRecords = @dns_get_record($host, DNS_A);
    if (is_array($aRecords)) {
        foreach ($aRecords as $record) {
            if (!empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                $ip = $record['ip'];
                break;
            }
        }
    }
    if ($ip === null) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP)) {
                    $ip = $record['ipv6'];
                    break;
                }
            }
        }
    }
    if ($ip === null) {
        $guess = @gethostbyname($host);
        if (is_string($guess) && $guess !== $host && filter_var($guess, FILTER_VALIDATE_IP)) {
            $ip = $guess;
        }
    }
    if ($ip === null) {
        return [
            'ok' => false,
            'error' => 'host_not_found',
            'message' => 'Could not resolve that hostname.',
        ];
    }

    return ['ok' => true, 'ip' => $ip, 'query' => $raw, 'resolved_from' => $host];
}

function geo_reverse_dns(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $ptr = implode('.', array_reverse($parts)) . '.in-addr.arpa';
        $records = @dns_get_record($ptr, DNS_PTR);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['target'])) {
                    return rtrim((string) $record['target'], '.');
                }
            }
        }
    }

    $name = @gethostbyaddr($ip);
    if (!is_string($name) || $name === '' || $name === $ip) {
        return null;
    }

    return $name;
}

function geo_parse_asn($value): ?int
{
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
    if (is_string($value) && preg_match('/AS(\d+)/i', $value, $m)) {
        return (int) $m[1];
    }

    return null;
}

function geo_classify_network(?string $org, bool $hostingFlag = false, bool $proxyFlag = false): array
{
    $hay = strtolower((string) $org);
    $vpnWords = ['vpn', 'proxy', 'tor exit', 'tor-exit', 'anonymiz', 'mullvad', 'nordvpn', 'proton vpn', 'expressvpn', 'surfshark', 'windscribe', 'cyberghost', 'hide.me', 'private internet', 'cloudflare warp'];
    $hostWords = ['amazon', 'aws', 'azure', 'google cloud', 'google llc', 'digitalocean', 'digital ocean', 'linode', 'vultr', 'hetzner', 'ovh', 'cloudflare', 'akamai', 'fastly', 'leaseweb', 'datacenter', 'data center', 'colo', 'dedicated', 'vps', 'hosting', 'server', 'oracle cloud', 'alibaba', 'm247', 'datacamp', 'choopa', 'contabo', 'scaleway', 'rackspace'];

    if ($proxyFlag) {
        return ['kind' => 'vpn', 'label' => 'Likely VPN or proxy'];
    }
    foreach ($vpnWords as $word) {
        if ($hay !== '' && str_contains($hay, $word)) {
            return ['kind' => 'vpn', 'label' => 'Likely VPN or proxy'];
        }
    }
    if ($hostingFlag) {
        return ['kind' => 'hosting', 'label' => 'Likely hosting or datacenter'];
    }
    foreach ($hostWords as $word) {
        if ($hay !== '' && str_contains($hay, $word)) {
            return ['kind' => 'hosting', 'label' => 'Likely hosting or datacenter'];
        }
    }
    if ($hay !== '') {
        return ['kind' => 'isp', 'label' => 'Likely ISP or enterprise'];
    }

    return ['kind' => 'unknown', 'label' => 'Network type unknown'];
}

function geo_lookup_asn(string $ip): array
{
    $db = __DIR__ . '/../maxmind-geoip/databases/GeoLite2-ASN.mmdb';
    if (!is_readable($db)) {
        return [];
    }
    try {
        $reader = new Reader($db);
        $record = $reader->asn($ip);

        return [
            'asn' => $record->autonomousSystemNumber,
            'asn_org' => $record->autonomousSystemOrganization,
            'network' => $record->network,
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function geo_lookup_connection(string $ip): array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
        return [];
    }
    $row = geo_http_get('https://ipwho.is/' . rawurlencode($ip));
    if (!is_array($row) || empty($row['success'])) {
        return [];
    }
    $conn = is_array($row['connection'] ?? null) ? $row['connection'] : [];

    return [
        'asn' => geo_parse_asn($conn['asn'] ?? null),
        'asn_org' => $conn['org'] ?? ($conn['isp'] ?? ($row['isp'] ?? null)),
        'isp' => $conn['isp'] ?? ($row['isp'] ?? null),
    ];
}

function geo_enrich_network(string $ip, array $base, array $points = []): array
{
    $asnDb = geo_lookup_asn($ip);
    $asn = $asnDb['asn'] ?? null;
    $org = $asnDb['asn_org'] ?? null;
    $network = $asnDb['network'] ?? ($base['network'] ?? null);
    $isp = $base['isp'] ?? null;
    $hostingFlag = false;
    $proxyFlag = false;

    foreach ($points as $point) {
        if ($asn === null && !empty($point['asn'])) {
            $asn = (int) $point['asn'];
        }
        if ($org === null && !empty($point['asn_org'])) {
            $org = (string) $point['asn_org'];
        }
        if ($isp === null && !empty($point['isp'])) {
            $isp = (string) $point['isp'];
        }
        $hostingFlag = $hostingFlag || !empty($point['hosting']);
        $proxyFlag = $proxyFlag || !empty($point['proxy']);
    }

    if ($asn === null || $org === null) {
        $conn = geo_lookup_connection($ip);
        if ($asn === null && !empty($conn['asn'])) {
            $asn = $conn['asn'];
        }
        if ($org === null && !empty($conn['asn_org'])) {
            $org = $conn['asn_org'];
        }
        if ($isp === null && !empty($conn['isp'])) {
            $isp = $conn['isp'];
        }
    }

    if ($org === null) {
        $org = $isp;
    }
    $class = geo_classify_network($org, $hostingFlag, $proxyFlag);
    $base['asn'] = $asn;
    $base['asn_org'] = $org;
    $base['isp'] = $isp ?: $org;
    $base['network'] = $network;
    $base['hostname'] = geo_reverse_dns($ip);
    $base['connection_kind'] = $class['kind'];
    $base['connection_hint'] = $class['label'];
    $base['likely_vpn'] = $class['kind'] === 'vpn';
    $base['likely_hosting'] = $class['kind'] === 'hosting';
    $base['ip_version'] = geo_is_ipv4($ip) ? 4 : 6;

    return $base;
}

function geo_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/detailed')) {
        $dir = dirname($dir);
    }
    if (str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }

    return $scheme . '://' . $host . $dir;
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

function geo_http_get(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'IPChecker/1.0',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'header' => "User-Agent: IPChecker/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return null;
    }
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

function geo_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/cache/geo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function geo_cache_get(string $ip): ?array
{
    $file = geo_cache_dir() . '/' . hash('sha256', 'v4|' . $ip) . '.json';
    if (!is_readable($file)) {
        return null;
    }
    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload) || ($payload['expires'] ?? 0) < time() || empty($payload['data']) || !is_array($payload['data'])) {
        return null;
    }

    return $payload['data'];
}

function geo_cache_set(string $ip, array $data, int $ttl = 86400): void
{
    if (empty($data['ok'])) {
        return;
    }
    $file = geo_cache_dir() . '/' . hash('sha256', 'v4|' . $ip) . '.json';
    @file_put_contents($file, json_encode([
        'expires' => time() + $ttl,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES));
}

function geo_point_is_useful(array $point): bool
{
    return !geo_is_coarse_point($point) && !empty($point['city']);
}

function geo_local_is_precise(array $local): bool
{
    if (empty($local['ok']) || empty($local['point'])) {
        return false;
    }
    $point = $local['point'];
    $accuracy = (float) ($point['accuracy_km'] ?? $local['accuracy_radius_km'] ?? 999);

    return geo_point_is_useful($point) && $accuracy > 0 && $accuracy <= 40;
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
            $point['asn'] = geo_parse_asn($row['connection']['asn'] ?? null);
            $point['asn_org'] = $row['connection']['org'] ?? $isp;
            $points[] = $point;
        }
    }

    if (($payloads['ipapi_com']['status'] ?? '') === 'success') {
        $row = $payloads['ipapi_com'];
        $point = geo_point('ip-api.com', $row['lat'] ?? null, $row['lon'] ?? null, $row['city'] ?? null, $row['regionName'] ?? null, $row['country'] ?? null, $row['countryCode'] ?? null, $row['zip'] ?? null, $row['timezone'] ?? null, $row['isp'] ?? ($row['org'] ?? null));
        if ($point) {
            $point['asn'] = geo_parse_asn($row['as'] ?? null);
            $point['asn_org'] = $row['asname'] ?? ($row['org'] ?? $row['isp'] ?? null);
            $point['hosting'] = !empty($row['hosting']);
            $point['proxy'] = !empty($row['proxy']);
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

    if (isset($payloads['ipquery']['location']) && is_array($payloads['ipquery']['location'])) {
        $row = $payloads['ipquery']['location'];
        $ispInfo = is_array($payloads['ipquery']['isp'] ?? null) ? $payloads['ipquery']['isp'] : [];
        $isp = $ispInfo['org'] ?? ($ispInfo['isp'] ?? null);
        $point = geo_point('ipquery.io', $row['latitude'] ?? null, $row['longitude'] ?? null, $row['city'] ?? null, $row['state'] ?? ($row['region'] ?? null), $row['country'] ?? null, $row['country_code'] ?? null, $row['zipcode'] ?? ($row['postal'] ?? null), $row['timezone'] ?? null, $isp);
        if ($point) {
            $point['asn'] = geo_parse_asn($ispInfo['asn'] ?? null);
            $point['asn_org'] = $ispInfo['org'] ?? $isp;
            $points[] = $point;
        }
    }

    if (isset($payloads['freeipapi']['latitude'], $payloads['freeipapi']['longitude'])) {
        $row = $payloads['freeipapi'];
        $point = geo_point('freeipapi', $row['latitude'], $row['longitude'], $row['cityName'] ?? null, $row['regionName'] ?? null, $row['countryName'] ?? null, $row['countryCode'] ?? null, $row['zipCode'] ?? null, $row['timeZones'][0] ?? null, null);
        if ($point) {
            $points[] = $point;
        }
    }

    return $points;
}

function geo_median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (float) $values[$mid];
    }

    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
}

function geo_majority_group(array $points, string $key): array
{
    $groups = [];
    foreach ($points as $point) {
        $value = strtolower(trim((string) ($point[$key] ?? '')));
        if ($value === '') {
            continue;
        }
        $groups[$value][] = $point;
    }
    if ($groups === []) {
        return $points;
    }
    uasort($groups, static fn ($a, $b) => count($b) <=> count($a));
    $top = reset($groups);
    if (count($top) >= 2) {
        return array_values($top);
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

    $usable = geo_majority_group($usable, 'city');
    $postalGroup = geo_majority_group($usable, 'postal');
    if (count($postalGroup) >= 2) {
        $usable = $postalGroup;
    }

    $radiusLimit = count($postalGroup) >= 2 ? 12.0 : 25.0;
    $best = null;
    $bestScore = -1;
    foreach ($usable as $candidate) {
        $neighbors = [];
        foreach ($usable as $other) {
            if (geo_haversine_km($candidate['lat'], $candidate['lng'], $other['lat'], $other['lng']) <= $radiusLimit) {
                $neighbors[] = $other;
            }
        }
        $score = count($neighbors) * 10;
        if (!empty($candidate['city'])) {
            $score += 8;
        }
        if (!empty($candidate['postal'])) {
            $score += 6;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['center' => $candidate, 'neighbors' => $neighbors];
        }
    }

    if ($best === null) {
        return null;
    }

    $lats = array_column($best['neighbors'], 'lat');
    $lngs = array_column($best['neighbors'], 'lng');
    $lat = geo_median($lats);
    $lng = geo_median($lngs);

    $distances = [];
    foreach ($best['neighbors'] as $neighbor) {
        $distances[] = geo_haversine_km($lat, $lng, $neighbor['lat'], $neighbor['lng']);
    }
    sort($distances, SORT_NUMERIC);
    $cutoff = $distances[(int) floor(0.8 * max(0, count($distances) - 1))] ?? 0;
    $radius = max(1, (int) ceil($cutoff > 0 ? $cutoff : 5));
    if (count($best['neighbors']) === 1) {
        $radius = max($radius, 8);
    }

    $fill = static function (string $key) use ($best) {
        $counts = [];
        foreach ($best['neighbors'] as $neighbor) {
            $value = trim((string) ($neighbor[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        if ($counts === []) {
            return $best['center'][$key] ?? null;
        }
        arsort($counts);

        return (string) array_key_first($counts);
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
    $urls = [
        'ipwho' => 'https://ipwho.is/' . $encoded,
        'ipquery' => 'https://api.ipquery.io/' . $encoded,
        'ipapi_com' => 'http://ip-api.com/json/' . $encoded . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,as,asname,hosting,proxy,query',
    ];

    $points = [];
    foreach ($urls as $name => $url) {
        $parsed = geo_parse_remote_points([$name => geo_http_get($url)]);
        foreach ($parsed as $point) {
            $points[] = $point;
        }
        if ($parsed !== [] && geo_point_is_useful($parsed[0])) {
            break;
        }
    }

    return $points;
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
    $cached = geo_cache_get($ip);
    if ($cached !== null) {
        $cached['cached'] = true;
        return $cached;
    }

    $local = geo_lookup_maxmind($ip);
    $remote = [];
    if (!geo_local_is_precise($local)) {
        $remote = geo_lookup_remote($ip);
    }

    $points = $remote;
    if (!empty($local['point'])) {
        $points[] = $local['point'];
    }

    $best = geo_pick_best_point($points);
    if ($best === null) {
        if (!empty($local['ok'])) {
            $local = geo_enrich_network($ip, $local, $points);
            unset($local['point']);
            geo_cache_set($ip, $local, 21600);
        }
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
    if (($best['country'] ?? '') !== '' && ($best['country'] !== ($local['country'] ?? null))) {
        $base['continent'] = null;
    }
    $base['postal'] = $best['postal'] ?: ($base['postal'] ?? null);
    $base['timezone'] = $best['timezone'] ?: ($base['timezone'] ?? null);
    $base['latitude'] = $best['lat'];
    $base['longitude'] = $best['lng'];
    $base['accuracy_radius_km'] = $best['accuracy_km'];
    if (!empty($best['isp'])) {
        $base['isp'] = $best['isp'];
    }
    $base['sources'] = $best['sources'];
    unset($base['point']);
    $base = geo_enrich_network($ip, $base, $points);

    geo_cache_set($ip, $base);

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
