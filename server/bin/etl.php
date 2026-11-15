<?php
// CLI: process new submissions now.   php ~/jocate-agent/server/bin/etl.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/etl.php';
echo json_encode(jocate_etl('cli'), JSON_PRETTY_PRINT), "\n";
