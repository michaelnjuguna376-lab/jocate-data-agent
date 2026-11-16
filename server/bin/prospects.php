<?php
// Load prospects into MySQL, email the weekly report and send the WhatsApp summary.
// php ~/jocate-agent/server/bin/prospects.php [--no-email|--force-email] [--no-whatsapp|--force-whatsapp] [--preview-whatsapp]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/prospects.php';
$import = jocate_prospects_import();
echo 'Import: ' . json_encode(array_diff_key($import, ['changed' => 1])) . "\n";
$mail = in_array('--no-email', $argv, true) ? ['sent' => false, 'reason' => 'skipped'] : jocate_prospects_email($import, in_array('--force-email', $argv, true));
echo 'Email: ' . json_encode($mail) . "\n";
$wa = in_array('--no-whatsapp', $argv, true) ? ['skipped' => true] : jocate_prospects_whatsapp($import, in_array('--force-whatsapp', $argv, true));
echo 'WhatsApp: ' . json_encode($wa) . "\n";
if (in_array('--preview-whatsapp', $argv, true)) echo "---\n" . jocate_prospects_whatsapp_text('PREVIEW', $import) . "\n---\n";
$db = jocate_db();
$db->prepare('INSERT INTO jocate_prospect_runs (run_at, week, total, inserted, updated, unchanged, rejected, emailed, note) VALUES (?,?,?,?,?,?,?,?,?)')
   ->execute([jocate_now(), gmdate('o-\WW'), $import['total'], $import['inserted'], $import['updated'], $import['unchanged'], $import['rejected'], $mail['sent'] ? 1 : 0, mb_substr((string) ($mail['reason'] ?? ($mail['week'] ?? '')), 0, 255)]);
