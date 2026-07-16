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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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

function landing_research_abstract_url(?string $relativePath): string
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '' || strpos($normalized, 'uploads/manuscript_abstracts/') !== 0) {
        return '';
    }

    return app_link($normalized);
}

function landing_research_repository_id(int $titleId): string
{
    return 'ORACLE-R-' . str_pad((string) max($titleId, 0), 6, '0', STR_PAD_LEFT);
}

function landing_research_citation(string $authors, int $year, string $title, string $type): string
{
    return sprintf(
        '%s (%d). %s [%s]. Sultan Kudarat State University Oracle Research Repository.',
        rtrim($authors, '.'),
        $year,
        rtrim($title, '.'),
        $type
    );
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
       m.abstract_file_path,
       m.abstract_original_name,
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

function landing_research_catalog_count_from_sql(array $filters = []): string
{
    $query = landing_research_normalize_text($filters['q'] ?? '');
    $focus = landing_research_normalize_text($filters['focus'] ?? '');
    $joins = [];

    if ($query !== '' || $focus === 'adviser') {
        $joins[] = 'LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid';
    }

    if ($query !== '') {
        $joins[] = 'LEFT JOIN tblcourse p ON p.courseid = m.programid';
        $joins[] = 'LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid';
    }

    return "FROM tblresearches m\n"
        . ($joins !== [] ? implode("\n", $joins) . "\n" : '')
        . "WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL";
}

function landing_research_catalog_filters(array $filters = []): array
{
    $conditions = [];
    $params = [];
    $titleId = isset($filters['title_id']) ? (int) $filters['title_id'] : 0;
    $year = isset($filters['year']) ? (int) $filters['year'] : 0;
    $typeId = isset($filters['type']) ? (int) $filters['type'] : 0;
    $programId = isset($filters['program']) ? (int) $filters['program'] : 0;
    $status = landing_research_normalize_text($filters['status'] ?? '');
    $focus = landing_research_normalize_text($filters['focus'] ?? '');
    $query = landing_research_normalize_text($filters['q'] ?? '');

    if ($titleId > 0) {
        $conditions[] = 'm.titleid = :titleid';
        $params[':titleid'] = [$titleId, PDO::PARAM_INT];
    }

    if ($year > 0) {
        $conditions[] = 'YEAR(COALESCE(m.submitted_at, m.updated_at)) = :research_year';
        $params[':research_year'] = [$year, PDO::PARAM_INT];
    }

    if ($typeId > 0) {
        $conditions[] = 'm.typeid = :research_type';
        $params[':research_type'] = [$typeId, PDO::PARAM_INT];
    }

    if ($programId > 0) {
        $conditions[] = 'm.programid = :research_program';
        $params[':research_program'] = [$programId, PDO::PARAM_INT];
    }

    if ($status !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(m.status, ''))) = LOWER(:research_status)";
        $params[':research_status'] = [$status, PDO::PARAM_STR];
    }

    if ($focus === 'adviser') {
        $conditions[] = "NULLIF(TRIM(COALESCE(m.adviser_name, a.acc_name, a.email, '')), '') IS NOT NULL";
    } elseif ($focus === 'sdg') {
        $conditions[] = "NULLIF(TRIM(COALESCE(m.sdgs, '')), '') IS NOT NULL";
    } elseif ($focus === 'abstract') {
        $conditions[] = "NULLIF(TRIM(COALESCE(m.abstract_file_path, '')), '') IS NOT NULL";
    }

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

function landing_research_catalog_order_sql(string $sort): string
{
    if ($sort === 'oldest') {
        return 'COALESCE(m.submitted_at, m.updated_at) ASC, m.titleid ASC';
    }

    if ($sort === 'title') {
        return 'm.title ASC, m.titleid DESC';
    }

    return 'COALESCE(m.submitted_at, m.updated_at) DESC, m.titleid DESC';
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
        $abstractUrl = landing_research_abstract_url($researchRow['abstract_file_path'] ?? '');
        $abstractFileName = landing_research_normalize_text($researchRow['abstract_original_name'] ?? '');
        $repositoryId = landing_research_repository_id($titleId);
        $typeLabel = $researchType !== '' ? $researchType : 'Research record';
        $statusLabel = $status !== '' ? $status : 'Status not set';
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
            'type' => $typeLabel,
            'status' => $statusLabel,
            'sdg_metric' => $sdgLabels !== [] ? implode(', ', $sdgLabels) : 'No SDG tags',
            'sdgs' => $sdgLabels,
            'summary' => $hasAbstract
                ? $abstractSummary
                : 'Abstract is not available in this ' . landing_research_abstract_type_label($researchRow) . '.',
            'summary_is_placeholder' => !$hasAbstract,
            'abstract_url' => $abstractUrl,
            'abstract_file_name' => $abstractFileName !== '' ? $abstractFileName : 'Research abstract',
            'access_label' => $abstractUrl !== '' ? 'Abstract file available' : 'Metadata record',
            'repository_id' => $repositoryId,
            'permalink' => app_link('index.php') . '?research=' . rawurlencode((string) $titleId),
            'citation' => landing_research_citation($authors, $year, $title, $typeLabel),
            'adviser_linked' => $adviser !== 'Adviser not assigned',
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
    array $filters = [],
    bool $includeTotal = true
): array {
    $limit = min(max($limit, 1), 48);
    $offset = max($offset, 0);
    [$filterSql, $params] = landing_research_catalog_filters($filters);
    $orderSql = landing_research_catalog_order_sql((string) ($filters['sort'] ?? 'latest'));

    $total = null;

    if ($includeTotal) {
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) ' . landing_research_catalog_count_from_sql($filters) . $filterSql
        );
        landing_research_bind_params($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();
    }

    $queryLimit = $includeTotal ? $limit : $limit + 1;

    $statement = $pdo->prepare(
        landing_research_catalog_select_sql() . "\n" .
        landing_research_catalog_from_sql() .
        $filterSql .
        "\nORDER BY " . $orderSql . "
LIMIT :limit OFFSET :offset"
    );
    landing_research_bind_params($statement, $params);
    $statement->bindValue(':limit', $queryLimit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll();
    $hasMoreWithoutTotal = !$includeTotal && count($rows) > $limit;

    if ($hasMoreWithoutTotal) {
        $rows = array_slice($rows, 0, $limit);
    }

    $items = landing_research_catalog_items($rows, $researchAvatarPalettes, $offset);

    return [
        'items' => $items,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => $includeTotal
            ? ($offset + count($items)) < (int) $total
            : $hasMoreWithoutTotal,
    ];
}

function landing_research_fetch_catalog_item(PDO $pdo, array $researchAvatarPalettes, int $titleId): ?array
{
    if ($titleId < 1) {
        return null;
    }

    [$filterSql, $params] = landing_research_catalog_filters([
        'title_id' => $titleId,
    ]);
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

function landing_research_fetch_filter_options(PDO $pdo): array
{
    $typeRows = $pdo->query(
        "SELECT DISTINCT rt.researchtypeid AS id, rt.research_type AS label
         FROM tblresearches m
         INNER JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL
         ORDER BY rt.research_type ASC"
    )->fetchAll();

    $programRows = $pdo->query(
        "SELECT DISTINCT p.courseid AS id, p.coursecode, p.coursedescription, p.coursemajor
         FROM tblresearches m
         INNER JOIN tblcourse p ON p.courseid = m.programid
         WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL
         ORDER BY p.coursecode ASC, p.coursedescription ASC"
    )->fetchAll();

    $statusRows = $pdo->query(
        "SELECT DISTINCT TRIM(status) AS label
         FROM tblresearches
         WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL
           AND NULLIF(TRIM(COALESCE(status, '')), '') IS NOT NULL
         ORDER BY label ASC"
    )->fetchAll();

    $focusCounts = $pdo->query(
        "SELECT
            SUM(CASE WHEN NULLIF(TRIM(COALESCE(m.abstract_file_path, '')), '') IS NOT NULL THEN 1 ELSE 0 END) AS abstract_count,
            SUM(CASE WHEN NULLIF(TRIM(COALESCE(m.adviser_name, a.acc_name, a.email, '')), '') IS NOT NULL THEN 1 ELSE 0 END) AS adviser_count,
            SUM(CASE WHEN NULLIF(TRIM(COALESCE(m.sdgs, '')), '') IS NOT NULL THEN 1 ELSE 0 END) AS sdg_count
         FROM tblresearches m
         LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
         WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL"
    )->fetch();

    return [
        'types' => array_values(array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => landing_research_normalize_text($row['label'] ?? ''),
            ];
        }, $typeRows ?: [])),
        'programs' => array_values(array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => landing_research_program_label($row),
            ];
        }, $programRows ?: [])),
        'statuses' => array_values(array_filter(array_map(static function (array $row): string {
            return landing_research_normalize_text($row['label'] ?? '');
        }, $statusRows ?: []))),
        'focus_counts' => [
            'abstract' => (int) ($focusCounts['abstract_count'] ?? 0),
            'adviser' => (int) ($focusCounts['adviser_count'] ?? 0),
            'sdg' => (int) ($focusCounts['sdg_count'] ?? 0),
        ],
    ];
}

if (($_GET['catalog'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');

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
            [
                'q' => (string) ($_GET['q'] ?? ''),
                'year' => isset($_GET['year']) ? (int) $_GET['year'] : 0,
                'type' => isset($_GET['type']) ? (int) $_GET['type'] : 0,
                'program' => isset($_GET['program']) ? (int) $_GET['program'] : 0,
                'status' => (string) ($_GET['status'] ?? ''),
                'focus' => (string) ($_GET['focus'] ?? ''),
                'sort' => (string) ($_GET['sort'] ?? 'latest'),
            ],
            (string) ($_GET['include_total'] ?? '1') !== '0'
        );

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('Landing catalog API failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode([
            'message' => 'Live research records are unavailable right now.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

$researchCatalog = [];
$researchYears = [];
$researchFilterOptions = [
    'types' => [],
    'programs' => [],
    'statuses' => [],
    'focus_counts' => [
        'abstract' => 0,
        'adviser' => 0,
        'sdg' => 0,
    ],
];
$researchTotalCount = 0;
$landingDataError = null;
$initialCatalogFilters = [
    'q' => (string) ($_GET['q'] ?? ''),
    'year' => isset($_GET['year']) ? (int) $_GET['year'] : 0,
    'type' => isset($_GET['type']) ? (int) $_GET['type'] : 0,
    'program' => isset($_GET['program']) ? (int) $_GET['program'] : 0,
    'status' => (string) ($_GET['status'] ?? ''),
    'focus' => (string) ($_GET['focus'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'latest'),
];
$pdo = null;

try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    $landingDataError = 'Live research records are unavailable right now.';
    error_log('Landing database connection failed: ' . $exception->getMessage());
}

if ($pdo instanceof PDO) {
    try {
        $catalogPage = landing_research_fetch_catalog_page(
            $pdo,
            $researchAvatarPalettes,
            $initialResearchBatchSize,
            0,
            $initialCatalogFilters
        );
        $researchCatalog = $catalogPage['items'];
        $researchTotalCount = (int) $catalogPage['total'];
    } catch (Throwable $exception) {
        $landingDataError = 'Live research records are unavailable right now.';
        error_log('Initial landing catalog load failed: ' . $exception->getMessage());
    }

    try {
        $researchYears = landing_research_fetch_years($pdo);
    } catch (Throwable $exception) {
        error_log('Landing research year options failed: ' . $exception->getMessage());
    }

    try {
        $researchFilterOptions = landing_research_fetch_filter_options($pdo);
    } catch (Throwable $exception) {
        error_log('Landing research filter options failed: ' . $exception->getMessage());
    }
}

$researchYearMin = $researchYears !== [] ? min($researchYears) : null;
$researchYearMax = $researchYears !== [] ? max($researchYears) : null;
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
    <title>Oracle Research Repository | Sultan Kudarat State University</title>
    <meta name="description" content="Discover theses, capstone projects, and institutional research from Sultan Kudarat State University." />
    <link rel="icon" type="image/png" sizes="64x64" href="<?= e(app_link('assets/img/favicon/oracle-favicon.png')); ?>" />
    <link rel="shortcut icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.ico')); ?>" />
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(app_link('assets/img/favicon/apple-touch-icon.png')); ?>" />
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
        display: block;
        flex: 0 0 2.9rem;
        object-fit: cover;
        box-shadow: 0 16px 30px rgba(123, 136, 115, 0.18);
      }

      .brand-title {
        margin: 0;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: -0.03em;
        color: var(--landing-ink);
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
        border-bottom: 1px solid #eef0f2;
      }

      .nav-shell {
        min-height: 5.75rem;
        gap: 1rem;
      }

      .brand-lockup {
        gap: 0.65rem;
      }

      .brand-mark {
        width: 4.5rem;
        height: 4.5rem;
        border-radius: 1rem;
        flex-basis: 4.5rem;
        filter: drop-shadow(0 0.25rem 0.4rem rgba(21, 128, 61, 0.2));
        box-shadow: none;
      }

      .brand-title {
        font-size: 1.08rem;
        letter-spacing: -0.02em;
      }

      .google-login-btn {
        gap: 0.5rem;
        min-height: 2.55rem;
        padding: 0.55rem 0.85rem;
        border-radius: 0.75rem;
        border-color: #dfe3e8;
        background: #ffffff;
        box-shadow: none;
        color: #374151;
        font-size: 0.86rem;
        font-weight: 650;
      }

      .google-login-btn:hover {
        transform: none;
        background: #f9fafb;
        border-color: #cfd5dc;
        color: #111827;
      }

      .google-login-icon {
        width: 1rem;
        height: 1rem;
      }

      .landing-main {
        padding: 1.4rem 0 2.5rem;
      }

      .repository-project-figure {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        min-width: 0;
        margin: 0;
      }

      .repository-project-figure-icon {
        width: 2.35rem;
        height: 2.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2.35rem;
        border: 1px solid #bbf7d0;
        border-radius: 0.75rem;
        background: #f0fdf4;
        color: #15803d;
        font-size: 1.05rem;
      }

      .repository-project-figure figcaption {
        margin: 0;
        color: #64748b;
        font-size: 0.8rem;
        line-height: 1.45;
      }

      .repository-project-figure strong {
        display: block;
        margin-bottom: 0.12rem;
        color: #14532d;
        font-weight: 800;
      }

      .repository-hero {
        margin-bottom: 1.25rem;
        padding: clamp(1.4rem, 3vw, 2.4rem);
        border: 1px solid #d1fae5;
        border-radius: 1.25rem;
        background:
          radial-gradient(circle at 92% 12%, rgba(34, 197, 94, 0.13), transparent 34%),
          linear-gradient(135deg, #f8fffa 0%, #f0fdf4 56%, #ecfeff 100%);
        overflow: hidden;
      }

      .repository-hero-main {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(19rem, 0.65fr);
        align-items: stretch;
        gap: clamp(1.5rem, 3vw, 3rem);
      }

      .repository-hero-content {
        align-self: center;
        min-width: 0;
      }

      .repository-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        margin: 0 0 0.65rem;
        color: #15803d;
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.09em;
        text-transform: uppercase;
      }

      .repository-title {
        max-width: 52rem;
        margin: 0;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        color: #111827;
        font-size: clamp(2rem, 4vw, 3.5rem);
        line-height: 1.02;
        letter-spacing: -0.055em;
      }

      .repository-copy {
        max-width: 54rem;
        margin: 0.9rem 0 0;
        color: #4b5563;
        font-size: 1rem;
        line-height: 1.7;
      }

      .repository-search {
        display: flex;
        align-items: stretch;
        gap: 0.65rem;
        max-width: 60rem;
        margin-top: 1.3rem;
      }

      .repository-search-field {
        position: relative;
        flex: 1 1 auto;
      }

      .repository-search-field i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 1.15rem;
        pointer-events: none;
      }

      .repository-search-input {
        width: 100%;
        min-height: 3.45rem;
        padding: 0.82rem 1rem 0.82rem 2.9rem;
        border: 1px solid #d1d5db;
        border-radius: 0.95rem;
        background: #ffffff;
        color: #111827;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
      }

      .repository-search-input:focus {
        outline: 0;
        border-color: #22c55e;
        box-shadow: 0 0 0 0.22rem rgba(34, 197, 94, 0.13);
      }

      .repository-search-submit,
      .repository-ai-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        min-height: 3.45rem;
        padding: 0.8rem 1.15rem;
        border-radius: 0.95rem;
        font-weight: 750;
        white-space: nowrap;
      }

      .repository-search-submit {
        border: 1px solid #15803d;
        background: #15803d;
        color: #ffffff;
      }

      .repository-search-submit:hover,
      .repository-search-submit:focus {
        background: #166534;
        border-color: #166534;
        color: #ffffff;
      }

      .repository-hero-aside {
        margin-top: 1.55rem;
        padding-top: 1.1rem;
        border-top: 1px solid rgba(21, 128, 61, 0.13);
      }

      .repository-discovery-card {
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-width: 0;
        padding: 1.35rem;
        border: 1px solid rgba(21, 128, 61, 0.14);
        border-radius: 1.1rem;
        background:
          radial-gradient(circle at 100% 0%, rgba(34, 197, 94, 0.16), transparent 42%),
          rgba(255, 255, 255, 0.78);
        box-shadow: 0 18px 40px rgba(21, 128, 61, 0.08);
        overflow: hidden;
      }

      .repository-discovery-heading {
        position: relative;
        display: flex;
        align-items: center;
        gap: 0.8rem;
      }

      .repository-discovery-icon {
        width: 2.75rem;
        height: 2.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2.75rem;
        border-radius: 0.85rem;
        background: #15803d;
        color: #ffffff;
        font-size: 1.25rem;
        box-shadow: 0 10px 22px rgba(21, 128, 61, 0.2);
      }

      .repository-discovery-kicker {
        margin: 0 0 0.2rem;
        color: #15803d;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .repository-discovery-title {
        margin: 0;
        color: #163522;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.12rem;
        line-height: 1.25;
      }

      .repository-discovery-copy {
        position: relative;
        margin: 0.9rem 0 1rem;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.55;
      }

      .repository-stat {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        min-width: 0;
        margin-top: 0.9rem;
        padding: 0.9rem 0 0;
        border-top: 1px solid rgba(21, 128, 61, 0.12);
      }

      .repository-stat-icon {
        width: 2rem;
        height: 2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2rem;
        border-radius: 0.65rem;
        background: #dcfce7;
        color: #15803d;
        font-size: 1rem;
      }

      .repository-stat-copy strong,
      .repository-stat-copy span {
        display: block;
      }

      .repository-stat-copy strong {
        color: #14532d;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1.15rem;
        line-height: 1;
      }

      .repository-stat-copy span {
        margin-top: 0.22rem;
        color: #6b7280;
        font-size: 0.7rem;
        font-weight: 650;
        white-space: nowrap;
      }

      .repository-ai-button {
        position: relative;
        width: 100%;
        min-height: 3rem;
        padding: 0.6rem 1rem;
        border: 0;
        border-radius: 0.8rem;
        background: #15803d;
        color: #ffffff;
      }

      .repository-ai-button:hover,
      .repository-ai-button:focus {
        background: #166534;
        color: #ffffff;
      }

      .repository-ai-button .bx-right-arrow-alt {
        font-size: 1.1rem;
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

      .repository-filter-form {
        display: grid;
        gap: 0.95rem;
      }

      .repository-filter-field {
        display: grid;
        gap: 0.42rem;
      }

      .repository-filter-field label {
        color: #4b5563;
        font-size: 0.79rem;
        font-weight: 750;
      }

      .repository-filter-control {
        width: 100%;
        min-height: 2.85rem;
        padding: 0.62rem 0.78rem;
        border: 1px solid #d1d5db;
        border-radius: 0.8rem;
        background: #ffffff;
        color: #1f2937;
        font-size: 0.88rem;
      }

      .repository-filter-control:focus {
        outline: 0;
        border-color: #22c55e;
        box-shadow: 0 0 0 0.2rem rgba(34, 197, 94, 0.12);
      }

      .repository-filter-actions {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.55rem;
      }

      .repository-filter-apply,
      .repository-filter-clear {
        min-height: 2.85rem;
        border-radius: 0.8rem;
        font-size: 0.86rem;
        font-weight: 750;
      }

      .repository-filter-apply {
        border: 1px solid #15803d;
        background: #15803d;
        color: #ffffff;
      }

      .repository-filter-clear {
        border: 1px solid #d1d5db;
        background: #ffffff;
        color: #4b5563;
      }

      .repository-filter-summary {
        margin: 0;
        color: #6b7280;
        font-size: 0.8rem;
        line-height: 1.5;
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
        transition: opacity 160ms ease;
      }

      .research-list[aria-busy='true'] {
        opacity: 0.72;
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

      .research-record-id {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin: 0 0 0.38rem;
        color: #15803d;
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.07em;
        text-transform: uppercase;
      }

      .metric-pill--neutral {
        border-color: #d1d5db;
        background: #f9fafb;
        color: #4b5563;
      }

      .metric-pill--blue {
        border-color: #bae6fd;
        background: #f0f9ff;
        color: #0369a1;
      }

      .research-record-note {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: #6b7280;
        font-size: 0.8rem;
      }

      .research-detail-modal .modal-dialog {
        max-width: min(920px, calc(100vw - 1.75rem));
      }

      .research-detail-modal .modal-content {
        overflow: hidden;
        border: 1px solid #d1fae5;
        border-radius: 1.35rem;
        box-shadow: 0 28px 64px rgba(15, 23, 42, 0.16);
      }

      .research-detail-modal .modal-header {
        align-items: flex-start;
        padding: 1.4rem 1.5rem 1.1rem;
        border-bottom: 1px solid #e5e7eb;
        background: linear-gradient(135deg, #f0fdf4, #ecfeff);
      }

      .research-detail-id {
        margin: 0 0 0.45rem;
        color: #15803d;
        font-size: 0.76rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .research-detail-modal .modal-title {
        margin: 0;
        padding-right: 1rem;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        color: #111827;
        font-size: clamp(1.35rem, 3vw, 2rem);
        line-height: 1.2;
        letter-spacing: -0.035em;
      }

      .research-detail-modal .modal-body {
        padding: 1.4rem 1.5rem 1.5rem;
        background: #ffffff;
      }

      .research-detail-meta {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
      }

      .research-detail-fact {
        padding: 0.85rem 0.9rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.9rem;
        background: #f9fafb;
      }

      .research-detail-fact span,
      .research-detail-fact strong {
        display: block;
      }

      .research-detail-fact span {
        color: #6b7280;
        font-size: 0.74rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .research-detail-fact strong {
        margin-top: 0.3rem;
        color: #1f2937;
        font-size: 0.9rem;
        line-height: 1.45;
      }

      .research-detail-section {
        margin-top: 1.25rem;
      }

      .research-detail-section h6 {
        margin: 0 0 0.5rem;
        color: #111827;
        font-size: 0.88rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .research-detail-section p {
        margin: 0;
        color: #374151;
        line-height: 1.75;
      }

      .research-detail-citation {
        padding: 1rem;
        border-left: 4px solid #22c55e;
        border-radius: 0.75rem;
        background: #f0fdf4;
      }

      .research-detail-actions {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        flex-wrap: wrap;
        margin-top: 1.25rem;
        padding-top: 1.15rem;
        border-top: 1px solid #e5e7eb;
      }

      .research-detail-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.42rem;
        min-height: 2.9rem;
        padding: 0.65rem 0.9rem;
        border: 1px solid #d1d5db;
        border-radius: 0.82rem;
        background: #ffffff;
        color: #374151;
        font-size: 0.86rem;
        font-weight: 750;
        text-decoration: none;
      }

      .research-detail-action--primary {
        border-color: #15803d;
        background: #15803d;
        color: #ffffff;
      }

      .research-detail-action:hover,
      .research-detail-action:focus {
        border-color: #16a34a;
        background: #f0fdf4;
        color: #166534;
      }

      .research-detail-action--primary:hover,
      .research-detail-action--primary:focus {
        background: #166534;
        color: #ffffff;
      }

      .research-detail-feedback {
        min-height: 1.2rem;
        margin: 0.55rem 0 0;
        color: #15803d;
        font-size: 0.82rem;
        font-weight: 650;
      }

      .empty-state {
        padding: 2.5rem 2rem;
      }

      .ai-floating-actions {
        position: fixed;
        right: clamp(1rem, 2.8vw, 2rem);
        bottom: clamp(1rem, 2.8vw, 2rem);
        width: 5.5rem;
        height: 5.5rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        z-index: 1080;
      }

      .ai-assistant-launcher {
        position: relative;
        width: 100%;
        height: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 0;
        border-radius: 50%;
        overflow: visible;
        background: transparent;
        color: #1d4ed8;
        box-shadow: none;
        cursor: pointer;
        animation: ai-orb-float 4.2s ease-in-out infinite;
        transition: filter 180ms ease, transform 180ms ease;
        z-index: 1;
      }

      .ai-orb-visual {
        position: absolute;
        inset: -0.2rem;
        display: block;
        pointer-events: none;
        filter:
          drop-shadow(0 0 0.18rem rgba(29, 78, 216, 0.95))
          drop-shadow(0 0 0.65rem rgba(0, 174, 255, 0.68));
        z-index: 1;
      }

      .ai-orb-visual svg {
        width: 100%;
        height: 100%;
        display: block;
        overflow: visible;
      }

      .ai-orb-core-halo {
        fill: url('#ai-orb-core-gradient');
        filter: url('#ai-orb-core-glow');
      }

      .ai-orb-core-shell {
        fill: url('#ai-orb-shell-gradient');
        filter: url('#ai-orb-core-glow');
      }

      .ai-orb-core-rim {
        fill: none;
        stroke: rgba(125, 211, 252, 0.88);
        stroke-width: 0.9;
        filter: url('#ai-orb-electric-glow');
      }

      .ai-orb-core-highlight {
        fill: rgba(255, 255, 255, 0.66);
        filter: url('#ai-orb-core-glow');
        opacity: 0.76;
      }

      .ai-orb-core-texture {
        fill: url('#ai-orb-speckles');
        opacity: 0.72;
        filter: url('#ai-orb-electric-glow');
      }

      .ai-orb-core-particles {
        transform-box: view-box;
        transform-origin: 50px 50px;
        animation: ai-orb-spin 12s linear infinite reverse;
      }

      .ai-orbit-electron {
        fill: #f97316;
        stroke: #fbbf24;
        stroke-width: 0.9;
        filter: url('#ai-orb-node-glow');
      }

      .ai-orbit-electron-core {
        fill: #ffd166;
        filter: url('#ai-orb-node-glow');
      }

      .ai-orbit-depth {
        transform-box: fill-box;
        transform-origin: center;
        animation: ai-orb-depth var(--orbit-duration, 7s) linear infinite;
        animation-delay: var(--orbit-delay, 0s);
      }

      .ai-orb-particle {
        fill: #bfdbfe;
        filter: url('#ai-orb-node-glow');
        animation: ai-orb-particle-twinkle 2.8s ease-in-out infinite;
        animation-delay: var(--particle-delay, 0s);
      }

      .ai-orb-search-icon,
      .ai-orb-search-word {
        transform-box: fill-box;
        transform-origin: center;
        filter: url('#ai-orb-node-glow');
      }

      .ai-orb-search-icon {
        fill: none;
        stroke: #ffb703;
        stroke-width: 2.7;
        stroke-linecap: round;
        filter:
          drop-shadow(0 0 0.7px #7c2d12)
          drop-shadow(0 0 2.8px #f97316);
        animation: ai-orb-morph-icon 5.6s ease-in-out infinite;
      }

      .ai-orb-search-word {
        fill: #ffd166;
        stroke: #7c2d12;
        stroke-width: 0.55;
        paint-order: stroke fill;
        font-family: Arial, sans-serif;
        font-size: 8.5px;
        font-weight: 800;
        letter-spacing: 0.12em;
        filter:
          drop-shadow(0 0 0.65px #7c2d12)
          drop-shadow(0 0 2.5px #f97316);
        opacity: 0;
        text-transform: lowercase;
        animation: ai-orb-morph-word 5.6s ease-in-out infinite;
      }

      .ai-assistant-launcher:hover {
        color: #1e40af;
        filter: brightness(1.16) saturate(1.2);
        animation: none;
        transform: translateY(-2px) scale(1.08);
      }

      .ai-assistant-launcher:focus-visible {
        outline: 3px solid rgba(37, 99, 235, 0.28);
        outline-offset: 5px;
      }

      .back-to-top-button {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 0.45rem);
        width: 2.25rem;
        height: 2.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 2px solid #f59e0b;
        border-radius: 50%;
        background: #f59e0b;
        color: #ffffff;
        box-shadow: 0 8px 18px rgba(217, 119, 6, 0.22);
        opacity: 0;
        pointer-events: none;
        transform: translate(-50%, 0.5rem) scale(0.9);
        transition: opacity 180ms ease, transform 180ms ease, background-color 160ms ease;
      }

      .back-to-top-button.is-visible {
        opacity: 1;
        pointer-events: auto;
        transform: translate(-50%, 0) scale(1);
      }

      .back-to-top-button:hover {
        border-color: #d97706;
        background: #d97706;
        color: #ffffff;
      }

      .back-to-top-button:focus-visible {
        outline: 3px solid rgba(245, 158, 11, 0.22);
        outline-offset: 3px;
      }

      .back-to-top-button i {
        font-size: 1.25rem;
        line-height: 1;
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

      .ai-discovery-notice {
        display: flex;
        align-items: flex-start;
        gap: 0.65rem;
        margin-top: 1rem;
        padding: 0.8rem 0.9rem;
        border: 1px solid #dbeafe;
        border-radius: 0.85rem;
        background: #eff6ff;
        color: #374151;
        font-size: 0.82rem;
        line-height: 1.55;
      }

      .ai-discovery-notice i {
        margin-top: 0.12rem;
        color: #2563eb;
        font-size: 1rem;
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

      @keyframes ai-orb-float {
        0%,
        100% {
          transform: translateY(0) scale(1);
        }

        50% {
          transform: translateY(-4px) scale(1.025);
        }
      }

      @keyframes ai-orb-spin {
        to {
          transform: rotate(360deg);
        }
      }

      @keyframes ai-orb-depth {
        0%,
        50%,
        100% {
          opacity: 0.58;
          transform: scale(0.82);
        }

        25% {
          opacity: 1;
          transform: scale(1.42);
        }

        75% {
          opacity: 0.22;
          transform: scale(0.48);
        }
      }

      @keyframes ai-orb-particle-twinkle {
        0%,
        100% {
          opacity: 0.18;
        }

        45% {
          opacity: 0.95;
        }
      }

      @keyframes ai-orb-morph-icon {
        0%, 32% {
          opacity: 1;
          transform: rotate(0deg) scale(1);
        }

        44%, 80% {
          opacity: 0;
          transform: rotate(-24deg) scale(0.52);
        }

        88% {
          opacity: 0;
          transform: rotate(18deg) scale(1.32);
        }

        100% {
          opacity: 1;
          transform: rotate(0deg) scale(1);
        }
      }

      @keyframes ai-orb-morph-word {
        0%, 37% {
          opacity: 0;
          transform: scaleX(0.35) scaleY(0.7);
        }

        49%, 74% {
          opacity: 1;
          transform: scale(1);
        }

        87%, 100% {
          opacity: 0;
          transform: scaleX(1.28) scaleY(0.72);
        }
      }

      @media (prefers-reduced-motion: reduce) {
        .ai-assistant-launcher,
        .ai-orb-core-particles,
        .ai-orbit-depth,
        .ai-orb-particle,
        .ai-orb-search-icon,
        .ai-orb-search-word {
          animation: none;
        }

        .ai-orbit-runner {
          display: none;
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

      .repository-footer-shell {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(12rem, 0.7fr) minmax(12rem, 0.9fr);
        align-items: start;
        gap: 1.5rem;
        padding-top: 1.5rem;
        padding-bottom: 1.5rem;
      }

      .repository-footer-title {
        margin: 0;
        color: #111827;
        font-family: 'Space Grotesk', 'Public Sans', sans-serif;
        font-size: 1rem;
        font-weight: 800;
      }

      .repository-footer-copy,
      .repository-footer-note {
        margin: 0.45rem 0 0;
        max-width: 42rem;
        color: #6b7280;
        font-size: 0.82rem;
        line-height: 1.6;
      }

      .repository-footer-heading {
        margin: 0 0 0.55rem;
        color: #374151;
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .repository-footer-links {
        display: grid;
        gap: 0.42rem;
      }

      .repository-footer-links a,
      .repository-footer-links button {
        width: fit-content;
        padding: 0;
        border: 0;
        background: transparent;
        color: #4b5563;
        font-size: 0.84rem;
        text-align: left;
        text-decoration: none;
      }

      .repository-footer-links a:hover,
      .repository-footer-links button:hover {
        color: #15803d;
      }

      @media (max-width: 991.98px) {
        .repository-footer-shell {
          grid-template-columns: 1fr;
        }

        .repository-hero-main {
          grid-template-columns: 1fr;
        }

        .repository-discovery-card {
          max-width: 34rem;
        }

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

        .footer-shell {
          flex-direction: column;
          align-items: flex-start;
          justify-content: center;
          padding: 1rem 0;
        }

        .nav-shell {
          flex-direction: row;
          align-items: center;
          justify-content: space-between;
          padding-top: 0.65rem;
          padding-bottom: 0.65rem;
        }

        .google-login-btn {
          width: auto;
        }
      }

      @media (max-width: 575.98px) {
        .brand-mark {
          width: 3.75rem;
          height: 3.75rem;
          flex-basis: 3.75rem;
        }

        .landing-main {
          padding-top: 1.25rem;
        }

        .repository-project-figure {
          gap: 0.65rem;
        }

        .repository-project-figure-icon {
          width: 2.1rem;
          height: 2.1rem;
          flex-basis: 2.1rem;
          font-size: 0.95rem;
        }

        .repository-hero {
          padding: 1.15rem;
        }

        .repository-search {
          flex-direction: column;
        }

        .repository-search-submit,
        .repository-ai-button {
          width: 100%;
        }

        .repository-stat {
          justify-content: flex-start;
        }

        .research-detail-meta {
          grid-template-columns: 1fr;
        }

        .research-detail-actions,
        .research-detail-action {
          width: 100%;
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

        .ai-floating-actions {
          width: 4.75rem;
          height: 4.75rem;
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
          <img
            class="brand-mark"
            src="<?= e(app_link('assets/img/branding/oracle-logo.png')); ?>"
            alt=""
            width="512"
            height="512"
            aria-hidden="true"
          />
          <span class="brand-title">Oracle</span>
        </a>

        <a href="<?= e(app_link('auth/google.php')); ?>" class="google-login-btn" aria-label="Sign in with Google">
          <svg class="google-login-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M21.805 12.232c0-.728-.065-1.428-.186-2.101H12.24v3.973h5.358a4.581 4.581 0 0 1-1.99 3.007v2.498h3.217c1.884-1.735 2.98-4.29 2.98-7.377Z" fill="#4285F4"></path>
            <path d="M12.24 22c2.688 0 4.94-.891 6.587-2.391l-3.217-2.498c-.891.597-2.03.949-3.37.949-2.594 0-4.79-1.752-5.575-4.109H3.34v2.576A9.942 9.942 0 0 0 12.24 22Z" fill="#34A853"></path>
            <path d="M6.665 13.951a5.966 5.966 0 0 1 0-3.803V7.572H3.34a9.942 9.942 0 0 0 0 8.955l3.325-2.576Z" fill="#FBBC05"></path>
            <path d="M12.24 6.04c1.462 0 2.775.503 3.808 1.491l2.854-2.854C17.175 3.066 14.923 2 12.24 2A9.942 9.942 0 0 0 3.34 7.572l3.325 2.576C7.45 7.792 9.646 6.04 12.24 6.04Z" fill="#EA4335"></path>
          </svg>
          <span>Sign in</span>
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

        <section class="repository-hero" aria-labelledby="repository-title">
          <div class="repository-hero-main">
            <div class="repository-hero-content">
              <p class="repository-eyebrow"><i class="bx bx-library"></i> SULTAN KUDARAT STATE UNIVERSITY</p>
              <h1 class="repository-title" id="repository-title">Oracle Research Repository</h1>
              <p class="repository-copy">
                Discover theses, capstone projects, and institutional research from Sultan Kudarat State University through structured catalog metadata and research abstracts.
              </p>
              <form class="repository-search" id="repository-search-form" role="search">
                <label class="visually-hidden" for="repository-search-input">Search the research repository</label>
                <span class="repository-search-field">
                  <i class="bx bx-search" aria-hidden="true"></i>
                  <input
                    class="repository-search-input"
                    id="repository-search-input"
                     name="q"
                     type="search"
                     value="<?= e((string) $initialCatalogFilters['q']); ?>"
                     autocomplete="off"
                    placeholder="Search titles, authors, advisers, abstracts, programs, or SDGs"
                  />
                </span>
                <button class="repository-search-submit" type="submit"><i class="bx bx-search-alt"></i> Search repository</button>
              </form>
            </div>
            <aside class="repository-discovery-card" aria-labelledby="repository-discovery-title">
              <div class="repository-discovery-heading">
                <span class="repository-discovery-icon" aria-hidden="true"><i class="bx bx-bot"></i></span>
                <div>
                  <p class="repository-discovery-kicker">AI research discovery</p>
                  <h2 class="repository-discovery-title" id="repository-discovery-title">Start with a topic. Find related work.</h2>
                </div>
              </div>
              <p class="repository-discovery-copy">Describe a research idea or problem and discover the closest studies already in the repository.</p>
              <button class="repository-ai-button" type="button" data-bs-toggle="modal" data-bs-target="#aiResearchModal">
                <span>Find related studies</span>
                <i class="bx bx-right-arrow-alt" aria-hidden="true"></i>
              </button>
              <div class="repository-stat" aria-label="<?= e(number_format($researchTotalCount)); ?> indexed research records">
                <span class="repository-stat-icon" aria-hidden="true"><i class="bx bx-collection"></i></span>
                <span class="repository-stat-copy">
                  <strong><?= e(number_format($researchTotalCount)); ?></strong>
                  <span>Indexed records available to search</span>
                </span>
              </div>
            </aside>
          </div>
          <div class="repository-hero-aside">
            <figure class="repository-project-figure" aria-label="Research and innovation statement">
              <span class="repository-project-figure-icon" aria-hidden="true"><i class="bx bx-badge-check"></i></span>
              <figcaption>
                <strong>Official university research output</strong>
                An approved project funded by the Office of Research, Development and Innovation (RDI).
              </figcaption>
            </figure>
          </div>
        </section>

        <div class="row g-4">
          <div class="col-xl-3 col-lg-4">
            <aside class="panel-card sidebar-panel">
              <h2 class="sidebar-heading">Explore this collection</h2>
              <p class="sidebar-copy">Narrow the live catalog using repository metadata. Every filter below updates the visible record set.</p>

              <form class="repository-filter-form" id="repository-filter-form">
                <div class="repository-filter-field">
                  <label for="repository-year-filter">Publication year</label>
                  <select class="repository-filter-control" id="repository-year-filter" name="year">
                    <option value="">All years</option>
<?php foreach ($researchYears as $researchYear): ?>
                    <option value="<?= e((string) $researchYear); ?>"<?= (int) $initialCatalogFilters['year'] === (int) $researchYear ? ' selected' : ''; ?>><?= e((string) $researchYear); ?></option>
<?php endforeach; ?>
                  </select>
                </div>

                <div class="repository-filter-field">
                  <label for="repository-type-filter">Research type</label>
                  <select class="repository-filter-control" id="repository-type-filter" name="type">
                    <option value="">All research types</option>
<?php foreach ($researchFilterOptions['types'] as $researchTypeOption): ?>
                    <option value="<?= e((string) $researchTypeOption['id']); ?>"<?= (int) $initialCatalogFilters['type'] === (int) $researchTypeOption['id'] ? ' selected' : ''; ?>><?= e($researchTypeOption['label']); ?></option>
<?php endforeach; ?>
                  </select>
                </div>

                <div class="repository-filter-field">
                  <label for="repository-program-filter">Academic program</label>
                  <select class="repository-filter-control" id="repository-program-filter" name="program">
                    <option value="">All programs</option>
<?php foreach ($researchFilterOptions['programs'] as $programOption): ?>
                    <option value="<?= e((string) $programOption['id']); ?>"<?= (int) $initialCatalogFilters['program'] === (int) $programOption['id'] ? ' selected' : ''; ?>><?= e($programOption['label']); ?></option>
<?php endforeach; ?>
                  </select>
                </div>

                <div class="repository-filter-field">
                  <label for="repository-status-filter">Research status</label>
                  <select class="repository-filter-control" id="repository-status-filter" name="status">
                    <option value="">All statuses</option>
<?php foreach ($researchFilterOptions['statuses'] as $statusOption): ?>
                    <option value="<?= e($statusOption); ?>"<?= (string) $initialCatalogFilters['status'] === (string) $statusOption ? ' selected' : ''; ?>><?= e($statusOption); ?></option>
<?php endforeach; ?>
                  </select>
                </div>

                <div class="repository-filter-field">
                  <label for="repository-focus-filter">Record availability</label>
                  <select class="repository-filter-control" id="repository-focus-filter" name="focus">
                    <option value="">All records</option>
                    <option value="abstract"<?= (string) $initialCatalogFilters['focus'] === 'abstract' ? ' selected' : ''; ?><?= $researchFilterOptions['focus_counts']['abstract'] < 1 && (string) $initialCatalogFilters['focus'] !== 'abstract' ? ' disabled' : ''; ?>>Abstract file available (<?= e(number_format($researchFilterOptions['focus_counts']['abstract'])); ?>)</option>
                    <option value="adviser"<?= (string) $initialCatalogFilters['focus'] === 'adviser' ? ' selected' : ''; ?><?= $researchFilterOptions['focus_counts']['adviser'] < 1 && (string) $initialCatalogFilters['focus'] !== 'adviser' ? ' disabled' : ''; ?>>Adviser linked (<?= e(number_format($researchFilterOptions['focus_counts']['adviser'])); ?>)</option>
                    <option value="sdg"<?= (string) $initialCatalogFilters['focus'] === 'sdg' ? ' selected' : ''; ?><?= $researchFilterOptions['focus_counts']['sdg'] < 1 && (string) $initialCatalogFilters['focus'] !== 'sdg' ? ' disabled' : ''; ?>>SDG tagged (<?= e(number_format($researchFilterOptions['focus_counts']['sdg'])); ?>)</option>
                  </select>
                </div>

                <div class="repository-filter-field">
                  <label for="repository-sort-filter">Sort records</label>
                  <select class="repository-filter-control" id="repository-sort-filter" name="sort">
                    <option value="latest"<?= (string) $initialCatalogFilters['sort'] === 'latest' ? ' selected' : ''; ?>>Latest additions</option>
                    <option value="oldest"<?= (string) $initialCatalogFilters['sort'] === 'oldest' ? ' selected' : ''; ?>>Oldest additions</option>
                    <option value="title"<?= (string) $initialCatalogFilters['sort'] === 'title' ? ' selected' : ''; ?>>Title A–Z</option>
                  </select>
                </div>

                <div class="repository-filter-actions">
                  <button class="repository-filter-apply" type="submit">Apply filters</button>
                  <button class="repository-filter-clear" id="repository-filter-clear" type="button">Clear</button>
                </div>

                <p class="repository-filter-summary" id="repository-filter-summary" aria-live="polite">
                  Browsing all <?= e(number_format($researchTotalCount)); ?> indexed records across <?= e($sidebarYearRangeLabel); ?>.
                </p>
              </form>
            </aside>
          </div>

          <div class="col-xl-9 col-lg-8">
            <section class="results-panel">
              <div class="results-header">
                <div>
                  <span class="hero-badge"><i class="bx bx-collection"></i> Research List</span>
                  <p class="research-count-copy">Showing <span id="research-count"><?= e((string) count($researchCatalog)); ?></span> of <span id="research-total"><?= e((string) $researchTotalCount); ?></span> research items</p>
                </div>
                <p class="results-note" id="repository-results-context" aria-live="polite">Latest additions from the complete repository collection.</p>
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
                          <p class="research-record-id"><i class="bx bx-fingerprint"></i> <?= e($research['repository_id']); ?></p>
                          <p class="research-author"><?= e($research['lead_author']); ?></p>
                          <h3 class="research-title"><?= e($research['title']); ?></h3>
                          <p class="research-location"><?= e($research['institution']); ?> | <?= e($research['location']); ?></p>
                        </div>

                        <button
                          type="button"
                          class="btn result-action"
                          data-research-detail="1"
                          data-titleid="<?= e((string) $research['titleid']); ?>"
                          aria-label="View repository record for <?= e($research['title']); ?>"
                        >View record</button>
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
                        <span class="metric-pill metric-pill--neutral">
                          <i class="bx bx-check-shield"></i> <?= e($research['status']); ?>
                        </span>
                        <span class="metric-pill metric-pill--blue">
                          <i class="bx bx-file"></i> <?= e($research['access_label']); ?>
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
      <div class="container-xxl footer-shell repository-footer-shell">
        <div>
          <p class="repository-footer-title">Oracle Research Repository</p>
          <p class="repository-footer-copy">An institutional discovery platform for theses, capstone projects, and research outputs of Sultan Kudarat State University.</p>
          <p class="repository-footer-note">Research files and metadata remain subject to university access, authorship, and copyright policies.</p>
        </div>
        <div>
          <p class="repository-footer-heading">Repository</p>
          <div class="repository-footer-links">
            <a href="#research-grid">Browse records</a>
            <button type="button" data-bs-toggle="modal" data-bs-target="#aiResearchModal">Find related studies</button>
            <a href="<?= e(app_link('auth/google.php')); ?>">Contributor sign in</a>
          </div>
        </div>
        <div>
          <p class="repository-footer-heading">Institutional context</p>
          <p class="repository-footer-note">Official output of an approved project funded by the Office of Research, Development and Innovation.</p>
          <p class="repository-footer-note">ORACLE <?= e(date('Y')); ?></p>
        </div>
      </div>
    </footer>

    <div class="ai-floating-actions">
      <button class="back-to-top-button" id="back-to-top-button" type="button" aria-label="Back to top" aria-hidden="true" tabindex="-1">
        <i class="bx bx-up-arrow-alt" aria-hidden="true"></i>
      </button>
      <button
        type="button"
        class="ai-assistant-launcher"
        data-bs-toggle="modal"
        data-bs-target="#aiResearchModal"
        aria-label="Open AI research search"
      >
        <span class="ai-orb-visual" aria-hidden="true">
          <svg viewBox="0 0 100 100" focusable="false">
            <defs>
              <radialGradient id="ai-orb-core-gradient" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#e0f2fe" stop-opacity="0.72"></stop>
                <stop offset="24%" stop-color="#38bdf8" stop-opacity="0.6"></stop>
                <stop offset="68%" stop-color="#2563eb" stop-opacity="0.32"></stop>
                <stop offset="100%" stop-color="#2563eb" stop-opacity="0"></stop>
              </radialGradient>
              <radialGradient id="ai-orb-shell-gradient" cx="34%" cy="29%" r="72%">
                <stop offset="0%" stop-color="#ffffff"></stop>
                <stop offset="14%" stop-color="#bae6fd"></stop>
                <stop offset="38%" stop-color="#38bdf8"></stop>
                <stop offset="67%" stop-color="#0ea5e9"></stop>
                <stop offset="88%" stop-color="#2563eb" stop-opacity="0.94"></stop>
                <stop offset="100%" stop-color="#1d4ed8" stop-opacity="0.28"></stop>
              </radialGradient>
              <pattern id="ai-orb-speckles" width="7" height="7" patternUnits="userSpaceOnUse">
                <circle cx="1" cy="1.5" r="0.75" fill="#e0f2fe"></circle>
                <circle cx="5.4" cy="2.1" r="0.5" fill="#38bdf8"></circle>
                <circle cx="3.2" cy="5.5" r="0.85" fill="#93c5fd"></circle>
                <circle cx="6.5" cy="6.1" r="0.32" fill="#ffffff"></circle>
              </pattern>
              <clipPath id="ai-orb-core-clip">
                <circle cx="50" cy="50" r="27"></circle>
              </clipPath>
              <path id="ai-orbit-path-one" d="M4 58 C19 31 69 23 95 42 C81 71 28 77 4 58 Z"></path>
              <path id="ai-orbit-path-two" d="M28 8 C56 15 91 52 75 91 C44 84 11 42 28 8 Z"></path>
              <path id="ai-orbit-path-three" d="M73 9 C92 39 65 85 26 91 C10 59 38 15 73 9 Z"></path>
              <path id="ai-orbit-path-four" d="M12 32 C42 5 88 26 91 61 C59 92 16 77 12 32 Z"></path>
              <filter id="ai-orb-electric-glow" x="-45%" y="-45%" width="190%" height="190%">
                <feGaussianBlur stdDeviation="1.35" result="arc-blur"></feGaussianBlur>
                <feMerge>
                  <feMergeNode in="arc-blur"></feMergeNode>
                  <feMergeNode in="SourceGraphic"></feMergeNode>
                </feMerge>
              </filter>
              <filter id="ai-orb-core-glow" x="-70%" y="-70%" width="240%" height="240%">
                <feGaussianBlur stdDeviation="3.8" result="core-blur"></feGaussianBlur>
                <feMerge>
                  <feMergeNode in="core-blur"></feMergeNode>
                  <feMergeNode in="SourceGraphic"></feMergeNode>
                </feMerge>
              </filter>
              <filter id="ai-orb-node-glow" x="-180%" y="-180%" width="460%" height="460%">
                <feGaussianBlur stdDeviation="2.2" result="node-blur"></feGaussianBlur>
                <feMerge>
                  <feMergeNode in="node-blur"></feMergeNode>
                  <feMergeNode in="SourceGraphic"></feMergeNode>
                </feMerge>
              </filter>
            </defs>
            <circle class="ai-orb-core-halo" cx="50" cy="50" r="33"></circle>
            <circle class="ai-orb-core-shell" cx="50" cy="50" r="26"></circle>
            <circle class="ai-orb-core-texture" cx="50" cy="50" r="25"></circle>
            <g class="ai-orb-core-particles" clip-path="url(#ai-orb-core-clip)">
              <circle class="ai-orb-particle" cx="31" cy="38" r="0.8" style="--particle-delay: -0.3s"></circle>
              <circle class="ai-orb-particle" cx="38" cy="30" r="0.55" style="--particle-delay: -1.1s"></circle>
              <circle class="ai-orb-particle" cx="48" cy="27" r="0.7" style="--particle-delay: -2s"></circle>
              <circle class="ai-orb-particle" cx="59" cy="31" r="0.5" style="--particle-delay: -0.7s"></circle>
              <circle class="ai-orb-particle" cx="69" cy="38" r="0.75" style="--particle-delay: -1.8s"></circle>
              <circle class="ai-orb-particle" cx="28" cy="48" r="0.55" style="--particle-delay: -0.2s"></circle>
              <circle class="ai-orb-particle" cx="38" cy="43" r="0.8" style="--particle-delay: -1.4s"></circle>
              <circle class="ai-orb-particle" cx="48" cy="39" r="0.6" style="--particle-delay: -2.4s"></circle>
              <circle class="ai-orb-particle" cx="59" cy="44" r="0.7" style="--particle-delay: -0.9s"></circle>
              <circle class="ai-orb-particle" cx="72" cy="48" r="0.5" style="--particle-delay: -2.2s"></circle>
              <circle class="ai-orb-particle" cx="32" cy="58" r="0.7" style="--particle-delay: -1.2s"></circle>
              <circle class="ai-orb-particle" cx="42" cy="55" r="0.5" style="--particle-delay: -0.5s"></circle>
              <circle class="ai-orb-particle" cx="53" cy="57" r="0.8" style="--particle-delay: -1.7s"></circle>
              <circle class="ai-orb-particle" cx="66" cy="55" r="0.55" style="--particle-delay: -2.5s"></circle>
              <circle class="ai-orb-particle" cx="38" cy="68" r="0.75" style="--particle-delay: -0.8s"></circle>
              <circle class="ai-orb-particle" cx="49" cy="72" r="0.55" style="--particle-delay: -1.9s"></circle>
              <circle class="ai-orb-particle" cx="60" cy="68" r="0.7" style="--particle-delay: -0.1s"></circle>
              <circle class="ai-orb-particle" cx="69" cy="62" r="0.5" style="--particle-delay: -1.5s"></circle>
            </g>

            <circle class="ai-orb-core-rim" cx="50" cy="50" r="26.2"></circle>
            <ellipse class="ai-orb-core-highlight" cx="42" cy="38" rx="9.5" ry="5.5" transform="rotate(-28 42 38)"></ellipse>

            <g class="ai-orbit-runner" style="--orbit-duration: 6.2s; --orbit-delay: 0s">
              <animateMotion dur="6.2s" begin="0s" repeatCount="indefinite"><mpath href="#ai-orbit-path-one"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="2.1"></circle><circle class="ai-orbit-electron-core" r="0.72"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 6.2s; --orbit-delay: -2.1s">
              <animateMotion dur="6.2s" begin="-2.1s" repeatCount="indefinite"><mpath href="#ai-orbit-path-one"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.85"></circle><circle class="ai-orbit-electron-core" r="0.62"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 6.2s; --orbit-delay: -4.2s">
              <animateMotion dur="6.2s" begin="-4.2s" repeatCount="indefinite"><mpath href="#ai-orbit-path-one"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.7"></circle><circle class="ai-orbit-electron-core" r="0.58"></circle></g>
            </g>

            <g class="ai-orbit-runner" style="--orbit-duration: 7.8s; --orbit-delay: -0.7s">
              <animateMotion dur="7.8s" begin="-0.7s" repeatCount="indefinite"><mpath href="#ai-orbit-path-two"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="2.15"></circle><circle class="ai-orbit-electron-core" r="0.75"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 7.8s; --orbit-delay: -3.3s">
              <animateMotion dur="7.8s" begin="-3.3s" repeatCount="indefinite"><mpath href="#ai-orbit-path-two"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.8"></circle><circle class="ai-orbit-electron-core" r="0.62"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 7.8s; --orbit-delay: -5.9s">
              <animateMotion dur="7.8s" begin="-5.9s" repeatCount="indefinite"><mpath href="#ai-orbit-path-two"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.7"></circle><circle class="ai-orbit-electron-core" r="0.56"></circle></g>
            </g>

            <g class="ai-orbit-runner" style="--orbit-duration: 5.8s; --orbit-delay: -1s">
              <animateMotion dur="5.8s" begin="-1s" repeatCount="indefinite"><mpath href="#ai-orbit-path-three"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="2.2"></circle><circle class="ai-orbit-electron-core" r="0.78"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 5.8s; --orbit-delay: -2.9s">
              <animateMotion dur="5.8s" begin="-2.9s" repeatCount="indefinite"><mpath href="#ai-orbit-path-three"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.85"></circle><circle class="ai-orbit-electron-core" r="0.64"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 5.8s; --orbit-delay: -4.8s">
              <animateMotion dur="5.8s" begin="-4.8s" repeatCount="indefinite"><mpath href="#ai-orbit-path-three"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.65"></circle><circle class="ai-orbit-electron-core" r="0.54"></circle></g>
            </g>

            <g class="ai-orbit-runner" style="--orbit-duration: 9.4s; --orbit-delay: -1.5s">
              <animateMotion dur="9.4s" begin="-1.5s" repeatCount="indefinite"><mpath href="#ai-orbit-path-four"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="2.05"></circle><circle class="ai-orbit-electron-core" r="0.7"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 9.4s; --orbit-delay: -4.6s">
              <animateMotion dur="9.4s" begin="-4.6s" repeatCount="indefinite"><mpath href="#ai-orbit-path-four"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.8"></circle><circle class="ai-orbit-electron-core" r="0.6"></circle></g>
            </g>
            <g class="ai-orbit-runner" style="--orbit-duration: 9.4s; --orbit-delay: -7.7s">
              <animateMotion dur="9.4s" begin="-7.7s" repeatCount="indefinite"><mpath href="#ai-orbit-path-four"></mpath></animateMotion>
              <g class="ai-orbit-depth"><circle class="ai-orbit-electron" r="1.7"></circle><circle class="ai-orbit-electron-core" r="0.56"></circle></g>
            </g>

            <g class="ai-orb-search-morph">
              <g class="ai-orb-search-icon">
                <circle cx="47" cy="47" r="7"></circle>
                <path d="M52.2 52.2 L59.5 59.5"></path>
              </g>
              <text class="ai-orb-search-word" x="50" y="53" text-anchor="middle">search</text>
            </g>
          </svg>
        </span>
      </button>
    </div>

    <div class="modal fade research-detail-modal" id="researchDetailModal" tabindex="-1" aria-labelledby="researchDetailModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <p class="research-detail-id" id="research-detail-id">Repository record</p>
              <h5 class="modal-title" id="researchDetailModalLabel">Research record</h5>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="research-detail-meta">
              <div class="research-detail-fact"><span>Authors</span><strong id="research-detail-authors">—</strong></div>
              <div class="research-detail-fact"><span>Research type</span><strong id="research-detail-type">—</strong></div>
              <div class="research-detail-fact"><span>Status</span><strong id="research-detail-status">—</strong></div>
              <div class="research-detail-fact"><span>Academic program</span><strong id="research-detail-program">—</strong></div>
              <div class="research-detail-fact"><span>Adviser</span><strong id="research-detail-adviser">—</strong></div>
              <div class="research-detail-fact"><span>Repository date</span><strong id="research-detail-date">—</strong></div>
              <div class="research-detail-fact"><span>SDG alignment</span><strong id="research-detail-sdgs">—</strong></div>
              <div class="research-detail-fact"><span>Record access</span><strong id="research-detail-access">—</strong></div>
              <div class="research-detail-fact"><span>Publication year</span><strong id="research-detail-year">—</strong></div>
            </div>

            <section class="research-detail-section" aria-labelledby="research-detail-abstract-heading">
              <h6 id="research-detail-abstract-heading">Abstract</h6>
              <p id="research-detail-abstract">Abstract information is unavailable.</p>
            </section>

            <section class="research-detail-section" aria-labelledby="research-detail-citation-heading">
              <h6 id="research-detail-citation-heading">Suggested repository citation</h6>
              <p class="research-detail-citation" id="research-detail-citation">Citation information is unavailable.</p>
            </section>

            <div class="research-detail-actions">
              <a class="research-detail-action research-detail-action--primary" id="research-detail-abstract-link" href="#" target="_blank" rel="noopener" hidden>
                <i class="bx bx-file-blank"></i> Open abstract file
              </a>
              <button class="research-detail-action" id="research-detail-copy-citation" type="button"><i class="bx bx-copy"></i> Copy citation</button>
              <button class="research-detail-action" id="research-detail-copy-link" type="button"><i class="bx bx-link"></i> Copy permanent link</button>
            </div>
            <p class="research-detail-feedback" id="research-detail-feedback" aria-live="polite"></p>
          </div>
        </div>
      </div>
    </div>

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

            <p class="ai-discovery-notice">
              <i class="bx bx-info-circle" aria-hidden="true"></i>
              <span>This assistant supports discovery only. Its matches do not assess originality, plagiarism, methodological quality, or academic approval.</span>
            </p>

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
        const backToTopButton = document.getElementById('back-to-top-button');

        if (!backToTopButton) {
          return;
        }

        let scrollUpdatePending = false;

        const updateBackToTop = () => {
          const isVisible = window.scrollY > 480;
          backToTopButton.classList.toggle('is-visible', isVisible);
          backToTopButton.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
          backToTopButton.tabIndex = isVisible ? 0 : -1;
          scrollUpdatePending = false;
        };

        window.addEventListener('scroll', () => {
          if (scrollUpdatePending) {
            return;
          }

          scrollUpdatePending = true;
          window.requestAnimationFrame(updateBackToTop);
        }, { passive: true });

        backToTopButton.addEventListener('click', () => {
          const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          window.scrollTo({
            top: 0,
            behavior: reduceMotion ? 'auto' : 'smooth',
          });
        });

        updateBackToTop();
      })();

      (function () {
        const batchSize = <?= e((string) $initialResearchBatchSize); ?>;
        const catalogEndpoint = <?= json_encode(app_link('index.php')); ?>;
        const countOutput = document.getElementById('research-count');
        const totalOutput = document.getElementById('research-total');
        const emptyState = document.getElementById('empty-state');
        const emptyStateMessage = emptyState ? emptyState.querySelector('p') : null;
        const researchGrid = document.getElementById('research-grid');
        const scrollSentinel = document.getElementById('scroll-sentinel');
        const searchForm = document.getElementById('repository-search-form');
        const searchInput = document.getElementById('repository-search-input');
        const filterForm = document.getElementById('repository-filter-form');
        const filterClearButton = document.getElementById('repository-filter-clear');
        const filterSummary = document.getElementById('repository-filter-summary');
        const resultsContext = document.getElementById('repository-results-context');
        const detailModalElement = document.getElementById('researchDetailModal');
        const detailModal = detailModalElement && window.bootstrap && window.bootstrap.Modal
          ? window.bootstrap.Modal.getOrCreateInstance(detailModalElement)
          : null;
        const initialCatalogError = <?= json_encode($landingDataError ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        let currentDetailResearch = null;
        let loadedCount = researchGrid ? researchGrid.querySelectorAll('.research-entry').length : 0;
        let nextOffset = loadedCount;
        let totalCount = <?= e((string) $researchTotalCount); ?>;
        let hasMore = nextOffset < totalCount;
        let isLoading = false;
        let requestToken = 0;
        let catalogError = initialCatalogError;
        let catalogAbortController = null;
        let initialRecoveryTimer = null;

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

        const filterKeys = ['q', 'year', 'type', 'program', 'status', 'focus', 'sort'];
        let activeFilters = {
          sort: 'latest',
        };

        const collectFilters = () => {
          const filters = {};
          const query = searchInput ? searchInput.value.trim() : '';

          if (query !== '') {
            filters.q = query;
          }

          if (filterForm) {
            const formData = new FormData(filterForm);

            ['year', 'type', 'program', 'status', 'focus', 'sort'].forEach(key => {
              const value = String(formData.get(key) || '').trim();

              if (value !== '') {
                filters[key] = value;
              }
            });
          }

          if (!filters.sort) {
            filters.sort = 'latest';
          }

          return filters;
        };

        const hasActiveFilters = () => {
          return Object.keys(activeFilters).some(key => {
            const value = String(activeFilters[key] || '');
            return key === 'sort' ? value !== '' && value !== 'latest' : value !== '';
          });
        };

        const syncFilterUrl = () => {
          const url = new URL(window.location.href);

          filterKeys.forEach(key => url.searchParams.delete(key));
          Object.keys(activeFilters).forEach(key => {
            const value = String(activeFilters[key] || '').trim();

            if (value !== '' && !(key === 'sort' && value === 'latest')) {
              url.searchParams.set(key, value);
            }
          });
          window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
        };

        const refreshFilterCopy = () => {
          const filtered = hasActiveFilters();
          const resultLabel = totalCount === 1 ? 'record' : 'records';

          if (filterSummary) {
            filterSummary.textContent = filtered
              ? totalCount + ' matching repository ' + resultLabel + '.'
              : 'Browsing all ' + totalCount + ' indexed ' + resultLabel + '.';
          }

          if (resultsContext) {
            if (isLoading) {
              resultsContext.textContent = 'Updating the repository results…';
            } else if (catalogError !== '') {
              resultsContext.textContent = catalogError;
            } else if (filtered) {
              resultsContext.textContent = totalCount === 0
                ? 'No records match the current search and filters.'
                : 'Results match the current repository search and filters.';
            } else {
              resultsContext.textContent = 'Latest additions from the complete repository collection.';
            }
          }

          if (emptyStateMessage) {
            emptyStateMessage.textContent = catalogError !== ''
              ? catalogError
              : (filtered
                ? 'Try removing a filter or using a broader search term.'
                : 'No research records are available in the catalog right now.');
          }
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
            + '          <p class="research-record-id"><i class="bx bx-fingerprint"></i> ' + escapeHtml(research.repository_id || 'Repository record') + '</p>'
            + '          <p class="research-author">' + escapeHtml(research.lead_author || 'Author information unavailable') + '</p>'
            + '          <h3 class="research-title">' + title + '</h3>'
            + '          <p class="research-location">' + escapeHtml((research.institution || 'Research record') + ' | ' + (research.location || 'Date unavailable')) + '</p>'
            + '        </div>'
            + '        <button type="button" class="btn result-action" data-research-detail="1" data-titleid="' + escapeHtml(research.titleid || '') + '" aria-label="View repository record for ' + title + '">View record</button>'
            + '      </div>'
            + '      <div class="research-metrics">'
            + '        <span class="metric-line"><span class="metric-icon"><i class="bx bx-calendar"></i></span><strong>' + escapeHtml(research.year || '') + '</strong></span>'
            + '        <span class="metric-line"><span class="metric-icon"><i class="bx bx-book-content"></i></span><strong>' + escapeHtml(research.type || 'Research record') + '</strong></span>'
            + '        <span class="metric-pill metric-pill--neutral"><i class="bx bx-check-shield"></i> ' + escapeHtml(research.status || 'Status not set') + '</span>'
            + '        <span class="metric-pill metric-pill--blue"><i class="bx bx-file"></i> ' + escapeHtml(research.access_label || 'Metadata record') + '</span>'
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
          researchGrid.setAttribute('aria-busy', isLoading ? 'true' : 'false');
          emptyState.classList.toggle('d-none', isLoading || loadedCount !== 0);
          refreshFilterCopy();

          if (scrollSentinel) {
            scrollSentinel.classList.toggle('d-none', isLoading || catalogError !== '' || !hasMore);
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

          if (options && options.filters) {
            activeFilters = Object.assign({ sort: 'latest' }, options.filters);
          }

          if (options && options.syncUrl) {
            syncFilterUrl();
          }

          if (isLoading && !shouldReset) {
            return false;
          }

          if (shouldReset && catalogAbortController) {
            catalogAbortController.abort();
          }

          const token = ++requestToken;
          const requestOffset = shouldReset ? 0 : nextOffset;
          const controller = 'AbortController' in window ? new AbortController() : null;
          catalogAbortController = controller;
          isLoading = true;
          refreshCounters();

          try {
            const response = await fetch(catalogUrl(Object.assign({
              limit: batchSize,
              offset: requestOffset,
              include_total: shouldReset ? 1 : 0,
            }, activeFilters)), {
              headers: {
                'Accept': 'application/json',
              },
              cache: 'no-store',
              signal: controller ? controller.signal : undefined,
            });
            const responseText = await response.text();
            let payload = null;

            try {
              payload = JSON.parse(responseText);
            } catch (error) {
              payload = null;
            }

            if (token !== requestToken) {
              return false;
            }

            if (!response.ok || !payload) {
              throw new Error(payload && payload.message ? payload.message : 'Unable to load research records.');
            }

            const payloadItems = Array.isArray(payload.items) ? payload.items : [];

            if (shouldReset) {
              researchGrid.innerHTML = '';
              loadedCount = 0;
              nextOffset = 0;
            }

            if (payload.total !== null && payload.total !== undefined && Number.isFinite(Number(payload.total))) {
              totalCount = Number(payload.total);
            }

            appendResearchItems(payloadItems);
            nextOffset = requestOffset + payloadItems.length;
            hasMore = Boolean(payload.has_more);
            catalogError = '';
            return true;
          } catch (error) {
            if (error && error.name === 'AbortError') {
              return false;
            }

            if (token === requestToken) {
              catalogError = error && error.message ? error.message : 'Unable to load research records.';
            }

            return false;
          } finally {
            if (token === requestToken) {
              if (catalogAbortController === controller) {
                catalogAbortController = null;
              }

              isLoading = false;
              refreshCounters();
            }
          }
        };

        const loadSingleResearch = async titleId => {
          try {
            const response = await fetch(catalogUrl({
              title_id: titleId,
              limit: 1,
            }), {
              headers: {
                'Accept': 'application/json',
              },
              cache: 'no-store',
            });
            const responseText = await response.text();
            const payload = JSON.parse(responseText);

            if (!response.ok || !payload) {
              return null;
            }

            const items = Array.isArray(payload.items) ? payload.items : [];

            return items[0] || null;
          } catch (error) {
            return null;
          }
        };

        const setDetailText = (id, value, fallback) => {
          const element = document.getElementById(id);

          if (element) {
            const hasValue = value !== null && value !== undefined && String(value).trim() !== '';
            element.textContent = String(hasValue ? value : (fallback !== undefined ? fallback : '—'));
          }
        };

        const renderResearchDetail = research => {
          currentDetailResearch = research;
          setDetailText('research-detail-id', research.repository_id, 'Repository record');
          setDetailText('researchDetailModalLabel', research.title, 'Research record');
          setDetailText('research-detail-authors', research.authors, 'Author information unavailable');
          setDetailText('research-detail-type', research.type, 'Research record');
          setDetailText('research-detail-status', research.status, 'Status not set');
          setDetailText('research-detail-program', research.domain, 'Program not set');
          setDetailText('research-detail-adviser', research.adviser, 'Adviser not assigned');
          setDetailText('research-detail-date', research.location, 'Date unavailable');
          setDetailText('research-detail-sdgs', research.sdg_metric, 'No SDG tags');
          setDetailText('research-detail-access', research.access_label, 'Metadata record');
          setDetailText('research-detail-year', research.year, 'Year unavailable');
          setDetailText('research-detail-abstract', research.summary, 'Abstract information is unavailable.');
          setDetailText('research-detail-citation', research.citation, 'Citation information is unavailable.');
          setDetailText('research-detail-feedback', '', '');

          const abstractLink = document.getElementById('research-detail-abstract-link');

          if (abstractLink) {
            const abstractUrl = String(research.abstract_url || '').trim();
            abstractLink.hidden = abstractUrl === '';

            if (abstractUrl !== '') {
              abstractLink.href = abstractUrl;
              abstractLink.setAttribute('aria-label', 'Open ' + String(research.abstract_file_name || 'research abstract'));
            } else {
              abstractLink.removeAttribute('href');
            }
          }
        };

        const openResearchDetail = async titleId => {
          const research = await loadSingleResearch(titleId);

          if (!research || !detailModal) {
            return false;
          }

          renderResearchDetail(research);
          const url = new URL(window.location.href);
          url.searchParams.set('research', String(titleId));
          window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
          detailModal.show();
          return true;
        };

        const copyText = async value => {
          const normalizedValue = String(value || '').trim();

          if (normalizedValue === '') {
            return false;
          }

          if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(normalizedValue);
            return true;
          }

          const temporaryInput = document.createElement('textarea');
          temporaryInput.value = normalizedValue;
          temporaryInput.setAttribute('readonly', 'readonly');
          temporaryInput.style.position = 'fixed';
          temporaryInput.style.opacity = '0';
          document.body.appendChild(temporaryInput);
          temporaryInput.select();
          const copied = document.execCommand('copy');
          temporaryInput.remove();
          return copied;
        };

        window.oracleLandingCatalog = {
          openResearchDetail,
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

        researchGrid.addEventListener('click', event => {
          const detailButton = event.target.closest('[data-research-detail]');

          if (!detailButton) {
            return;
          }

          const titleId = detailButton.getAttribute('data-titleid') || '';

          if (titleId !== '') {
            openResearchDetail(titleId);
          }
        });

        const applyRepositoryFilters = () => {
          if (initialRecoveryTimer !== null) {
            window.clearTimeout(initialRecoveryTimer);
            initialRecoveryTimer = null;
          }

          loadCatalog({
            reset: true,
            filters: collectFilters(),
            syncUrl: true,
          });
          researchGrid.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
          });
        };

        if (searchForm) {
          searchForm.addEventListener('submit', event => {
            event.preventDefault();
            applyRepositoryFilters();
          });
        }

        if (filterForm) {
          filterForm.addEventListener('submit', event => {
            event.preventDefault();
            applyRepositoryFilters();
          });
        }

        if (filterClearButton) {
          filterClearButton.addEventListener('click', () => {
            if (filterForm) {
              filterForm.reset();
            }

            if (searchInput) {
              searchInput.value = '';
            }

            applyRepositoryFilters();
          });
        }

        const copyCitationButton = document.getElementById('research-detail-copy-citation');
        const copyLinkButton = document.getElementById('research-detail-copy-link');
        const detailFeedback = document.getElementById('research-detail-feedback');

        if (copyCitationButton) {
          copyCitationButton.addEventListener('click', async () => {
            let copied = false;

            try {
              copied = currentDetailResearch
                ? await copyText(currentDetailResearch.citation || '')
                : false;
            } catch (error) {
              copied = false;
            }

            if (detailFeedback) {
              detailFeedback.textContent = copied ? 'Citation copied.' : 'Citation could not be copied.';
            }
          });
        }

        if (copyLinkButton) {
          copyLinkButton.addEventListener('click', async () => {
            const permalink = currentDetailResearch
              ? new URL(currentDetailResearch.permalink || window.location.href, window.location.origin).toString()
              : '';
            let copied = false;

            try {
              copied = await copyText(permalink);
            } catch (error) {
              copied = false;
            }

            if (detailFeedback) {
              detailFeedback.textContent = copied ? 'Permanent link copied.' : 'Link could not be copied.';
            }
          });
        }

        if (detailModalElement) {
          detailModalElement.addEventListener('hidden.bs.modal', () => {
            const url = new URL(window.location.href);
            url.searchParams.delete('research');
            window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
          });
        }

        if (scrollSentinel && 'IntersectionObserver' in window) {
          const observer = new IntersectionObserver(entries => {
            const isVisible = entries.some(entry => entry.isIntersecting);

            if (!isVisible || isLoading || !hasMore) {
              return;
            }

            loadCatalog();
          }, {
            rootMargin: '240px 0px',
          });

          observer.observe(scrollSentinel);
        }

        const initialUrl = new URL(window.location.href);
        const initialQuery = initialUrl.searchParams.get('q') || '';

        if (searchInput && initialQuery !== '') {
          searchInput.value = initialQuery;
        }

        if (filterForm) {
          ['year', 'type', 'program', 'status', 'focus', 'sort'].forEach(key => {
            const control = filterForm.elements.namedItem(key);
            const value = initialUrl.searchParams.get(key);

            if (control && value !== null) {
              control.value = value;
            }
          });
        }

        activeFilters = collectFilters();
        refreshCounters();

        const needsInitialRecovery = catalogError !== '' || (totalCount > 0 && loadedCount === 0);

        if (needsInitialRecovery) {
          const recoverInitialCatalog = async attempt => {
            const recovered = await loadCatalog({
              reset: true,
              filters: activeFilters,
            });

            if (!recovered && attempt < 2) {
              initialRecoveryTimer = window.setTimeout(() => {
                recoverInitialCatalog(attempt + 1);
              }, 750 * (attempt + 1));
            }
          };

          recoverInitialCatalog(0);
        }

        const requestedResearchId = initialUrl.searchParams.get('research') || '';

        if (requestedResearchId !== '') {
          openResearchDetail(requestedResearchId);
        }
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
