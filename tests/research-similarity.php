<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/ResearchAiSearch.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function closeTo(float $actual, float $expected, string $message): void
{
    check(abs($actual - $expected) < 1e-10, $message . ': ' . $actual . ' versus ' . $expected);
}

function algorithms(array $result): array
{
    return array_column($result['algorithms'], null, 'key');
}

$exact = ResearchAiSearch::scoreResearch('Solar energy', 'SOLAR ENERGY', 'Solar energy');
closeTo($exact['overall_score'], 1.0, 'Identical title and abstract should score 100%');
foreach ($exact['algorithms'] as $algorithm) {
    closeTo($algorithm['title_score'], 1.0, 'Exact title must match in every algorithm');
    closeTo($algorithm['abstract_score'], 1.0, 'Exact abstract must match in every algorithm');
}

// These values follow from two shared terms out of three, and eight inserted characters.
$result = ResearchAiSearch::scoreResearch('solar energy', 'solar energy', 'solar energy storage');
$expectedAbstract = ['jaccard' => 2 / 3, 'cosine' => sqrt(2 / 3), 'levenshtein' => 12 / 20, 'dice' => 4 / 5];
$weights = ['jaccard' => .2, 'cosine' => .3, 'levenshtein' => .2, 'dice' => .3];
$expectedOverall = 0.0;
foreach (algorithms($result) as $key => $algorithm) {
    closeTo($algorithm['abstract_score'], $expectedAbstract[$key], $key . ' abstract calculation');
    closeTo($algorithm['weight'], $weights[$key], $key . ' algorithm weight');
    $combined = .7 + .3 * $expectedAbstract[$key];
    closeTo($algorithm['combined_score'], $combined, $key . ' 70/30 split');
    closeTo($algorithm['contribution'], $combined * $weights[$key], $key . ' contribution');
    $expectedOverall += $combined * $weights[$key];
}
closeTo($result['overall_score'], $expectedOverall, 'Overall must be the weighted average, without boosts');
check($result['overall_score'] < 1.0, 'Exact title must not bypass the abstract calculation');
echo "PASS: all four formulas, 70/30 field weights, 20/30/20/30 algorithm weights, and total\n";

foreach ([null, '', '   ', '<p>&nbsp;</p>', '!!!'] as $missingAbstract) {
    $result = ResearchAiSearch::scoreResearch('solar energy', 'solar energy', $missingAbstract);
    check($result['basis'] === 'title_only' && $result['abstract_score'] === null, 'Missing abstract must be N/A');
    closeTo($result['title_weight'], 1.0, 'Missing abstract must use 100% title');
    closeTo($result['overall_score'], 1.0, 'Missing abstract must not penalize the title score');
    foreach ($result['algorithms'] as $algorithm) {
        check($algorithm['abstract_score'] === null, 'Missing abstract must not be reported as zero similarity');
    }
}
$abstractOnly = ResearchAiSearch::scoreResearch('solar energy', 'zzzz', 'solar energy');
closeTo($abstractOnly['overall_score'], .3, 'An abstract-only match must contribute its 30% share');
echo "PASS: missing abstract fallback and abstract-only evidence\n";

$prefix = str_repeat('background text ', 30);
$tailMatch = algorithms(ResearchAiSearch::scoreResearch('quasar', 'astronomy', $prefix . 'quasar'));
$tailMismatch = algorithms(ResearchAiSearch::scoreResearch('quasar', 'astronomy', $prefix . 'zzzzzz'));
foreach ($tailMatch as $key => $algorithm) {
    check($algorithm['abstract_score'] > $tailMismatch[$key]['abstract_score'], $key . ' must include abstract text beyond the former excerpt');
}
$longPrefix = str_repeat('a', 300);
$longTitle = algorithms(ResearchAiSearch::scoreResearch($longPrefix . 'cccc', $longPrefix . 'bbbb'));
closeTo($longTitle['levenshtein']['title_score'], 300 / 304, 'Levenshtein must include characters beyond 255');
$html = ResearchAiSearch::scoreResearch('solar energy', 'SOLAR, ENERGY!', '<p>Solar</p><p>energy</p>');
closeTo($html['overall_score'], 1.0, 'Normalize HTML word boundaries, case, and punctuation');
echo "PASS: full abstract and title lengths, HTML and text normalization\n";

foreach (['', '!!!', 'the and of', str_repeat('x', 1001)] as $invalidQuery) {
    try {
        ResearchAiSearch::scoreResearch($invalidQuery, 'solar energy');
        throw new RuntimeException('Invalid search was accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected validation failure.
    }
}
echo "PASS: empty, meaningless, and oversized searches are rejected\n";

if (!in_array('--integration', $argv, true)) {
    exit(0);
}

require dirname(__DIR__) . '/app/Env.php';
Env::load(dirname(__DIR__) . '/.env');
require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Database.php';
$pdo = Database::connection();

// Connection-local temporary tables shadow live tables; no persistent records are changed.
foreach ([
    'tblresearches' => 'titleid INT PRIMARY KEY, title TEXT, authors TEXT, other_details TEXT, sdgs TEXT, status TEXT,
        adviser_name TEXT, adviser_accountid INT, programid INT, typeid INT, submitted_at DATETIME, updated_at DATETIME,
        owner_accountid INT, is_published INT',
    'tblaccount' => 'accountid INT PRIMARY KEY, acc_name TEXT, email TEXT',
    'tblcourse' => 'courseid INT PRIMARY KEY, coursecode TEXT, coursedescription TEXT, coursemajor TEXT',
    'tblresearchtype' => 'researchtypeid INT PRIMARY KEY, research_type TEXT',
] as $table => $columns) {
    $pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ')');
}
$insert = $pdo->prepare('INSERT INTO tblresearches (titleid, title, authors, other_details, adviser_name, owner_accountid, is_published, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'Solar energy', 'Author One', 'solar energy storage', 'Reviewer One', null, 1, '2026-01-01'],
    [2, 'Solar energy', 'Entirely Different Author', 'solar energy storage', 'Entirely Different Adviser', null, 1, '2026-01-01'],
    [3, 'Solar energy', '', null, '', null, 1, '2026-01-01'],
    [4, 'zzzz', 'solar energy', '', 'solar energy', null, 1, '2026-01-01'],
    [5, 'Solar energy', '', 'solar energy', '', 42, 0, '2026-01-01'],
    [6, 'Solar energy', '', 'solar energy', '', 42, 1, '2026-01-01'],
    [7, 'zzzz', '', 'solar energy', '', null, 1, '2026-01-01'],
] as $row) {
    $insert->execute($row);
}
$response = ResearchAiSearch::search($pdo, 'solar energy', 8);
$matches = array_column($response['matches'], null, 'titleid');
check($response['provider'] === 'local' && !$response['used_gemini'], 'The score must remain algorithm-only');
check(!isset($matches[5]), 'An unpublished faculty record must remain private');
check(!isset($matches[4]), 'Authors and advisers must not contribute to similarity');
check(isset($matches[6], $matches[7]), 'Published and abstract-only matches should be searchable');
closeTo($matches[1]['score'], $matches[2]['score'], 'Metadata must not change scores');
check(array_keys($matches) === [3, 6, 1, 2, 7], 'Results must sort by overall score with stable ties');
foreach ($matches as $match) {
    $sum = array_sum(array_column($match['score_breakdown']['algorithms'], 'contribution'));
    closeTo($match['score'], $sum, 'API score must equal its breakdown');
    check($match['score_label'] === number_format($sum * 100, 1) . '%', 'Display percentage must agree with contributions');
}
check(count(ResearchAiSearch::search($pdo, 'solar energy', 2)['matches']) === 2, 'Respect requested result limit');
check(ResearchAiSearch::search($pdo, 'qqqqqq', 5)['matches'] === [], 'No evidence must not produce arbitrary fallback results');
echo "PASS: search ranking, visibility, metadata exclusion, limits, and API score consistency\n";
