<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::check()) {
    redirect(app_link('administrator/'));
}

$authError = get_flash('auth_error');
$authSuccess = get_flash('auth_success');
$configIssues = configuration_issues();

$researchImages = [
    'assets/img/elements/1.jpg',
    'assets/img/elements/2.jpg',
    'assets/img/elements/3.jpg',
    'assets/img/elements/4.jpg',
    'assets/img/elements/5.jpg',
    'assets/img/elements/7.jpg',
    'assets/img/elements/11.jpg',
    'assets/img/elements/12.jpg',
    'assets/img/elements/13.jpg',
    'assets/img/elements/17.jpg',
    'assets/img/elements/18.jpg',
    'assets/img/elements/19.jpg',
    'assets/img/elements/20.jpg',
];

$initialResearchBatchSize = 12;

function landing_research_normalize_text(?string $value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

    return is_string($normalized) ? $normalized : '';
}

function landing_research_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function landing_research_trim(string $value, int $limit = 220): string
{
    $value = landing_research_normalize_text($value);

    if ($value === '' || strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(substr($value, 0, $limit - 3)) . '...';
}

function landing_research_authors_label(?string $authors): string
{
    $normalized = landing_research_normalize_text($authors);

    if ($normalized === '') {
        return 'Author information unavailable';
    }

    $parts = preg_split('/\s*;\s*/', $normalized) ?: [];
    $parts = array_values(array_filter(array_map('landing_research_normalize_text', $parts), static function ($part) {
        return $part !== '';
    }));

    return $parts !== [] ? implode(', ', $parts) : $normalized;
}

function landing_research_program_label(array $research): string
{
    $courseCode = landing_research_normalize_text($research['coursecode'] ?? '');
    $courseDescription = landing_research_normalize_text($research['coursedescription'] ?? '');
    $courseMajor = landing_research_normalize_text($research['coursemajor'] ?? '');

    $parts = [];

    if ($courseCode !== '') {
        $parts[] = $courseCode;
    }

    if ($courseDescription !== '') {
        $parts[] = $courseDescription;
    }

    $label = $parts !== [] ? implode(' - ', $parts) : '';

    if ($courseMajor !== '') {
        $label = $label !== '' ? $label . ' (' . $courseMajor . ')' : $courseMajor;
    }

    return $label !== '' ? $label : 'Program not set';
}

function landing_research_program_short_label(array $research): string
{
    $courseCode = landing_research_normalize_text($research['coursecode'] ?? '');

    if ($courseCode !== '') {
        return $courseCode;
    }

    $courseDescription = landing_research_normalize_text($research['coursedescription'] ?? '');

    return $courseDescription !== '' ? $courseDescription : 'This program';
}

function landing_research_adviser_label(array $research): string
{
    $adviser = landing_research_normalize_text($research['adviser_display'] ?? '');

    return $adviser !== '' ? $adviser : 'Adviser not assigned';
}

function landing_research_year(array $research): int
{
    foreach (['submitted_at', 'updated_at'] as $dateField) {
        $dateValue = landing_research_normalize_text($research[$dateField] ?? '');

        if ($dateValue === '') {
            continue;
        }

        $timestamp = strtotime($dateValue);

        if ($timestamp !== false) {
            return (int) date('Y', $timestamp);
        }
    }

    return (int) date('Y');
}

function landing_research_date_label(array $research): string
{
    foreach ([
        'submitted_at' => 'Submitted ',
        'updated_at' => 'Updated ',
    ] as $dateField => $prefix) {
        $dateValue = landing_research_normalize_text($research[$dateField] ?? '');

        if ($dateValue === '') {
            continue;
        }

        $timestamp = strtotime($dateValue);

        if ($timestamp !== false) {
            return $prefix . date('M j, Y', $timestamp);
        }
    }

    return 'Date unavailable';
}

function landing_research_static_views(string $title, int $year): int
{
    $seed = (int) sprintf('%u', crc32(strtolower($title) . '|' . (string) $year));

    return 120 + ($seed % 1380);
}

function landing_research_similarity_normalized_title(string $title): string
{
    $normalized = landing_research_lower(landing_research_normalize_text($title));
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);

    if (!is_string($normalized)) {
        return '';
    }

    $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

    return is_string($normalized) ? $normalized : '';
}

function landing_research_similarity_tokens(string $title): array
{
    static $stopWords = [
        'a' => true,
        'an' => true,
        'and' => true,
        'at' => true,
        'based' => true,
        'by' => true,
        'for' => true,
        'from' => true,
        'in' => true,
        'of' => true,
        'on' => true,
        'or' => true,
        'the' => true,
        'to' => true,
        'using' => true,
        'with' => true,
    ];

    $normalized = landing_research_similarity_normalized_title($title);

    if ($normalized === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $normalized) ?: [];
    $filtered = [];

    foreach ($tokens as $token) {
        $token = landing_research_normalize_text($token);

        if ($token === '' || strlen($token) <= 1 || isset($stopWords[$token])) {
            continue;
        }

        $filtered[] = $token;
    }

    return $filtered;
}

function landing_research_similarity_frequencies(array $tokens): array
{
    $frequencies = [];

    foreach ($tokens as $token) {
        if (!isset($frequencies[$token])) {
            $frequencies[$token] = 0;
        }

        $frequencies[$token]++;
    }

    return $frequencies;
}

function landing_research_jaccard_similarity(array $tokensA, array $tokensB): float
{
    $setA = array_fill_keys(array_values(array_unique($tokensA)), true);
    $setB = array_fill_keys(array_values(array_unique($tokensB)), true);
    $union = array_unique(array_merge(array_keys($setA), array_keys($setB)));
    $unionCount = count($union);

    if ($unionCount === 0) {
        return 0.0;
    }

    $intersectionCount = count(array_intersect(array_keys($setA), array_keys($setB)));

    return $intersectionCount / $unionCount;
}

function landing_research_dice_similarity(array $tokensA, array $tokensB): float
{
    $setA = array_values(array_unique($tokensA));
    $setB = array_values(array_unique($tokensB));
    $setTotal = count($setA) + count($setB);

    if ($setTotal === 0) {
        return 0.0;
    }

    $intersectionCount = count(array_intersect($setA, $setB));

    return (2 * $intersectionCount) / $setTotal;
}

function landing_research_cosine_similarity(array $frequenciesA, array $frequenciesB): float
{
    if ($frequenciesA === [] || $frequenciesB === []) {
        return 0.0;
    }

    $dotProduct = 0.0;
    $magnitudeA = 0.0;
    $magnitudeB = 0.0;

    foreach ($frequenciesA as $token => $count) {
        $magnitudeA += $count * $count;

        if (isset($frequenciesB[$token])) {
            $dotProduct += $count * $frequenciesB[$token];
        }
    }

    foreach ($frequenciesB as $count) {
        $magnitudeB += $count * $count;
    }

    $denominator = sqrt($magnitudeA) * sqrt($magnitudeB);

    if ($denominator <= 0.0) {
        return 0.0;
    }

    return $dotProduct / $denominator;
}

function landing_research_levenshtein_similarity(string $titleA, string $titleB): float
{
    if ($titleA === '' || $titleB === '') {
        return 0.0;
    }

    $maxLength = max(strlen($titleA), strlen($titleB));

    if ($maxLength === 0) {
        return 0.0;
    }

    $distance = levenshtein($titleA, $titleB);
    $score = 1 - (min($distance, $maxLength) / $maxLength);

    return max(0.0, min(1.0, $score));
}

function landing_research_hybrid_similarity(array $scores): float
{
    $weights = [
        'jaccard' => 0.20,
        'cosine' => 0.30,
        'levenshtein' => 0.20,
        'dice' => 0.30,
    ];

    $hybridScore = 0.0;

    foreach ($weights as $key => $weight) {
        $hybridScore += ((float) ($scores[$key] ?? 0.0)) * $weight;
    }

    return max(0.0, min(1.0, $hybridScore));
}

function landing_research_similarity_definitions(): array
{
    return [
        'hybrid' => [
            'label' => 'Hybrid',
            'threshold' => 0.56,
            'featured' => true,
        ],
        'jaccard' => [
            'label' => 'Jaccard',
            'threshold' => 0.34,
            'featured' => false,
        ],
        'cosine' => [
            'label' => 'Cosine',
            'threshold' => 0.50,
            'featured' => false,
        ],
        'levenshtein' => [
            'label' => 'Levenshtein',
            'threshold' => 0.68,
            'featured' => false,
        ],
        'dice' => [
            'label' => 'Sorensen-Dice',
            'threshold' => 0.44,
            'featured' => false,
        ],
    ];
}

function landing_research_similarity_percentage_label(float $score): string
{
    $score = max(0.0, min(1.0, $score));

    return (string) round($score * 100) . '%';
}

function landing_research_similarity_summary_from_totals(array $totals, int $titlePoolCount): array
{
    $definitions = landing_research_similarity_definitions();
    $algorithmCards = [];

    foreach ($definitions as $key => $definition) {
        $count = (int) ($totals['counts'][$key] ?? 0);
        $averageScore = $count > 0
            ? ((float) ($totals['matched_score_sums'][$key] ?? 0.0)) / $count
            : 0.0;
        $titleLabel = $count === 1 ? '1 title' : (string) $count . ' titles';

        $algorithmCards[] = [
            'label' => $definition['label'],
            'detail' => $titleLabel . ' | ' . landing_research_similarity_percentage_label($averageScore) . ' avg',
            'featured' => (bool) ($definition['featured'] ?? false),
        ];
    }

    if ($titlePoolCount <= 1) {
        return [
            'headline' => 'Only one indexed title is available for similarity comparison right now.',
            'note' => 'Similarity counts will appear once more titles are added to the live collection.',
            'algorithms' => $algorithmCards,
        ];
    }

    $hybridCount = (int) ($totals['counts']['hybrid'] ?? 0);
    $hybridAverage = $hybridCount > 0
        ? ((float) ($totals['matched_score_sums']['hybrid'] ?? 0.0)) / $hybridCount
        : 0.0;

    if ($hybridCount > 0) {
        return [
            'headline' => 'Hybrid scoring found ' . $hybridCount . ' related ' . ($hybridCount === 1 ? 'title' : 'titles') . ' in this live collection.',
            'note' => 'Average weighted probability: ' . landing_research_similarity_percentage_label($hybridAverage) . ' across Jaccard, Cosine, Levenshtein, and Sorensen-Dice.',
            'algorithms' => $algorithmCards,
        ];
    }

    return [
        'headline' => 'Hybrid scoring did not detect strong related-title matches yet.',
        'note' => 'Per-algorithm title probabilities are still shown below for the current live collection.',
        'algorithms' => $algorithmCards,
    ];
}

function landing_research_similarity_summary_empty(int $titlePoolCount): array
{
    $definitions = landing_research_similarity_definitions();
    $emptyTotals = [
        'counts' => array_fill_keys(array_keys($definitions), 0),
        'matched_score_sums' => array_fill_keys(array_keys($definitions), 0.0),
    ];

    return landing_research_similarity_summary_from_totals($emptyTotals, $titlePoolCount);
}

function landing_research_title_similarity_summaries(array $researchRows): array
{
    $definitions = landing_research_similarity_definitions();
    $preparedTitles = [];

    foreach ($researchRows as $index => $researchRow) {
        $title = landing_research_normalize_text($researchRow['title'] ?? '');

        if ($title === '') {
            continue;
        }

        $normalizedTitle = landing_research_similarity_normalized_title($title);
        $tokens = landing_research_similarity_tokens($title);

        $preparedTitles[$index] = [
            'normalized_title' => $normalizedTitle,
            'tokens' => $tokens,
            'frequencies' => landing_research_similarity_frequencies($tokens),
        ];
    }

    $titlePoolCount = count($preparedTitles);
    $totalsByIndex = [];

    foreach (array_keys($preparedTitles) as $index) {
        $totalsByIndex[$index] = [
            'counts' => array_fill_keys(array_keys($definitions), 0),
            'matched_score_sums' => array_fill_keys(array_keys($definitions), 0.0),
        ];
    }

    $preparedIndexes = array_keys($preparedTitles);
    $preparedCount = count($preparedIndexes);

    for ($outer = 0; $outer < $preparedCount; $outer++) {
        $leftIndex = $preparedIndexes[$outer];
        $leftTitle = $preparedTitles[$leftIndex];

        for ($inner = $outer + 1; $inner < $preparedCount; $inner++) {
            $rightIndex = $preparedIndexes[$inner];
            $rightTitle = $preparedTitles[$rightIndex];

            $scores = [
                'jaccard' => landing_research_jaccard_similarity($leftTitle['tokens'], $rightTitle['tokens']),
                'cosine' => landing_research_cosine_similarity($leftTitle['frequencies'], $rightTitle['frequencies']),
                'levenshtein' => landing_research_levenshtein_similarity($leftTitle['normalized_title'], $rightTitle['normalized_title']),
                'dice' => landing_research_dice_similarity($leftTitle['tokens'], $rightTitle['tokens']),
            ];
            $scores['hybrid'] = landing_research_hybrid_similarity($scores);

            foreach ($definitions as $key => $definition) {
                $threshold = (float) ($definition['threshold'] ?? 0.0);

                if (((float) ($scores[$key] ?? 0.0)) < $threshold) {
                    continue;
                }

                $totalsByIndex[$leftIndex]['counts'][$key]++;
                $totalsByIndex[$rightIndex]['counts'][$key]++;
                $totalsByIndex[$leftIndex]['matched_score_sums'][$key] += (float) $scores[$key];
                $totalsByIndex[$rightIndex]['matched_score_sums'][$key] += (float) $scores[$key];
            }
        }
    }

    $summaries = [];
    $defaultSummary = landing_research_similarity_summary_empty($titlePoolCount);

    foreach ($researchRows as $index => $researchRow) {
        if (!isset($totalsByIndex[$index])) {
            $summaries[$index] = $defaultSummary;
            continue;
        }

        $summaries[$index] = landing_research_similarity_summary_from_totals($totalsByIndex[$index], $titlePoolCount);
    }

    return $summaries;
}

function landing_research_sdg_labels(?string $sdgs): array
{
    $normalized = landing_research_normalize_text($sdgs);

    if ($normalized === '') {
        return [];
    }

    $labels = [];
    $parts = preg_split('/\s*,\s*/', $normalized) ?: [];

    foreach ($parts as $part) {
        $part = landing_research_normalize_text($part);

        if ($part === '') {
            continue;
        }

        $labels[] = stripos($part, 'SDG') === 0 ? $part : 'SDG ' . $part;
    }

    return array_values(array_unique($labels));
}

function landing_research_temporary_abstract(array $research): string
{
    $title = landing_research_normalize_text($research['title'] ?? 'This study');
    $programLabel = landing_research_program_label($research);
    $researchType = landing_research_normalize_text($research['research_type'] ?? '');
    $sdgLabels = landing_research_sdg_labels($research['sdgs'] ?? '');
    $researchTypeLabel = $researchType !== '' ? strtolower($researchType) : 'research';
    $programPhrase = $programLabel !== 'Program not set'
        ? 'under ' . $programLabel
        : 'within the current catalog record';
    $solutionLabel = 'digital solution';
    $solutionArticle = 'a';
    $lowerTitle = strtolower($title);

    if (strpos($lowerTitle, 'system') !== false) {
        $solutionLabel = 'system';
        $solutionArticle = 'a';
    } elseif (strpos($lowerTitle, 'application') !== false) {
        $solutionLabel = 'application';
        $solutionArticle = 'an';
    } elseif (strpos($lowerTitle, 'platform') !== false) {
        $solutionLabel = 'platform';
        $solutionArticle = 'a';
    }

    $abstract = 'This temporary abstract presents ' . $title . ' as a ' . $researchTypeLabel . ' project ' . $programPhrase . '. '
        . 'The study proposes ' . $solutionArticle . ' ' . $solutionLabel . ' that can improve the current workflow of its target users through faster transactions, organized records, and more accessible information management. '
        . 'It is intended to support day-to-day operations, reduce manual processing, and provide a practical basis for future testing, refinement, and deployment.';

    if ($sdgLabels !== []) {
        $abstract .= ' The proposed output is aligned with ' . implode(', ', $sdgLabels) . '.';
    }

    return landing_research_trim($abstract, 360);
}

$researchCatalog = [];
$landingDataError = null;

try {
    $pdo = Database::connection();
    $researchRows = $pdo->query(
        <<<'SQL'
SELECT m.titleid,
       m.title,
       m.authors,
       COALESCE(
           NULLIF(TRIM(m.adviser_name), ''),
           NULLIF(TRIM(a.acc_name), ''),
           NULLIF(TRIM(a.email), '')
       ) AS adviser_display,
       m.programid,
       p.coursecode,
       p.coursedescription,
       p.coursemajor,
       m.status,
       m.sdgs,
       m.other_details,
       rt.research_type,
       m.submitted_at,
       m.updated_at
FROM tblresearches m
LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
LEFT JOIN tblcourse p ON p.courseid = m.programid
LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL
ORDER BY COALESCE(m.submitted_at, m.updated_at) DESC, m.titleid DESC
SQL
    )->fetchAll();

    $titleSimilaritySummaries = landing_research_title_similarity_summaries($researchRows);

    foreach ($researchRows as $index => $researchRow) {
        $title = landing_research_normalize_text($researchRow['title'] ?? '');

        if ($title === '') {
            continue;
        }

        $authors = landing_research_authors_label($researchRow['authors'] ?? '');
        $adviser = landing_research_adviser_label($researchRow);
        $researchType = landing_research_normalize_text($researchRow['research_type'] ?? '');
        $status = landing_research_normalize_text($researchRow['status'] ?? '');
        $programLabel = landing_research_program_label($researchRow);
        $programShortLabel = landing_research_program_short_label($researchRow);
        $year = landing_research_year($researchRow);
        $dateLabel = landing_research_date_label($researchRow);
        $sdgLabels = landing_research_sdg_labels($researchRow['sdgs'] ?? '');
        $temporaryAbstract = landing_research_temporary_abstract($researchRow);
        $views = landing_research_static_views($title, $year);
        $similaritySummary = $titleSimilaritySummaries[$index] ?? landing_research_similarity_summary_empty(count($titleSimilaritySummaries));

        $researchCatalog[] = [
            'titleid' => isset($researchRow['titleid']) ? (int) $researchRow['titleid'] : 0,
            'title' => $title,
            'authors' => $authors,
            'adviser' => $adviser,
            'lead_author' => $authors,
            'year' => $year,
            'domain' => $programLabel,
            'institution' => $researchType !== '' ? $researchType : 'Research record',
            'location' => $dateLabel,
            'type' => $researchType !== '' ? $researchType : 'Research record',
            'status' => $status !== '' ? $status : 'Status not set',
            'sdg_metric' => $sdgLabels !== [] ? implode(', ', $sdgLabels) : 'No SDG tags',
            'similarity_headline' => $similaritySummary['headline'],
            'similarity_note' => $similaritySummary['note'],
            'similarity_algorithms' => $similaritySummary['algorithms'],
            'summary' => $temporaryAbstract,
            'views' => $views,
            'image' => app_link($researchImages[$index % count($researchImages)]),
            'search' => strtolower(
                implode(
                    ' ',
                    [
                        $title,
                        $authors,
                        $adviser,
                        $programLabel,
                        $programShortLabel,
                        $researchType,
                        $status,
                        $dateLabel,
                        implode(' ', $sdgLabels),
                        $temporaryAbstract,
                    ]
                )
            ),
        ];
    }
} catch (Throwable $exception) {
    $landingDataError = 'Live research records are unavailable right now.';
}

$researchYears = array_values(
    array_unique(
        array_map(
            static function ($research) {
                return $research['year'];
            },
            $researchCatalog
        )
    )
);
rsort($researchYears);

$researchYearMin = $researchYears !== [] ? min($researchYears) : null;
$researchYearMax = $researchYears !== [] ? max($researchYears) : null;
$sidebarRecentYear = $researchYearMax ?? (int) date('Y');
$sidebarPreviousYear = $sidebarRecentYear - 1;
$sidebarYearRangeLabel = $researchYearMin !== null && $researchYearMax !== null
    ? (string) $researchYearMin . ' to ' . (string) $researchYearMax
    : 'Range unavailable';
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="<?= e(app_link('assets/')); ?>"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Oracle | Research Landing</title>
    <meta name="description" content="Oracle research landing page with Google access and catalog preview." />
    <link rel="icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.png')); ?>" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700&family=Space+Grotesk:wght@500;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/fonts/boxicons.css')); ?>" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/core.css')); ?>" class="template-customizer-core-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/theme-default.css')); ?>" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/css/demo.css')); ?>" />
    <script src="<?= e(app_link('assets/vendor/js/helpers.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/config.js')); ?>"></script>
    <style>
      :root {
        --landing-ink: #283027;
        --landing-ink-soft: #6d7469;
        --landing-accent: #7b8873;
        --landing-accent-soft: rgba(123, 136, 115, 0.12);
        --landing-border: rgba(40, 48, 39, 0.08);
        --landing-surface: rgba(252, 249, 243, 0.9);
        --landing-shadow: 0 24px 54px rgba(76, 71, 62, 0.08);
        --landing-shell-padding: clamp(1rem, 2.4vw, 3rem);
      }

      body.landing-page {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        background:
          radial-gradient(circle at top left, rgba(168, 176, 151, 0.22), transparent 34%),
          radial-gradient(circle at top right, rgba(214, 206, 193, 0.35), transparent 30%),
          linear-gradient(180deg, #f6f2ea 0%, #efe9e0 52%, #faf7f1 100%);
        color: var(--landing-ink);
      }

      .landing-page .container-xxl {
        max-width: none;
        padding-left: var(--landing-shell-padding);
        padding-right: var(--landing-shell-padding);
      }

      body.landing-page::before {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        background-image: linear-gradient(rgba(88, 81, 69, 0.02) 1px, transparent 1px),
          linear-gradient(90deg, rgba(88, 81, 69, 0.02) 1px, transparent 1px);
        background-size: 48px 48px;
        mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.6), transparent 85%);
        z-index: 0;
      }

      .landing-nav {
        position: sticky;
        top: 0;
        z-index: 1030;
        backdrop-filter: blur(18px);
        background: rgba(246, 242, 234, 0.88);
        border-bottom: 1px solid rgba(123, 136, 115, 0.12);
      }

      .landing-nav .nav-shell,
      .landing-main .content-shell,
      .landing-footer .footer-shell {
        position: relative;
        z-index: 1;
      }

      .nav-shell {
        min-height: 5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1.5rem;
      }

      .brand-lockup {
        display: inline-flex;
        align-items: center;
        gap: 0.9rem;
        text-decoration: none;
      }

      .brand-mark {
        width: 2.9rem;
        height: 2.9rem;
        border-radius: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #7b8873, #c2b5a2);
        color: #fff;
        box-shadow: 0 16px 30px rgba(123, 136, 115, 0.18);
        font-size: 1.3rem;
      }

      .brand-title {
        margin: 0;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: -0.03em;
        color: var(--landing-ink);
      }

      .brand-copy {
        margin: 0.1rem 0 0;
        color: var(--landing-ink-soft);
        font-size: 0.86rem;
      }

      .google-login-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        min-height: 3rem;
        padding: 0.75rem 1.2rem;
        border-radius: 999px;
        border: 1px solid rgba(40, 48, 39, 0.1);
        background: rgba(255, 252, 246, 0.96);
        color: #2f342d;
        box-shadow: 0 14px 24px rgba(76, 71, 62, 0.06);
        text-decoration: none;
        font-weight: 600;
      }

      .google-login-btn:hover {
        color: #2f342d;
        transform: translateY(-1px);
        background: rgba(255, 255, 255, 0.98);
      }

      .google-login-icon {
        width: 1.1rem;
        height: 1.1rem;
        flex: 0 0 auto;
      }

      .landing-main {
        flex: 1 0 auto;
        padding: 2rem 0 3rem;
      }

      .status-stack {
        display: grid;
        gap: 0.9rem;
        margin-bottom: 1.5rem;
      }

      .panel-card,
      .results-panel,
      .research-result,
      .empty-state {
        background: var(--landing-surface);
        border: 1px solid var(--landing-border);
        border-radius: 0;
        box-shadow: var(--landing-shadow);
        backdrop-filter: blur(18px);
      }

      .hero-badge,
      .metric-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        background: rgba(255, 252, 246, 0.76);
        border: 1px solid rgba(123, 136, 115, 0.14);
        color: var(--landing-ink);
        font-size: 0.78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .research-title {
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        letter-spacing: -0.04em;
      }

      .panel-card {
        padding: 1.5rem;
      }

      .sidebar-panel {
        position: sticky;
        top: 6.2rem;
      }

      .panel-title {
        margin: 0;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.3rem;
        letter-spacing: -0.03em;
      }

      .panel-copy {
        color: var(--landing-ink-soft);
      }

      .filter-label {
        display: inline-block;
        margin-bottom: 0.55rem;
        color: var(--landing-ink);
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .filter-control {
        min-height: 3.2rem;
        border-radius: 1rem;
        border: 1px solid rgba(40, 48, 39, 0.12);
        background: rgba(255, 252, 246, 0.96);
        color: var(--landing-ink);
      }

      .filter-control:focus {
        border-color: rgba(123, 136, 115, 0.45);
        box-shadow: 0 0 0 0.25rem rgba(123, 136, 115, 0.12);
      }

      .insight-list {
        display: grid;
        gap: 0.85rem;
        margin-top: 1.4rem;
      }

      .insight-item {
        padding: 0.95rem 1rem;
        border-radius: 0;
        background: rgba(255, 252, 246, 0.64);
        border: 1px solid rgba(40, 48, 39, 0.06);
      }

      .insight-label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        color: var(--landing-ink-soft);
      }

      .insight-value {
        margin-top: 0.35rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--landing-ink);
      }

      .results-panel {
        padding: 1rem;
        background: linear-gradient(180deg, rgba(244, 239, 231, 0.94), rgba(238, 233, 225, 0.98));
      }

      .results-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.35rem 0.45rem 1rem;
      }

      .results-note {
        max-width: 18rem;
        margin: 0;
        color: var(--landing-ink-soft);
        font-size: 0.92rem;
        text-align: right;
      }

      .research-count-copy {
        margin: 0.45rem 0 0;
        color: var(--landing-ink-soft);
      }

      .research-list {
        display: grid;
        gap: 1rem;
      }

      .scroll-sentinel {
        width: 100%;
        height: 1px;
      }

      .research-result {
        overflow: hidden;
        background: rgba(255, 252, 246, 0.94);
        transition: transform 0.22s ease, box-shadow 0.22s ease;
      }

      .research-result:hover {
        transform: translateY(-2px);
        box-shadow: 0 28px 56px rgba(76, 71, 62, 0.1);
      }

      .result-shell {
        display: flex;
        gap: 1.15rem;
        padding: 1.25rem;
        align-items: flex-start;
      }

      .result-visual {
        position: relative;
        flex: 0 0 74px;
      }

      .result-avatar {
        width: 74px;
        height: 74px;
        overflow: hidden;
        border-radius: 999px;
        border: 3px solid rgba(255, 255, 255, 0.88);
        background: #d9d2c6;
        box-shadow: 0 12px 24px rgba(76, 71, 62, 0.14);
      }

      .result-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }

      .result-avatar-badge {
        position: absolute;
        right: -0.1rem;
        bottom: -0.05rem;
        width: 1.7rem;
        height: 1.7rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 3px solid #fff;
        background: linear-gradient(135deg, #7b8873, #b8aa95);
        color: #fff;
        font-size: 0.82rem;
      }

      .result-main {
        display: flex;
        flex: 1 1 auto;
        min-width: 0;
        flex-direction: column;
        gap: 0.9rem;
      }

      .result-topbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
      }

      .result-heading {
        min-width: 0;
      }

      .research-author {
        margin: 0 0 0.2rem;
        color: #3c4738;
        font-size: 1.03rem;
        font-weight: 600;
      }

      .research-title {
        margin: 0;
        font-size: clamp(1.3rem, 2vw, 1.95rem);
        line-height: 1.1;
        color: #21261f;
      }

      .research-location {
        margin: 0.25rem 0 0;
        color: var(--landing-ink-soft);
        font-size: 0.96rem;
      }

      .research-metrics {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.9rem 1.3rem;
      }

      .metric-line {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        color: #6a7167;
        font-size: 0.98rem;
      }

      .metric-line strong {
        color: #30352d;
      }

      .metric-icon {
        width: 2.1rem;
        height: 2.1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 2px solid rgba(123, 136, 115, 0.2);
        color: var(--landing-accent);
        background: rgba(255, 252, 246, 0.9);
        font-size: 1.05rem;
      }

      .metric-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.38rem 0.82rem;
        border-radius: 999px;
        font-size: 0.86rem;
        font-weight: 600;
      }

      .metric-pill--violet {
        background: rgba(123, 136, 115, 0.12);
        color: #55624d;
      }

      .metric-pill--green {
        background: rgba(194, 181, 162, 0.24);
        color: #6b6458;
      }

      .research-summary {
        margin: 0;
        color: #535a51;
        font-size: 0.98rem;
      }

      .research-match {
        display: grid;
        gap: 0.7rem;
        padding: 1rem 1.05rem;
        border: 1px solid rgba(134, 176, 118, 0.24);
        border-radius: 1rem;
        background: linear-gradient(135deg, rgba(240, 253, 244, 0.92), rgba(255, 255, 255, 0.96));
      }

      .research-match-copy,
      .research-match-note {
        margin: 0;
      }

      .research-match-copy {
        color: #166534;
        font-size: 0.97rem;
        font-weight: 700;
        line-height: 1.5;
      }

      .research-match-note {
        color: #4b5563;
        font-size: 0.84rem;
        line-height: 1.55;
      }

      .research-match-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
      }

      .similarity-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.72rem;
        border: 1px solid rgba(187, 247, 208, 0.9);
        border-radius: 999px;
        background: #ffffff;
        color: #166534;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1.2;
      }

      .similarity-pill strong {
        color: #111827;
        font-weight: 700;
      }

      .similarity-pill--primary {
        border-color: #166534;
        background: #166534;
        color: #ffffff;
      }

      .similarity-pill--primary strong {
        color: #ffffff;
      }

      .research-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding-top: 0.95rem;
        border-top: 1px solid rgba(40, 48, 39, 0.08);
      }

      .research-affiliation {
        display: inline-flex;
        align-items: center;
        gap: 0.85rem;
      }

      .research-affiliation-mark {
        width: 2.9rem;
        height: 2.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.95rem;
        background: linear-gradient(135deg, rgba(123, 136, 115, 0.16), rgba(194, 181, 162, 0.22));
        color: var(--landing-accent);
        font-size: 1.2rem;
      }

      .research-domain {
        color: #2f372d;
        font-size: 1rem;
        font-weight: 700;
      }

      .result-action {
        min-width: 9.25rem;
        border-radius: 0.9rem;
        border: 1px solid rgba(123, 136, 115, 0.38);
        background: rgba(255, 252, 246, 0.96);
        color: #4f5c48;
        font-weight: 700;
      }

      .result-action:hover,
      .result-action:focus {
        color: #475240;
        background: rgba(123, 136, 115, 0.1);
        border-color: rgba(123, 136, 115, 0.55);
      }

      .empty-state {
        padding: 2rem;
        text-align: center;
      }

      .empty-state h5 {
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        letter-spacing: -0.03em;
      }

      .landing-footer {
        position: sticky;
        bottom: 0;
        z-index: 1020;
        margin-top: auto;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(49, 53, 46, 0.92);
        backdrop-filter: blur(20px);
      }

      .footer-shell {
        min-height: 4rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        color: rgba(244, 247, 251, 0.82);
        font-size: 0.92rem;
      }

      .footer-shell p {
        margin: 0;
      }

      body.landing-page {
        background: #ffffff;
      }

      body.landing-page::before {
        display: none;
      }

      .landing-nav {
        backdrop-filter: none;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
      }

      .nav-shell {
        min-height: 5.2rem;
      }

      .brand-mark {
        border-radius: 0.85rem;
        background: #15803d;
        box-shadow: none;
      }

      .brand-title {
        font-size: 1.2rem;
        letter-spacing: -0.02em;
      }

      .google-login-btn {
        min-height: 3.2rem;
        padding: 0.85rem 1.25rem;
        border-radius: 0.9rem;
        border-color: #d1d5db;
        background: #ffffff;
        box-shadow: none;
        font-size: 0.94rem;
      }

      .google-login-btn:hover {
        transform: none;
        background: #f9fafb;
      }

      .landing-main {
        padding: 1.4rem 0 2.5rem;
      }

      .catalog-toolbar {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding: 0 0 1.45rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .catalog-search-shell {
        flex: 1 1 auto;
        min-height: 4.35rem;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0 0.95rem 0 1.15rem;
        border: 1px solid #d1d5db;
        border-radius: 999px;
        background: #ffffff;
      }

      .catalog-search-icon {
        color: #4b5563;
        font-size: 1.45rem;
      }

      .catalog-search-input {
        flex: 1 1 auto;
        border: none;
        outline: none;
        background: transparent;
        color: #111827;
        font-size: 0.98rem;
      }

      .catalog-search-input::placeholder {
        color: #6b7280;
      }

      .catalog-search-clear {
        width: 2.8rem;
        height: 2.8rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 999px;
        background: transparent;
        color: #4b5563;
        font-size: 1.45rem;
      }

      .catalog-search-clear:hover,
      .catalog-search-clear:focus {
        background: #f3f4f6;
        color: #111827;
      }

      .catalog-toolbar-side {
        display: inline-flex;
        align-items: center;
        gap: 0.8rem;
        flex: 0 0 auto;
      }

      .catalog-year-label {
        margin: 0;
        color: #4b5563;
        font-size: 0.92rem;
        font-weight: 600;
      }

      .catalog-year-select {
        min-width: 10rem;
        min-height: 4.1rem;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        color: #111827;
        font-size: 0.94rem;
        box-shadow: none;
      }

      .catalog-year-select:focus {
        border-color: #15803d;
        box-shadow: 0 0 0 0.2rem rgba(21, 128, 61, 0.08);
      }

      .panel-card,
      .research-result,
      .empty-state {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 1.1rem;
        box-shadow: none;
        backdrop-filter: none;
      }

      .results-panel {
        padding: 0;
        background: transparent;
        border: none;
        box-shadow: none;
      }

      .hero-badge,
      .metric-chip {
        padding: 0;
        border: none;
        background: transparent;
        color: #111827;
        font-size: 1.02rem;
        font-weight: 700;
        text-transform: none;
        letter-spacing: -0.02em;
      }

      .sidebar-panel {
        top: 6rem;
        padding: 1.4rem;
      }

      .sidebar-heading {
        margin: 0 0 1rem;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.08rem;
        letter-spacing: -0.02em;
        color: #111827;
      }

      .insight-list {
        margin-top: 0;
        gap: 0.95rem;
      }

      .insight-item {
        padding: 1rem 1.05rem;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 1rem;
      }

      .insight-label {
        font-size: 0.82rem;
        text-transform: none;
        letter-spacing: 0;
        font-weight: 600;
      }

      .insight-value {
        font-size: 0.96rem;
        font-weight: 700;
      }

      .sidebar-copy {
        margin: 0 0 1rem;
        color: #4b5563;
        font-size: 0.92rem;
        line-height: 1.6;
      }

      .sidebar-group {
        display: grid;
        gap: 0.8rem;
      }

      .sidebar-option-list,
      .sidebar-checklist {
        display: grid;
        gap: 0.6rem;
      }

      .sidebar-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        padding: 0.78rem 0.88rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.9rem;
        background: #f9fafb;
        color: #374151;
        font-size: 0.9rem;
        font-weight: 600;
        line-height: 1.45;
      }

      .sidebar-option--active {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #166534;
      }

      .sidebar-option-hint {
        color: #6b7280;
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
      }

      .sidebar-check {
        display: flex;
        align-items: flex-start;
        gap: 0.7rem;
        color: #374151;
        font-size: 0.9rem;
        font-weight: 600;
        line-height: 1.5;
      }

      .sidebar-check i {
        width: 1.45rem;
        height: 1.45rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 1.45rem;
        margin-top: 0.1rem;
        border-radius: 0.45rem;
        background: #f0fdf4;
        color: #15803d;
        font-size: 0.95rem;
      }

      .sidebar-note {
        margin: 0;
        color: #6b7280;
        font-size: 0.84rem;
        line-height: 1.55;
      }

      .results-header {
        padding: 0 0 1rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .research-count-copy {
        margin-top: 0.35rem;
        font-size: 0.92rem;
      }

      .research-list {
        gap: 1rem;
      }

      .research-result {
        background: #ffffff;
        transition: none;
      }

      .research-result:hover {
        transform: none;
        box-shadow: none;
      }

      .research-result--focus {
        border-color: #86efac;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.12);
      }

      .result-shell {
        gap: 1.4rem;
        padding: 1.6rem;
      }

      .result-avatar {
        border: 1px solid #e5e7eb;
        background: #f3f4f6;
        box-shadow: none;
      }

      .result-avatar-badge {
        width: 1.85rem;
        height: 1.85rem;
        border: 2px solid #ffffff;
        background: #15803d;
        box-shadow: none;
        font-size: 0.92rem;
      }

      .research-author {
        font-size: 1.06rem;
        color: #374151;
      }

      .research-title {
        font-size: clamp(1.45rem, 1.8vw, 1.9rem);
        line-height: 1.15;
        letter-spacing: -0.03em;
      }

      .research-location {
        font-size: 0.92rem;
        color: #4b5563;
      }

      .research-metrics {
        gap: 1rem 1.2rem;
      }

      .metric-line {
        font-size: 0.92rem;
        color: #374151;
      }

      .metric-icon {
        width: 2.35rem;
        height: 2.35rem;
        border: 1px solid #d1d5db;
        background: #ffffff;
        color: #4b5563;
      }

      .metric-pill {
        padding: 0.5rem 0.95rem;
        border: 1px solid #bbf7d0;
        background: #f0fdf4;
        font-size: 0.88rem;
      }

      .metric-pill--green {
        color: #15803d;
      }

      .research-summary {
        font-size: 0.93rem;
        line-height: 1.72;
        color: #374151;
      }

      .research-match {
        display: grid;
        gap: 0.7rem;
        padding: 1rem 1.05rem;
        border: 1px solid rgba(134, 176, 118, 0.24);
        border-radius: 1rem;
        background: linear-gradient(135deg, rgba(240, 253, 244, 0.92), rgba(255, 255, 255, 0.96));
      }

      .research-footer {
        padding-top: 1.1rem;
        border-top: 1px solid #e5e7eb;
      }

      .research-affiliation-mark {
        background: #f3f4f6;
        color: #15803d;
      }

      .research-domain {
        font-size: 0.96rem;
        color: #111827;
      }

      .result-action {
        min-width: 10rem;
        min-height: 3.2rem;
        border-radius: 0.9rem;
        border: 1px solid #16a34a;
        background: #ffffff;
        color: #15803d;
        box-shadow: none;
        font-size: 0.92rem;
      }

      .result-action:hover,
      .result-action:focus {
        background: #f0fdf4;
        color: #166534;
        border-color: #16a34a;
      }

      .empty-state {
        padding: 2.5rem 2rem;
      }

      .ai-assistant-launcher {
        position: fixed;
        right: clamp(1rem, 2.8vw, 2rem);
        bottom: clamp(1rem, 2.8vw, 2rem);
        width: 5.5rem;
        height: 5.5rem;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        z-index: 1080;
      }

      .ai-assistant-launcher::before {
        content: '';
        position: absolute;
        inset: -0.45rem;
        border-radius: inherit;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.28), rgba(16, 185, 129, 0));
        animation: ai-launcher-pulse 2.8s ease-in-out infinite;
      }

      .ai-launcher-shell {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        border-radius: inherit;
        overflow: hidden;
        isolation: isolate;
        background: radial-gradient(circle at 30% 30%, #fef3c7 0%, #22c55e 42%, #0f766e 100%);
        box-shadow: 0 22px 42px rgba(16, 185, 129, 0.22);
      }

      .ai-launcher-shell::after {
        content: '';
        position: absolute;
        inset: 0.4rem;
        border-radius: inherit;
        border: 1px solid rgba(255, 255, 255, 0.42);
      }

      .ai-launcher-ring {
        position: absolute;
        inset: 0.55rem;
        border-radius: inherit;
        border: 1px solid rgba(255, 255, 255, 0.45);
        animation: ai-launcher-spin 9s linear infinite;
      }

      .ai-launcher-ring--alt {
        inset: 1rem;
        border-style: dashed;
        border-color: rgba(254, 243, 199, 0.76);
        animation-direction: reverse;
        animation-duration: 6s;
      }

      .ai-launcher-core {
        position: relative;
        z-index: 1;
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 0.08rem;
        color: #ffffff;
        text-shadow: 0 1px 8px rgba(0, 0, 0, 0.18);
      }

      .ai-launcher-label {
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
      }

      .ai-launcher-copy {
        font-size: 0.66rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
      }

      .ai-search-modal .modal-dialog {
        max-width: min(860px, calc(100vw - 1.75rem));
      }

      .ai-search-modal .modal-content {
        border: 1px solid #d1fae5;
        border-radius: 1.35rem;
        overflow: hidden;
        box-shadow: 0 28px 64px rgba(15, 23, 42, 0.16);
      }

      .ai-search-modal .modal-header {
        align-items: flex-start;
        padding: 1.35rem 1.5rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        background: linear-gradient(135deg, rgba(240, 253, 244, 0.98), rgba(236, 253, 245, 0.96));
      }

      .ai-search-modal .modal-title {
        margin: 0.8rem 0 0.35rem;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.35rem;
        letter-spacing: -0.03em;
        color: #111827;
      }

      .ai-modal-copy {
        margin: 0;
        color: #4b5563;
        font-size: 0.92rem;
        line-height: 1.6;
      }

      .ai-search-modal .modal-body {
        padding: 1.4rem 1.5rem 1.5rem;
        background: #fcfffd;
      }

      .ai-search-form {
        display: grid;
        gap: 1rem;
      }

      .ai-search-form .form-label {
        margin: 0;
        color: #1f2937;
        font-weight: 700;
      }

      .ai-search-prompt {
        min-height: 7.5rem;
        resize: vertical;
        border: 1px solid #d1d5db;
        border-radius: 1rem;
        padding: 1rem 1.05rem;
        color: #111827;
        background: #ffffff;
        box-shadow: none;
      }

      .ai-search-prompt:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.12);
      }

      .ai-search-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
      }

      .ai-search-hint {
        flex: 1 1 18rem;
        margin: 0;
        color: #4b5563;
        font-size: 0.88rem;
        line-height: 1.6;
      }

      .ai-search-submit {
        min-width: 12.5rem;
        min-height: 3.2rem;
        border-radius: 0.95rem;
        border: 0;
        background: linear-gradient(135deg, #059669, #16a34a);
        color: #ffffff;
        font-weight: 700;
        box-shadow: 0 16px 28px rgba(5, 150, 105, 0.18);
      }

      .ai-search-submit:hover,
      .ai-search-submit:focus {
        color: #ffffff;
        background: linear-gradient(135deg, #047857, #15803d);
      }

      .ai-search-submit:disabled {
        opacity: 0.7;
        cursor: wait;
      }

      .ai-search-status {
        margin-top: 1rem;
        padding: 0.95rem 1rem;
        border-radius: 1rem;
        border: 1px solid #d1fae5;
        background: #f0fdf4;
      }

      .ai-search-status--danger {
        border-color: #fecaca;
        background: #fef2f2;
      }

      .ai-search-status--neutral {
        border-color: #d1d5db;
        background: #f9fafb;
      }

      .ai-search-status--success {
        border-color: #a7f3d0;
        background: #ecfdf5;
      }

      .ai-search-status-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.9rem;
        flex-wrap: wrap;
      }

      .ai-search-status-copy,
      .ai-search-status-note {
        margin: 0;
      }

      .ai-search-status-copy {
        color: #111827;
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.55;
      }

      .ai-search-status-note {
        margin-top: 0.6rem;
        color: #4b5563;
        font-size: 0.84rem;
        line-height: 1.5;
      }

      .ai-search-status-badges {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
      }

      .ai-status-badge,
      .ai-search-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.72rem;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #374151;
        font-size: 0.76rem;
        font-weight: 700;
      }

      .ai-search-results {
        display: grid;
        gap: 0.9rem;
        margin-top: 1rem;
      }

      .ai-search-empty {
        padding: 1.3rem 1.1rem;
        border: 1px dashed #d1d5db;
        border-radius: 1rem;
        background: #ffffff;
        color: #4b5563;
        font-size: 0.92rem;
        text-align: center;
      }

      .ai-search-card {
        display: grid;
        gap: 0.85rem;
        padding: 1rem 1.05rem;
        border: 1px solid #e5e7eb;
        border-radius: 1rem;
        background: #ffffff;
      }

      .ai-search-card-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
      }

      .ai-search-card-kicker {
        margin: 0 0 0.35rem;
        color: #16a34a;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .ai-search-card-title {
        margin: 0;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.12rem;
        line-height: 1.35;
        color: #111827;
      }

      .ai-search-card-score {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 5.7rem;
        padding: 0.5rem 0.85rem;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        font-size: 0.86rem;
        font-weight: 800;
      }

      .ai-search-card-meta,
      .ai-search-card-chips {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
      }

      .ai-search-card-meta span {
        color: #4b5563;
        font-size: 0.84rem;
        line-height: 1.5;
      }

      .ai-search-card-reason,
      .ai-search-card-copy {
        margin: 0;
        color: #374151;
        line-height: 1.65;
      }

      .ai-search-card-reason {
        font-size: 0.88rem;
        font-weight: 700;
      }

      .ai-search-card-copy {
        font-size: 0.9rem;
      }

      .ai-search-card-actions {
        display: flex;
        justify-content: flex-end;
      }

      .ai-locate-btn {
        min-height: 2.85rem;
        border-radius: 0.85rem;
        border: 1px solid #16a34a;
        background: #f0fdf4;
        color: #166534;
        font-weight: 700;
      }

      .ai-locate-btn:hover,
      .ai-locate-btn:focus {
        color: #14532d;
        background: #dcfce7;
        border-color: #15803d;
      }

      @keyframes ai-launcher-pulse {
        0%,
        100% {
          transform: scale(0.92);
          opacity: 0.62;
        }

        50% {
          transform: scale(1.08);
          opacity: 1;
        }
      }

      @keyframes ai-launcher-spin {
        0% {
          transform: rotate(0deg);
        }

        100% {
          transform: rotate(360deg);
        }
      }

      .landing-footer {
        position: static;
        border-top: 1px solid #e5e7eb;
        background: #ffffff;
        backdrop-filter: none;
      }

      .footer-shell {
        min-height: 4.4rem;
        color: #4b5563;
        font-size: 0.9rem;
      }

      @media (max-width: 991.98px) {
        .catalog-toolbar {
          flex-direction: column;
          align-items: stretch;
        }

        .catalog-toolbar-side {
          width: 100%;
          justify-content: space-between;
        }

        .catalog-year-select {
          flex: 1 1 auto;
        }

        .sidebar-panel {
          position: static;
        }

        .results-note {
          max-width: none;
          text-align: left;
        }

        .result-shell,
        .result-topbar,
        .research-footer {
          flex-direction: column;
        }

        .result-action {
          width: 100%;
        }

        .nav-shell,
        .footer-shell {
          flex-direction: column;
          align-items: flex-start;
          justify-content: center;
          padding: 1rem 0;
        }

        .google-login-btn {
          width: 100%;
        }
      }

      @media (max-width: 575.98px) {
        .landing-main {
          padding-top: 1.25rem;
        }

        .catalog-search-shell,
        .catalog-year-select {
          min-height: 3.6rem;
        }

        .catalog-toolbar-side {
          flex-direction: column;
          align-items: stretch;
        }

        .panel-card,
        .results-panel,
        .research-result,
        .empty-state {
          border-radius: 1rem;
        }

        .result-shell {
          padding: 1rem;
        }

        .research-title {
          font-size: 1.35rem;
        }

        .ai-assistant-launcher {
          width: 4.8rem;
          height: 4.8rem;
          bottom: 1rem;
          right: 1rem;
        }

        .ai-search-modal .modal-header,
        .ai-search-modal .modal-body {
          padding-left: 1rem;
          padding-right: 1rem;
        }

        .ai-search-card-actions,
        .ai-search-toolbar {
          justify-content: stretch;
        }

        .ai-search-submit,
        .ai-locate-btn {
          width: 100%;
        }
      }
    </style>
  </head>
  <body class="landing-page">
    <nav class="landing-nav">
      <div class="container-xxl nav-shell">
        <a href="<?= e(app_link()); ?>" class="brand-lockup">
          <span class="brand-mark">
            <i class="bx bx-book-reader"></i>
          </span>
          <span>
            <span class="brand-title">Oracle Research</span>
          </span>
        </a>

        <a href="<?= e(app_link('auth/google.php')); ?>" class="google-login-btn">
          <svg class="google-login-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M21.805 12.232c0-.728-.065-1.428-.186-2.101H12.24v3.973h5.358a4.581 4.581 0 0 1-1.99 3.007v2.498h3.217c1.884-1.735 2.98-4.29 2.98-7.377Z" fill="#4285F4"></path>
            <path d="M12.24 22c2.688 0 4.94-.891 6.587-2.391l-3.217-2.498c-.891.597-2.03.949-3.37.949-2.594 0-4.79-1.752-5.575-4.109H3.34v2.576A9.942 9.942 0 0 0 12.24 22Z" fill="#34A853"></path>
            <path d="M6.665 13.951a5.966 5.966 0 0 1 0-3.803V7.572H3.34a9.942 9.942 0 0 0 0 8.955l3.325-2.576Z" fill="#FBBC05"></path>
            <path d="M12.24 6.04c1.462 0 2.775.503 3.808 1.491l2.854-2.854C17.175 3.066 14.923 2 12.24 2A9.942 9.942 0 0 0 3.34 7.572l3.325 2.576C7.45 7.792 9.646 6.04 12.24 6.04Z" fill="#EA4335"></path>
          </svg>
          <span>Continue with Google</span>
        </a>
      </div>
    </nav>

    <main class="landing-main">
      <div class="container-xxl content-shell">
<?php if ($authError !== null || $authSuccess !== null || $configIssues !== []): ?>
        <div class="status-stack">
<?php if ($authError !== null): ?>
          <div class="alert alert-danger mb-0" role="alert"><?= e($authError); ?></div>
<?php endif; ?>
<?php if ($authSuccess !== null): ?>
          <div class="alert alert-success mb-0" role="alert"><?= e($authSuccess); ?></div>
<?php endif; ?>
<?php if ($configIssues !== []): ?>
          <div class="alert alert-warning mb-0" role="alert">
            Complete <code>.env</code> before testing Google login: <?= e(implode(', ', $configIssues)); ?>.
          </div>
<?php endif; ?>
        </div>
<?php endif; ?>

        <section class="catalog-toolbar">
          <div class="catalog-search-shell">
            <label for="research-search" class="visually-hidden">Search research</label>
            <span class="catalog-search-icon" aria-hidden="true"><i class="bx bx-search"></i></span>
            <input
              id="research-search"
              type="search"
              class="catalog-search-input"
              placeholder="Search research titles, authors, advisers, and programs"
              autocomplete="off"
            />
            <button type="button" class="catalog-search-clear" id="search-clear" aria-label="Clear search">
              <i class="bx bx-x"></i>
            </button>
          </div>

          <div class="catalog-toolbar-side">
            <label for="research-year" class="catalog-year-label">Year</label>
            <select id="research-year" class="form-select catalog-year-select">
              <option value="">All years</option>
<?php foreach ($researchYears as $year): ?>
              <option value="<?= e((string) $year); ?>"><?= e((string) $year); ?></option>
<?php endforeach; ?>
            </select>
          </div>
        </section>

        <div class="row g-4">
          <div class="col-xl-3 col-lg-4">
            <aside class="panel-card sidebar-panel">
              <h2 class="sidebar-heading">Explore this collection</h2>
              <p class="sidebar-copy">Scholar-inspired browsing cues for the Oracle research catalog while keeping the current portal design intact.</p>

              <div class="insight-list">
                <section class="insight-item sidebar-group" aria-label="Publication window">
                  <div class="insight-label">Publication window</div>
                  <div class="sidebar-option-list">
                    <div class="sidebar-option sidebar-option--active">
                      <span>Any time</span>
                      <span class="sidebar-option-hint">Default</span>
                    </div>
                    <div class="sidebar-option">
                      <span>Since <?= e((string) $sidebarRecentYear); ?></span>
                    </div>
                    <div class="sidebar-option">
                      <span>Since <?= e((string) $sidebarPreviousYear); ?></span>
                    </div>
                    <div class="sidebar-option">
                      <span>Custom range</span>
                      <span class="sidebar-option-hint"><?= e($sidebarYearRangeLabel); ?></span>
                    </div>
                  </div>
                </section>

                <section class="insight-item sidebar-group" aria-label="Sort results">
                  <div class="insight-label">Sort results</div>
                  <div class="sidebar-option-list">
                    <div class="sidebar-option sidebar-option--active">
                      <span>Sort by relevance</span>
                    </div>
                    <div class="sidebar-option">
                      <span>Sort by latest upload</span>
                    </div>
                  </div>
                </section>

                <section class="insight-item sidebar-group" aria-label="Research focus">
                  <div class="insight-label">Research focus</div>
                  <div class="sidebar-option-list">
                    <div class="sidebar-option sidebar-option--active">
                      <span>All research records</span>
                    </div>
                    <div class="sidebar-option">
                      <span>Adviser-reviewed studies</span>
                    </div>
                    <div class="sidebar-option">
                      <span>SDG-tagged projects</span>
                    </div>
                  </div>
                </section>

                <section class="insight-item sidebar-group" aria-label="Catalog tools">
                  <div class="insight-label">Catalog tools</div>
                  <div class="sidebar-checklist">
                    <div class="sidebar-check">
                      <i class="bx bx-checkbox-checked"></i>
                      <span>Include citation-ready records in the browsing view</span>
                    </div>
                    <div class="sidebar-check">
                      <i class="bx bx-checkbox-checked"></i>
                      <span>Keep adviser-linked entries visible in the current result set</span>
                    </div>
                    <div class="sidebar-check">
                      <i class="bx bx-bell"></i>
                      <span>Create alerts for newly uploaded manuscripts and research updates</span>
                    </div>
                  </div>
                  <p class="sidebar-note"><?= e((string) count($researchCatalog)); ?> indexed records are currently available across <?= e($sidebarYearRangeLabel); ?>.</p>
                </section>
              </div>
            </aside>
          </div>

          <div class="col-xl-9 col-lg-8">
            <section class="results-panel">
              <div class="results-header">
                <div>
                  <span class="hero-badge"><i class="bx bx-collection"></i> Research List</span>
                  <p class="research-count-copy">Showing <span id="research-count"><?= e((string) min($initialResearchBatchSize, count($researchCatalog))); ?></span> of <?= e((string) count($researchCatalog)); ?> research items</p>
                </div>
              </div>

              <div class="research-list" id="research-grid">
<?php foreach ($researchCatalog as $researchIndex => $research): ?>
                <article
                  id="research-entry-<?= e((string) $research['titleid']); ?>"
                  class="research-result research-entry<?= $researchIndex >= $initialResearchBatchSize ? ' d-none' : ''; ?>"
                  data-search="<?= e($research['search']); ?>"
                  data-year="<?= e((string) $research['year']); ?>"
                  data-titleid="<?= e((string) $research['titleid']); ?>"
                >
                  <div class="result-shell">
                    <div class="result-visual">
                      <div class="result-avatar">
                        <img src="<?= e($research['image']); ?>" alt="<?= e($research['title']); ?>" loading="lazy" />
                      </div>
                      <span class="result-avatar-badge"><i class="bx bx-file"></i></span>
                    </div>

                    <div class="result-main">
                      <div class="result-topbar">
                        <div class="result-heading">
                          <p class="research-author"><?= e($research['lead_author']); ?></p>
                          <h3 class="research-title"><?= e($research['title']); ?></h3>
                          <p class="research-location"><?= e($research['institution']); ?> | <?= e($research['location']); ?></p>
                        </div>

                        <button type="button" class="btn result-action">View research</button>
                      </div>

                      <div class="research-metrics">
                        <span class="metric-line">
                          <span class="metric-icon"><i class="bx bx-calendar"></i></span>
                          <strong><?= e((string) $research['year']); ?></strong>
                        </span>
                        <span class="metric-line">
                          <span class="metric-icon"><i class="bx bx-book-content"></i></span>
                          <strong><?= e($research['type']); ?></strong>
                        </span>
                        <span class="metric-line">
                          <span class="metric-icon"><i class="bx bx-show"></i></span>
                          <strong><?= e(number_format((int) $research['views'])); ?></strong> views
                        </span>
                        <span class="metric-pill metric-pill--green">
                          <i class="bx bx-target-lock"></i> <?= e($research['sdg_metric']); ?>
                        </span>
                      </div>

                      <p class="research-summary"><?= e($research['summary']); ?></p>

                      <div class="research-match">
                        <p class="research-match-copy"><?= e($research['similarity_headline']); ?></p>
                        <p class="research-match-note"><?= e($research['similarity_note']); ?></p>
                        <div class="research-match-list">
<?php foreach ($research['similarity_algorithms'] as $algorithm): ?>
                          <span class="similarity-pill<?= !empty($algorithm['featured']) ? ' similarity-pill--primary' : ''; ?>">
                            <strong><?= e($algorithm['label']); ?></strong>
                            <span><?= e($algorithm['detail']); ?></span>
                          </span>
<?php endforeach; ?>
                        </div>
                      </div>

                      <div class="research-footer">
                        <div class="research-affiliation">
                          <span class="research-affiliation-mark"><i class="bx bx-buildings"></i></span>
                          <div>
                            <div class="insight-label">Adviser</div>
                            <div class="research-domain"><?= e($research['adviser']); ?></div>
                          </div>
                        </div>

                        <div>
                          <div class="insight-label">Research domain</div>
                          <div class="research-domain"><?= e($research['domain']); ?></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </article>
<?php endforeach; ?>
              </div>
              <div id="scroll-sentinel" class="scroll-sentinel<?= count($researchCatalog) <= $initialResearchBatchSize ? ' d-none' : ''; ?>" aria-hidden="true"></div>
            </section>

            <div id="empty-state" class="empty-state d-none mt-4">
              <h5 class="mb-2">No research matched the current filters</h5>
              <p class="mb-0 text-muted">Try another keyword or switch the year filter back to "All years".</p>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="landing-footer">
      <div class="container-xxl footer-shell">
        <p>Oracle Research Portal</p>
        <p>ORACLE 2026</p>
      </div>
    </footer>

    <button
      type="button"
      class="ai-assistant-launcher"
      data-bs-toggle="modal"
      data-bs-target="#aiResearchModal"
      aria-label="Open AI research search"
    >
      <span class="ai-launcher-shell" aria-hidden="true">
        <span class="ai-launcher-ring"></span>
        <span class="ai-launcher-ring ai-launcher-ring--alt"></span>
        <span class="ai-launcher-core">
          <span class="ai-launcher-label">AI</span>
          <span class="ai-launcher-copy">Match</span>
        </span>
      </span>
    </button>

    <div class="modal fade ai-search-modal" id="aiResearchModal" tabindex="-1" aria-labelledby="aiResearchModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <span class="hero-badge"><i class="bx bx-bot"></i> AI Research Match</span>
              <h5 class="modal-title" id="aiResearchModalLabel">Find the closest related research</h5>
              <p class="ai-modal-copy">Describe a topic, problem, or title idea and the assistant will search <code>tblresearches</code> for the closest matching studies.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form id="ai-research-form" class="ai-search-form">
              <label for="ai-research-query" class="form-label">What research are you looking for?</label>
              <textarea
                id="ai-research-query"
                class="form-control ai-search-prompt"
                placeholder="Example: mobile appointment system for healthcare services"
              ></textarea>
              <div class="ai-search-toolbar">
                <p class="ai-search-hint">Gemini reranks the closest catalog candidates when a key is available. If not, the portal still returns the best local similarity matches from the current research table.</p>
                <button type="submit" class="btn ai-search-submit" id="ai-search-submit">
                  <span class="ai-submit-default">Search related research</span>
                  <span class="ai-submit-loading d-none">Searching...</span>
                </button>
              </div>
            </form>

            <div id="ai-search-status" class="ai-search-status ai-search-status--neutral d-none" aria-live="polite"></div>
            <div id="ai-search-results" class="ai-search-results" aria-live="polite"></div>
          </div>
        </div>
      </div>
    </div>

    <script src="<?= e(app_link('assets/vendor/libs/jquery/jquery.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/libs/popper/popper.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/js/bootstrap.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/main.js')); ?>"></script>
    <script>
      (function () {
        const batchSize = <?= e((string) $initialResearchBatchSize); ?>;
        const searchInput = document.getElementById('research-search');
        const searchClearButton = document.getElementById('search-clear');
        const yearInput = document.getElementById('research-year');
        const countOutput = document.getElementById('research-count');
        const emptyState = document.getElementById('empty-state');
        const emptyStateMessage = emptyState ? emptyState.querySelector('p') : null;
        const researchEntries = Array.from(document.querySelectorAll('.research-entry'));
        const scrollSentinel = document.getElementById('scroll-sentinel');
        let visibleLimit = batchSize;
        let matchedCount = 0;

        if (!searchInput || !yearInput || !countOutput || !emptyState || researchEntries.length === 0) {
          return;
        }

        if (emptyStateMessage) {
          emptyStateMessage.textContent = 'Try another keyword or switch the year filter back to "All years".';
        }

        const applyFilters = () => {
          const query = searchInput.value.trim().toLowerCase();
          const selectedYear = yearInput.value;
          let matchedIndex = 0;
          let visibleCount = 0;

          researchEntries.forEach(entry => {
            const matchesQuery = query === '' || entry.dataset.search.includes(query);
            const matchesYear = selectedYear === '' || entry.dataset.year === selectedYear;
            const matchesFilters = matchesQuery && matchesYear;
            const visible = matchesFilters && matchedIndex < visibleLimit;

            entry.classList.toggle('d-none', !visible);

            if (matchesFilters) {
              matchedIndex += 1;
            }

            if (visible) {
              visibleCount += 1;
            }
          });

          matchedCount = matchedIndex;
          countOutput.textContent = String(visibleCount);
          emptyState.classList.toggle('d-none', matchedCount !== 0);

          if (scrollSentinel) {
            scrollSentinel.classList.toggle('d-none', matchedCount === 0 || visibleCount >= matchedCount);
          }
        };

        const resetAndApplyFilters = () => {
          visibleLimit = batchSize;
          applyFilters();
        };

        window.oracleLandingCatalog = {
          focusResearch(titleId, titleQuery) {
            const normalizedTitleId = String(titleId || '').trim();

            if (normalizedTitleId === '') {
              return false;
            }

            if (searchInput) {
              searchInput.value = String(titleQuery || '').trim();
            }

            if (yearInput) {
              yearInput.value = '';
            }

            visibleLimit = researchEntries.length;
            applyFilters();

            const targetEntry = document.querySelector('.research-entry[data-titleid="' + normalizedTitleId + '"]');

            if (!targetEntry || targetEntry.classList.contains('d-none')) {
              return false;
            }

            targetEntry.scrollIntoView({
              behavior: 'smooth',
              block: 'center',
            });
            targetEntry.classList.add('research-result--focus');

            window.setTimeout(() => {
              targetEntry.classList.remove('research-result--focus');
            }, 2200);

            return true;
          },
        };

        searchInput.addEventListener('input', resetAndApplyFilters);
        yearInput.addEventListener('change', resetAndApplyFilters);

        if (searchClearButton) {
          searchClearButton.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.focus();
            resetAndApplyFilters();
          });
        }

        if (scrollSentinel && 'IntersectionObserver' in window) {
          const observer = new IntersectionObserver(entries => {
            const isVisible = entries.some(entry => entry.isIntersecting);

            if (!isVisible || matchedCount <= visibleLimit) {
              return;
            }

            visibleLimit += batchSize;
            applyFilters();
          }, {
            rootMargin: '240px 0px',
          });

          observer.observe(scrollSentinel);
        }

        resetAndApplyFilters();
      })();

      (function () {
        const modalElement = document.getElementById('aiResearchModal');
        const form = document.getElementById('ai-research-form');
        const queryInput = document.getElementById('ai-research-query');
        const statusContainer = document.getElementById('ai-search-status');
        const resultsContainer = document.getElementById('ai-search-results');
        const submitButton = document.getElementById('ai-search-submit');
        const defaultText = submitButton ? submitButton.querySelector('.ai-submit-default') : null;
        const loadingText = submitButton ? submitButton.querySelector('.ai-submit-loading') : null;
        const landingSearchInput = document.getElementById('research-search');
        const aiSearchEndpoint = <?= json_encode(app_link('research_ai_search.php')); ?>;

        if (!modalElement || !form || !queryInput || !statusContainer || !resultsContainer || !submitButton || !defaultText || !loadingText) {
          return;
        }

        const escapeHtml = value => {
          const node = document.createElement('div');
          node.textContent = value == null ? '' : String(value);
          return node.innerHTML;
        };

        const setSubmitting = isSubmitting => {
          submitButton.disabled = isSubmitting;
          defaultText.classList.toggle('d-none', isSubmitting);
          loadingText.classList.toggle('d-none', !isSubmitting);
        };

        const renderPlaceholder = message => {
          resultsContainer.innerHTML = '<div class="ai-search-empty">' + escapeHtml(message) + '</div>';
        };

        const renderStatus = (message, tone, badges, note) => {
          const badgeMarkup = (badges || []).map(badge => {
            return '<span class="ai-status-badge">' + escapeHtml(badge) + '</span>';
          }).join('');

          statusContainer.className = 'ai-search-status ai-search-status--' + (tone || 'neutral');
          statusContainer.classList.remove('d-none');
          statusContainer.innerHTML =
            '<div class="ai-search-status-row">'
            + '<p class="ai-search-status-copy">' + escapeHtml(message || '') + '</p>'
            + '<div class="ai-search-status-badges">' + badgeMarkup + '</div>'
            + '</div>'
            + ((note || '') !== ''
              ? '<p class="ai-search-status-note">' + escapeHtml(note) + '</p>'
              : '');
        };

        const renderMatches = payload => {
          const matches = Array.isArray(payload && payload.matches) ? payload.matches : [];
          const badges = [
            payload && payload.used_gemini ? 'Gemini reranked' : 'Local similarity',
            matches.length + ' match' + (matches.length === 1 ? '' : 'es'),
          ];

          renderStatus(
            payload && payload.summary ? payload.summary : 'Closest research matches are ready.',
            payload && payload.used_gemini ? 'success' : 'neutral',
            badges,
            payload && payload.notice ? payload.notice : ''
          );

          if (matches.length === 0) {
            renderPlaceholder('No close research match was found for that prompt.');
            return;
          }

          resultsContainer.innerHTML = matches.map(match => {
            const keywords = Array.isArray(match.keywords) ? match.keywords : [];
            const chips = keywords.map(keyword => {
              return '<span class="ai-search-chip">' + escapeHtml(keyword) + '</span>';
            }).join('');
            const sourceChip = '<span class="ai-search-chip">' + escapeHtml(match.rank_source === 'gemini' ? 'AI-ranked' : 'Similarity-ranked') + '</span>';

            return ''
              + '<article class="ai-search-card">'
              + '  <div class="ai-search-card-top">'
              + '    <div>'
              + '      <p class="ai-search-card-kicker">' + escapeHtml((match.research_type || 'Research record') + ' - ' + (match.year || '')) + '</p>'
              + '      <h6 class="ai-search-card-title">' + escapeHtml(match.title || '') + '</h6>'
              + '    </div>'
              + '    <span class="ai-search-card-score">' + escapeHtml(match.score_label || '') + '</span>'
              + '  </div>'
              + '  <div class="ai-search-card-meta">'
              + '    <span><strong>Program:</strong> ' + escapeHtml(match.program || 'Program not set') + '</span>'
              + '    <span><strong>Date:</strong> ' + escapeHtml(match.date_label || 'Date unavailable') + '</span>'
              + '  </div>'
              + '  <div class="ai-search-card-meta">'
              + '    <span><strong>Authors:</strong> ' + escapeHtml(match.authors || 'Author information unavailable') + '</span>'
              + '    <span><strong>Adviser:</strong> ' + escapeHtml(match.adviser || 'Adviser not assigned') + '</span>'
              + '  </div>'
              + '  <p class="ai-search-card-reason">' + escapeHtml(match.match_reason || '') + '</p>'
              + '  <p class="ai-search-card-copy">' + escapeHtml(match.summary || '') + '</p>'
              + '  <div class="ai-search-card-chips">' + sourceChip + chips + '</div>'
              + '  <div class="ai-search-card-actions">'
              + '    <button type="button" class="btn ai-locate-btn" data-ai-locate="1" data-titleid="' + escapeHtml(match.titleid || '') + '" data-title="' + escapeHtml(match.title || '') + '">Locate on page</button>'
              + '  </div>'
              + '</article>';
          }).join('');
        };

        modalElement.addEventListener('shown.bs.modal', () => {
          if (queryInput.value.trim() === '' && landingSearchInput && landingSearchInput.value.trim() !== '') {
            queryInput.value = landingSearchInput.value.trim();
            queryInput.select();
            return;
          }

          queryInput.focus();
        });

        resultsContainer.addEventListener('click', event => {
          const locateButton = event.target.closest('[data-ai-locate]');

          if (!locateButton) {
            return;
          }

          const titleId = locateButton.getAttribute('data-titleid') || '';
          const title = locateButton.getAttribute('data-title') || '';

          if (!window.oracleLandingCatalog || typeof window.oracleLandingCatalog.focusResearch !== 'function') {
            renderStatus('The matching research card could not be located from the landing page.', 'danger', ['Locator unavailable'], '');
            return;
          }

          const focused = window.oracleLandingCatalog.focusResearch(titleId, title);

          if (!focused) {
            renderStatus('The matching research card could not be located from the landing page.', 'danger', ['Locator unavailable'], '');
            return;
          }

          if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).hide();
          }
        });

        form.addEventListener('submit', async event => {
          event.preventDefault();

          const query = queryInput.value.trim();

          if (query === '') {
            renderStatus('Enter a research topic, title idea, or problem statement first.', 'danger', ['Input needed'], '');
            renderPlaceholder('Describe a topic and the assistant will search the catalog for the closest match.');
            queryInput.focus();
            return;
          }

          setSubmitting(true);
          renderStatus('Searching the research catalog for the closest related studies...', 'neutral', ['Searching'], '');
          renderPlaceholder('Checking titles, summaries, advisers, and program context...');

          try {
            const response = await fetch(aiSearchEndpoint, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              },
              body: new URLSearchParams({
                query,
                limit: '5',
              }),
            });

            const responseText = await response.text();
            let payload = null;

            try {
              payload = JSON.parse(responseText);
            } catch (error) {
              payload = null;
            }

            if (!response.ok || !payload) {
              throw new Error(payload && payload.message ? payload.message : 'Unable to search the research catalog right now.');
            }

            renderMatches(payload);
          } catch (error) {
            renderStatus(error && error.message ? error.message : 'Unable to search the research catalog right now.', 'danger', ['Search failed'], '');
            renderPlaceholder('Try another prompt or check the Gemini configuration in the environment file.');
          } finally {
            setSubmitting(false);
          }
        });

        renderPlaceholder('Describe a topic and the assistant will search the catalog for the closest related research.');
      })();
    </script>
  </body>
</html>
