<?php
// First-party, cookieless website analytics. No personal data: IP addresses are never stored;
// a visitor is a salted hash of (date, IP, browser) that changes every day and cannot be reversed.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const JOCATE_EVENTS = ['whatsapp', 'call', 'email', 'download', 'enquiry'];

function jocate_is_bot(string $ua): bool {
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|whatsapp|curl|wget|python|monitor|pingdom|uptime/i', $ua);
}

/** Record one page view or event. Returns false when the hit is ignored. */
function jocate_track(array $in, string $ip, string $ua): bool {
    if (jocate_is_bot($ua)) return false;
    $kind = ($in['k'] ?? '') === 'event' ? 'event' : 'view';
    $path = (string) ($in['p'] ?? '');
    if (!preg_match('#^/[A-Za-z0-9/_.\-]{0,158}$#', $path)) return false;
    $event = null; $label = null;
    if ($kind === 'event') {
        $event = (string) ($in['e'] ?? '');
        if (!in_array($event, JOCATE_EVENTS, true)) return false;
        $label = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($in['l'] ?? ''))), 0, 120) ?: null;
    }
    $ref = null;
    $rh = strtolower((string) parse_url((string) ($in['r'] ?? ''), PHP_URL_HOST));
    if ($rh !== '' && !preg_match('/(^|\.)jocateorg\.com$/', $rh)) $ref = mb_substr(preg_replace('/^www\./', '', $rh), 0, 120);
    $src = preg_replace('/[^a-z0-9_.\-]/', '', strtolower((string) ($in['s'] ?? ''))) ?: null;
    $device = preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'desktop';
    $cfg = jocate_config();
    $day = gmdate('Y-m-d');
    $visitor = substr(hash('sha256', ($cfg['ip_salt'] ?? 'jocate') . '|' . $day . '|' . $ip . '|' . $ua), 0, 16);
    $db = jocate_db();
    $st = $db->prepare('SELECT COUNT(*) FROM jocate_analytics WHERE visitor = ? AND day = ?');
    $st->execute([$visitor, $day]);
    if ((int) $st->fetchColumn() >= 300) return false;   // flood guard
    $db->prepare('INSERT INTO jocate_analytics (ts, day, kind, path, ref_host, source, event, label, device, visitor) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([jocate_now(), $day, $kind, $path, $ref, $src ? mb_substr($src, 0, 60) : null, $event, $label, $device, $visitor]);
    return true;
}

/** Summary for the admin page over the last $days days. */
function jocate_analytics_summary(int $days = 30): array {
    $db = jocate_db();
    $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
    $q = function (string $sql) use ($db, $since) { $st = $db->prepare($sql); $st->execute([$since]); return $st->fetchAll(); };
    $one = function (string $sql) use ($db, $since) { $st = $db->prepare($sql); $st->execute([$since]); return (int) $st->fetchColumn(); };
    return [
        'since'    => $since,
        'views'    => $one('SELECT COUNT(*) FROM jocate_analytics WHERE kind="view" AND day >= ?'),
        'visitors' => $one('SELECT COUNT(DISTINCT visitor, day) FROM jocate_analytics WHERE day >= ?'),
        'contacts' => $one('SELECT COUNT(*) FROM jocate_analytics WHERE kind="event" AND event IN ("whatsapp","call","email","enquiry") AND day >= ?'),
        'mobile'   => $one('SELECT COUNT(*) FROM jocate_analytics WHERE kind="view" AND device="mobile" AND day >= ?'),
        'daily'    => $q('SELECT day, SUM(kind="view") views, COUNT(DISTINCT visitor) visitors, SUM(kind="event") events FROM jocate_analytics WHERE day >= ? GROUP BY day ORDER BY day'),
        'pages'    => $q('SELECT path, COUNT(*) views, COUNT(DISTINCT visitor, day) visitors FROM jocate_analytics WHERE kind="view" AND day >= ? GROUP BY path ORDER BY views DESC LIMIT 25'),
        'refs'     => $q('SELECT COALESCE(source, ref_host) ref, COUNT(*) views FROM jocate_analytics WHERE kind="view" AND (ref_host IS NOT NULL OR source IS NOT NULL) AND day >= ? GROUP BY ref ORDER BY views DESC LIMIT 15'),
        'events'   => $q('SELECT event, path, COUNT(*) n FROM jocate_analytics WHERE kind="event" AND day >= ? GROUP BY event, path ORDER BY n DESC LIMIT 30'),
    ];
}
