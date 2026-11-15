<?php
// Weekly job (Hostinger cron, Mondays 06:00 EAT):
//   1. pull the latest rules the learning agent committed
//   2. run ETL with those rules
//   3. write the no-PII weekly report into reports/
//   4. commit and push to GitHub
// Usage: php ~/jocate-agent/server/bin/weekly.php [--no-git]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/etl.php';
require_once __DIR__ . '/../lib/report.php';

$useGit = !in_array('--no-git', $argv, true) && is_dir(JOCATE_HOME . '/.git');
$git = function (string $args) {
    $cmd = 'cd ' . escapeshellarg(JOCATE_HOME) . ' && git ' . $args . ' 2>&1';
    exec($cmd, $out, $code);
    jocate_log('weekly.log', "git $args -> $code " . implode(' | ', array_slice($out, -3)));
    return $code;
};

if ($useGit) $git('pull --rebase --autostash origin main');

$etl = jocate_etl('weekly');
$report = jocate_weekly_report();
$dir = JOCATE_HOME . '/reports';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$base = $dir . '/' . $report['week'];
file_put_contents($base . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($base . '.md', jocate_report_markdown($report));
file_put_contents($dir . '/LATEST.md', "Latest report: [{$report['week']}]({$report['week']}.md)\n");
echo "ETL: " . json_encode($etl) . "\nReport: {$base}.md\n";

if ($useGit) {
    $git('add reports');
    if ($git('diff --cached --quiet') !== 0) {
        $git('commit -m ' . escapeshellarg("Weekly data report {$report['week']} (rules v{$report['rules_version']})"));
        $code = $git('push origin main');
        echo $code === 0 ? "Pushed to GitHub.\n" : "Push failed: see ~/jocate-private/logs/weekly.log\n";
    } else {
        echo "No report changes to commit.\n";
    }
}
