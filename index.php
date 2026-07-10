<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::check()) {
    Auth::requireLogin();
    $workspaceUrl = Auth::defaultWorkspaceUrl();

    if ($workspaceUrl !== app_link()) {
        redirect($workspaceUrl);
    }
}

$authError = get_flash('auth_error');
$authSuccess = get_flash('auth_success');
$configIssues = configuration_issues();

$researchAvatarPalettes = [
    ['accent' => '#facc15', 'soft' => '#fef3c7', 'icon' => 'bx-book-open'],
    ['accent' => '#38bdf8', 'soft' => '#e0f2fe', 'icon' => 'bx-book-reader'],
    ['accent' => '#a78bfa', 'soft' => '#ede9fe', 'icon' => 'bx-book-content'],
    ['accent' => '#fb7185', 'soft' => '#ffe4e6', 'icon' => 'bx-file-find'],
    ['accent' => '#34d399', 'soft' => '#d1fae5', 'icon' => 'bx-clipboard'],
    ['accent' => '#f97316', 'soft' => '#ffedd5', 'icon' => 'bx-bulb'],
    ['accent' => '#60a5fa', 'soft' => '#dbeafe', 'icon' => 'bx-data'],
    ['accent' => '#f59e0b', 'soft' => '#fef3c7', 'icon' => 'bx-line-chart'],
    ['accent' => '#2dd4bf', 'soft' => '#ccfbf1', 'icon' => 'bx-analyse'],
    ['accent' => '#c084fc', 'soft' => '#f3e8ff', 'icon' => 'bx-brain'],
    ['accent' => '#22c55e', 'soft' => '#dcfce7', 'icon' => 'bx-test-tube'],
    ['accent' => '#ef4444', 'soft' => '#fee2e2', 'icon' => 'bx-folder-open'],
    ['accent' => '#64748b', 'soft' => '#f1f5f9', 'icon' => 'bx-collection'],
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

function landing_research_abstract_summary(array $research): string
{
    $abstract = landing_research_normalize_text(strip_tags((string) ($research['other_details'] ?? '')));

    return $abstract !== '' ? landing_research_trim($abstract, 360) : '';
}

function landing_research_abstract_type_label(array $research): string
{
    $researchType = landing_research_normalize_text($research['research_type'] ?? '');
    $researchTypeLower = strtolower($researchType);

    if (strpos($researchTypeLower, 'capstone') !== false) {
        return 'Capstone Project';
    }

    if (strpos($researchTypeLower, 'thesis') !== false) {
        return 'Thesis';
    }

    return $researchType !== '' ? ucwords(strtolower($researchType)) : 'Research Record';
}

function landing_research_catalog_select_sql(): string
{
    return <<<'SQL'
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
SQL;
}

function landing_research_catalog_from_sql(): string
{
    return <<<'SQL'
FROM tblresearches m
LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
LEFT JOIN tblcourse p ON p.courseid = m.programid
LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL
SQL;
}

function landing_research_catalog_filters(string $query, int $year = 0, int $titleId = 0): array
{
    $conditions = [];
    $params = [];

    if ($titleId > 0) {
        $conditions[] = 'm.titleid = :titleid';
        $params[':titleid'] = [$titleId, PDO::PARAM_INT];
    }

    if ($year > 0) {
        $conditions[] = 'YEAR(COALESCE(m.submitted_at, m.updated_at)) = :research_year';
        $params[':research_year'] = [$year, PDO::PARAM_INT];
    }

    $query = landing_research_normalize_text($query);

    if ($query !== '') {
        $normalizedQuery = function_exists('mb_strtolower')
            ? mb_strtolower($query, 'UTF-8')
            : strtolower($query);
        $escapedQuery = '%' . addcslashes($normalizedQuery, "\\%_") . '%';
        $searchColumns = [
            "LOWER(COALESCE(m.title, ''))",
            "LOWER(COALESCE(m.authors, ''))",
            "LOWER(COALESCE(m.adviser_name, ''))",
            "LOWER(COALESCE(a.acc_name, ''))",
            "LOWER(COALESCE(a.email, ''))",
            "LOWER(COALESCE(p.coursecode, ''))",
            "LOWER(COALESCE(p.coursedescription, ''))",
            "LOWER(COALESCE(p.coursemajor, ''))",
            "LOWER(COALESCE(rt.research_type, ''))",
            "LOWER(COALESCE(m.status, ''))",
            "LOWER(COALESCE(m.sdgs, ''))",
            "LOWER(COALESCE(m.other_details, ''))",
        ];
        $searchConditions = [];

        foreach ($searchColumns as $index => $column) {
            $paramName = ':search' . (string) $index;
            $searchConditions[] = $column . " LIKE " . $paramName . " ESCAPE '\\\\'";
            $params[$paramName] = [$escapedQuery, PDO::PARAM_STR];
        }

        $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
    }

    return [
        $conditions === [] ? '' : "\nAND " . implode("\nAND ", $conditions),
        $params,
    ];
}

function landing_research_bind_params(PDOStatement $statement, array $params): void
{
    foreach ($params as $name => $param) {
        $statement->bindValue($name, $param[0], $param[1]);
    }
}

function landing_research_catalog_items(array $researchRows, array $researchAvatarPalettes, int $offset = 0): array
{
    $items = [];
    $paletteCount = count($researchAvatarPalettes);

    foreach ($researchRows as $index => $researchRow) {
        $title = landing_research_normalize_text($researchRow['title'] ?? '');

        if ($title === '') {
            continue;
        }

        $titleId = isset($researchRow['titleid']) ? (int) $researchRow['titleid'] : 0;
        $authors = landing_research_authors_label($researchRow['authors'] ?? '');
        $adviser = landing_research_adviser_label($researchRow);
        $researchType = landing_research_normalize_text($researchRow['research_type'] ?? '');
        $status = landing_research_normalize_text($researchRow['status'] ?? '');
        $programLabel = landing_research_program_label($researchRow);
        $year = landing_research_year($researchRow);
        $dateLabel = landing_research_date_label($researchRow);
        $sdgLabels = landing_research_sdg_labels($researchRow['sdgs'] ?? '');
        $abstractSummary = landing_research_abstract_summary($researchRow);
        $hasAbstract = $abstractSummary !== '';
        $views = landing_research_static_views($title, $year);
        $paletteIndex = $paletteCount > 0 ? ($offset + $index) % $paletteCount : 0;
        $avatarPalette = $paletteCount > 0
            ? $researchAvatarPalettes[$paletteIndex]
            : ['accent' => '#15803d', 'soft' => '#dcfce7', 'icon' => 'bx-book-open'];

        $items[] = [
            'titleid' => $titleId,
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
            'summary' => $hasAbstract
                ? $abstractSummary
                : 'Abstract is not available in this ' . landing_research_abstract_type_label($researchRow) . '.',
            'summary_is_placeholder' => !$hasAbstract,
            'views' => $views,
            'avatar_accent' => $avatarPalette['accent'],
            'avatar_soft' => $avatarPalette['soft'],
            'avatar_icon' => $avatarPalette['icon'],
        ];
    }

    return $items;
}

function landing_research_fetch_catalog_page(
    PDO $pdo,
    array $researchAvatarPalettes,
    int $limit,
    int $offset,
    string $query = '',
    int $year = 0
): array {
    $limit = min(max($limit, 1), 48);
    $offset = max($offset, 0);
    [$filterSql, $params] = landing_research_catalog_filters($query, $year);

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*) ' . landing_research_catalog_from_sql() . $filterSql
    );
    landing_research_bind_params($countStatement, $params);
    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();

    $statement = $pdo->prepare(
        landing_research_catalog_select_sql() . "\n" .
        landing_research_catalog_from_sql() .
        $filterSql .
        "\nORDER BY COALESCE(m.submitted_at, m.updated_at) DESC, m.titleid DESC
LIMIT :limit OFFSET :offset"
    );
    landing_research_bind_params($statement, $params);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $items = landing_research_catalog_items($statement->fetchAll(), $researchAvatarPalettes, $offset);

    return [
        'items' => $items,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => ($offset + count($items)) < $total,
    ];
}

function landing_research_fetch_catalog_item(PDO $pdo, array $researchAvatarPalettes, int $titleId): ?array
{
    if ($titleId < 1) {
        return null;
    }

    [$filterSql, $params] = landing_research_catalog_filters('', 0, $titleId);
    $statement = $pdo->prepare(
        landing_research_catalog_select_sql() . "\n" .
        landing_research_catalog_from_sql() .
        $filterSql .
        "\nLIMIT 1"
    );
    landing_research_bind_params($statement, $params);
    $statement->execute();

    $items = landing_research_catalog_items($statement->fetchAll(), $researchAvatarPalettes);

    return $items[0] ?? null;
}

function landing_research_fetch_years(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT YEAR(COALESCE(submitted_at, updated_at)) AS research_year
         FROM tblresearches
         WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL
         ORDER BY research_year DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $years = array_map('intval', $rows ?: []);
    $years = array_values(array_filter($years, static function (int $year): bool {
        return $year > 0;
    }));

    rsort($years);

    return $years;
}

if (($_GET['catalog'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo = Database::connection();
        $titleId = isset($_GET['title_id']) ? (int) $_GET['title_id'] : 0;

        if ($titleId > 0) {
            $item = landing_research_fetch_catalog_item($pdo, $researchAvatarPalettes, $titleId);
            echo json_encode([
                'items' => $item !== null ? [$item] : [],
                'total' => $item !== null ? 1 : 0,
                'offset' => 0,
                'limit' => 1,
                'has_more' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $payload = landing_research_fetch_catalog_page(
            $pdo,
            $researchAvatarPalettes,
            isset($_GET['limit']) ? (int) $_GET['limit'] : $initialResearchBatchSize,
            isset($_GET['offset']) ? (int) $_GET['offset'] : 0,
            (string) ($_GET['q'] ?? ''),
            isset($_GET['year']) ? (int) $_GET['year'] : 0
        );

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'message' => 'Live research records are unavailable right now.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

$researchCatalog = [];
$researchYears = [];
$researchTotalCount = 0;
$landingDataError = null;

try {
    $pdo = Database::connection();
    $researchYears = landing_research_fetch_years($pdo);
    $catalogPage = landing_research_fetch_catalog_page($pdo, $researchAvatarPalettes, $initialResearchBatchSize, 0);
    $researchCatalog = $catalogPage['items'];
    $researchTotalCount = (int) $catalogPage['total'];
} catch (Throwable $exception) {
    $landingDataError = 'Live research records are unavailable right now.';
}

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
        max-height: calc(100vh - 7.2rem);
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-color: #cbd5e1 transparent;
        scrollbar-width: thin;
      }

      .sidebar-panel::-webkit-scrollbar {
        width: 0.45rem;
      }

      .sidebar-panel::-webkit-scrollbar-track {
        background: transparent;
      }

      .sidebar-panel::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
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
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 3px solid rgba(255, 255, 255, 0.88);
        background: var(--research-avatar-soft, #dcfce7);
        color: var(--research-avatar-accent, #15803d);
        box-shadow: 0 12px 24px rgba(76, 71, 62, 0.14);
      }

      .result-avatar i {
        font-size: 2.1rem;
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

      .research-summary--empty {
        color: #6b7280;
        font-style: italic;
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

      .rdi-statement {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding: 0.72rem 0.95rem;
        border: 1px solid #bbf7d0;
        border-left: 4px solid #15803d;
        border-radius: 0.9rem;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfeff 100%);
        color: #374151;
      }

      .rdi-statement-icon {
        width: 2.2rem;
        height: 2.2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2.2rem;
        border-radius: 999px;
        background: #15803d;
        color: #ffffff;
        font-size: 1rem;
        box-shadow: 0 8px 18px rgba(21, 128, 61, 0.14);
      }

      .rdi-statement p {
        margin: 0;
        max-width: 76rem;
        font-size: 0.94rem;
        line-height: 1.45;
      }

      .rdi-statement strong {
        color: #14532d;
        font-weight: 800;
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
        max-height: calc(100vh - 7rem);
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
        margin: 0 0 1.1rem;
        padding-left: 0.85rem;
        border-left: 3px solid #d1d5db;
        color: #6b7280;
        font-size: 0.92rem;
        font-style: italic;
        font-weight: 400;
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
        background: var(--research-avatar-soft, #dcfce7);
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

      .research-summary--empty {
        color: #6b7280;
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
        width: 5.8rem;
        height: 5.8rem;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        z-index: 1080;
      }

      .ai-assistant-launcher::before {
        content: '';
        position: absolute;
        inset: -0.65rem;
        border-radius: inherit;
        background: radial-gradient(circle, rgba(45, 212, 191, 0.26), rgba(59, 130, 246, 0));
        animation: ai-launcher-glow 2.8s ease-in-out infinite;
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
        background: radial-gradient(circle at 34% 22%, #ecfeff 0%, #a5f3fc 42%, #0ea5e9 100%);
        border: 1px solid rgba(14, 165, 233, 0.22);
        box-shadow: 0 22px 42px rgba(14, 165, 233, 0.24);
      }

      .ai-launcher-shell::after {
        content: '';
        position: absolute;
        inset: 0.38rem;
        border-radius: inherit;
        border: 1px solid rgba(255, 255, 255, 0.64);
      }

      .ai-launcher-bot {
        position: absolute;
        inset: 0.6rem;
        z-index: 1;
        animation: ai-bot-float 2.4s ease-in-out infinite;
      }

      .ai-bot-bubble {
        position: absolute;
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 999px;
        background: linear-gradient(135deg, #67e8f9, #2563eb);
        box-shadow: inset 0 0 0 0.28rem rgba(255, 255, 255, 0.32);
        animation: ai-bubble-pop 2.8s ease-in-out infinite;
      }

      .ai-bot-bubble--left {
        left: 0.05rem;
        top: 1.1rem;
      }

      .ai-bot-bubble--right {
        right: 0.05rem;
        top: 1.2rem;
        animation-delay: 0.45s;
      }

      .ai-bot-head {
        position: absolute;
        left: 50%;
        top: 0.95rem;
        width: 3.4rem;
        height: 2.45rem;
        transform: translateX(-50%);
        border-radius: 1.35rem 1.35rem 1.05rem 1.05rem;
        background: linear-gradient(135deg, #bfdbfe, #eff6ff);
        box-shadow: inset 0 -0.45rem 0 rgba(14, 116, 144, 0.1);
      }

      .ai-bot-head::before {
        content: '';
        position: absolute;
        left: 50%;
        top: -0.55rem;
        width: 0.18rem;
        height: 0.8rem;
        transform: rotate(26deg);
        border-radius: 999px;
        background: #22d3ee;
      }

      .ai-bot-head::after {
        content: '';
        position: absolute;
        left: calc(50% + 0.28rem);
        top: -0.7rem;
        width: 0.44rem;
        height: 0.44rem;
        border-radius: 999px;
        background: #38bdf8;
        box-shadow: 0 0 0 0.16rem rgba(255, 255, 255, 0.72);
      }

      .ai-bot-face {
        position: absolute;
        left: 50%;
        top: 0.55rem;
        width: 2.35rem;
        height: 1.35rem;
        transform: translateX(-50%);
        border-radius: 999px;
        background: #075985;
      }

      .ai-bot-eye {
        position: absolute;
        top: 0.38rem;
        width: 0.28rem;
        height: 0.45rem;
        border-radius: 999px;
        background: #99f6e4;
        animation: ai-eye-blink 3.2s ease-in-out infinite;
      }

      .ai-bot-eye--left {
        left: 0.62rem;
      }

      .ai-bot-eye--right {
        right: 0.62rem;
      }

      .ai-bot-smile {
        position: absolute;
        left: 50%;
        bottom: 0.28rem;
        width: 0.84rem;
        height: 0.34rem;
        transform: translateX(-50%);
        border-radius: 0 0 999px 999px;
        background: #ffffff;
      }

      .ai-bot-body {
        position: absolute;
        left: 50%;
        bottom: 0.45rem;
        width: 2.1rem;
        height: 1.65rem;
        transform: translateX(-50%);
        border-radius: 0.95rem;
        background: linear-gradient(135deg, #dbeafe, #0e7490);
        box-shadow: inset 0 -0.32rem 0 rgba(15, 23, 42, 0.12);
      }

      .ai-bot-body::before {
        content: '';
        position: absolute;
        left: 50%;
        top: 0.45rem;
        width: 0.44rem;
        height: 0.44rem;
        transform: translateX(-50%);
        border-radius: 999px;
        background: #99f6e4;
      }

      .ai-bot-jet {
        position: absolute;
        left: 50%;
        bottom: -0.05rem;
        width: 0.72rem;
        height: 1.05rem;
        transform: translateX(-50%);
        border-radius: 999px;
        background: linear-gradient(180deg, #cffafe, #38bdf8);
        animation: ai-jet-pulse 0.9s ease-in-out infinite;
      }

      .ai-bot-arm {
        position: absolute;
        bottom: 1rem;
        width: 0.7rem;
        height: 1.25rem;
        border-radius: 999px;
        background: linear-gradient(180deg, #e0f2fe, #64748b);
      }

      .ai-bot-arm--left {
        left: 1.05rem;
        transform: rotate(42deg);
      }

      .ai-bot-arm--right {
        right: 1.05rem;
        transform: rotate(-42deg);
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

      .ai-search-loader {
        display: flex;
        align-items: center;
        gap: 0.95rem;
        padding: 1.15rem 1.2rem;
        border: 1px solid #bfdbfe;
        border-radius: 1rem;
        background: linear-gradient(135deg, #eff6ff, #ecfeff);
        color: #1f2937;
      }

      .ai-search-loader-spinner {
        width: 2.6rem;
        height: 2.6rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2.6rem;
        border-radius: 999px;
        background: #ffffff;
        box-shadow: inset 0 0 0 1px #bfdbfe;
      }

      .ai-search-loader-spinner::before {
        content: '';
        width: 1.45rem;
        height: 1.45rem;
        border-radius: 999px;
        border: 3px solid #bae6fd;
        border-top-color: #059669;
        animation: ai-loader-spin 0.78s linear infinite;
      }

      .ai-search-loader-copy {
        display: grid;
        gap: 0.2rem;
      }

      .ai-search-loader-title,
      .ai-search-loader-note {
        margin: 0;
      }

      .ai-search-loader-title {
        color: #111827;
        font-size: 0.95rem;
        font-weight: 700;
      }

      .ai-search-loader-note {
        color: #4b5563;
        font-size: 0.84rem;
        line-height: 1.5;
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

      @keyframes ai-launcher-glow {
        0%,
        100% {
          transform: scale(0.92);
          opacity: 0.48;
        }

        50% {
          transform: scale(1.08);
          opacity: 0.92;
        }
      }

      @keyframes ai-bot-float {
        0% {
          transform: translateY(0);
        }

        50% {
          transform: translateY(-0.18rem);
        }

        100% {
          transform: translateY(0);
        }
      }

      @keyframes ai-bubble-pop {
        0%,
        100% {
          transform: translateY(0) scale(0.88);
          opacity: 0.72;
        }

        50% {
          transform: translateY(-0.2rem) scale(1.08);
          opacity: 1;
        }
      }

      @keyframes ai-eye-blink {
        0%,
        84%,
        100% {
          transform: scaleY(1);
        }

        90% {
          transform: scaleY(0.18);
        }
      }

      @keyframes ai-jet-pulse {
        0%,
        100% {
          transform: translateX(-50%) scaleY(0.76);
          opacity: 0.74;
        }

        50% {
          transform: translateX(-50%) scaleY(1.08);
          opacity: 1;
        }
      }

      @keyframes ai-loader-spin {
        to {
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
        .sidebar-panel {
          position: static;
          max-height: none;
          overflow-y: visible;
          overscroll-behavior: auto;
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

        .rdi-statement {
          gap: 0.65rem;
          padding: 0.7rem 0.85rem;
        }

        .rdi-statement-icon {
          width: 2rem;
          height: 2rem;
          flex-basis: 2rem;
          font-size: 0.95rem;
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
            <i class="bx bx-network-chart"></i>
          </span>
          <span>
            <span class="brand-title">Oracle</span>
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
<?php if ($configIssues !== []): ?>
        <div class="status-stack">
          <div class="alert alert-warning mb-0" role="alert">
            Complete <code>.env</code> before testing Google login: <?= e(implode(', ', $configIssues)); ?>.
          </div>
        </div>
<?php endif; ?>

        <section class="rdi-statement" aria-label="Research and innovation statement">
          <span class="rdi-statement-icon" aria-hidden="true"><i class="bx bx-badge-check"></i></span>
          <p>
            <strong>This application is an official output of an approved research project</strong>
            funded by the Sultan Kudarat State University &ndash; Office of the Research, Development and
            Innovation (RDI), reflecting the University&rsquo;s commitment to transforming research into
            impactful digital solutions.
          </p>
        </section>

        <div class="row g-4">
          <div class="col-xl-3 col-lg-4">
            <aside class="panel-card sidebar-panel">
              <h2 class="sidebar-heading">Explore this collection</h2>
              <p class="sidebar-copy">Use these controls to narrow the research list by year, sort order, focus, and catalog tools.</p>

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
                  <p class="sidebar-note"><?= e((string) $researchTotalCount); ?> indexed records are currently available across <?= e($sidebarYearRangeLabel); ?>.</p>
                </section>
              </div>
            </aside>
          </div>

          <div class="col-xl-9 col-lg-8">
            <section class="results-panel">
              <div class="results-header">
                <div>
                  <span class="hero-badge"><i class="bx bx-collection"></i> Research List</span>
                  <p class="research-count-copy">Showing <span id="research-count"><?= e((string) count($researchCatalog)); ?></span> of <span id="research-total"><?= e((string) $researchTotalCount); ?></span> research items</p>
                </div>
              </div>

              <div class="research-list" id="research-grid">
<?php foreach ($researchCatalog as $research): ?>
                <article
                  id="research-entry-<?= e((string) $research['titleid']); ?>"
                  class="research-result research-entry"
                  data-year="<?= e((string) $research['year']); ?>"
                  data-titleid="<?= e((string) $research['titleid']); ?>"
                >
                  <div class="result-shell">
                    <div class="result-visual">
                      <div
                        class="result-avatar"
                        style="--research-avatar-accent: <?= e($research['avatar_accent']); ?>; --research-avatar-soft: <?= e($research['avatar_soft']); ?>;"
                        aria-hidden="true"
                      >
                        <i class="bx <?= e($research['avatar_icon']); ?>"></i>
                      </div>
                      <span class="result-avatar-badge"><i class="bx bx-search-alt"></i></span>
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

                      <p class="research-summary<?= !empty($research['summary_is_placeholder']) ? ' research-summary--empty' : ''; ?>"><?= e($research['summary']); ?></p>

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
              <div id="scroll-sentinel" class="scroll-sentinel<?= $researchTotalCount <= count($researchCatalog) ? ' d-none' : ''; ?>" aria-hidden="true"></div>
            </section>

            <div id="empty-state" class="empty-state d-none mt-4">
              <h5 class="mb-2">No research records found</h5>
              <p class="mb-0 text-muted">No research records are available in the catalog right now.</p>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="landing-footer">
      <div class="container-xxl footer-shell">
        <p>Oracle</p>
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
        <span class="ai-launcher-bot">
          <span class="ai-bot-bubble ai-bot-bubble--left"></span>
          <span class="ai-bot-bubble ai-bot-bubble--right"></span>
          <span class="ai-bot-head">
            <span class="ai-bot-face">
              <span class="ai-bot-eye ai-bot-eye--left"></span>
              <span class="ai-bot-eye ai-bot-eye--right"></span>
              <span class="ai-bot-smile"></span>
            </span>
          </span>
          <span class="ai-bot-arm ai-bot-arm--left"></span>
          <span class="ai-bot-arm ai-bot-arm--right"></span>
          <span class="ai-bot-body"></span>
          <span class="ai-bot-jet"></span>
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
              <p class="ai-modal-copy">Describe a topic, problem, or title idea and the assistant will search the research catalog for the closest matching studies.</p>
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
                <p class="ai-search-hint">Gemini reranks the closest catalog candidates when a key is available. If not, the portal still searches the approved research catalog using local similarity.</p>
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
        const catalogEndpoint = <?= json_encode(app_link('index.php')); ?>;
        const countOutput = document.getElementById('research-count');
        const totalOutput = document.getElementById('research-total');
        const emptyState = document.getElementById('empty-state');
        const emptyStateMessage = emptyState ? emptyState.querySelector('p') : null;
        const researchGrid = document.getElementById('research-grid');
        const scrollSentinel = document.getElementById('scroll-sentinel');
        let loadedCount = researchGrid ? researchGrid.querySelectorAll('.research-entry').length : 0;
        let nextOffset = loadedCount;
        let totalCount = <?= e((string) $researchTotalCount); ?>;
        let isLoading = false;
        let requestToken = 0;

        if (!countOutput || !totalOutput || !emptyState || !researchGrid) {
          return;
        }

        if (emptyStateMessage) {
          emptyStateMessage.textContent = 'No research records are available in the catalog right now.';
        }

        const escapeHtml = value => {
          const node = document.createElement('div');
          node.textContent = value == null ? '' : String(value);
          return node.innerHTML;
        };

        const formatNumber = value => {
          const numericValue = Number(value || 0);

          if (window.Intl && window.Intl.NumberFormat) {
            return new Intl.NumberFormat().format(numericValue);
          }

          return String(numericValue);
        };

        const renderResearch = research => {
          const title = escapeHtml(research.title || '');

          return ''
            + '<article id="research-entry-' + escapeHtml(research.titleid || '') + '" class="research-result research-entry" data-year="' + escapeHtml(research.year || '') + '" data-titleid="' + escapeHtml(research.titleid || '') + '">'
            + '  <div class="result-shell">'
            + '    <div class="result-visual">'
            + '      <div class="result-avatar" style="--research-avatar-accent: ' + escapeHtml(research.avatar_accent || '#15803d') + '; --research-avatar-soft: ' + escapeHtml(research.avatar_soft || '#dcfce7') + ';" aria-hidden="true">'
            + '        <i class="bx ' + escapeHtml(research.avatar_icon || 'bx-book-open') + '"></i>'
            + '      </div>'
            + '      <span class="result-avatar-badge"><i class="bx bx-search-alt"></i></span>'
            + '    </div>'
            + '    <div class="result-main">'
            + '      <div class="result-topbar">'
            + '        <div class="result-heading">'
            + '          <p class="research-author">' + escapeHtml(research.lead_author || 'Author information unavailable') + '</p>'
            + '          <h3 class="research-title">' + title + '</h3>'
            + '          <p class="research-location">' + escapeHtml((research.institution || 'Research record') + ' | ' + (research.location || 'Date unavailable')) + '</p>'
            + '        </div>'
            + '        <button type="button" class="btn result-action">View research</button>'
            + '      </div>'
            + '      <div class="research-metrics">'
            + '        <span class="metric-line"><span class="metric-icon"><i class="bx bx-calendar"></i></span><strong>' + escapeHtml(research.year || '') + '</strong></span>'
            + '        <span class="metric-line"><span class="metric-icon"><i class="bx bx-book-content"></i></span><strong>' + escapeHtml(research.type || 'Research record') + '</strong></span>'
            + '        <span class="metric-line"><span class="metric-icon"><i class="bx bx-show"></i></span><strong>' + formatNumber(research.views || 0) + '</strong> views</span>'
            + '        <span class="metric-pill metric-pill--green"><i class="bx bx-target-lock"></i> ' + escapeHtml(research.sdg_metric || 'No SDG tags') + '</span>'
            + '      </div>'
            + '      <p class="research-summary' + (research.summary_is_placeholder ? ' research-summary--empty' : '') + '">' + escapeHtml(research.summary || '') + '</p>'
            + '      <div class="research-footer">'
            + '        <div class="research-affiliation">'
            + '          <span class="research-affiliation-mark"><i class="bx bx-buildings"></i></span>'
            + '          <div><div class="insight-label">Adviser</div><div class="research-domain">' + escapeHtml(research.adviser || 'Adviser not assigned') + '</div></div>'
            + '        </div>'
            + '        <div><div class="insight-label">Research domain</div><div class="research-domain">' + escapeHtml(research.domain || 'Program not set') + '</div></div>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            + '</article>';
        };

        const refreshCounters = () => {
          loadedCount = researchGrid.querySelectorAll('.research-entry').length;
          countOutput.textContent = String(loadedCount);
          totalOutput.textContent = String(totalCount);
          emptyState.classList.toggle('d-none', isLoading || totalCount !== 0);

          if (scrollSentinel) {
            scrollSentinel.classList.toggle('d-none', isLoading || totalCount === 0 || nextOffset >= totalCount);
          }
        };

        const appendResearchItems = items => {
          const existingTitleIds = new Set(
            Array.from(researchGrid.querySelectorAll('.research-entry')).map(entry => entry.dataset.titleid)
          );
          const markup = (items || [])
            .filter(item => {
              const titleId = String(item && item.titleid ? item.titleid : '').trim();

              if (titleId === '' || existingTitleIds.has(titleId)) {
                return false;
              }

              existingTitleIds.add(titleId);
              return true;
            })
            .map(renderResearch)
            .join('');

          if (markup !== '') {
            researchGrid.insertAdjacentHTML('beforeend', markup);
          }
        };

        const catalogUrl = params => {
          const url = new URL(catalogEndpoint, window.location.origin);
          url.searchParams.set('catalog', '1');

          Object.keys(params).forEach(key => {
            const value = params[key];

            if (value !== null && value !== undefined && String(value) !== '') {
              url.searchParams.set(key, String(value));
            }
          });

          return url;
        };

        const loadCatalog = async options => {
          const shouldReset = Boolean(options && options.reset);

          if (isLoading && !shouldReset) {
            return false;
          }

          const token = ++requestToken;
          isLoading = true;
          refreshCounters();

          if (shouldReset) {
            researchGrid.innerHTML = '';
            loadedCount = 0;
            nextOffset = 0;
            refreshCounters();
          }

          try {
            const response = await fetch(catalogUrl({
              limit: batchSize,
              offset: shouldReset ? 0 : nextOffset,
            }), {
              headers: {
                'Accept': 'application/json',
              },
            });
            const payload = await response.json();

            if (token !== requestToken) {
              return false;
            }

            if (!response.ok) {
              throw new Error(payload && payload.message ? payload.message : 'Unable to load research records.');
            }

            totalCount = Number(payload.total || 0);
            const payloadItems = Array.isArray(payload.items) ? payload.items : [];
            appendResearchItems(payloadItems);
            nextOffset = (shouldReset ? 0 : nextOffset) + payloadItems.length;
            return true;
          } catch (error) {
            if (token === requestToken && emptyStateMessage) {
              totalCount = researchGrid.querySelectorAll('.research-entry').length;
              emptyStateMessage.textContent = error && error.message ? error.message : 'Unable to load research records.';
            }
            return false;
          } finally {
            if (token === requestToken) {
              isLoading = false;
              refreshCounters();
            }
          }
        };

        const loadSingleResearch = async titleId => {
          const response = await fetch(catalogUrl({
            title_id: titleId,
            limit: 1,
          }), {
            headers: {
              'Accept': 'application/json',
            },
          });
          const payload = await response.json();

          if (!response.ok) {
            return null;
          }

          const items = Array.isArray(payload.items) ? payload.items : [];

          return items[0] || null;
        };

        window.oracleLandingCatalog = {
          async focusResearch(titleId, titleQuery) {
            const normalizedTitleId = String(titleId || '').trim();

            if (normalizedTitleId === '') {
              return false;
            }

            let targetEntry = document.querySelector('.research-entry[data-titleid="' + normalizedTitleId + '"]');

            if (!targetEntry) {
              const item = await loadSingleResearch(normalizedTitleId);

              if (item) {
                researchGrid.insertAdjacentHTML('afterbegin', renderResearch(item));
                totalCount = Math.max(totalCount, researchGrid.querySelectorAll('.research-entry').length);
                refreshCounters();
              }

              targetEntry = document.querySelector('.research-entry[data-titleid="' + normalizedTitleId + '"]');
            }

            if (!targetEntry) {
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

        if (scrollSentinel && 'IntersectionObserver' in window) {
          const observer = new IntersectionObserver(entries => {
            const isVisible = entries.some(entry => entry.isIntersecting);

            if (!isVisible || isLoading || nextOffset >= totalCount) {
              return;
            }

            loadCatalog();
          }, {
            rootMargin: '240px 0px',
          });

          observer.observe(scrollSentinel);
        }

        refreshCounters();
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
          resultsContainer.setAttribute('aria-busy', 'false');
          resultsContainer.innerHTML = '<div class="ai-search-empty">' + escapeHtml(message) + '</div>';
        };

        const renderSearchLoader = query => {
          resultsContainer.setAttribute('aria-busy', 'true');
          resultsContainer.innerHTML =
            '<div class="ai-search-loader" role="status">'
            + '  <span class="ai-search-loader-spinner" aria-hidden="true"></span>'
            + '  <span class="ai-search-loader-copy">'
            + '    <span class="ai-search-loader-title">Searching the research database...</span>'
            + '    <span class="ai-search-loader-note">Checking titles, abstracts, authors, advisers, and program details for "' + escapeHtml(query) + '".</span>'
            + '  </span>'
            + '</div>';
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
          resultsContainer.setAttribute('aria-busy', 'false');

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
          queryInput.focus();
        });

        resultsContainer.addEventListener('click', async event => {
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

          const focused = await window.oracleLandingCatalog.focusResearch(titleId, title);

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
          renderStatus('Searching the research catalog for the closest related studies...', 'neutral', ['Database search'], '');
          renderSearchLoader(query);

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
            resultsContainer.setAttribute('aria-busy', 'false');
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
