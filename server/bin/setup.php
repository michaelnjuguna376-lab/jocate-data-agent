<?php
// One-time setup and password changes. Run on the server:
//   php ~/jocate-agent/server/bin/setup.php                 create config (if missing) + print a new admin password
//   php ~/jocate-agent/server/bin/setup.php --password 'X'  set a specific admin password
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/bootstrap.php';

if (!is_dir(JOCATE_PRIVATE)) mkdir(JOCATE_PRIVATE, 0700, true);
if (!is_dir(JOCATE_PRIVATE . '/logs')) mkdir(JOCATE_PRIVATE . '/logs', 0700, true);
$file = JOCATE_PRIVATE . '/config.php';
$cfg = is_file($file) ? (require $file) : [];
if (empty($cfg['ip_salt'])) $cfg['ip_salt'] = bin2hex(random_bytes(16));

$i = array_search('--password', $argv, true);
$password = $i !== false && isset($argv[$i + 1]) ? $argv[$i + 1] : null;
if ($password === null && empty($cfg['admin_password_hash'])) {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($n = 0; $n < 16; $n++) $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
if ($password !== null) {
    if (strlen($password) < 10) { fwrite(STDERR, "Password must be at least 10 characters.\n"); exit(1); }
    $cfg['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    echo "Admin password set: $password\n";
}
file_put_contents($file, "<?php\n// Jocate private config. Not in git, not in the web root.\nreturn " . var_export($cfg, true) . ";\n");
chmod($file, 0600);
jocate_db(); // creates tables if missing
echo "Config: $file\nDatabase tables ready.\n";
