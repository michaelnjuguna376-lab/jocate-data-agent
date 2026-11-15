<?php
// Extract -> Transform -> Load for website submissions.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/transform.php';

/** Process every unprocessed submission. Safe to run repeatedly (idempotent per submission). */
function jocate_etl(string $trigger = 'manual', int $limit = 1000, ?int $onlyId = null): array {
    $db = jocate_db();
    $rules = jocate_rules();
    $now = jocate_now();
    $db->prepare('INSERT INTO jocate_etl_runs (started_at, trigger_source, rules_version) VALUES (?, ?, ?)')
       ->execute([$now, $trigger, $rules['version'] ?? null]);
    $runId = (int) $db->lastInsertId();
    $stats = ['extracted' => 0, 'loaded' => 0, 'merged' => 0, 'rejected' => 0, 'flagged' => 0, 'dormant_flagged' => 0];

    // EXTRACT
    if ($onlyId !== null) {
        $st = $db->prepare('SELECT * FROM jocate_submissions WHERE processed_at IS NULL AND id = ?');
        $st->execute([$onlyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $db->query('SELECT * FROM jocate_submissions WHERE processed_at IS NULL ORDER BY id LIMIT ' . (int) $limit)->fetchAll();
    }
    $stats['extracted'] = count($rows);

    foreach ($rows as $row) {
        $unmatched = [];
        $flags = [];
        $p = json_decode($row['payload'], true) ?: [];
        try {
            $db->beginTransaction();
            // Reject spam (honeypot filled) and empty submissions
            if (!empty($p['website'])) { jocate_mark($db, (int) $row['id'], $runId, 'rejected', 'honeypot'); $stats['rejected']++; $db->commit(); continue; }

            // TRANSFORM
            $f = jocate_transform($row['form'], $p, $rules, $unmatched, $flags);
            if ($f === null) { jocate_mark($db, (int) $row['id'], $runId, 'rejected', 'empty'); $stats['rejected']++; $db->commit(); continue; }

            // LOAD: contact (deduplicated by phone, then email), then lead
            $contactId = null;
            if ($f['contact_key'] !== null) {
                [$contactId, $merged] = jocate_upsert_contact($db, $f, $row['created_at'], $rules);
                if ($merged) $stats['merged']++;
            }
            $db->prepare('INSERT IGNORE INTO jocate_leads (contact_id, submission_id, type, created_at, county, fuel, materials, monthly_tonnes, quantity_text, availability, details)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$contactId, $row['id'], $f['lead_type'], $row['created_at'], $f['county'], $f['fuel'], $f['materials'],
                          $f['tonnes'], $f['quantity_text'], $f['availability'], json_encode($f['details'], JSON_UNESCAPED_UNICODE)]);
            foreach ($unmatched as [$field, $value]) jocate_unmatched($db, $field, (string) $value);
            if ($flags) $stats['flagged']++;
            jocate_mark($db, (int) $row['id'], $runId, 'loaded', implode(',', array_unique($flags)));
            $stats['loaded']++;
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jocate_log('etl.log', 'submission ' . $row['id'] . ' failed: ' . $e->getMessage());
            jocate_mark($db, (int) $row['id'], $runId, 'error', 'exception');
            $stats['rejected']++;
        }
    }

    // ENGAGEMENT: contacts we spoke to but haven't heard from in a while
    $fu = $rules['follow_up'] ?? [];
    $days = (int) ($fu['dormant_days'] ?? 30);
    $st = $db->prepare("UPDATE jocate_contacts SET next_action = ?, next_action_due = ?, updated_at = ?
                        WHERE status IN ('contacted','qualified') AND last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
                          AND (next_action IS NULL OR next_action <> ?)");
    $act = $fu['dormant_action'] ?? 'Re-engage';
    $st->execute([$act, $now, $now, $days, $act]);
    $stats['dormant_flagged'] = $st->rowCount();

    $db->prepare('UPDATE jocate_etl_runs SET finished_at=?, extracted=?, loaded=?, merged=?, rejected=?, flagged=?, dormant_flagged=? WHERE id=?')
       ->execute([jocate_now(), $stats['extracted'], $stats['loaded'], $stats['merged'], $stats['rejected'], $stats['flagged'], $stats['dormant_flagged'], $runId]);
    jocate_log('etl.log', "run $runId ($trigger): " . json_encode($stats));
    return ['run_id' => $runId] + $stats;
}

function jocate_transform(string $form, array $p, array $rules, array &$unmatched, array &$flags): ?array {
    $f = ['role' => null, 'lead_type' => null, 'name' => null, 'company' => null, 'phone' => null, 'email' => null, 'county' => null,
          'fuel' => null, 'materials' => null, 'tonnes' => null, 'quantity_text' => null, 'availability' => null, 'details' => [], 'contact_key' => null];
    if ($form === 'partner') {
        $f['role'] = 'buyer'; $f['lead_type'] = 'supply_enquiry';
        $f['company'] = jt_name($p['company'] ?? null);
        $f['name'] = jt_name($p['name'] ?? null);
        $f['phone'] = jt_phone($p['phone'] ?? null);
        $f['email'] = jt_email($p['email'] ?? null);
        $f['fuel'] = jt_text($p['fuel'] ?? null, 80);
        $f['quantity_text'] = jt_text($p['volume'] ?? null, 120);
        [$f['tonnes']] = jt_tonnes($p['volume'] ?? null, $rules, $unmatched);
        $f['county'] = jt_county($p['location'] ?? null, $rules, $unmatched);
        $f['details'] = ['location' => jt_text($p['location'] ?? null, 120), 'message' => jt_text($p['message'] ?? null, 2000)];
        if (!empty($p['phone']) && !$f['phone']) $flags[] = 'invalid_phone';
        if (!empty($p['email']) && !$f['email']) $flags[] = 'invalid_email';
        if (!empty($p['volume']) && $f['tonnes'] === null) $flags[] = 'unparsed_volume';
        if (!empty($p['location']) && !$f['county']) $flags[] = 'unknown_county';
    } elseif ($form === 'supplier') {
        $f['role'] = 'supplier'; $f['lead_type'] = 'supplier_offer';
        $f['name'] = jt_name($p['name'] ?? null);
        $f['phone'] = jt_phone($p['phone'] ?? null);
        $f['county'] = jt_county($p['county'] ?? null, $rules, $unmatched);
        $mats = array_values(array_filter(array_map('strval', (array) ($p['materials'] ?? []))));
        $f['materials'] = $mats ? mb_substr(implode('; ', $mats), 0, 255) : null;
        $qty = trim((string) ($p['qty'] ?? ''));
        $unit = trim((string) ($p['unit'] ?? ''));
        $f['quantity_text'] = $qty !== '' ? jt_text($qty . ' ' . $unit, 120) : null;
        if ($qty !== '') [$f['tonnes']] = jt_tonnes($qty, $rules, $unmatched, $unit);
        $f['availability'] = jt_text($p['when'] ?? null, 60);
        $f['details'] = ['location' => jt_text($p['location'] ?? null, 120), 'notes' => jt_text($p['notes'] ?? null, 2000), 'via' => $p['via'] ?? null];
        if (!empty($p['phone']) && !$f['phone']) $flags[] = 'invalid_phone';
        if (!$f['materials']) $flags[] = 'no_material';
    } elseif ($form === 'calculator') {
        $f['role'] = null; $f['lead_type'] = 'fuel_estimate';
        $f['fuel'] = jt_text($p['fuel'] ?? null, 80);
        $f['tonnes'] = isset($p['tMonth']) && is_numeric($p['tMonth']) ? round((float) $p['tMonth'], 1) : null;
        $f['details'] = array_intersect_key($p, array_flip(['mode', 'steam', 'load', 'hours', 'days', 'oiltype', 'litres', 'eff', 'buffer', 'co2', 'via']));
        return $f; // anonymous demand signal, no contact
    } else {
        return null;
    }
    if (!$f['phone'] && !$f['email'] && !$f['name']) return null;
    $f['contact_key'] = $f['phone'] ?? ($f['email'] ? 'email:' . $f['email'] : null);
    if ($f['contact_key'] === null) $flags[] = 'no_contact_route';
    return $f;
}

/** Insert a new contact or merge into the existing one. Returns [contact_id, merged?]. */
function jocate_upsert_contact(PDO $db, array $f, string $seenAt, array $rules): array {
    $now = jocate_now();
    $st = $db->prepare('SELECT * FROM jocate_contacts WHERE contact_key = ? OR (? IS NOT NULL AND email = ?) LIMIT 1');
    $st->execute([$f['contact_key'], $f['email'], $f['email']]);
    $c = $st->fetch();
    if ($c) {
        $role = $c['role'] === $f['role'] ? $c['role'] : 'both';
        $score = jt_score($f + ['repeat' => true], $rules);
        $db->prepare('UPDATE jocate_contacts SET role=?, name=COALESCE(?, name), company=COALESCE(?, company), phone_e164=COALESCE(phone_e164, ?),
                      email=COALESCE(email, ?), county=COALESCE(?, county), last_seen=?, submissions_count=submissions_count+1,
                      lead_score=GREATEST(lead_score, ?), next_action=?, next_action_due=?, updated_at=? WHERE id=?')
           ->execute([$role, $f['name'], $f['company'], $f['phone'], $f['email'], $f['county'], $seenAt, $score,
                      'Returning contact: review new submission and respond', gmdate('Y-m-d H:i:s', time() + 24 * 3600), $now, $c['id']]);
        $db->prepare('INSERT INTO jocate_activity (contact_id, at, kind, detail) VALUES (?,?,?,?)')
           ->execute([$c['id'], $now, 'system', 'New ' . $f['lead_type'] . ' merged into this contact']);
        return [(int) $c['id'], true];
    }
    [$action, $due] = jt_next_action($f['role'], $f['availability'], $rules);
    $flags = [];
    if (!$f['phone']) $flags[] = 'no_phone';
    if (!$f['email'] && $f['role'] === 'buyer') $flags[] = 'no_email';
    $db->prepare('INSERT INTO jocate_contacts (contact_key, role, name, company, phone_e164, email, county, first_seen, last_seen, lead_score, next_action, next_action_due, quality_flags, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$f['contact_key'], $f['role'], $f['name'], $f['company'], $f['phone'], $f['email'], $f['county'], $seenAt, $seenAt,
                  jt_score($f, $rules), $action, $due, implode(',', $flags) ?: null, $now]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO jocate_activity (contact_id, at, kind, detail) VALUES (?,?,?,?)')
       ->execute([$id, $now, 'system', 'Created from website ' . $f['lead_type']]);
    return [$id, false];
}

function jocate_mark(PDO $db, int $id, int $runId, string $outcome, string $flags): void {
    $db->prepare('UPDATE jocate_submissions SET processed_at=?, etl_run_id=?, outcome=?, quality_flags=? WHERE id=?')
       ->execute([jocate_now(), $runId, $outcome, $flags !== '' ? $flags : null, $id]);
}

function jocate_unmatched(PDO $db, string $field, string $value): void {
    $value = mb_substr(trim($value), 0, 80);
    if ($value === '') return;
    $now = jocate_now();
    $db->prepare('INSERT INTO jocate_unmatched (field, value, first_seen, last_seen) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), resolved = 0')
       ->execute([$field, $value, $now, $now]);
}
