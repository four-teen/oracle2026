<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
Env::load(dirname(__DIR__) . '/.env');
require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Database.php';

function executeMigration(PDO $pdo, string $sql): array
{
    $sql = preg_replace('/^\s*--[^\r\n]*$/m', '', $sql);
    $reports = [];
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) === '') continue;
        if (!preg_match('/^\s*(SELECT|EXECUTE)\b/i', $statement)) {
            $pdo->exec($statement);
            continue;
        }
        $query = $pdo->query($statement);
        do {
            if ($query->columnCount() > 0) $reports[] = $query->fetchAll();
        } while ($query->nextRowset());
        $query->closeCursor();
    }
    return $reports;
}

function validateMigration(array $reports): void
{
    $rows = end($reports);
    if (count($rows) !== 6) throw new RuntimeException('Expected six verification rows.');
    foreach ($rows as $row) {
        foreach (['table_status', 'column_status', 'index_status', 'foreign_key_status'] as $key) {
            if ($row[$key] !== 'OK') throw new RuntimeException('Schema issue: ' . json_encode($row));
        }
    }
}

$pdo = Database::connection();
// SQL-level PREPARE/EXECUTE must be sent as text commands, as phpMyAdmin does.
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$originalDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'oracle_rm_test_' . bin2hex(random_bytes(6));
$installer = file_get_contents(dirname(__DIR__) . '/sql/research_match_install_or_update.sql');
$verify = file_get_contents(dirname(__DIR__) . '/sql/research_match_verify.sql');
$pdo->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo->exec('USE `' . $testDatabase . '`');
    validateMigration(executeMigration($pdo, $installer));
    $pdo->exec("INSERT INTO tblresearchmatch_run (source_kind, comparison_mode, status, query_text, algorithm_version, catalog_hash, catalog_count)
        VALUES ('user_submitted', 'query_to_record', 'completed', 'Retain this search', 'research-match-1.1', REPEAT('a', 64), 0)");
    validateMigration(executeMigration($pdo, $installer));
    $pdo->exec('ALTER TABLE tblresearchmatch_run DROP COLUMN summary_json');
    $pdo->exec('ALTER TABLE tblresearchmatch_result DROP COLUMN reused_from_result_id');
    $old = executeMigration($pdo, $verify);
    $oldRows = array_column(end($old), null, 'table_name');
    if ($oldRows['tblresearchmatch_run']['column_status'] !== 'CHECK COLUMNS'
        || $oldRows['tblresearchmatch_result']['column_status'] !== 'CHECK COLUMNS') {
        throw new RuntimeException('Missing upgrade columns were not reported.');
    }
    validateMigration(executeMigration($pdo, $installer));
    validateMigration(executeMigration($pdo, $installer));
    if ($pdo->query('SELECT query_text FROM tblresearchmatch_run')->fetchColumn() !== 'Retain this search') {
        throw new RuntimeException('Existing search was not preserved.');
    }
    // Schema drift must be reported, rather than silently changing an existing definition.
    $pdo->exec('ALTER TABLE tblresearchmatch_run MODIFY summary_json TEXT NULL');
    $pdo->exec('ALTER TABLE tblresearchmatch_result DROP INDEX idx_rm_result_score');
    $pdo->exec('ALTER TABLE tblresearchmatch_review DROP FOREIGN KEY fk_rm_review_result');
    $drift = executeMigration($pdo, $verify);
    $driftRows = array_column(end($drift), null, 'table_name');
    if ($driftRows['tblresearchmatch_run']['column_status'] !== 'CHECK COLUMNS'
        || $driftRows['tblresearchmatch_result']['index_status'] !== 'CHECK INDEXES'
        || $driftRows['tblresearchmatch_review']['foreign_key_status'] !== 'CHECK FOREIGN KEYS') {
        throw new RuntimeException('Incompatible schema definitions were not reported.');
    }
    echo "PASS: fresh install, repeat imports, missing-column upgrade, record preservation, and detection of column/index/foreign-key mismatches.\n";
} finally {
    $pdo->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    if (preg_match('/^oracle_rm_test_[a-f0-9]{12}$/D', $testDatabase)) $pdo->exec('DROP DATABASE `' . $testDatabase . '`');
}
echo "PASS: disposable database removed; application records untouched.\n";
