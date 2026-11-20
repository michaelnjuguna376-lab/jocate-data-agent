<?php
// Public endpoint for first-party analytics (page views and contact clicks). Always answers 204.
// Deployed to public_html/api/track.php. Logic lives outside the web root.
declare(strict_types=1);
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !preg_match('#^https://(www\.)?jocateorg\.com$#', $origin)) { http_response_code(204); exit; }
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 2000), true);
if (is_array($in)) {
    $home = dirname(__DIR__, 4); // /home/<account>
    require_once $home . '/jocate-agent/server/lib/analytics.php';
    try { jocate_track($in, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)); }
    catch (Throwable $e) { jocate_log('api.log', 'track failed: ' . $e->getMessage()); }
}
http_response_code(204);
