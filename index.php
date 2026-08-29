<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/geo.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$clientIp = geo_client_ip();
$clientIpv6 = geo_client_ipv6();
$requested = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
$isSelf = isset($_GET['self']) && (string) $_GET['self'] === '1';
$isOwnLookup = $requested === '' || $isSelf;
$rawTarget = $requested === '' ? $clientIp : $requested;
$resolved = geo_resolve_target($rawTarget);
$targetIp = $resolved['ok'] ? $resolved['ip'] : null;
$resolvedFrom = $resolved['ok'] ? ($resolved['resolved_from'] ?? null) : null;
$detectIpv4 = $requested === '' && $targetIp !== null && !geo_is_ipv4($targetIp);

$inputError = null;
$result = null;

if (!$resolved['ok']) {
    $inputError = $resolved['message'] ?? 'Enter a valid IPv4, IPv6, or hostname.';
} else {
    $result = geo_lookup($targetIp);
}

$ok = is_array($result) && !empty($result['ok']);
$hasMap = $ok && $result['latitude'] !== null && $result['longitude'] !== null;
$displayIp = $ok ? $result['ip'] : ($targetIp ?? $rawTarget);
$queryIp = ($isOwnLookup ? $displayIp : $targetIp) ?: $clientIp;
$publicBase = geo_public_base();
$shareQuery = '?ip=' . rawurlencode((string) $queryIp);
$shareUrl = $publicBase . '/' . $shareQuery;
$badgeUrl = $publicBase . '/badge.php?ip=' . rawurlencode((string) $queryIp);
$embedSnippet = '<iframe src="' . $badgeUrl . '" width="340" height="112" loading="lazy" style="border:0;border-radius:10px;overflow:hidden" title="IP Checker"></iframe>';
$basicApi = 'api/?ip=' . rawurlencode((string) $queryIp);
$detailedApi = 'api/detailed/?ip=' . rawurlencode((string) $queryIp);
$curlBasic = 'curl -sS "' . $publicBase . '/api/?ip=' . rawurlencode((string) $queryIp) . '"';
$curlHost = 'curl -sS "' . $publicBase . '/api/?ip=google.com"';

$placeParts = $ok ? array_values(array_filter([
    $result['city'],
    $result['region'],
    $result['country'],
], static fn ($part) => $part !== null && $part !== '')) : [];
$place = $placeParts ? implode(', ', $placeParts) : '';
$countryLabel = ($ok && $result['country']) ? $result['country'] : '';
if ($place !== '' && $place === $countryLabel) {
    $place = '';
}

$showMap = $hasMap || $isOwnLookup;
$mapPayload = [
    'lat' => $hasMap ? (float) $result['latitude'] : null,
    'lng' => $hasMap ? (float) $result['longitude'] : null,
    'title' => $displayIp,
    'details' => $place,
    'accuracy' => $ok ? $result['accuracy_radius_km'] : null,
    'useDeviceLocation' => false,
];

$showIpv6 = $isOwnLookup && $clientIpv6 && $clientIpv6 !== $displayIp;
$hintKind = $ok ? ($result['connection_kind'] ?? 'unknown') : 'unknown';
$hintLabel = $ok ? ($result['connection_hint'] ?? '') : '';

$kicker = 'Looked up IP';
if ($resolvedFrom) {
    $kicker = 'Resolved ' . $resolvedFrom;
} elseif ($isOwnLookup) {
    $kicker = ($targetIp && geo_is_ipv4($targetIp)) ? 'Your IPv4' : 'Your IP';
}

$fieldRows = [];
if ($ok) {
    $asnLabel = null;
    if (!empty($result['asn'])) {
        $asnLabel = 'AS' . $result['asn'];
        if (!empty($result['asn_org'])) {
            $asnLabel .= ' · ' . $result['asn_org'];
        }
    } elseif (!empty($result['asn_org'])) {
        $asnLabel = $result['asn_org'];
    }
    $candidates = [
        ['Hostname', $result['hostname'] ?? null],
        ['City', $result['city']],
        ['Region', $result['region']],
        ['Country', $result['country'] ? $result['country'] . ($result['country_code'] ? ' (' . $result['country_code'] . ')' : '') : null],
        ['Continent', $result['continent']],
        ['Postal', $result['postal']],
        ['Timezone', $result['timezone']],
        ['Coordinates', $hasMap ? $result['latitude'] . ', ' . $result['longitude'] : null],
        ['Accuracy', $result['accuracy_radius_km'] ? '± ' . $result['accuracy_radius_km'] . ' km' : null],
        ['ASN', $asnLabel],
        ['ISP', $result['isp'] ?? null],
        ['Network', $result['network']],
        ['Sources', !empty($result['sources']) ? implode(', ', $result['sources']) : null],
    ];
    foreach ($candidates as $row) {
        if ($row[1] !== null && $row[1] !== '') {
            $fieldRows[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>IP Checker</title>
		<meta name="description" content="Look up location, ASN, hostname, and network hints for any IP or domain." />
		<link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
		<link rel="stylesheet" href="assets/css/leaflet.css"/>
		<link rel="stylesheet" href="assets/css/app.css" />
		<script>
			(function () {
				try {
					var stored = localStorage.getItem('theme');
					var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
					if (stored === 'dark' || (!stored && prefersDark)) {
						document.documentElement.classList.add('dark');
					}
				} catch (e) {}
			})();
			var mapData = <?php echo json_encode($mapPayload, JSON_UNESCAPED_SLASHES); ?>;
			var pageConfig = <?php echo json_encode([
				'detectIpv4' => $detectIpv4,
				'useDeviceLocation' => false,
			], JSON_UNESCAPED_SLASHES); ?>;
		</script>
	</head>
	<body>
		<header class="site-header">
			<div class="wrap">
				<a class="brand" href="index.php">
					<img src="assets/images/logo/logo.svg" alt="IP Checker" class="logo-light" />
					<img src="assets/images/logo/logo-white.svg" alt="IP Checker" class="logo-dark" />
				</a>
				<button type="button" id="themeSwitcher" class="theme-toggle" aria-label="Toggle color theme">
					<svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24"><path d="M13.3 1.5c-.6-.2-1.3-.3-1.9-.4-.5-.1-1 .2-1.2.6-.2.5-.1 1 .3 1.3 2.7 2.5 3.6 6.1 2.5 9.5-1.2 3.8-4.7 6.5-8.8 6.6-.5 0-1 .3-1.1.8s.1 1 .5 1.3c.9.6 1.9 1 2.8 1.3 1.1.3 2.2.5 3.3.5 1.9 0 3.8-.5 5.5-1.4 2.7-1.5 4.5-3.8 5.4-6.7 1.6-5.6-1.6-11.5-7.3-13.4z"/></svg>
					<svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24"><path d="M12 6.9A5.1 5.1 0 1 0 17.1 12 5.1 5.1 0 0 0 12 6.9zm0 8.5A3.4 3.4 0 1 1 15.4 12 3.4 3.4 0 0 1 12 15.4zM12 4.2a.9.9 0 0 0 .9-.9V1.5a.9.9 0 1 0-1.8 0v1.9a.9.9 0 0 0 .9.8zM12 19.8a.9.9 0 0 0-.9.9v1.8a.9.9 0 1 0 1.8 0v-1.9a.9.9 0 0 0-.9-.8z"/></svg>
				</button>
			</div>
		</header>

		<main class="wrap">
			<section class="hero">
				<h1>Look up any IP address</h1>
				<p>City, ASN, reverse DNS, and a simple hosting or VPN hint. Share the result or embed a badge.</p>
				<form class="search" method="get" action="index.php">
					<label class="sr-only" for="ip">IP address or hostname</label>
					<input
						id="ip"
						name="ip"
						type="text"
						inputmode="text"
						autocomplete="off"
						spellcheck="false"
						placeholder="8.8.8.8, 2001:4860:4860::8888, or google.com"
						value="<?php echo h($isOwnLookup ? '' : $requested); ?>"
					/>
					<button type="submit" class="btn btn-primary">Lookup</button>
					<a href="index.php" class="btn btn-ghost">My IP</a>
				</form>
			</section>

			<?php if ($detectIpv4): ?>
				<div class="notice" id="ipv4-status">Finding your IPv4 address…</div>
			<?php endif; ?>

			<?php if ($inputError): ?>
				<div class="notice error"><?php echo h($inputError); ?></div>
			<?php elseif (!$ok): ?>
				<div class="notice">
					<strong><?php echo h($displayIp); ?></strong>
					<div><?php echo h($result['message'] ?? 'Lookup failed.'); ?></div>
				</div>
			<?php else: ?>
				<section class="results">
					<div class="facts-stack">
						<div class="panel facts">
							<p class="kicker"><?php echo h($kicker); ?></p>
							<div class="ip-row">
								<h2 id="display-ip"><?php echo h($displayIp); ?></h2>
								<button type="button" class="btn btn-primary btn-small copy-ip" data-copy="<?php echo h($displayIp); ?>">Copy</button>
							</div>
							<?php if ($hintLabel !== ''): ?>
								<p class="hint hint-<?php echo h($hintKind); ?>"><?php echo h($hintLabel); ?></p>
							<?php endif; ?>
							<?php if ($place !== ''): ?>
								<p class="place"><?php echo h($place); ?></p>
							<?php endif; ?>
							<dl class="rows">
								<?php foreach ($fieldRows as $row): ?>
									<div class="row">
										<dt><?php echo h($row[0]); ?></dt>
										<dd<?php echo $row[0] === 'Coordinates' ? ' id="field-coordinates"' : ($row[0] === 'Accuracy' ? ' id="field-accuracy"' : ''); ?>><?php echo h($row[1]); ?></dd>
									</div>
								<?php endforeach; ?>
							</dl>
						</div>
						<?php if ($showIpv6): ?>
							<div class="panel facts compact">
								<p class="kicker">Your IPv6</p>
								<div class="ip-row">
									<h2><?php echo h($clientIpv6); ?></h2>
									<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($clientIpv6); ?>">Copy</button>
									<a class="btn btn-primary btn-small" href="index.php?ip=<?php echo h(rawurlencode($clientIpv6)); ?>">Lookup</a>
								</div>
							</div>
						<?php endif; ?>
					</div>
					<div class="panel map-card">
						<?php if ($showMap): ?>
							<div id="map"></div>
							<?php if ($isOwnLookup): ?>
								<button type="button" id="precise-location" class="btn btn-primary btn-small map-locate">Device GPS</button>
							<?php endif; ?>
						<?php else: ?>
							<div class="notice">No map coordinates for this address.</div>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ($ok): ?>
			<section class="panel share">
				<h2>Share / embed</h2>
				<p>Copy a permalink to this lookup, or drop a compact badge on a blog.</p>
				<div class="share-grid">
					<div class="api-item">
						<h3>Share URL</h3>
						<pre><?php echo h($shareUrl); ?></pre>
						<div class="api-actions">
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($shareUrl); ?>">Copy link</button>
							<a class="btn btn-primary btn-small" href="<?php echo h($shareQuery); ?>">Open</a>
						</div>
					</div>
					<div class="api-item">
						<h3>This IP badge</h3>
						<pre><?php echo h($embedSnippet); ?></pre>
						<div class="api-actions">
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($embedSnippet); ?>">Copy embed</button>
							<a class="btn btn-primary btn-small" href="<?php echo h($badgeUrl); ?>" target="_blank" rel="noopener noreferrer">Preview</a>
						</div>
					</div>
				</div>
			</section>
			<?php endif; ?>

			<section class="panel api">
				<h2>JSON API</h2>
				<p>GET either endpoint with <code>ip</code> as an address or hostname. Omit <code>ip</code> to look up the caller. CORS is open (<code>Access-Control-Allow-Origin: *</code>). Lookups are cached on the server for 24 hours; API responses send <code>Cache-Control: no-store</code>.</p>
				<div class="api-grid">
					<div class="api-item">
						<h3>Basic</h3>
						<pre>GET /api/?ip=<?php echo h((string) $queryIp); ?></pre>
						<pre><?php echo h($curlBasic); ?></pre>
						<div class="api-actions">
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($basicApi); ?>">Copy path</button>
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($curlBasic); ?>">Copy curl</button>
							<a class="btn btn-primary btn-small" href="<?php echo h($basicApi); ?>" target="_blank" rel="noopener noreferrer">Open JSON</a>
						</div>
					</div>
					<div class="api-item">
						<h3>Detailed</h3>
						<pre>GET /api/detailed/?ip=<?php echo h((string) $queryIp); ?></pre>
						<pre><?php echo h($curlHost); ?></pre>
						<div class="api-actions">
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($detailedApi); ?>">Copy path</button>
							<a class="btn btn-primary btn-small" href="<?php echo h($detailedApi); ?>" target="_blank" rel="noopener noreferrer">Open JSON</a>
						</div>
					</div>
				</div>
			</section>
		</main>

		<footer class="site-footer">
			<div class="wrap">
				<p>IP data from <a href="https://www.maxmind.com" rel="nofollow noopener" target="_blank">MaxMind</a></p>
				<p>This product includes GeoLite2 data created by MaxMind.</p>
			</div>
		</footer>

		<div id="toast" class="toast" role="status">Copied</div>
		<script src="assets/js/leaflet.js"></script>
		<script src="assets/js/main.js?v=6" defer></script>
	</body>
</html>
