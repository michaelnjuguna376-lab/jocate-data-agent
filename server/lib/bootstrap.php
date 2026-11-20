<?php
// Jocate data agent: shared bootstrap (config, database, schema).
// Lives outside the web root: ~/jocate-agent/server/lib/bootstrap.php
declare(strict_types=1);

define('JOCATE_HOME', dirname(__DIR__, 2));            // ~/jocate-agent (git working copy)
define('JOCATE_USER_HOME', dirname(JOCATE_HOME));       // /home/<account>
define('JOCATE_PRIVATE', JOCATE_USER_HOME . '/jocate-private'); // secrets + logs, never in git or web root

function jocate_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = JOCATE_PRIVATE . '/config.php';
    $cfg = is_file($file) ? (require $file) : [];
    // Database credentials are read from WordPress's wp-config.php, so no second copy of the password exists.
    $wp = $cfg['wp_config'] ?? (JOCATE_USER_HOME . '/domains/jocateorg.com/public_html/wp-config.php');
    $src = is_file($wp) ? (string) file_get_contents($wp) : '';
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $k) {
        if (!isset($cfg[$k]) && preg_match("/define\\(\\s*'" . $k . "'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $src, $m)) {
            $cfg[$k] = str_replace(["\\'", "\\\\"], ["'", "\\"], $m[1]);
        }
    }
    return $cfg;
}

function jocate_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = jocate_config();
    $host = $c['DB_HOST'] ?? '127.0.0.1';
    $port = null;
    if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
    $dsn = 'mysql:host=' . $host . ($port ? ';port=' . $port : '') . ';dbname=' . ($c['DB_NAME'] ?? '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['DB_USER'] ?? '', $c['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    jocate_migrate($pdo);
    return $pdo;
}

function jocate_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $f = JOCATE_HOME . '/rules/rules.json';
    $rules = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    return $rules;
}

function jocate_log(string $file, string $msg): void {
    $dir = JOCATE_PRIVATE . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/' . $file, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . "\n", FILE_APPEND);
}

function jocate_migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_submissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        form VARCHAR(20) NOT NULL,
        channel VARCHAR(20) NOT NULL,
        page VARCHAR(120) NULL,
        payload JSON NOT NULL,
        ip_hash CHAR(64) NULL,
        user_agent VARCHAR(200) NULL,
        processed_at DATETIME NULL,
        etl_run_id BIGINT UNSIGNED NULL,
        outcome VARCHAR(20) NULL,
        quality_flags VARCHAR(255) NULL,
        INDEX (processed_at), INDEX (form), INDEX (created_at), INDEX (ip_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_contacts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_key VARCHAR(190) NOT NULL UNIQUE,
        role VARCHAR(20) NOT NULL,
        name VARCHAR(150) NULL,
        company VARCHAR(190) NULL,
        phone_e164 VARCHAR(20) NULL,
        email VARCHAR(190) NULL,
        county VARCHAR(60) NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        submissions_count INT NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        lead_score INT NOT NULL DEFAULT 0,
        next_action VARCHAR(255) NULL,
        next_action_due DATETIME NULL,
        notes TEXT NULL,
        quality_flags VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL,
        INDEX (role), INDEX (status), INDEX (next_action_due)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_leads (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id BIGINT UNSIGNED NULL,
        submission_id BIGINT UNSIGNED NOT NULL UNIQUE,
        type VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL,
        county VARCHAR(60) NULL,
        fuel VARCHAR(80) NULL,
        materials VARCHAR(255) NULL,
        monthly_tonnes DECIMAL(10,1) NULL,
        quantity_text VARCHAR(120) NULL,
        availability VARCHAR(60) NULL,
        details JSON NULL,
        INDEX (contact_id), INDEX (type), INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_activity (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id BIGINT UNSIGNED NOT NULL,
        at DATETIME NOT NULL,
        kind VARCHAR(20) NOT NULL,
        detail TEXT NULL,
        INDEX (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_etl_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        trigger_source VARCHAR(20) NOT NULL,
        rules_version VARCHAR(20) NULL,
        extracted INT NOT NULL DEFAULT 0,
        loaded INT NOT NULL DEFAULT 0,
        merged INT NOT NULL DEFAULT 0,
        rejected INT NOT NULL DEFAULT 0,
        flagged INT NOT NULL DEFAULT 0,
        dormant_flagged INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_prospects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        company VARCHAR(190) NOT NULL,
        sector VARCHAR(40) NULL,
        tier CHAR(1) NULL,
        hotspot VARCHAR(40) NULL,
        towns VARCHAR(255) NULL,
        website VARCHAR(255) NULL,
        procurement_url VARCHAR(255) NULL,
        summary TEXT NULL,
        current_fuel VARCHAR(255) NULL,
        signals TEXT NULL,
        fit_score INT NOT NULL DEFAULT 0,
        band VARCHAR(12) NULL,
        confidence VARCHAR(8) NULL,
        suggested_angle TEXT NULL,
        next_step VARCHAR(255) NULL,
        research_status VARCHAR(12) NULL,
        evidence JSON NULL,
        first_seen DATE NULL,
        last_reviewed DATE NULL,
        team_status VARCHAR(20) NOT NULL DEFAULT 'new',
        team_notes TEXT NULL,
        imported_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX (band), INDEX (hotspot), INDEX (team_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_prospect_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_at DATETIME NOT NULL,
        week VARCHAR(10) NOT NULL,
        total INT NOT NULL DEFAULT 0,
        inserted INT NOT NULL DEFAULT 0,
        updated INT NOT NULL DEFAULT 0,
        unchanged INT NOT NULL DEFAULT 0,
        rejected INT NOT NULL DEFAULT 0,
        emailed TINYINT NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_unmatched (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        field VARCHAR(30) NOT NULL,
        value VARCHAR(80) NOT NULL,
        hits INT NOT NULL DEFAULT 1,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        resolved TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY field_value (field, value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // First-party, cookieless analytics: page views and contact clicks. visitor = daily salted hash, not reversible.
    $db->exec("CREATE TABLE IF NOT EXISTS jocate_analytics (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ts DATETIME NOT NULL,
        day DATE NOT NULL,
        kind VARCHAR(8) NOT NULL,
        path VARCHAR(160) NOT NULL,
        ref_host VARCHAR(120) NULL,
        source VARCHAR(60) NULL,
        event VARCHAR(30) NULL,
        label VARCHAR(120) NULL,
        device VARCHAR(8) NOT NULL,
        visitor CHAR(16) NOT NULL,
        INDEX (day), INDEX (kind, day), INDEX (visitor, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function jocate_now(): string { return gmdate('Y-m-d H:i:s'); }
