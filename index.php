<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/geo.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$clientIp = geo_client_ip();
$requested = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
$isOwnLookup = $requested === '';
$rawTarget = $isOwnLookup ? $clientIp : $requested;
$targetIp = geo_normalize_ip($rawTarget);

$inputError = null;
$result = null;

if ($targetIp === null) {
    $inputError = 'Enter a valid IPv4 or IPv6 address.';
} else {
    $result = geo_lookup($targetIp);
}

$ok = is_array($result) && !empty($result['ok']);
$hasMap = $ok && $result['latitude'] !== null && $result['longitude'] !== null;
$displayIp = $ok ? $result['ip'] : ($targetIp ?? $rawTarget);
$queryIp = ($isOwnLookup ? $displayIp : $targetIp) ?: $clientIp;
$basicApi = 'api/?ip=' . rawurlencode((string) $queryIp);
$detailedApi = 'api/detailed/?ip=' . rawurlencode((string) $queryIp);

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

$mapPayload = [
    'lat' => $hasMap ? (float) $result['latitude'] : null,
    'lng' => $hasMap ? (float) $result['longitude'] : null,
    'title' => $displayIp,
    'details' => $place,
    'accuracy' => $ok ? $result['accuracy_radius_km'] : null,
];

$fieldRows = [];
if ($ok) {
    $candidates = [
        ['City', $result['city']],
        ['Region', $result['region']],
        ['Country', $result['country'] ? $result['country'] . ($result['country_code'] ? ' (' . $result['country_code'] . ')' : '') : null],
        ['Continent', $result['continent']],
        ['Postal', $result['postal']],
        ['Timezone', $result['timezone']],
        ['Coordinates', $hasMap ? $result['latitude'] . ', ' . $result['longitude'] : null],
        ['Accuracy', $result['accuracy_radius_km'] ? '± ' . $result['accuracy_radius_km'] . ' km' : null],
        ['Network', $result['network']],
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
		<meta name="description" content="Look up location, timezone, and network details for any IP address." />
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
				<p>City, country, coordinates, timezone, and network range. Share the result URL or call the JSON API.</p>
				<form class="search" method="get" action="index.php">
					<label class="sr-only" for="ip">IP address</label>
					<input
						id="ip"
						name="ip"
						type="text"
						inputmode="decimal"
						autocomplete="off"
						spellcheck="false"
						placeholder="8.8.8.8 or 2001:4860:4860::8888"
						value="<?php echo h($isOwnLookup ? '' : $requested); ?>"
					/>
					<button type="submit" class="btn btn-primary">Lookup</button>
					<a href="index.php" class="btn btn-ghost">My IP</a>
				</form>
			</section>

			<?php if ($inputError): ?>
				<div class="notice error"><?php echo h($inputError); ?></div>
			<?php elseif (!$ok): ?>
				<div class="notice">
					<strong><?php echo h($displayIp); ?></strong>
					<div><?php echo h($result['message'] ?? 'Lookup failed.'); ?></div>
				</div>
			<?php else: ?>
				<section class="results">
					<div class="panel facts">
						<p class="kicker"><?php echo $isOwnLookup ? 'Your IP' : 'Looked up IP'; ?></p>
						<div class="ip-row">
							<h2><?php echo h($displayIp); ?></h2>
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($displayIp); ?>">Copy</button>
						</div>
						<?php if ($place !== ''): ?>
							<p class="place"><?php echo h($place); ?></p>
						<?php endif; ?>
						<dl class="rows">
							<?php foreach ($fieldRows as $row): ?>
								<div class="row">
									<dt><?php echo h($row[0]); ?></dt>
									<dd><?php echo h($row[1]); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</div>
					<div class="panel map-card">
						<?php if ($hasMap): ?>
							<div id="map"></div>
						<?php else: ?>
							<div class="notice">No map coordinates for this address.</div>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<section class="panel api">
				<h2>JSON API</h2>
				<p>GET either endpoint. Omit <code>ip</code> to look up the caller.</p>
				<div class="api-grid">
					<div class="api-item">
						<h3>Basic</h3>
						<pre>GET /api/?ip=<?php echo h((string) $queryIp); ?></pre>
						<div class="api-actions">
							<button type="button" class="btn btn-ghost btn-small" data-copy="<?php echo h($basicApi); ?>">Copy path</button>
							<a class="btn btn-primary btn-small" href="<?php echo h($basicApi); ?>" target="_blank" rel="noopener noreferrer">Open JSON</a>
						</div>
					</div>
					<div class="api-item">
						<h3>Detailed</h3>
						<pre>GET /api/detailed/?ip=<?php echo h((string) $queryIp); ?></pre>
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
		<script src="assets/js/main.js" defer></script>
	</body>
</html>
