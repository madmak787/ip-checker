<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/geo.php';

function badge_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$raw = isset($_GET['ip']) ? trim((string) $_GET['ip']) : geo_client_ip();
$resolved = geo_resolve_target($raw);
$ok = $resolved['ok'];
$result = $ok ? geo_lookup($resolved['ip']) : null;
$ok = $ok && is_array($result) && !empty($result['ok']);
$ip = $ok ? $result['ip'] : ($resolved['ip'] ?? $raw);
$place = '';
if ($ok) {
    $place = implode(', ', array_values(array_filter([
        $result['city'] ?? null,
        $result['country'] ?? null,
    ], static fn ($part) => $part !== null && $part !== '')));
}
$hint = $ok ? ($result['connection_hint'] ?? '') : '';
$asn = $ok && !empty($result['asn']) ? 'AS' . $result['asn'] : '';
$link = geo_public_base() . '/?ip=' . rawurlencode((string) $ip);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>IP <?php echo badge_h($ip); ?></title>
	<style>
		:root { color-scheme: light dark; }
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font: 13px/1.4 ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif;
			background: #111827;
			color: #f8fafc;
		}
		a.badge {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 12px 14px;
			text-decoration: none;
			color: inherit;
			height: 112px;
		}
		.mark {
			flex: 0 0 auto;
			width: 36px;
			height: 36px;
			border-radius: 8px;
			background: #2563eb;
			display: grid;
			place-items: center;
			font-weight: 800;
			font-size: 11px;
			letter-spacing: 0.04em;
		}
		.meta { min-width: 0; }
		.kicker { margin: 0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; }
		.ip { margin: 2px 0 0; font-size: 15px; font-weight: 700; word-break: break-all; }
		.place { margin: 2px 0 0; color: #cbd5e1; }
	</style>
</head>
<body>
	<a class="badge" href="<?php echo badge_h($link); ?>" target="_blank" rel="noopener noreferrer">
		<div class="mark">IP</div>
		<div class="meta">
			<p class="kicker"><?php echo $hint !== '' ? badge_h($hint) : 'This IP'; ?></p>
			<p class="ip"><?php echo badge_h($ip); ?></p>
			<p class="place"><?php echo badge_h(trim($place . ($asn !== '' ? ' · ' . $asn : ''))); ?></p>
		</div>
	</a>
</body>
</html>
