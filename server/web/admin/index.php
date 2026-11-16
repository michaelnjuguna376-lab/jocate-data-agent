<?php
// Jocate admin: view, update and record every submission and follow-up.
// Deployed to public_html/admin/index.php. Password hash lives in ~/jocate-private/config.php.
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

$home = dirname(__DIR__, 4);
require_once $home . '/jocate-agent/server/lib/etl.php';

session_name('jocate_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$cfg = jocate_config();
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Bad request'); } }
function go(string $q = ''): void { header('Location: ./' . ($q ? '?' . $q : '')); exit; }

// ---------- login ----------
$lockFile = JOCATE_PRIVATE . '/logs/admin-login.json';
$lock = is_file($lockFile) ? (json_decode((string) file_get_contents($lockFile), true) ?: []) : [];
$ipKey = hash('sha256', ($cfg['ip_salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
if (($_POST['action'] ?? '') === 'login') {
    $fails = array_filter($lock[$ipKey] ?? [], fn($t) => $t > time() - 900);
    if (count($fails) >= 5) { $error = 'Too many attempts. Try again in 15 minutes.'; }
    elseif (!empty($cfg['admin_password_hash']) && password_verify((string) ($_POST['password'] ?? ''), $cfg['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = time();
        unset($lock[$ipKey]); @file_put_contents($lockFile, json_encode($lock));
        go();
    } else {
        $fails[] = time(); $lock[$ipKey] = array_values($fails); @file_put_contents($lockFile, json_encode($lock));
        sleep(1);
        $error = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); go(); }
$authed = !empty($_SESSION['auth']) && $_SESSION['auth'] > time() - 8 * 3600;

$db = jocate_db();
$statuses = ['new', 'contacted', 'qualified', 'active', 'won', 'lost', 'dormant'];
$pstatuses = ['new', 'researching', 'approached', 'meeting', 'proposal', 'won', 'not-a-fit'];

// ---------- actions ----------
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'prospect') {
        $pid = (int) $_POST['id'];
        $ts = in_array($_POST['team_status'] ?? '', $pstatuses, true) ? $_POST['team_status'] : 'new';
        $db->prepare('UPDATE jocate_prospects SET team_status=?, team_notes=?, updated_at=? WHERE id=?')
           ->execute([$ts, mb_substr(trim((string) ($_POST['team_notes'] ?? '')), 0, 4000) ?: null, jocate_now(), $pid]);
        $_SESSION['flash'] = 'Prospect updated.';
        go('view=partners#p' . $pid);
    }
    if ($a === 'etl') { $r = jocate_etl('admin'); $_SESSION['flash'] = "Processed {$r['extracted']} new submission(s): {$r['loaded']} loaded, {$r['merged']} merged, {$r['rejected']} rejected."; go(); }
    if ($a === 'update') {
        $id = (int) $_POST['id'];
        $c = $db->prepare('SELECT status, next_action, next_action_due FROM jocate_contacts WHERE id=?'); $c->execute([$id]); $old = $c->fetch();
        if ($old) {
            $status = in_array($_POST['status'] ?? '', $statuses, true) ? $_POST['status'] : $old['status'];
            $next = mb_substr(trim((string) ($_POST['next_action'] ?? '')), 0, 255) ?: null;
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['due'] ?? '')) ? $_POST['due'] . ' 06:00:00' : null;
            $db->prepare('UPDATE jocate_contacts SET status=?, next_action=?, next_action_due=?, updated_at=? WHERE id=?')->execute([$status, $next, $due, jocate_now(), $id]);
            $log = $db->prepare('INSERT INTO jocate_activity (contact_id, at, kind, detail) VALUES (?,?,?,?)');
            if ($status !== $old['status']) $log->execute([$id, jocate_now(), 'status', $old['status'] . ' → ' . $status]);
            if ($next !== $old['next_action']) $log->execute([$id, jocate_now(), 'followup', 'Next action: ' . ($next ?? '(none)') . ($due ? ' by ' . substr($due, 0, 10) : '')]);
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($note !== '') $log->execute([$id, jocate_now(), 'note', mb_substr($note, 0, 2000)]);
            $_SESSION['flash'] = 'Contact updated.';
        }
        go('contact=' . $id);
    }
}
if ($authed && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jocate-contacts-' . gmdate('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['id', 'role', 'name', 'company', 'phone', 'email', 'county', 'status', 'lead_score', 'next_action', 'next_action_due', 'first_seen', 'last_seen', 'submissions']);
    foreach ($db->query('SELECT id, role, name, company, phone_e164, email, county, status, lead_score, next_action, next_action_due, first_seen, last_seen, submissions_count FROM jocate_contacts ORDER BY id') as $r) fputcsv($o, $r);
    exit;
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jocate Admin</title>
<link rel="stylesheet" href="/assets/styles.css?v=20261115d">
<style>
  body{background:var(--bg)} .adm{width:min(1240px,100% - 32px);margin:24px auto 64px}
  .adm h1{font-size:1.8rem;margin:0} .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:20px}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0 24px}
  .tile{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px 16px}.tile strong{display:block;font-family:var(--font-display);font-size:1.8rem;color:var(--green-800)}
  .tile span{font-size:.85rem;color:var(--muted)} table{min-width:900px} td,th{padding:10px 12px;font-size:.9rem}
  .pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.78rem;font-weight:600;background:var(--surface-2)}
  .pill.new{background:#dcfce7;color:#14532d}.pill.overdue{background:#fee2e2;color:#991b1b}.pill.won{background:#14532d;color:#fff}.pill.lost{background:#e5e7eb;color:#374151}
  .filters-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}.filters-row a{padding:6px 14px;border:1px solid var(--line);border-radius:999px;text-decoration:none;font-size:.88rem;background:var(--surface)}
  .filters-row a.on{background:var(--green-800);color:#fff;border-color:var(--green-800)} .grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
  @media(max-width:860px){.grid2{grid-template-columns:1fr}} .log li{margin-bottom:8px;font-size:.92rem} .muted{color:var(--muted);font-size:.85rem}
  .flash{background:var(--green-100);padding:12px 16px;border-radius:10px;margin-bottom:16px}
</style></head><body><div class="adm">
<?php if (!$authed): ?>
  <div class="card" style="max-width:420px;margin:12vh auto">
    <h1>Jocate admin</h1>
    <?php if (empty($cfg['admin_password_hash'])): ?><p>Admin password not configured on the server.</p><?php else: ?>
    <form method="post" class="form" style="margin-top:16px">
      <input type="hidden" name="action" value="login">
      <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" autocomplete="current-password" required></div>
      <?php if (!empty($error)): ?><p style="color:#b91c1c;margin:0"><?= h($error) ?></p><?php endif; ?>
      <button class="btn btn-primary" type="submit">Sign in</button>
    </form><?php endif; ?>
  </div>
<?php else:
  $contactId = isset($_GET['contact']) ? (int) $_GET['contact'] : 0; ?>
  <div class="bar">
    <h1><a href="./" style="text-decoration:none;color:inherit">Jocate admin</a></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="etl"><button class="btn btn-ghost" type="submit">Process new submissions</button></form>
      <a class="btn btn-ghost" href="?export=1">Export CSV</a>
      <a class="btn btn-ghost" href="?view=partners">Potential partners</a>
      <a class="btn btn-ghost" href="?view=estimates">Calculator estimates</a>
      <a class="btn btn-ghost" href="?logout=1">Sign out</a>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash" role="status"><?= h($flash) ?></div><?php endif; ?>

<?php if ($contactId):
    $st = $db->prepare('SELECT * FROM jocate_contacts WHERE id=?'); $st->execute([$contactId]); $c = $st->fetch();
    if (!$c): ?><p>Contact not found. <a href="./">Back</a></p><?php else:
    $leads = $db->prepare('SELECT l.*, s.channel, s.page FROM jocate_leads l JOIN jocate_submissions s ON s.id=l.submission_id WHERE l.contact_id=? ORDER BY l.id DESC'); $leads->execute([$contactId]);
    $acts = $db->prepare('SELECT * FROM jocate_activity WHERE contact_id=? ORDER BY id DESC LIMIT 100'); $acts->execute([$contactId]);
    $wa = $c['phone_e164'] ? 'https://wa.me/' . ltrim($c['phone_e164'], '+') : null; ?>
  <p><a href="./">&larr; All contacts</a></p>
  <div class="grid2">
    <div class="card">
      <h2 style="font-size:1.4rem;margin:0 0 4px"><?= h($c['name'] ?: '(no name)') ?> <span class="pill"><?= h($c['role']) ?></span></h2>
      <p class="muted">Contact #<?= (int) $c['id'] ?> · first seen <?= h(substr($c['first_seen'], 0, 10)) ?> · <?= (int) $c['submissions_count'] ?> submission(s) · score <?= (int) $c['lead_score'] ?></p>
      <ul class="checklist" style="margin:12px 0">
        <?php if ($c['company']): ?><li><span></span><div><strong>Company:</strong> <?= h($c['company']) ?></div></li><?php endif; ?>
        <li><span></span><div><strong>Phone:</strong> <?= $c['phone_e164'] ? '<a href="tel:' . h($c['phone_e164']) . '">' . h($c['phone_e164']) . '</a> · <a href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : '—' ?></div></li>
        <li><span></span><div><strong>Email:</strong> <?= $c['email'] ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '—' ?></div></li>
        <li><span></span><div><strong>County:</strong> <?= h($c['county'] ?: '—') ?></div></li>
        <?php if ($c['quality_flags']): ?><li><span></span><div><strong>Data flags:</strong> <?= h($c['quality_flags']) ?></div></li><?php endif; ?>
      </ul>
      <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <div class="form-row">
          <div class="field"><label for="st">Status</label><select id="st" name="status"><?php foreach ($statuses as $s): ?><option <?= $s === $c['status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="due">Next action due</label><input id="due" type="date" name="due" value="<?= h(substr((string) $c['next_action_due'], 0, 10)) ?>"></div>
        </div>
        <div class="field"><label for="na">Next action</label><input id="na" name="next_action" value="<?= h($c['next_action']) ?>"></div>
        <div class="field"><label for="note">Add a note (call outcome, quote sent, etc.)</label><textarea id="note" name="note"></textarea></div>
        <button class="btn btn-primary" type="submit">Save update</button>
      </form>
    </div>
    <div>
      <div class="card" style="margin-bottom:20px"><h3>Submissions</h3>
        <?php foreach ($leads as $l): $d = json_decode((string) $l['details'], true) ?: []; ?>
          <div style="border-top:1px solid var(--line);padding:10px 0">
            <strong><?= h(str_replace('_', ' ', $l['type'])) ?></strong> <span class="muted"><?= h(substr($l['created_at'], 0, 16)) ?> UTC · via <?= h($l['channel']) ?></span><br>
            <?= $l['fuel'] ? 'Fuel: ' . h($l['fuel']) . '<br>' : '' ?><?= $l['materials'] ? 'Materials: ' . h($l['materials']) . '<br>' : '' ?>
            <?= $l['quantity_text'] ? 'Quantity: ' . h($l['quantity_text']) . ($l['monthly_tonnes'] !== null ? ' (≈ ' . h($l['monthly_tonnes']) . ' t)' : '') . '<br>' : '' ?>
            <?= $l['availability'] ? 'Available: ' . h($l['availability']) . '<br>' : '' ?>
            <?php foreach (['location' => 'Location', 'message' => 'Message', 'notes' => 'Notes'] as $k => $lab) if (!empty($d[$k])) echo h($lab) . ': ' . nl2br(h($d[$k])) . '<br>'; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card"><h3>Activity</h3><ul class="log" style="padding-left:1em">
        <?php foreach ($acts as $a): ?><li><span class="muted"><?= h(substr($a['at'], 0, 16)) ?> · <?= h($a['kind']) ?></span><br><?= nl2br(h($a['detail'])) ?></li><?php endforeach; ?>
      </ul></div>
    </div>
  </div>
<?php endif; elseif (($_GET['view'] ?? '') === 'partners'):
    $band = in_array($_GET['band'] ?? '', ['priority', 'strong', 'watch'], true) ? $_GET['band'] : '';
    $hs = preg_match('/^[a-z-]{2,40}$/', (string) ($_GET['hotspot'] ?? '')) ? $_GET['hotspot'] : '';
    $w = ['research_status <> "closed"']; $args = [];
    if ($band) { $w[] = 'band = ?'; $args[] = $band; }
    if ($hs) { $w[] = 'hotspot = ?'; $args[] = $hs; }
    $st = $db->prepare('SELECT * FROM jocate_prospects WHERE ' . implode(' AND ', $w) . ' ORDER BY fit_score DESC, company LIMIT 300'); $st->execute($args);
    $rows = $st->fetchAll();
    $hotspots = $db->query('SELECT hotspot, COUNT(*) n FROM jocate_prospects WHERE research_status <> "closed" GROUP BY hotspot ORDER BY n DESC')->fetchAll();
    $lastRun = $db->query('SELECT * FROM jocate_prospect_runs ORDER BY id DESC LIMIT 1')->fetch(); ?>
  <p><a href="./">&larr; All contacts</a></p>
  <h2 style="font-size:1.4rem;margin-bottom:4px">Potential partners</h2>
  <p class="muted">Researched every Friday by the Potential Partners agent (company-level public information, cited). Last import: <?= $lastRun ? h(substr($lastRun['run_at'], 0, 16)) . ' UTC · ' . (int) $lastRun['inserted'] . ' new, ' . (int) $lastRun['updated'] . ' updated' . ($lastRun['emailed'] ? ' · emailed' : '') : 'not yet' ?>.</p>
  <div class="filters-row">
    <a href="?view=partners" class="<?= !$band && !$hs ? 'on' : '' ?>">All</a>
    <?php foreach (['priority', 'strong', 'watch'] as $b): ?><a href="?view=partners&amp;band=<?= $b ?>" class="<?= $band === $b ? 'on' : '' ?>"><?= ucfirst($b) ?></a><?php endforeach; ?>
    <?php foreach ($hotspots as $x): ?><a href="?view=partners&amp;hotspot=<?= h($x['hotspot']) ?>" class="<?= $hs === $x['hotspot'] ? 'on' : '' ?>"><?= h($x['hotspot']) ?> (<?= (int) $x['n'] ?>)</a><?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><div class="card"><p>No prospects yet. The agent's first research run will fill this list.</p></div><?php endif; ?>
  <?php foreach ($rows as $p): $ev = json_decode((string) $p['evidence'], true) ?: []; ?>
  <div class="card" id="p<?= (int) $p['id'] ?>" style="margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div><h3 style="margin:0"><?= h($p['company']) ?></h3>
        <p class="muted" style="margin:2px 0 8px"><?= h($p['sector']) ?> · tier <?= h($p['tier']) ?> · <?= h($p['towns']) ?> · hub: <?= h($p['hotspot']) ?></p></div>
      <div style="text-align:right"><span class="pill <?= $p['band'] === 'priority' ? 'won' : '' ?>"><?= h($p['band']) ?> · <?= (int) $p['fit_score'] ?></span><br><span class="muted">confidence: <?= h($p['confidence']) ?></span></div>
    </div>
    <p style="margin:0 0 6px"><?= h($p['summary']) ?></p>
    <?php if ($p['current_fuel']): ?><p class="muted" style="margin:0 0 4px"><strong>Fuel:</strong> <?= h($p['current_fuel']) ?></p><?php endif; ?>
    <?php if ($p['signals']): ?><p class="muted" style="margin:0 0 4px"><strong>Signals:</strong> <?= h($p['signals']) ?></p><?php endif; ?>
    <?php if ($p['suggested_angle']): ?><p style="margin:0 0 4px"><strong>Angle:</strong> <?= h($p['suggested_angle']) ?></p><?php endif; ?>
    <?php if ($p['next_step']): ?><p style="margin:0 0 8px"><strong>Next step:</strong> <?= h($p['next_step']) ?></p><?php endif; ?>
    <p class="muted" style="margin:0 0 8px"><?= $p['website'] ? '<a href="' . h($p['website']) . '" target="_blank" rel="noopener">Website</a>' : '' ?><?= $p['procurement_url'] ? ' · <a href="' . h($p['procurement_url']) . '" target="_blank" rel="noopener">Procurement</a>' : '' ?>
      · Evidence: <?php foreach ($ev as $i => $e): ?><a href="<?= h($e['url'] ?? '#') ?>" target="_blank" rel="noopener" title="<?= h(($e['claim'] ?? '') . ' (' . ($e['source'] ?? '') . ', ' . ($e['date'] ?? '') . ')') ?>">[<?= $i + 1 ?>]</a> <?php endforeach; ?>
      · reviewed <?= h($p['last_reviewed']) ?></p>
    <form method="post" class="form-row" style="align-items:end">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="prospect"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div class="field" style="margin:0"><label for="ts<?= (int) $p['id'] ?>">Team status</label><select id="ts<?= (int) $p['id'] ?>" name="team_status"><?php foreach ($pstatuses as $s): ?><option <?= $s === $p['team_status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0"><label for="tn<?= (int) $p['id'] ?>">Team notes</label><input id="tn<?= (int) $p['id'] ?>" name="team_notes" value="<?= h($p['team_notes']) ?>"></div>
      <button class="btn btn-primary" type="submit" style="min-height:48px">Save</button>
    </form>
  </div>
  <?php endforeach; ?>
<?php elseif (($_GET['view'] ?? '') === 'estimates'):
    $rows = $db->query('SELECT l.id, l.created_at, l.fuel, l.monthly_tonnes, l.details, s.channel FROM jocate_leads l JOIN jocate_submissions s ON s.id=l.submission_id WHERE l.type="fuel_estimate" ORDER BY l.id DESC LIMIT 300')->fetchAll(); ?>
  <p><a href="./">&larr; All contacts</a></p>
  <h2 style="font-size:1.3rem">Calculator estimates sent (anonymous)</h2>
  <div class="table-wrap"><table><thead><tr><th>Date (UTC)</th><th>Sent via</th><th>Mode</th><th>Input</th><th>Fuel</th><th>t/month</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $d = json_decode((string) $r['details'], true) ?: []; ?>
    <tr><td><?= h(substr($r['created_at'], 0, 16)) ?></td><td><?= h($r['channel']) ?></td><td><?= h($d['mode'] ?? '') ?></td>
    <td><?= ($d['mode'] ?? '') === 'oil' ? h(($d['litres'] ?? '?') . ' L/day ' . ($d['oiltype'] ?? '')) : h(($d['steam'] ?? '?') . ' kg/h, ' . ($d['load'] ?? '?') . '% load') ?></td>
    <td><?= h($r['fuel']) ?></td><td><?= h($r['monthly_tonnes']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6">No estimates yet.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php else:
    $role = in_array($_GET['role'] ?? '', ['buyer', 'supplier', 'both'], true) ? $_GET['role'] : '';
    $status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
    $overdue = isset($_GET['overdue']);
    $where = ['1=1']; $args = [];
    if ($role) { $where[] = 'role = ?'; $args[] = $role; }
    if ($status) { $where[] = 'status = ?'; $args[] = $status; }
    if ($overdue) $where[] = 'next_action_due < UTC_TIMESTAMP() AND status NOT IN ("won","lost")';
    $st = $db->prepare('SELECT * FROM jocate_contacts WHERE ' . implode(' AND ', $where) . ' ORDER BY (next_action_due IS NULL), next_action_due, lead_score DESC LIMIT 500'); $st->execute($args);
    $list = $st->fetchAll();
    $tile = fn($sql) => (int) $db->query($sql)->fetchColumn(); ?>
  <div class="tiles">
    <div class="tile"><strong><?= $tile('SELECT COUNT(*) FROM jocate_submissions WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)') ?></strong><span>Submissions, last 7 days</span></div>
    <div class="tile"><strong><?= $tile('SELECT COUNT(*) FROM jocate_contacts WHERE role IN ("buyer","both")') ?></strong><span>Buyer contacts</span></div>
    <div class="tile"><strong><?= $tile('SELECT COUNT(*) FROM jocate_contacts WHERE role IN ("supplier","both")') ?></strong><span>Supplier contacts</span></div>
    <div class="tile"><strong><?= $tile('SELECT COUNT(*) FROM jocate_contacts WHERE next_action_due < UTC_TIMESTAMP() AND status NOT IN ("won","lost")') ?></strong><span>Overdue follow-ups</span></div>
    <div class="tile"><strong><?= $tile('SELECT COUNT(*) FROM jocate_submissions WHERE processed_at IS NULL') ?></strong><span>Waiting to process</span></div>
  </div>
  <div class="filters-row">
    <a href="./" class="<?= !$role && !$status && !$overdue ? 'on' : '' ?>">All</a>
    <a href="?role=buyer" class="<?= $role === 'buyer' ? 'on' : '' ?>">Buyers</a>
    <a href="?role=supplier" class="<?= $role === 'supplier' ? 'on' : '' ?>">Suppliers</a>
    <a href="?overdue=1" class="<?= $overdue ? 'on' : '' ?>">Overdue</a>
    <?php foreach ($statuses as $s): ?><a href="?status=<?= h($s) ?>" class="<?= $status === $s ? 'on' : '' ?>"><?= h(ucfirst($s)) ?></a><?php endforeach; ?>
  </div>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>Name / company</th><th>Role</th><th>County</th><th>Phone</th><th>Score</th><th>Status</th><th>Next action</th><th>Due</th></tr></thead><tbody>
  <?php foreach ($list as $c): $late = $c['next_action_due'] && strtotime($c['next_action_due'] . ' UTC') < time() && !in_array($c['status'], ['won', 'lost'], true); ?>
    <tr><td><a href="?contact=<?= (int) $c['id'] ?>"><?= (int) $c['id'] ?></a></td>
      <td><a href="?contact=<?= (int) $c['id'] ?>"><?= h($c['name'] ?: '(no name)') ?></a><?= $c['company'] ? '<br><span class="muted">' . h($c['company']) . '</span>' : '' ?></td>
      <td><?= h($c['role']) ?></td><td><?= h($c['county'] ?: '—') ?></td>
      <td><?= $c['phone_e164'] ? '<a href="tel:' . h($c['phone_e164']) . '">' . h($c['phone_e164']) . '</a>' : '—' ?></td>
      <td><?= (int) $c['lead_score'] ?></td><td><span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
      <td><?= h($c['next_action'] ?: '—') ?></td><td><?= $c['next_action_due'] ? '<span class="pill ' . ($late ? 'overdue' : '') . '">' . h(substr($c['next_action_due'], 0, 10)) . '</span>' : '—' ?></td></tr>
  <?php endforeach; if (!$list): ?><tr><td colspan="9">No contacts match.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif; endif; ?>
</div></body></html>
