<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
Env::load(dirname(__DIR__) . '/.env');
require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/ResearchSimilarity.php';

function assertCollection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertScore(float $actual, float $expected, string $message): void
{
    assertCollection(abs($actual - $expected) < 1e-10, $message . ': ' . $actual . ' / ' . $expected);
}

$pdo = Database::connection();
// A disposable database exercises the real foreign keys and self-referencing cache-copy queries.
// No application rows are changed. The generated name is never supplied by a caller.
$originalDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'oracle_rm_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
$pdo->exec('USE `' . $testDatabase . '`');
$schema = file_get_contents(dirname(__DIR__) . '/sql/research_match_collection.sql');
foreach (explode(';', $schema) as $sql) {
    if (trim($sql) !== '') {
        $pdo->exec($sql);
    }
}
foreach ([
    'tblresearches' => 'titleid INT PRIMARY KEY, title TEXT, authors TEXT, other_details TEXT, sdgs TEXT, status TEXT,
        adviser_name TEXT, adviser_accountid INT, programid INT, typeid INT, submitted_at DATETIME, updated_at DATETIME,
        owner_accountid INT, is_published INT',
    'tblaccount' => 'accountid INT PRIMARY KEY, acc_name TEXT, email TEXT',
    'tblcourse' => 'courseid INT PRIMARY KEY, coursecode TEXT, coursedescription TEXT, coursemajor TEXT',
    'tblresearchtype' => 'researchtypeid INT PRIMARY KEY, research_type TEXT',
] as $table => $columns) {
    $pdo->exec('CREATE TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB');
}
$insert = $pdo->prepare('INSERT INTO tblresearches (titleid, title, other_details, owner_accountid, is_published, submitted_at) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'Solar energy', 'solar energy storage', null, 1, '2026-01-01'],
    [2, 'Solar energy storage', '<p>solar energy</p>', null, 1, '2026-01-02'],
    [3, 'zzzz', '', null, 1, '2026-01-03'],
    [4, 'Private solar energy', 'solar energy', 55, 0, '2026-01-04'],
] as $row) {
    $insert->execute($row);
}

$response = ResearchSimilarity::searchAndSave($pdo, 'solar energy', 5, hash('sha256', 'public-one'));
$runId = $response['collection']['run_id'];
$run = ResearchSimilarity::run($pdo, $runId);
assertCollection($run['source_kind'] === 'user_submitted' && $run['comparison_mode'] === 'query_to_record', 'Public source/mode are assigned by the server');
assertCollection((int) $run['catalog_count'] === 3 && (int) $run['result_count'] === count($response['matches']), 'Public catalog and saved result counts');
foreach (ResearchSimilarity::results($pdo, $runId, '', 1)['rows'] as $result) {
    assertCollection((int) $result['target_titleid'] !== 4, 'Private manuscripts must not enter public results');
    $match = array_column($response['matches'], null, 'titleid')[$result['target_titleid']];
    assertCollection($result['calculation_json'] === json_encode($match['score_breakdown'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Saved JSON must exactly match the displayed calculation');
}
$retry = ResearchSimilarity::searchAndSave($pdo, 'solar energy', 5, hash('sha256', 'public-one'));
assertCollection($retry['collection']['run_id'] === $runId, 'A retried request must not create a second run');
$cached = ResearchSimilarity::searchAndSave($pdo, 'solar energy', 5, hash('sha256', 'public-two'));
assertCollection($cached['collection']['run_id'] !== $runId, 'A distinct submission gets its own run');
assertCollection((int) ResearchSimilarity::run($pdo, $cached['collection']['run_id'])['cached_from_run_id'] === $runId, 'Identical computations reuse the recent cache');
$empty = ResearchSimilarity::searchAndSave($pdo, 'qqqqqq', 5, hash('sha256', 'public-empty'));
assertCollection($empty['matches'] === [] && $empty['collection']['saved'], 'A successful zero-match search must still be recorded');
echo "PASS: public persistence, exact calculation snapshots, visibility, zero matches, idempotency, and cache reuse\n";

$key = hash('sha256', 'batch-one');
$batch = ResearchSimilarity::startBatch($pdo, 7, $key);
$batchId = (int) $batch['batch_id'];
assertCollection((int) $batch['catalog_count'] === 4 && (int) $batch['expected_comparisons'] === 12, 'Catalog evaluation includes all titled manuscripts and excludes four self matches');
assertCollection((int) ResearchSimilarity::startBatch($pdo, 7, $key)['batch_id'] === $batchId, 'Start retries must reuse a batch');
$pdo->exec("UPDATE tblresearches SET title = 'Edited after snapshot', other_details = 'entirely changed abstract' WHERE titleid = 2");
ResearchSimilarity::setBatchStatus($pdo, $batchId, 'paused');
assertCollection((int) ResearchSimilarity::processBatch($pdo, $batchId)['processed_comparisons'] === 0, 'Paused evaluations do no work');
ResearchSimilarity::setBatchStatus($pdo, $batchId, 'running');

// Force an insertion conflict after one successful insert to verify transaction/cursor atomicity.
$runs = ResearchSimilarity::runList($pdo, ResearchSimilarity::filter(['batch' => $batchId]), 1)['rows'];
usort($runs, static function ($a, $b) { return $a['source_position'] <=> $b['source_position']; });
$sourceRun = $runs[0];
$targetDoc = $pdo->query('SELECT document_id FROM tblresearchmatch_batch_document WHERE batch_id = ' . $batchId . ' AND position = 2')->fetchColumn();
$pdo->prepare("INSERT INTO tblresearchmatch_result
    (run_id, target_document_id, basis, title_score, overall_score, calculation_json)
    VALUES (?, ?, 'title_only', 0, 0, '{}')")->execute([$sourceRun['run_id'], $targetDoc]);
try {
    ResearchSimilarity::processBatch($pdo, $batchId, 4);
    throw new RuntimeException('Expected insertion conflict');
} catch (PDOException $expected) {
    assertCollection((int) ResearchSimilarity::run($pdo, (int) $sourceRun['run_id'])['next_target_position'] === 0, 'Failure must roll back the cursor');
    assertCollection((int) ResearchSimilarity::batch($pdo, $batchId)['processed_comparisons'] === 0, 'Failure must roll back batch progress');
    assertCollection((int) $pdo->query('SELECT COUNT(*) FROM tblresearchmatch_result WHERE run_id = ' . (int) $sourceRun['run_id'])->fetchColumn() === 1, 'Partial result insert must be rolled back');
}
$pdo->exec('DELETE FROM tblresearchmatch_result WHERE run_id = ' . (int) $sourceRun['run_id']);
for ($attempt = 0; $attempt < 20; $attempt++) {
    $batch = ResearchSimilarity::processBatch($pdo, $batchId, 2);
    if ($batch['status'] === 'completed') {
        break;
    }
}
assertCollection($batch['status'] === 'completed' && (int) $batch['processed_comparisons'] === 12 && (int) $batch['completed_runs'] === 4, 'Resumed evaluation must complete exactly N*(N-1) results');
$reused = (int) $pdo->query('SELECT COUNT(*) FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id WHERE r.batch_id = ' . $batchId . ' AND x.reused_from_result_id IS NOT NULL')->fetchColumn();
assertCollection($reused === 6, 'Symmetric catalog comparisons should reuse exactly half the computations while storing both directions');
ResearchSimilarity::processBatch($pdo, $batchId);
foreach ($runs as $item) {
    $saved = ResearchSimilarity::run($pdo, (int) $item['run_id']);
    assertCollection($saved['source_kind'] === 'system_generated' && $saved['comparison_mode'] === 'record_to_record', 'Generated run labeling');
    $results = ResearchSimilarity::results($pdo, (int) $item['run_id'], '', 1)['rows'];
    assertCollection(count($results) === 3 && $saved['status'] === 'completed', 'Each source stores all three other records');
    assertCollection(array_map('intval', array_column($results, 'rank_order')) === [1, 2, 3], 'Final ranks must be complete and deterministic');
    foreach ($results as $result) {
        assertCollection((int) $result['target_titleid'] !== (int) $saved['source_titleid'], 'Self-match was stored');
        assertScore((float) $result['overall_score'], array_sum(array_column(json_decode($result['calculation_json'], true)['algorithms'], 'contribution')), 'Overall must equal saved contributions');
    }
}
$sourceResults = ResearchSimilarity::results($pdo, (int) $sourceRun['run_id'], '', 1)['rows'];
$pair = array_column($sourceResults, null, 'target_titleid')[2];
$pairDetail = ResearchSimilarity::detail($pdo, (int) $pair['result_id']);
assertCollection($pairDetail['target_title'] === 'Solar energy storage' && $pairDetail['target_abstract'] === 'solar energy', 'Batch must use the original frozen input text');
$calculation = json_decode($pairDetail['calculation_json'], true);
assertCollection($calculation['system_assessment']['source'] === 'system_generated', 'A calculated assessment must be stored independently of human reviews');
$titleValues = ['jaccard' => 2 / 3, 'cosine' => sqrt(2 / 3), 'levenshtein' => 12 / 20, 'dice' => 4 / 5];
foreach ($calculation['algorithms'] as $algorithm) {
    assertScore($algorithm['title_score'], $titleValues[$algorithm['key']], 'Catalog title-to-title formula');
    assertScore($algorithm['abstract_score'], $titleValues[$algorithm['key']], 'Catalog abstract-to-abstract formula');
}
$noAbstract = array_column($sourceResults, null, 'target_titleid')[3];
assertCollection($noAbstract['basis'] === 'title_only' && $noAbstract['abstract_score'] === null, 'Missing either abstract uses N/A and title-only weighting');
$newPublic = ResearchSimilarity::searchAndSave($pdo, 'solar energy', 5, hash('sha256', 'public-after-edit'));
assertCollection(ResearchSimilarity::run($pdo, $newPublic['collection']['run_id'])['cached_from_run_id'] === null, 'Catalog edits must invalidate the public calculation cache');
echo "PASS: complete catalog coverage, self exclusion, pause/resume, rollback, stable snapshots, rankings, and field-to-field formulas\n";

$autoFilter = ResearchSimilarity::filter(['batch' => $batchId]);
$autoBeforeReview = ResearchSimilarity::automatedSummary($pdo, $autoFilter);
$auto = array_values($autoBeforeReview)[0];
assertCollection($auto['comparisons'] === 12 && $auto['runs'] === 4, 'Automatic summaries must cover every completed source before any review');
assertCollection($auto['high'] + $auto['medium'] + $auto['low'] === 12, 'Every comparison must have one automatic score band');
$expectedSum = (float) $pdo->query('SELECT SUM(x.overall_score) FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id WHERE r.batch_id = ' . $batchId)->fetchColumn();
assertScore($auto['algorithms']['hybrid']['score_sum'], $expectedSum, 'Automatic summary must reproduce stored scores');
assertCollection(ResearchSimilarity::metrics($pdo, $autoFilter) === [], 'Automated assessments must not manufacture human validation metrics');

$resultId = (int) $pair['result_id'];
ResearchSimilarity::saveReview($pdo, $resultId, 7, 'Reviewer A', 'related', 'Relevant topic');
ResearchSimilarity::saveReview($pdo, $resultId, 8, 'Reviewer B', 'not_related', 'Different application');
$filter = ResearchSimilarity::filter(['batch' => $batchId]);
assertCollection(ResearchSimilarity::metrics($pdo, $filter) === [], 'Tied reviewer votes must be excluded');
ResearchSimilarity::saveReview($pdo, $resultId, 8, 'Reviewer B', 'highly_related', 'Changed after reading abstract');
assertCollection(count(ResearchSimilarity::reviews($pdo, $resultId)) === 2, 'Updating one reviewer must preserve other reviewers');
assertCollection(ResearchSimilarity::automatedSummary($pdo, $autoFilter) === $autoBeforeReview, 'Later reviews must not overwrite the system assessment');
$metric = array_values(ResearchSimilarity::metrics($pdo, $filter))[0];
assertCollection($metric['mode'] === 'record_to_record' && $metric['metrics']['hybrid']['count'] === 1, 'Metrics must use the saved mode and reviewed comparison');
assertCollection(ResearchSimilarity::results($pdo, (int) $sourceRun['run_id'], 'reviewed', 1)['total'] === 1, 'Reviewed result filter');
assertCollection(ResearchSimilarity::results($pdo, (int) $sourceRun['run_id'], 'pending', 1)['total'] === 2, 'Pending result filter');
assertCollection(ResearchSimilarity::runList($pdo, ResearchSimilarity::filter(['q' => 'Solar energy storage', 'source' => 'system_generated']), 1)['total'] === 1, 'Search filters use stored source snapshots');

$stream = fopen('php://temp', 'w+');
ResearchSimilarity::export($pdo, $filter, $stream);
rewind($stream);
$header = fgetcsv($stream);
$exportRows = [];
while (($values = fgetcsv($stream)) !== false) {
    assertCollection(count($values) === count($header), 'Export header must match every data row');
    $exportRows[] = array_combine($header, $values);
}
assertCollection(count($exportRows) === 12, 'Export must include all comparisons, not only leading results');
$exportPair = array_column($exportRows, null, 'result_id')[$resultId];
assertCollection($exportPair['source_kind'] === 'system_generated' && $exportPair['target_title'] === 'Solar energy storage', 'Export source distinction and snapshots');
assertCollection($exportPair['system_bucket'] === $calculation['system_assessment']['bucket'], 'Export must preserve the automatic score assessment');
assertCollection(count(json_decode($exportPair['reviews_json'], true)) === 2, 'Export includes independent reviewer labels');
assertScore((float) $exportPair['jaccard_title_score'], 2 / 3, 'Export score accuracy');
fclose($stream);
echo "PASS: independent expert reviews, consensus metrics, collection filters, and complete calculation exports\n";

// Exercise multiple export pages and prevent different input modes from entering one metric group.
$publicResult = ResearchSimilarity::results($pdo, $runId, '', 1)['rows'][0];
ResearchSimilarity::saveReview($pdo, (int) $publicResult['result_id'], 7, 'Reviewer A', 'related', 'Public search label');
assertCollection(count(ResearchSimilarity::metrics($pdo, ResearchSimilarity::filter([]))) === 2, 'Public and generated metrics must stay in different mode groups');
for ($id = 5; $id <= 18; $id++) {
    $insert->execute([$id, 'Research topic ' . $id, 'Shared abstract ' . $id, null, 1, '2026-01-01']);
}
$largeBatch = ResearchSimilarity::startBatch($pdo, 7, hash('sha256', 'export-pages'));
for ($attempt = 0; $attempt < 30 && $largeBatch['status'] !== 'completed'; $attempt++) {
    $largeBatch = ResearchSimilarity::processBatch($pdo, (int) $largeBatch['batch_id'], 40);
}
assertCollection((int) $largeBatch['processed_comparisons'] === 306, 'Larger fixture should create every directed pair');
$stream = fopen('php://temp', 'w+');
ResearchSimilarity::export($pdo, ResearchSimilarity::filter(['batch' => $largeBatch['batch_id']]), $stream);
rewind($stream);
fgetcsv($stream);
$exportCount = 0;
while (fgetcsv($stream) !== false) {
    $exportCount++;
}
assertCollection($exportCount === 306, 'Keyset export must cross its 250-row page boundary without loss or duplication');
fclose($stream);
for ($id = 19; $id <= 206; $id++) {
    $insert->execute([$id, 'Research topic ' . $id, 'Shared abstract ' . $id, null, 1, '2026-01-01']);
}
$rankBatch = ResearchSimilarity::startBatch($pdo, 7, hash('sha256', 'rank-pages'));
for ($attempt = 0; $attempt < 10 && (int) $rankBatch['completed_runs'] === 0; $attempt++) {
    $rankBatch = ResearchSimilarity::processBatch($pdo, (int) $rankBatch['batch_id'], 40);
}
assertCollection((int) $rankBatch['completed_runs'] === 1, 'First source should finish independently of the rest');
$rankRun = $pdo->query('SELECT run_id FROM tblresearchmatch_run WHERE batch_id = ' . (int) $rankBatch['batch_id'] . ' AND source_position = 0')->fetchColumn();
$ranks = $pdo->query('SELECT COUNT(*) AS total, COUNT(DISTINCT rank_order) AS distinct_ranks, MAX(rank_order) AS highest FROM tblresearchmatch_result WHERE run_id = ' . (int) $rankRun)->fetch();
assertCollection((int) $ranks['total'] === 205 && (int) $ranks['distinct_ranks'] === 205 && (int) $ranks['highest'] === 205, 'Rank updates must cross the 200-row chunk boundary');
$pdo->exec("UPDATE tblresearchmatch_batch SET algorithm_version = 'previous-version' WHERE batch_id = " . (int) $rankBatch['batch_id']);
try {
    ResearchSimilarity::processBatch($pdo, (int) $rankBatch['batch_id']);
    throw new RuntimeException('An old-version batch was resumed');
} catch (InvalidArgumentException $expected) {
    assertCollection(strpos($expected->getMessage(), 'version changed') !== false, 'Version guard must prevent mixing algorithms within a batch');
}
echo "PASS: separate evaluation modes, export pagination, rank pagination, and algorithm-version protection\n";

$repair = ResearchSimilarity::startBatch($pdo, 7, hash('sha256', 'php71-resume'));
$repairId = (int) $repair['batch_id'];
$pdo->exec("UPDATE tblresearchmatch_batch SET algorithm_version = 'research-match-1.0' WHERE batch_id = " . $repairId);
$pdo->exec("UPDATE tblresearchmatch_run SET algorithm_version = 'research-match-1.0' WHERE batch_id = " . $repairId);
$repaired = ResearchSimilarity::setBatchStatus($pdo, $repairId, 'running');
assertCollection($repaired['algorithm_version'] === ResearchAiSearch::ALGORITHM_VERSION && (int) $repaired['processed_comparisons'] === 0, 'An untouched PHP 7.1 failed batch must safely reuse its frozen snapshot');
assertCollection((int) $pdo->query("SELECT COUNT(*) FROM tblresearchmatch_run WHERE batch_id = " . $repairId . " AND algorithm_version <> '" . ResearchAiSearch::ALGORITHM_VERSION . "'")->fetchColumn() === 0, 'Repaired queued runs must use the current algorithm version consistently');
echo "PASS: automated reports without reviews, independent later reviews, assessment exports, and safe PHP 7.1 recovery\n";

$emptyTables = ['tblresearchmatch_review', 'tblresearchmatch_result', 'tblresearchmatch_run', 'tblresearchmatch_batch_document', 'tblresearchmatch_batch', 'tblresearchmatch_document'];
foreach ($emptyTables as $table) {
    // These tables are in the disposable database created above.
    $pdo->exec('DELETE FROM ' . $table);
}
$pdo->exec('DELETE FROM tblresearches');
$empty = ResearchSimilarity::searchAndSave($pdo, 'solar energy', 5, hash('sha256', 'empty-catalog'));
assertCollection($empty['collection']['saved'] && $empty['matches'] === [], 'Empty catalog search must be saved');
try {
    ResearchSimilarity::startBatch($pdo, 7, hash('sha256', 'empty-batch'));
    throw new RuntimeException('Empty batch was accepted');
} catch (InvalidArgumentException $expected) {
    // No meaningful catalog experiment can run with fewer than two documents.
}
echo "PASS: empty catalog handling\n";
} finally {
    $pdo->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    if (preg_match('/^oracle_rm_test_[a-f0-9]{12}$/D', $testDatabase)) {
        $pdo->exec('DROP DATABASE `' . $testDatabase . '`');
    }
}
echo "PASS: disposable test database removed; application data was untouched\n";
