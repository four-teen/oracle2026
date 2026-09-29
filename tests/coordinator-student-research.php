<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
Env::load(dirname(__DIR__) . '/.env');
require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TitleSimilarity.php';
require dirname(__DIR__) . '/app/CoordinatorStudentResearch.php';

// Temporary tables shadow application tables for this connection only.
$pdo = Database::connection();
foreach ([
    'tblcampus' => 'campusid INT PRIMARY KEY',
    'tblcollege' => 'collegeid INT PRIMARY KEY, collegecampus VARCHAR(20)',
    'tblcourse' => 'courseid INT PRIMARY KEY, coursecollege VARCHAR(20)',
    'tblresearchtype' => 'researchtypeid INT PRIMARY KEY',
    'tblacademic_year' => 'ayid INT PRIMARY KEY',
    'tblaccount' => 'accountid INT PRIMARY KEY, campus INT, programid INT, acc_name VARCHAR(100), is_enabled INT',
    'tblresearches' => 'titleid INT AUTO_INCREMENT PRIMARY KEY, title TEXT, normalized_title TEXT, typeid INT, campusid INT,
        programid INT, ayid INT, status VARCHAR(30), authors TEXT, other_details TEXT, sdgs VARCHAR(100), encoder INT,
        adviser_name TEXT, adviser_accountid INT, panelists TEXT, panelist_accountids TEXT, statisticians TEXT,
        statistician_accountid INT, english_critic_name TEXT, english_critic_accountid INT, owner_accountid INT,
        is_published INT, similarity_refreshed_at DATETIME',
    'tblmanuscript_panelists' => 'manuscriptid INT, accountid INT, UNIQUE KEY(manuscriptid, accountid)',
    'tblmanuscript_coauthors' => 'manuscriptid INT, accountid INT',
] as $table => $columns) {
    $pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB');
}
$pdo->exec('INSERT INTO tblcampus VALUES (2), (3)');
$pdo->exec("INSERT INTO tblcollege VALUES (10, '2'), (20, '3')");
$pdo->exec("INSERT INTO tblcourse VALUES (100, '10'), (200, '20')");
$pdo->exec('INSERT INTO tblresearchtype VALUES (1)');
$pdo->exec('INSERT INTO tblacademic_year VALUES (1)');
$pdo->exec("INSERT INTO tblaccount VALUES (1,3,200,'Adviser',1), (2,2,100,'Panelist',1), (3,2,100,'Statistician',1), (4,2,100,'Critic',1), (5,2,100,'Disabled',0), (55,2,100,'Coordinator',1)");
$input = [
    'student_title' => 'Campus study', 'student_typeid' => 1, 'student_ayid' => 1,
    'student_status' => 'Proposal', 'adviser_accountid' => 1,
    'campusid' => 3, 'programid' => 100, 'student_other_details' => 'Research abstract',
    'student_sdgs' => [4, 9], 'student_researchers' => ['Student One', 'Student Two', 'student one'],
    'panelist_accountids' => [2, 2], 'statistician_accountid' => 3, 'english_critic_accountid' => 4,
];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (array $invalid) use ($pdo, $input, $assert): void {
    $before = $pdo->query('SELECT * FROM tblresearches ORDER BY titleid')->fetchAll();
    $panelsBefore = $pdo->query('SELECT * FROM tblmanuscript_panelists ORDER BY manuscriptid, accountid')->fetchAll();
    try {
        coordinator_student_save($pdo, 2, 55, array_replace($input, $invalid));
    } catch (RuntimeException $exception) {
        $assert(!$pdo->inTransaction(), 'Rejected save left an open transaction.');
        $assert($before === $pdo->query('SELECT * FROM tblresearches ORDER BY titleid')->fetchAll(), 'Rejected save changed research data.');
        $assert($panelsBefore === $pdo->query('SELECT * FROM tblmanuscript_panelists ORDER BY manuscriptid, accountid')->fetchAll(), 'Rejected save changed panelists.');
        return;
    }
    throw new RuntimeException('Invalid research was accepted.');
};

$saved = coordinator_student_save($pdo, 2, 55, $input);
$id = (int) $saved['titleId'];
$record = $pdo->query('SELECT * FROM tblresearches WHERE titleid = ' . $id)->fetch();
$assert((int) $record['campusid'] === 2 && (int) $record['encoder'] === 55, 'Client campus or adviser overrode coordinator scope.');
$assert($record['title'] === 'CAMPUS STUDY' && $record['authors'] === 'STUDENT ONE; STUDENT TWO', 'Research title or student rows were not saved correctly.');
$assert($record['sdgs'] === '4,9' && $record['panelist_accountids'] === '2', 'SDGs or panelist storage are incorrect.');
$assert((int) $record['adviser_accountid'] === 1 && $record['owner_accountid'] === null && (int) $record['is_published'] === 1, 'Adviser student-research assignment is incorrect.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM tblmanuscript_panelists')->fetchColumn() === 1, 'Panelists were not deduplicated.');
echo "PASS: complete research creation, student rows, SDGs, adviser ownership, and coordinator campus/encoder\n";

foreach ([['programid' => 200], ['student_researchers' => []], ['student_sdgs' => []], ['panelist_accountids' => [1]], ['panelist_accountids' => [5]], ['adviser_accountid' => 5], ['english_critic_accountid' => 0], ['student_ayid' => 999]] as $invalid) {
    $expectFailure($invalid);
}
echo "PASS: wrong-campus programs, missing fields, adviser/reviewer conflicts, and disabled accounts are rejected\n";

$pdo->exec("INSERT INTO tblresearches (titleid,title,campusid,adviser_accountid,authors) VALUES (900,'OTHER CAMPUS',3,1,'OTHER STUDENT')");
$expectFailure(['titleid' => 900]);
echo "PASS: coordinator cannot edit another campus record\n";

coordinator_student_save($pdo, 2, 55, array_replace($input, ['titleid' => $id, 'student_title' => 'Updated study', 'panelist_accountids' => [2, 4]]));
$record = $pdo->query('SELECT * FROM tblresearches WHERE titleid = ' . $id)->fetch();
$assert($record['title'] === 'UPDATED STUDY' && $record['panelist_accountids'] === '2,4', 'Edit did not save all form fields.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM tblmanuscript_panelists WHERE manuscriptid = ' . $id)->fetchColumn() === 2, 'Edit did not replace panelist relationships.');
echo "PASS: complete research editing and review-team relationships\n";
