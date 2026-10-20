<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/Env.php';
Env::load(dirname(__DIR__) . '/.env');
require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Database.php';

Database::ensureResearchMatchTables();
$pdo = Database::connection();
$count = (int) $pdo->query("SELECT COUNT(*) FROM tblresearches WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL")->fetchColumn();
echo 'Research Match collection tables are ready.' . PHP_EOL;
echo number_format($count) . ' titled manuscripts; a catalog evaluation will save '
    . number_format($count * max(0, $count - 1)) . ' directed comparisons.' . PHP_EOL;
echo 'No catalog evaluation was started.' . PHP_EOL;
