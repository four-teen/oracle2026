<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/ResearchAnalytics.php';

// An isolated in-memory database exercises the shared queries without live data.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ([
    'tblcampus' => 'campusid INT PRIMARY KEY, campusname VARCHAR(100)',
    'tblaccount' => 'accountid INT PRIMARY KEY, acc_name VARCHAR(100)',
    'tblresearches' => 'titleid INT PRIMARY KEY, campusid INT, sdgs VARCHAR(100), updated_at DATETIME,
        adviser_accountid INT, statistician_accountid INT, english_critic_accountid INT',
    'tblmanuscript_panelists' => 'manuscriptid INT, accountid INT',
] as $table => $columns) {
    $pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ')');
}
$pdo->exec("INSERT INTO tblcampus VALUES (2,'Main'),(3,'Other')");
$pdo->exec("INSERT INTO tblaccount VALUES (10,'Shared Name'),(11,'Shared Name'),(12,'Reviewer')");
$pdo->exec("INSERT INTO tblresearches VALUES
    (1,2,'4,4,9,99,0,word','2026-01-01',10,10,12),
    (2,2,'9','2026-01-02',11,NULL,12),
    (3,2,'99,word','2026-01-03',NULL,NULL,NULL),
    (4,3,'1','2026-01-04',10,10,10)");
$pdo->exec('INSERT INTO tblmanuscript_panelists VALUES (1,10),(1,10),(1,11),(2,12),(4,10),(3,999)');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$sdgs = ResearchAnalytics::sdgs($pdo, 2);
$assert($sdgs['total'] === 3 && $sdgs['aligned'] === 2 && $sdgs['untagged'] === 1, 'SDG coverage or campus isolation is incorrect.');
$assert($sdgs['counts'][4] === 1 && $sdgs['counts'][9] === 2 && $sdgs['counts'][1] === 0, 'Repeated, invalid, or other-campus SDG tags were counted.');
$assert($sdgs['represented'] === 2 && $sdgs['ranked'][0] === 9 && count($sdgs['counts']) === 17, 'SDG ranking or goal count is incorrect.');
echo "PASS: campus-scoped SDG counts, duplicate tags, invalid tags, coverage, and rankings\n";

$contributors = ResearchAnalytics::contributors($pdo, 2);
$roles = $contributors['roles'];
$assert(count($roles['advisers']['people']) === 2, 'Different accounts with the same name were merged.');
$assert($roles['panelists']['recorded_total'] === 2, 'Multiple panelists inflated manuscript coverage.');
$assert(array_sum(array_column($roles['panelists']['people'], 'total')) === 3, 'Panelist assignments were not deduplicated per manuscript.');
$assert($contributors['people'] === 3 && $contributors['assignments'] === 8, 'People were double-counted across roles or assignments are incorrect.');
$assert($roles['english-critics']['recorded_total'] === 2 && $roles['statisticians']['recorded_total'] === 1, 'Role coverage is incorrect.');
echo "PASS: account identity, distinct people, multi-role assignments, panelist deduplication, and manuscript coverage\n";

$assert(ResearchAnalytics::campus($pdo, 2, '3')['id'] === 3, 'A valid requested campus was ignored.');
$assert(ResearchAnalytics::campus($pdo, 2, '999')['id'] === 2, 'Invalid campus did not fall back to assigned campus.');
$assert(ResearchAnalytics::campus($pdo, 999, [])['id'] === 2, 'Invalid campus input did not fall back safely.');
$assert(ResearchAnalytics::summary($pdo, 2)['total'] === 3, 'Summary includes another campus.');
$assert(ResearchAnalytics::sdgs($pdo, 999)['aligned'] === 0 && ResearchAnalytics::contributors($pdo, 999)['people'] === 0, 'Empty campus statistics are incorrect.');
$assert(ResearchAnalytics::percentage(0, 0) === '0%' && ResearchAnalytics::percentage(2, 3) === '66.7%', 'Percentages are incorrect.');
echo "PASS: campus selection, invalid inputs, empty datasets, and zero-safe percentages\n";
