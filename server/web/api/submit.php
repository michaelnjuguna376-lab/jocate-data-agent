<?php
// Public endpoint: records every website submission before the visitor is handed to WhatsApp or Gmail.
// Deployed to public_html/api/submit.php. All logic lives outside the web root.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'method']);

// Same-site only
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !preg_match('#^https://(www\.)?jocateorg\.com$#', $origin)) out(403, ['ok' => false, 'error' => 'origin']);

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string) $raw, true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'json']);

$form = (string) ($in['form'] ?? '');
$allowed = ['partner', 'supplier', 'calculator'];
if (!in_array($form, $allowed, true)) out(400, ['ok' => false, 'error' => 'form']);
$channel = in_array($in['channel'] ?? '', ['whatsapp', 'email', 'form'], true) ? $in['channel'] : 'form';
$data = is_array($in['data'] ?? null) ? $in['data'] : [];

// Keep only scalar fields and short lists; cap lengths
$clean = [];
foreach ($data as $k => $v) {
    if (!is_string($k) || strlen($k) > 30) continue;
    if (is_array($v)) { $clean[$k] = array_slice(array_map(fn($x) => mb_substr((string) $x, 0, 120), array_filter($v, 'is_scalar')), 0, 12); }
    elseif (is_scalar($v) || $v === null) { $clean[$k] = $v === null ? null : mb_substr((string) $v, 0, 2000); }
}

$home = dirname(__DIR__, 4); // /home/<account>
require_once $home . '/jocate-agent/server/lib/etl.php';

try {
    $db = jocate_db();
    $cfg = jocate_config();
    $ipHash = hash('sha256', ($cfg['ip_salt'] ?? 'jocate') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    // Simple rate limit: 20 submissions per address per 10 minutes
    $st = $db->prepare('SELECT COUNT(*) FROM jocate_submissions WHERE ip_hash = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)');
    $st->execute([$ipHash]);
    if ((int) $st->fetchColumn() >= 20) out(429, ['ok' => false, 'error' => 'rate']);

    $page = mb_substr(parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '', 0, 120);
    $db->prepare('INSERT INTO jocate_submissions (created_at, form, channel, page, payload, ip_hash, user_agent) VALUES (?,?,?,?,?,?,?)')
       ->execute([jocate_now(), $form, $channel, $page, json_encode($clean, JSON_UNESCAPED_UNICODE), $ipHash, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    $id = (int) $db->lastInsertId();
    // Clean it straight away so the admin page is always current; the weekly job re-runs as a safety net.
    try { jocate_etl('submit', 1, $id); } catch (Throwable $e) { jocate_log('api.log', 'inline etl failed: ' . $e->getMessage()); }
    out(200, ['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    jocate_log('api.log', 'submit failed: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server']);
}
