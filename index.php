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

    $programCounts = [];

    foreach ($researchRows as $researchRow) {
        $programKey = isset($researchRow['programid']) && (int) $researchRow['programid'] > 0
            ? 'program-' . (int) $researchRow['programid']
            : 'program-' . landing_research_program_label($researchRow);

        if (!isset($programCounts[$programKey])) {
            $programCounts[$programKey] = 0;
        }

        $programCounts[$programKey]++;
    }

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
        $programKey = isset($researchRow['programid']) && (int) $researchRow['programid'] > 0
            ? 'program-' . (int) $researchRow['programid']
            : 'program-' . $programLabel;

        $researchCatalog[] = [
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
            'match_count' => $programCounts[$programKey] ?? 1,
            'related_label' => $programShortLabel !== '' ? $programShortLabel : 'This program',
            'summary' => $temporaryAbstract,
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
    <link rel="icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.ico')); ?>" />
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
        margin: 0;
        color: #5b6952;
        font-size: 0.98rem;
        font-weight: 600;
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
        font-size: 0.93rem;
        color: #15803d;
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
              <h2 class="sidebar-heading">Research overview</h2>

              <div class="insight-list">
                <div class="insight-item">
                  <div class="insight-label">Loaded entries</div>
                  <div class="insight-value"><?= e((string) count($researchCatalog)); ?> research records</div>
                </div>
                <div class="insight-item">
                  <div class="insight-label">Access flow</div>
                  <div class="insight-value">Google authentication remains the active sign-in method</div>
                </div>
                <div class="insight-item">
                  <div class="insight-label">Year span</div>
                  <div class="insight-value">
                    <?= $researchYearMin !== null && $researchYearMax !== null ? e((string) $researchYearMin . ' to ' . (string) $researchYearMax) : 'Not available'; ?>
                  </div>
                </div>
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
                  class="research-result research-entry<?= $researchIndex >= $initialResearchBatchSize ? ' d-none' : ''; ?>"
                  data-search="<?= e($research['search']); ?>"
                  data-year="<?= e((string) $research['year']); ?>"
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
                        <span class="metric-pill metric-pill--green">
                          <i class="bx bx-target-lock"></i> <?= e($research['sdg_metric']); ?>
                        </span>
                      </div>

                      <p class="research-summary"><?= e($research['summary']); ?></p>

                      <p class="research-match">
                        <?= e($research['related_label']); ?> has <?= e((string) $research['match_count']); ?> related studies in this live collection.
                      </p>

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
    </script>
  </body>
</html>
