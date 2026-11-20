<?php
// Potential partners: load the agent's prospects.json into MySQL and email the weekly findings.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Upsert prospects from the repo. The team's status and notes are never overwritten. */
function jocate_prospects_import(): array {
    $db = jocate_db();
    $file = JOCATE_HOME . '/prospects/prospects.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $stats = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'changed' => []];
    if (!is_array($data) || !isset($data['prospects']) || !is_array($data['prospects'])) {
        jocate_log('prospects.log', 'prospects.json missing or invalid');
        return $stats + ['error' => 'prospects.json missing or invalid'];
    }
    $now = jocate_now();
    $find = $db->prepare('SELECT * FROM jocate_prospects WHERE slug = ?');
    $ins = $db->prepare('INSERT INTO jocate_prospects (slug, company, sector, tier, hotspot, towns, website, procurement_url, summary, current_fuel, signals,
        fit_score, band, confidence, suggested_angle, next_step, research_status, evidence, first_seen, last_reviewed, imported_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $upd = $db->prepare('UPDATE jocate_prospects SET company=?, sector=?, tier=?, hotspot=?, towns=?, website=?, procurement_url=?, summary=?, current_fuel=?, signals=?,
        fit_score=?, band=?, confidence=?, suggested_angle=?, next_step=?, research_status=?, evidence=?, first_seen=?, last_reviewed=?, imported_at=?, updated_at=? WHERE slug=?');
    foreach ($data['prospects'] as $p) {
        $stats['total']++;
        $slug = (string) ($p['id'] ?? '');
        $company = trim((string) ($p['company'] ?? ''));
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || $company === '' || empty($p['evidence'])) { $stats['rejected']++; continue; }
        $url = fn($u) => (is_string($u) && preg_match('#^https?://#', $u)) ? mb_substr($u, 0, 255) : null;
        $date = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
        $towns = implode(', ', array_map(fn($s) => trim(($s['town'] ?? '') . (isset($s['county']) ? ' (' . $s['county'] . ')' : '')), (array) ($p['sites'] ?? [])));
        $row = [
            mb_substr($company, 0, 190), mb_substr((string) ($p['sector'] ?? ''), 0, 40), mb_substr((string) ($p['tier'] ?? ''), 0, 1),
            mb_substr((string) ($p['hotspot'] ?? ''), 0, 40), mb_substr($towns, 0, 255), $url($p['website'] ?? null), $url($p['procurement_url'] ?? null),
            (string) ($p['summary'] ?? ''), mb_substr((string) ($p['current_fuel'] ?? ''), 0, 255), implode('; ', array_map('strval', (array) ($p['signals'] ?? []))),
            (int) ($p['fit_score'] ?? 0), mb_substr((string) ($p['band'] ?? ''), 0, 12), mb_substr((string) ($p['confidence'] ?? ''), 0, 8),
            (string) ($p['suggested_angle'] ?? ''), mb_substr((string) ($p['next_step'] ?? ''), 0, 255), mb_substr((string) ($p['research_status'] ?? ''), 0, 12),
            json_encode($p['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $date($p['first_seen'] ?? null), $date($p['last_reviewed'] ?? null),
        ];
        $find->execute([$slug]);
        $old = $find->fetch();
        if (!$old) {
            $ins->execute(array_merge([$slug], $row, [$now, $now]));
            $stats['inserted']++; $stats['changed'][] = $company . ' (new)';
        } elseif ($old['last_reviewed'] !== $row[18] || (int) $old['fit_score'] !== $row[10] || $old['research_status'] !== $row[15]) {
            $upd->execute(array_merge($row, [$now, $now, $slug]));
            $stats['updated']++; $stats['changed'][] = $company . ' (updated)';
        } else {
            $stats['unchanged']++;
        }
    }
    jocate_log('prospects.log', 'import: ' . json_encode(array_diff_key($stats, ['changed' => 1])));
    return $stats;
}

/** Minimal Markdown to HTML for the weekly report email (headings, lists, tables, bold, italics, links). */
function jocate_md_html(string $md): string {
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $inline = function (string $s) use ($h): string {
        $s = $h($s);
        $s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" style="color:#15803d">$1</a>', $s);
        $s = preg_replace('/(?<!["=>])(https?:\/\/[^\s<]+)/', '<a href="$1" style="color:#15803d">$1</a>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        return preg_replace('/(?<!\*)\*(?!\s)(.+?)\*(?!\*)/', '<em>$1</em>', $s);
    };
    $out = ''; $list = false; $table = [];
    $flushTable = function () use (&$table, &$out, $inline) {
        if (!$table) return;
        $out .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;margin:8px 0 16px">';
        foreach ($table as $i => $cells) {
            $out .= '<tr>';
            foreach ($cells as $c) $out .= ($i === 0 ? '<th style="text-align:left;background:#f2efe6;border:1px solid #e2ddd0">' : '<td style="border:1px solid #e2ddd0;vertical-align:top">') . $inline(trim($c)) . ($i === 0 ? '</th>' : '</td>');
            $out .= '</tr>';
        }
        $out .= '</table>'; $table = [];
    };
    foreach (preg_split('/\r?\n/', $md) as $line) {
        if (preg_match('/^\s*\|(.+)\|\s*$/', $line, $m)) {
            if ($list) { $out .= '</ul>'; $list = false; }
            if (preg_match('/^[\s|:-]+$/', $m[1])) continue;
            $table[] = explode('|', $m[1]); continue;
        }
        $flushTable();
        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
            if ($list) { $out .= '</ul>'; $list = false; }
            $sz = [1 => 22, 2 => 17, 3 => 15][strlen($m[1])];
            $out .= '<h' . strlen($m[1]) . ' style="font-family:Arial,sans-serif;color:#1b201f;font-size:' . $sz . 'px;margin:18px 0 8px">' . $inline($m[2]) . '</h' . strlen($m[1]) . '>';
        } elseif (preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/', $line, $m)) {
            if (!$list) { $out .= '<ul style="padding-left:20px;margin:6px 0">'; $list = true; }
            $out .= '<li style="margin-bottom:4px">' . $inline($m[1]) . '</li>';
        } elseif (trim($line) === '') {
            if ($list) { $out .= '</ul>'; $list = false; }
        } else {
            if ($list) { $out .= '</ul>'; $list = false; }
            $out .= '<p style="margin:6px 0">' . $inline($line) . '</p>';
        }
    }
    $flushTable();
    if ($list) $out .= '</ul>';
    return $out;
}

/** Email the latest weekly report to the company address, once per report week. */
function jocate_prospects_email(array $import, bool $force = false): array {
    $cfg = jocate_config();
    $to = $cfg['report_email'] ?? 'jocateent@gmail.com';
    $latest = JOCATE_HOME . '/prospects/reports/LATEST.md';
    if (!is_file($latest) || !preg_match('/\(([0-9]{4}-W[0-9]{2})\.md\)/', (string) file_get_contents($latest), $m)) return ['sent' => false, 'reason' => 'no report yet'];
    $week = $m[1];
    $reportFile = JOCATE_HOME . '/prospects/reports/' . $week . '.md';
    if (!is_file($reportFile)) return ['sent' => false, 'reason' => "report $week missing"];
    $stateFile = JOCATE_PRIVATE . '/prospects-email.json';
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    if (!$force && ($state['last_week'] ?? '') === $week) return ['sent' => false, 'reason' => "already emailed $week"];

    $db = jocate_db();
    $counts = $db->query('SELECT band, COUNT(*) n FROM jocate_prospects WHERE research_status <> "closed" GROUP BY band')->fetchAll(PDO::FETCH_KEY_PAIR);
    $md = (string) file_get_contents($reportFile);
    $repo = 'https://github.com/michaelnjuguna376-lab/jocate-data-agent/blob/main/prospects/reports/' . $week . '.md';
    $html = '<!doctype html><html><body style="margin:0;background:#faf8f3"><div style="max-width:760px;margin:0 auto;background:#fff;font-family:Arial,sans-serif;color:#1b201f;font-size:14px;line-height:1.5">'
        . '<div style="background:#14532d;padding:18px 24px;border-bottom:4px solid #28ee00"><span style="color:#fff;font-size:18px;font-weight:bold;letter-spacing:.5px">JOCATE · Potential partners</span>'
        . '<br><span style="color:#c9d8cd;font-size:13px">Weekly prospect research · ' . htmlspecialchars($week) . '</span></div>'
        . '<div style="padding:16px 24px;background:#fef3c7;border-bottom:1px solid #e2ddd0;font-size:13px">Pipeline: <strong>' . (int) array_sum($counts) . '</strong> open prospects · '
        . (int) ($counts['priority'] ?? 0) . ' priority · ' . (int) ($counts['strong'] ?? 0) . ' strong · ' . (int) ($counts['watch'] ?? 0) . ' watch · this week ' . (int) ($import['inserted'] ?? 0) . ' new, ' . (int) ($import['updated'] ?? 0) . ' updated</div>'
        . '<div style="padding:8px 24px 24px">' . jocate_md_html($md) . '</div>'
        . '<div style="padding:16px 24px;background:#f2efe6;font-size:12px;color:#4b5a50">Work these prospects in the admin page: <a href="https://jocateorg.com/admin/?view=partners" style="color:#15803d">Potential partners</a> · '
        . 'Full report on GitHub: <a href="' . $repo . '" style="color:#15803d">' . htmlspecialchars($week) . '</a><br>Prepared by the Jocate Potential Partners agent. Company-level public information only; verify before approaching.</div>'
        . '</div></body></html>';
    $subject = 'Jocate potential partners ' . $week . ': ' . (int) ($import['inserted'] ?? 0) . ' new, ' . (int) ($counts['priority'] ?? 0) . ' priority';
    $boundary = 'jocate' . bin2hex(random_bytes(8));
    $text = "Jocate potential partners $week\n\n" . $md . "\n\nAdmin: https://jocateorg.com/admin/?view=partners\nGitHub: $repo\n";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
    $from = $cfg['mail_from'] ?? 'Jocate Agents <agents@jocateorg.com>';
    $headers = "From: $from\r\nReply-To: $to\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\nX-Jocate-Report: potential-partners $week";
    $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    jocate_log('prospects.log', "email $week to $to: " . ($ok ? 'accepted' : 'FAILED'));
    if ($ok) { $state['last_week'] = $week; $state['sent_at'] = gmdate('c'); @file_put_contents($stateFile, json_encode($state)); }
    return ['sent' => $ok, 'week' => $week, 'to' => $to];
}

/** Short WhatsApp version of the weekly findings: pipeline counts + top 5 + links. */
function jocate_prospects_whatsapp_text(string $week, array $import): string {
    $db = jocate_db();
    $counts = $db->query('SELECT band, COUNT(*) n FROM jocate_prospects WHERE research_status <> "closed" GROUP BY band')->fetchAll(PDO::FETCH_KEY_PAIR);
    $top = $db->query('SELECT company, towns, hotspot, fit_score, band, confidence, next_step FROM jocate_prospects
                       WHERE research_status <> "closed" AND team_status NOT IN ("won","not-a-fit")
                       ORDER BY fit_score DESC, last_reviewed DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    $t = "*JOCATE · Potential partners $week*\n"
       . (int) array_sum($counts) . ' open prospects: ' . (int) ($counts['priority'] ?? 0) . ' priority, ' . (int) ($counts['strong'] ?? 0) . ' strong, ' . (int) ($counts['watch'] ?? 0) . " watch\n"
       . 'This week: ' . (int) ($import['inserted'] ?? 0) . ' new, ' . (int) ($import['updated'] ?? 0) . " updated\n\n*Top 5 to approach*\n";
    foreach ($top as $i => $p) {
        $where = $p['towns'] ?: $p['hotspot'];
        $t .= ($i + 1) . '. *' . $p['company'] . '*' . ($where ? " ($where)" : '') . ' - ' . (int) $p['fit_score'] . ' ' . $p['band'] . ', ' . $p['confidence'] . " confidence\n";
        if (!empty($p['next_step'])) $t .= '   Next: ' . mb_strimwidth((string) $p['next_step'], 0, 140, '...') . "\n";
    }
    $t .= "\nFull list: https://jocateorg.com/admin/?view=partners\nFull report emailed to the company inbox.\n_Company-level public info only. Verify before approaching._";
    return mb_substr($t, 0, 1800);
}

/**
 * Send the weekly findings to WhatsApp via CallMeBot, once per report week per number.
 * Config (~/jocate-private/config.php): 'whatsapp' => ['254720922376' => 'apikey', '254768834778' => 'apikey']
 */
function jocate_prospects_whatsapp(array $import, bool $force = false): array {
    $cfg = jocate_config();
    $recipients = $cfg['whatsapp'] ?? [];
    if (!$recipients) return ['sent' => 0, 'reason' => 'no WhatsApp recipients configured'];
    $latest = JOCATE_HOME . '/prospects/reports/LATEST.md';
    if (!is_file($latest) || !preg_match('/\(([0-9]{4}-W[0-9]{2})\.md\)/', (string) file_get_contents($latest), $m)) return ['sent' => 0, 'reason' => 'no report yet'];
    $week = $m[1];
    $stateFile = JOCATE_PRIVATE . '/prospects-whatsapp.json';
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $text = jocate_prospects_whatsapp_text($week, $import);
    $results = [];
    foreach ($recipients as $phone => $apikey) {
        $phone = preg_replace('/\D/', '', (string) $phone);
        $ref = substr($phone, -3);  // never log full numbers
        if (!$apikey) { $results[$ref] = 'no apikey'; continue; }
        if (!$force && ($state[$phone] ?? '') === $week) { $results[$ref] = "already sent $week"; continue; }
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query(['phone' => '+' . $phone, 'text' => $text, 'apikey' => $apikey]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $code === 200 && preg_match('/queued|sent/i', $body) === 1;
        $results[$ref] = $ok ? 'sent' : "failed (HTTP $code: " . mb_substr(trim(strip_tags($body)), 0, 120) . ')';
        jocate_log('prospects.log', "whatsapp $week to ...$ref: " . $results[$ref]);
        if ($ok) $state[$phone] = $week;
        sleep(3);  // CallMeBot rate limit
    }
    @file_put_contents($stateFile, json_encode($state));
    return ['week' => $week, 'results' => $results];
}
