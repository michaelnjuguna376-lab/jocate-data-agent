<?php
// Weekly report: aggregates only. No names, phones, emails or free text leave the server.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/transform.php';

/** Re-test unmatched values against the current rules; values the agent has taught the rules to handle are marked resolved. */
function jocate_resolve_unmatched(PDO $db, array $rules): int {
    $n = 0;
    foreach ($db->query('SELECT id, field, value FROM jocate_unmatched WHERE resolved = 0')->fetchAll() as $u) {
        $sink = [];
        $ok = false;
        if ($u['field'] === 'county') $ok = jt_county($u['value'], $rules, $sink) !== null;
        elseif ($u['field'] === 'unit') { [$t, $unit] = jt_tonnes('1 ' . $u['value'], $rules, $sink); $ok = $unit !== null; }
        elseif ($u['field'] === 'volume') { [$t, $unit] = jt_tonnes($u['value'], $rules, $sink); $ok = $t !== null || $unit !== null; }
        if ($ok) { $db->prepare('UPDATE jocate_unmatched SET resolved = 1 WHERE id = ?')->execute([$u['id']]); $n++; }
    }
    return $n;
}

function jocate_weekly_report(): array {
    $db = jocate_db();
    $rules = jocate_rules();
    $resolved = jocate_resolve_unmatched($db, $rules);
    $q = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(); })();
    $one = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
    $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    $week = gmdate('o-\WW');

    $r = [
        'week' => $week,
        'generated_at' => gmdate('c'),
        'rules_version' => $rules['version'] ?? null,
        'submissions' => [
            'last_7_days' => $q('SELECT form, channel, COUNT(*) n FROM jocate_submissions WHERE created_at >= ? GROUP BY form, channel ORDER BY form', [$since]),
            'all_time' => (int) $one('SELECT COUNT(*) FROM jocate_submissions'),
            'outcomes_7d' => $q('SELECT COALESCE(outcome, "pending") outcome, COUNT(*) n FROM jocate_submissions WHERE created_at >= ? GROUP BY outcome', [$since]),
        ],
        'data_quality' => [
            'flags_7d' => jocate_flag_counts($q('SELECT quality_flags FROM jocate_submissions WHERE created_at >= ? AND quality_flags IS NOT NULL', [$since])),
            'unmatched_open' => array_values(array_filter($q('SELECT field, value, hits FROM jocate_unmatched WHERE resolved = 0 ORDER BY hits DESC LIMIT 40'),
                                    fn($u) => jt_report_safe($u['value']))),
            'unmatched_resolved_this_run' => $resolved,
        ],
        'demand' => [
            'enquiries_7d' => (int) $one('SELECT COUNT(*) FROM jocate_leads WHERE type="supply_enquiry" AND created_at >= ?', [$since]),
            'enquiry_tonnes_month_7d' => (float) $one('SELECT COALESCE(SUM(monthly_tonnes),0) FROM jocate_leads WHERE type="supply_enquiry" AND created_at >= ?', [$since]),
            'estimates_7d' => (int) $one('SELECT COUNT(*) FROM jocate_leads WHERE type="fuel_estimate" AND created_at >= ?', [$since]),
            'estimate_tonnes_month_median_7d' => jocate_median(array_map('floatval', array_column($q('SELECT monthly_tonnes FROM jocate_leads WHERE type="fuel_estimate" AND monthly_tonnes IS NOT NULL AND created_at >= ?', [$since]), 'monthly_tonnes'))),
            'estimate_modes_7d' => $q('SELECT JSON_UNQUOTE(JSON_EXTRACT(details, "$.mode")) mode, COUNT(*) n FROM jocate_leads WHERE type="fuel_estimate" AND created_at >= ? GROUP BY mode', [$since]),
            'fuel_requested_all' => $q('SELECT COALESCE(fuel,"(not stated)") fuel, COUNT(*) n FROM jocate_leads WHERE type IN ("supply_enquiry","fuel_estimate") GROUP BY fuel ORDER BY n DESC LIMIT 10'),
            'buyer_counties_all' => $q('SELECT COALESCE(county,"(unknown)") county, COUNT(*) n FROM jocate_leads WHERE type="supply_enquiry" GROUP BY county ORDER BY n DESC LIMIT 15'),
        ],
        'supply' => [
            'offers_7d' => (int) $one('SELECT COUNT(*) FROM jocate_leads WHERE type="supplier_offer" AND created_at >= ?', [$since]),
            'offer_tonnes_7d' => (float) $one('SELECT COALESCE(SUM(monthly_tonnes),0) FROM jocate_leads WHERE type="supplier_offer" AND created_at >= ?', [$since]),
            'materials_all' => jocate_material_counts($q('SELECT materials FROM jocate_leads WHERE type="supplier_offer" AND materials IS NOT NULL')),
            'supplier_counties_all' => $q('SELECT COALESCE(county,"(unknown)") county, COUNT(*) n FROM jocate_leads WHERE type="supplier_offer" GROUP BY county ORDER BY n DESC LIMIT 15'),
            'availability_all' => $q('SELECT COALESCE(availability,"(not stated)") availability, COUNT(*) n FROM jocate_leads WHERE type="supplier_offer" GROUP BY availability'),
        ],
        'engagement' => [
            'contacts_by_role_status' => $q('SELECT role, status, COUNT(*) n FROM jocate_contacts GROUP BY role, status ORDER BY role, status'),
            'overdue_actions' => (int) $one('SELECT COUNT(*) FROM jocate_contacts WHERE next_action_due < UTC_TIMESTAMP() AND status NOT IN ("won","lost")'),
            'overdue_by_role' => $q('SELECT role, COUNT(*) n FROM jocate_contacts WHERE next_action_due < UTC_TIMESTAMP() AND status NOT IN ("won","lost") GROUP BY role'),
            'score_bands' => $q('SELECT CASE WHEN lead_score>=60 THEN "60+" WHEN lead_score>=40 THEN "40-59" WHEN lead_score>=20 THEN "20-39" ELSE "0-19" END band, COUNT(*) n FROM jocate_contacts GROUP BY band ORDER BY band DESC'),
            'activity_7d' => $q('SELECT kind, COUNT(*) n FROM jocate_activity WHERE at >= ? GROUP BY kind', [$since]),
            'top_open_leads' => $q('SELECT id AS contact_ref, role, county, lead_score, status, IF(next_action_due < UTC_TIMESTAMP(), "yes", "no") AS overdue FROM jocate_contacts WHERE status NOT IN ("won","lost") ORDER BY lead_score DESC, next_action_due ASC LIMIT 10'),
        ],
        'etl_runs_7d' => $q('SELECT trigger_source, COUNT(*) runs, GROUP_CONCAT(DISTINCT rules_version) rules_versions, SUM(extracted) extracted, SUM(loaded) loaded, SUM(merged) merged, SUM(rejected) rejected, SUM(flagged) flagged, SUM(dormant_flagged) dormant_flagged FROM jocate_etl_runs WHERE started_at >= ? GROUP BY trigger_source', [$since]),
    ];
    return $r;
}

function jocate_flag_counts(array $rows): array {
    $c = [];
    foreach ($rows as $r) foreach (explode(',', (string) $r['quality_flags']) as $f) if ($f !== '') $c[$f] = ($c[$f] ?? 0) + 1;
    arsort($c);
    return $c;
}

function jocate_material_counts(array $rows): array {
    $c = [];
    foreach ($rows as $r) foreach (explode('; ', (string) $r['materials']) as $m) if ($m !== '') $c[$m] = ($c[$m] ?? 0) + 1;
    arsort($c);
    return $c;
}

function jocate_median(array $v): ?float {
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : round(($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
}

function jocate_report_markdown(array $r): string {
    $t = fn(array $rows, array $cols) => $rows
        ? "| " . implode(' | ', $cols) . " |\n|" . str_repeat(' --- |', count($cols)) . "\n" .
          implode("\n", array_map(fn($row) => '| ' . implode(' | ', array_map(fn($c) => str_replace('|', '/', (string) ($row[$c] ?? '')), $cols)) . ' |', $rows)) . "\n"
        : "_None._\n";
    $kv = fn(array $a) => $a ? implode("\n", array_map(fn($k, $v) => "- **$k**: $v", array_keys($a), $a)) . "\n" : "_None._\n";
    $s = $r['submissions'];
    $md = "# Weekly data report: {$r['week']}\n\nGenerated {$r['generated_at']} · rules v{$r['rules_version']} · no personal data in this file.\n\n";
    $md .= "## Submissions (last 7 days)\n" . $t($s['last_7_days'], ['form', 'channel', 'n']) . "\nAll-time submissions: **{$s['all_time']}**\n\n";
    $md .= "### Processing outcomes\n" . $t($s['outcomes_7d'], ['outcome', 'n']) . "\n";
    $md .= "## Data quality\n### Flags (last 7 days)\n" . $kv($r['data_quality']['flags_7d']) . "\n### Values the rules could not map (open)\n" . $t($r['data_quality']['unmatched_open'], ['field', 'value', 'hits']) .
           "\nResolved by updated rules this run: **{$r['data_quality']['unmatched_resolved_this_run']}**\n\n";
    $d = $r['demand'];
    $md .= "## Demand\n- Supply enquiries (7d): **{$d['enquiries_7d']}**, stated volume **{$d['enquiry_tonnes_month_7d']} t/month**\n- Calculator estimates (7d): **{$d['estimates_7d']}**, median **" . ($d['estimate_tonnes_month_median_7d'] ?? 'n/a') . " t/month**\n\n";
    $md .= "### Estimate modes (7d)\n" . $t($d['estimate_modes_7d'], ['mode', 'n']) . "\n### Fuels requested (all time)\n" . $t($d['fuel_requested_all'], ['fuel', 'n']) . "\n### Buyer counties (all time)\n" . $t($d['buyer_counties_all'], ['county', 'n']) . "\n";
    $p = $r['supply'];
    $md .= "## Supply\n- Supplier offers (7d): **{$p['offers_7d']}**, about **{$p['offer_tonnes_7d']} t** where convertible\n\n### Materials offered (all time)\n" . $kv($p['materials_all']) .
           "\n### Supplier counties (all time)\n" . $t($p['supplier_counties_all'], ['county', 'n']) . "\n### Availability\n" . $t($p['availability_all'], ['availability', 'n']) . "\n";
    $e = $r['engagement'];
    $md .= "## Engagement\n- Overdue follow-ups: **{$e['overdue_actions']}**\n\n### Contacts by role and status\n" . $t($e['contacts_by_role_status'], ['role', 'status', 'n']) .
           "\n### Lead score bands\n" . $t($e['score_bands'], ['band', 'n']) . "\n### Team activity (7d)\n" . $t($e['activity_7d'], ['kind', 'n']) .
           "\n### Top open leads (reference IDs only)\n" . $t($e['top_open_leads'], ['contact_ref', 'role', 'county', 'lead_score', 'status', 'overdue']) . "\n";
    $md .= "## ETL runs (7d)\n" . $t($r['etl_runs_7d'], ['trigger_source', 'runs', 'rules_versions', 'extracted', 'loaded', 'merged', 'rejected', 'flagged', 'dormant_flagged']);
    return $md;
}
