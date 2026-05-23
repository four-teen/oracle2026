<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireResearchCoordinator();

function coordinator_research_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    return $query === ''
        ? app_link('coordinator/research.php')
        : app_link('coordinator/research.php') . '?' . $query;
}

function coordinator_research_positive_int($value): int
{
    if (is_array($value)) {
        return 0;
    }

    $normalized = trim((string) $value);

    if ($normalized === '' || !ctype_digit($normalized)) {
        return 0;
    }

    return (int) $normalized;
}

function coordinator_research_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function coordinator_research_upper_name($value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
    $normalized = is_string($normalized) ? $normalized : trim((string) $value);

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($normalized, 'UTF-8')
        : strtoupper($normalized);
}

function coordinator_research_date_label(?string $value): string
{
    $normalized = trim((string) $value);

    if ($normalized === '') {
        return 'Date unavailable';
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('M j, Y', $timestamp) : $normalized;
}

function coordinator_research_year_label(?string $submittedAt, ?string $updatedAt = null): string
{
    foreach ([$submittedAt, $updatedAt] as $value) {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            continue;
        }

        $timestamp = strtotime($normalized);

        if ($timestamp !== false) {
            return date('Y', $timestamp);
        }
    }

    return 'Year not set';
}

function coordinator_research_trim_text(string $value, int $limit): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    $normalized = is_string($normalized) ? $normalized : trim($value);

    if (coordinator_research_text_length($normalized) <= $limit) {
        return $normalized;
    }

    $sliceLength = max(0, $limit - 3);
    $slice = function_exists('mb_substr')
        ? mb_substr($normalized, 0, $sliceLength, 'UTF-8')
        : substr($normalized, 0, $sliceLength);

    return rtrim((string) $slice) . '...';
}

function coordinator_research_author_label($value): string
{
    $normalized = coordinator_research_upper_name($value);

    return $normalized !== '' ? coordinator_research_trim_text($normalized, 160) : 'Author information not yet encoded';
}

function coordinator_research_adviser_label($value): string
{
    $normalized = coordinator_research_upper_name($value);

    return $normalized !== '' ? $normalized : 'Adviser not assigned';
}

function coordinator_research_account_option_name(array $account): string
{
    $accountId = isset($account['accountid']) ? (int) $account['accountid'] : 0;
    $name = coordinator_research_upper_name($account['acc_name'] ?? '');
    $email = trim((string) ($account['email'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return $email !== '' ? coordinator_research_upper_name($email) : 'ACCOUNT #' . $accountId;
}

function coordinator_research_account_option_label(array $account): string
{
    $label = coordinator_research_account_option_name($account);
    $email = trim((string) ($account['email'] ?? ''));

    if ($email !== '' && strcasecmp($label, $email) !== 0) {
        $label .= ' (' . $email . ')';
    }

    if (isset($account['is_enabled']) && (int) $account['is_enabled'] !== 1) {
        $label .= ' (Disabled)';
    }

    return $label;
}

function coordinator_research_adviser_account(PDO $pdo, int $accountId, int $campusId): ?array
{
    if ($accountId < 1 || $campusId < 1) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT accountid, acc_name, email, is_enabled, campus
         FROM tblaccount
         WHERE accountid = :accountid
         LIMIT 1'
    );
    $statement->bindValue(':accountid', $accountId, PDO::PARAM_INT);
    $statement->execute();
    $account = $statement->fetch();

    return is_array($account) ? $account : null;
}

function coordinator_research_adviser_options(PDO $pdo, int $campusId, string $term, int $limit, int $offset): array
{
    if ($campusId < 1) {
        return [];
    }

    $conditions = [];
    $term = trim($term);

    if ($term !== '') {
        $conditions[] = '(acc_name LIKE :term OR email LIKE :term OR CAST(accountid AS CHAR) LIKE :term)';
    }

    $statement = $pdo->prepare(
        'SELECT accountid, acc_name, email, is_enabled, campus
         FROM tblaccount
         ' . ($conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '') . '
         ORDER BY CASE
                    WHEN campus = :campusid THEN 0
                    WHEN campus IS NULL OR campus = 0 THEN 1
                    ELSE 2
                  END,
                  is_enabled DESC,
                  acc_name ASC,
                  email ASC,
                  accountid ASC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue(':campusid', $campusId, PDO::PARAM_INT);

    if ($term !== '') {
        $statement->bindValue(':term', '%' . $term . '%', PDO::PARAM_STR);
    }

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $accounts = $statement->fetchAll();

    return is_array($accounts) ? $accounts : [];
}

function coordinator_research_abstract_placeholder(array $record): string
{
    $type = trim((string) ($record['research_type'] ?? 'research record'));

    if ($type === '') {
        $type = 'research record';
    }

    if (stripos($type, 'capstone') !== false) {
        $type = 'Capstone Project';
    } elseif (stripos($type, 'thesis') !== false) {
        $type = 'Thesis';
    }

    return 'Abstract is not available in this ' . $type . '.';
}

function coordinator_research_avatar_palette(int $recordId): array
{
    $palettes = [
        ['icon' => 'bx-book-open', 'accent' => '#eab308', 'soft' => '#fef3c7'],
        ['icon' => 'bx-book-reader', 'accent' => '#0ea5e9', 'soft' => '#e0f2fe'],
        ['icon' => 'bx-file-find', 'accent' => '#16a34a', 'soft' => '#dcfce7'],
        ['icon' => 'bx-data', 'accent' => '#8b5cf6', 'soft' => '#ede9fe'],
        ['icon' => 'bx-line-chart', 'accent' => '#f97316', 'soft' => '#ffedd5'],
        ['icon' => 'bx-network-chart', 'accent' => '#0891b2', 'soft' => '#cffafe'],
        ['icon' => 'bx-chip', 'accent' => '#4f46e5', 'soft' => '#e0e7ff'],
        ['icon' => 'bx-test-tube', 'accent' => '#db2777', 'soft' => '#fce7f3'],
        ['icon' => 'bx-bulb', 'accent' => '#ca8a04', 'soft' => '#fef9c3'],
        ['icon' => 'bx-clipboard', 'accent' => '#059669', 'soft' => '#d1fae5'],
    ];

    return $palettes[$recordId % count($palettes)];
}

function coordinator_research_sdg_options(): array
{
    return [
        1 => 'No Poverty',
        2 => 'Zero Hunger',
        3 => 'Good Health and Well-Being',
        4 => 'Quality Education',
        5 => 'Gender Equality',
        6 => 'Clean Water and Sanitation',
        7 => 'Affordable and Clean Energy',
        8 => 'Decent Work and Economic Growth',
        9 => 'Industry, Innovation and Infrastructure',
        10 => 'Reduced Inequalities',
        11 => 'Sustainable Cities and Communities',
        12 => 'Responsible Consumption and Production',
        13 => 'Climate Action',
        14 => 'Life Below Water',
        15 => 'Life on Land',
        16 => 'Peace, Justice and Strong Institutions',
        17 => 'Partnerships for the Goals',
    ];
}

function coordinator_research_sdg_codes($value): array
{
    $options = coordinator_research_sdg_options();
    $rawValues = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
    $rawValues = is_array($rawValues) ? $rawValues : [];
    $codes = [];

    foreach ($rawValues as $rawValue) {
        if (is_array($rawValue)) {
            continue;
        }

        $normalized = trim((string) $rawValue);

        if ($normalized === '' || !ctype_digit($normalized)) {
            continue;
        }

        $code = (int) $normalized;

        if (isset($options[$code])) {
            $codes[$code] = $code;
        }
    }

    ksort($codes);

    return array_values($codes);
}

function coordinator_research_sdg_storage($value): string
{
    return implode(',', coordinator_research_sdg_codes($value));
}

function coordinator_research_sdg_label($value): string
{
    $codes = coordinator_research_sdg_codes($value);

    if ($codes === []) {
        return 'SDG not set';
    }

    return implode(', ', array_map(static function (int $code): string {
        return 'SDG ' . $code;
    }, $codes));
}

function coordinator_research_program_label(array $program): string
{
    $programId = isset($program['programid']) ? (int) $program['programid'] : (int) ($program['courseid'] ?? 0);
    $courseCode = trim((string) ($program['coursecode'] ?? ''));
    $courseDescription = trim((string) ($program['coursedescription'] ?? ''));
    $courseMajor = trim((string) ($program['coursemajor'] ?? ''));
    $parts = [];

    if ($courseCode !== '') {
        $parts[] = $courseCode;
    }

    if ($courseDescription !== '') {
        $parts[] = $courseDescription;
    }

    $label = $parts !== [] ? implode(' - ', $parts) : ($programId > 0 ? 'Program #' . $programId : 'Program not set');

    if ($courseMajor !== '') {
        $label .= ' (' . $courseMajor . ')';
    }

    return $label;
}

function coordinator_research_fetch(PDO $pdo, int $researchId, int $campusId): ?array
{
    if ($researchId < 1 || $campusId < 1) {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT m.titleid,
                m.title,
                m.typeid,
                m.campusid,
                m.ayid,
                m.authors,
                m.sdgs,
                m.status,
                m.programid,
                m.other_details,
                m.adviser_accountid,
                COALESCE(
                    NULLIF(TRIM(m.adviser_name), ''),
                    NULLIF(TRIM(a.acc_name), ''),
                    NULLIF(TRIM(a.email), '')
                ) AS adviser_name,
                a.acc_name AS adviser_account_name,
                a.email AS adviser_email,
                a.is_enabled AS adviser_is_enabled,
                m.submitted_at,
                m.updated_at,
                rt.research_type,
                p.coursecode,
                p.coursedescription,
                p.coursemajor
         FROM tblresearches m
         LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         LEFT JOIN tblcourse p ON p.courseid = m.programid
         WHERE m.titleid = :titleid
           AND m.campusid = :campusid
         LIMIT 1"
    );
    $statement->bindValue(':titleid', $researchId, PDO::PARAM_INT);
    $statement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $statement->execute();
    $record = $statement->fetch();

    return is_array($record) ? $record : null;
}

function coordinator_research_record_payload(array $record): array
{
    return [
        'titleid' => (int) ($record['titleid'] ?? 0),
        'title' => coordinator_research_upper_name($record['title'] ?? ''),
        'typeid' => coordinator_research_positive_int($record['typeid'] ?? 0),
        'ayid' => coordinator_research_positive_int($record['ayid'] ?? 0),
        'programid' => coordinator_research_positive_int($record['programid'] ?? 0),
        'sdgs' => coordinator_research_sdg_storage($record['sdgs'] ?? []),
        'sdg_codes' => coordinator_research_sdg_codes($record['sdgs'] ?? []),
        'status' => trim((string) ($record['status'] ?? 'Pending')),
        'authors' => coordinator_research_upper_name($record['authors'] ?? ''),
        'adviser_accountid' => coordinator_research_positive_int($record['adviser_accountid'] ?? 0),
        'adviser_name' => coordinator_research_upper_name($record['adviser_name'] ?? ''),
        'other_details' => (string) ($record['other_details'] ?? ''),
    ];
}

function coordinator_research_ensure_schema(): void
{
    $schemaSessionKey = 'coordinator_research_schema_ready_v3';

    if (!empty($_SESSION[$schemaSessionKey])) {
        return;
    }

    Database::ensureAccountCoordinatorColumns();
    Database::ensureManuscriptTable();
    $_SESSION[$schemaSessionKey] = time();
}

function coordinator_research_record_html(array $record): string
{
    $recordId = (int) ($record['titleid'] ?? 0);
    $recordTitle = coordinator_research_upper_name($record['title'] ?? '');
    $recordTitle = $recordTitle !== '' ? $recordTitle : 'UNTITLED RESEARCH';
    $recordType = trim((string) ($record['research_type'] ?? ''));
    $recordType = $recordType !== '' ? $recordType : 'Research record';
    $recordProgramLabel = coordinator_research_program_label($record);
    $recordAbstract = trim((string) ($record['other_details'] ?? ''));
    $recordDateSource = trim((string) ($record['submitted_at'] ?? '')) !== ''
        ? (string) ($record['submitted_at'] ?? '')
        : (string) ($record['updated_at'] ?? '');
    $recordDateLabel = coordinator_research_date_label($recordDateSource);
    $recordYearLabel = coordinator_research_year_label($record['submitted_at'] ?? null, $record['updated_at'] ?? null);
    $recordStatus = trim((string) ($record['status'] ?? ''));
    $recordStatus = $recordStatus !== '' ? $recordStatus : 'Pending';
    $recordAvatar = coordinator_research_avatar_palette($recordId);
    $recordSummary = $recordAbstract !== ''
        ? coordinator_research_trim_text($recordAbstract, 360)
        : coordinator_research_abstract_placeholder($record);

    ob_start();
    ?>
    <article class="coord-record">
      <div class="coord-record-shell">
        <div class="coord-record-visual">
          <div
            class="coord-record-avatar"
            style="--coord-avatar-accent: <?= e($recordAvatar['accent']); ?>; --coord-avatar-soft: <?= e($recordAvatar['soft']); ?>;"
            aria-hidden="true"
          >
            <i class="bx <?= e($recordAvatar['icon']); ?>"></i>
          </div>
        </div>

        <div class="coord-record-main">
          <div class="coord-record-topbar">
            <div class="coord-record-heading">
              <p class="coord-record-author"><?= e(coordinator_research_author_label($record['authors'] ?? '')); ?></p>
              <h3 class="coord-record-title"><?= e($recordTitle); ?></h3>
              <p class="coord-record-subtitle"><?= e($recordType); ?> | Submitted <?= e($recordDateLabel); ?></p>
            </div>

            <div class="coord-record-actions">
              <a class="coord-btn-secondary" href="<?= e(coordinator_research_url(['edit' => $recordId])); ?>" data-coord-edit-link>
                <i class="bx bx-edit"></i>
                Edit
              </a>
              <form
                method="post"
                action="<?= e(coordinator_research_url()); ?>"
                data-coord-confirm
                data-confirm-title="Delete Manuscript?"
                data-confirm-text="Delete this campus research record?"
                data-confirm-button="Delete"
                data-progress-title="Deleting..."
                data-progress-text="Removing the campus research record."
              >
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete_research">
                <input type="hidden" name="titleid" value="<?= e((string) $recordId); ?>">
                <button class="coord-btn-danger" type="submit">
                  <i class="bx bx-trash"></i>
                  Delete
                </button>
              </form>
            </div>
          </div>

          <div class="coord-record-metrics">
            <span class="coord-record-metric">
              <span class="coord-record-metric-icon"><i class="bx bx-calendar"></i></span>
              <strong><?= e($recordYearLabel); ?></strong>
            </span>
            <span class="coord-record-metric">
              <span class="coord-record-metric-icon"><i class="bx bx-book-content"></i></span>
              <strong><?= e($recordType); ?></strong>
            </span>
            <span class="coord-record-chip">
              <i class="bx bx-target-lock"></i>
              <?= e(coordinator_research_sdg_label($record['sdgs'] ?? '')); ?>
            </span>
            <span class="coord-record-chip coord-record-status">
              <i class="bx bx-check-shield"></i>
              <?= e($recordStatus); ?>
            </span>
          </div>

          <p class="coord-record-copy<?= $recordAbstract === '' ? ' empty' : ''; ?>"><?= e($recordSummary); ?></p>

          <div class="coord-record-footer">
            <div class="coord-record-affiliation">
              <span class="coord-record-affiliation-mark"><i class="bx bx-user"></i></span>
              <div>
                <div class="coord-record-label">Adviser</div>
                <div class="coord-record-domain"><?= e(coordinator_research_adviser_label($record['adviser_name'] ?? '')); ?></div>
              </div>
            </div>

            <div>
              <div class="coord-record-label">Research domain</div>
              <div class="coord-record-domain"><?= e($recordProgramLabel); ?></div>
            </div>
          </div>
        </div>
      </div>
    </article>
    <?php

    return (string) ob_get_clean();
}

$campusId = Auth::campusId();
$currentAccount = Auth::account() ?? [];
$currentAccountId = isset($currentAccount['accountid']) ? (int) $currentAccount['accountid'] : 0;
$editResearchId = coordinator_research_positive_int($_GET['edit'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$sdgOptions = coordinator_research_sdg_options();
$campusLabel = 'Campus #' . $campusId;
$researchTypes = [];
$academicYears = [];
$programOptions = [];
$programOptionMap = [];
$adviserOptions = [];
$records = [];
$recordsPerPage = 8;
$recordsOffset = coordinator_research_positive_int($_GET['offset'] ?? 0);
$totalRecords = 0;
$hasMoreRecords = false;
$nextRecordsOffset = 0;
$isRecordsPartialRequest = trim((string) ($_GET['partial'] ?? '')) === 'records';
$selectedRecord = null;
$pageError = null;
$formError = null;
$actionSuccess = get_flash('coordinator_research_success');
$actionError = get_flash('coordinator_research_error');
$statusOptions = ['Pending', 'On-going', 'Completed', 'Archived'];

if (!in_array($statusFilter, ['', 'Pending', 'On-going', 'Completed', 'Archived'], true)) {
    $statusFilter = '';
}

try {
    $pdo = Database::connection();
    coordinator_research_ensure_schema();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['partial'] ?? '')) === 'record') {
        header('Content-Type: application/json; charset=UTF-8');

        $record = coordinator_research_fetch($pdo, $editResearchId, $campusId);

        if ($record === null) {
            echo json_encode([
                'ok' => false,
                'message' => 'The selected research record was not found in your assigned campus.',
            ]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'record' => coordinator_research_record_payload($record),
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['partial'] ?? '')) === 'advisers') {
        header('Content-Type: application/json; charset=UTF-8');

        $page = max(1, coordinator_research_positive_int($_GET['page'] ?? 1));
        $pageSize = 20;
        $accounts = coordinator_research_adviser_options(
            $pdo,
            $campusId,
            trim((string) ($_GET['q'] ?? '')),
            $pageSize + 1,
            ($page - 1) * $pageSize
        );
        $hasMoreAccounts = count($accounts) > $pageSize;
        $accounts = array_slice($accounts, 0, $pageSize);

        echo json_encode([
            'results' => array_map(static function (array $account): array {
                return [
                    'id' => (string) ((int) ($account['accountid'] ?? 0)),
                    'text' => coordinator_research_account_option_label($account),
                ];
            }, $accounts),
            'pagination' => [
                'more' => $hasMoreAccounts,
            ],
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedAction = trim((string) ($_POST['action'] ?? ''));

        try {
            if (!verify_csrf_token(trim((string) ($_POST['csrf_token'] ?? '')))) {
                throw new RuntimeException('The request is invalid. Refresh the page and try again.');
            }

            if ($postedAction === 'save_research') {
                $researchId = coordinator_research_positive_int($_POST['titleid'] ?? 0);
                $title = coordinator_research_upper_name($_POST['title'] ?? '');
                $typeId = coordinator_research_positive_int($_POST['typeid'] ?? 0);
                $ayId = coordinator_research_positive_int($_POST['ayid'] ?? 0);
                $programId = coordinator_research_positive_int($_POST['programid'] ?? 0);
                $status = trim((string) ($_POST['status'] ?? 'Pending'));
                $authors = coordinator_research_upper_name($_POST['authors'] ?? '');
                $adviserAccountId = coordinator_research_positive_int($_POST['adviser_accountid'] ?? 0);
                $adviserName = '';
                $abstract = trim((string) ($_POST['other_details'] ?? ''));
                $sdgCodes = coordinator_research_sdg_codes($_POST['sdgs'] ?? []);

                if ($researchId > 0 && coordinator_research_fetch($pdo, $researchId, $campusId) === null) {
                    throw new RuntimeException('The selected research record was not found in your assigned campus.');
                }

                if ($title === '') {
                    throw new RuntimeException('Research title is required.');
                }

                if (coordinator_research_text_length($title) > 1000) {
                    throw new RuntimeException('Research title must be 1000 characters or fewer.');
                }

                if ($typeId < 1) {
                    throw new RuntimeException('Select a research type.');
                }

                $typeStatement = $pdo->prepare(
                    'SELECT researchtypeid
                     FROM tblresearchtype
                     WHERE researchtypeid = :typeid
                     LIMIT 1'
                );
                $typeStatement->bindValue(':typeid', $typeId, PDO::PARAM_INT);
                $typeStatement->execute();

                if ((int) $typeStatement->fetchColumn() < 1) {
                    throw new RuntimeException('The selected research type does not exist.');
                }

                if ($programId > 0) {
                    $programStatement = $pdo->prepare(
                        "SELECT course.courseid
                         FROM tblcourse course
                         INNER JOIN tblcollege college
                           ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                         WHERE course.courseid = :programid
                           AND CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = :campusid
                         LIMIT 1"
                    );
                    $programStatement->bindValue(':programid', $programId, PDO::PARAM_INT);
                    $programStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
                    $programStatement->execute();

                    if ((int) $programStatement->fetchColumn() < 1) {
                        throw new RuntimeException('Select a program that belongs to your assigned campus.');
                    }
                }

                if ($sdgCodes === []) {
                    throw new RuntimeException('Select at least one SDG.');
                }

                if (!in_array($status, $statusOptions, true)) {
                    throw new RuntimeException('Select a valid status.');
                }

                if (coordinator_research_text_length($authors) > 3000) {
                    throw new RuntimeException('Authors must be 3000 characters or fewer.');
                }

                if ($adviserAccountId > 0) {
                    $adviserAccount = coordinator_research_adviser_account($pdo, $adviserAccountId, $campusId);

                    if ($adviserAccount === null) {
                        throw new RuntimeException('Select a valid adviser.');
                    }

                    $adviserName = coordinator_research_account_option_name($adviserAccount);
                }

                if (coordinator_research_text_length($adviserName) > 150) {
                    throw new RuntimeException('Adviser name must be 150 characters or fewer.');
                }

                if (coordinator_research_text_length($abstract) > 5000) {
                    throw new RuntimeException('Abstract must be 5000 characters or fewer.');
                }

                $params = [
                    'typeid' => $typeId,
                    'campusid' => $campusId,
                    'ayid' => $ayId > 0 ? $ayId : null,
                    'status' => $status,
                    'encoder' => $currentAccountId > 0 ? $currentAccountId : null,
                    'programid' => $programId > 0 ? $programId : null,
                    'sdgs' => coordinator_research_sdg_storage($sdgCodes),
                    'title' => $title,
                    'normalized_title' => TitleSimilarity::normalizeTitle($title),
                    'other_details' => $abstract !== '' ? $abstract : null,
                    'authors' => $authors !== '' ? $authors : null,
                    'adviser_accountid' => $adviserAccountId > 0 ? $adviserAccountId : null,
                    'adviser_name' => $adviserName !== '' ? $adviserName : null,
                ];

                $pdo->beginTransaction();

                try {
                    if ($researchId > 0) {
                        $params['titleid'] = $researchId;
                        $statement = $pdo->prepare(
                            'UPDATE tblresearches
                             SET typeid = :typeid,
                                 ayid = :ayid,
                                 status = :status,
                                 encoder = :encoder,
                                 programid = :programid,
                                 sdgs = :sdgs,
                                 title = :title,
                                 normalized_title = :normalized_title,
                                 similarity_refreshed_at = NULL,
                                 other_details = :other_details,
                                 authors = :authors,
                                 adviser_accountid = :adviser_accountid,
                                 adviser_name = :adviser_name
                             WHERE titleid = :titleid
                               AND campusid = :campusid'
                        );
                        $message = 'Campus research record updated.';
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO tblresearches
                                (typeid, campusid, ayid, status, encoder, programid, sdgs, title, normalized_title, other_details, authors, adviser_accountid, adviser_name)
                             VALUES
                                (:typeid, :campusid, :ayid, :status, :encoder, :programid, :sdgs, :title, :normalized_title, :other_details, :authors, :adviser_accountid, :adviser_name)'
                        );
                        $message = 'Campus research record saved.';
                    }

                    $statement->execute($params);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $exception;
                }

                set_flash('coordinator_research_success', $message);
                redirect(coordinator_research_url());
            }

            if ($postedAction === 'delete_research') {
                $researchId = coordinator_research_positive_int($_POST['titleid'] ?? 0);

                if ($researchId < 1) {
                    throw new RuntimeException('The selected research record was not found in your assigned campus.');
                }

                $deleteAccessStatement = $pdo->prepare(
                    'SELECT titleid
                     FROM tblresearches
                     WHERE titleid = :titleid
                       AND campusid = :campusid
                     LIMIT 1'
                );
                $deleteAccessStatement->bindValue(':titleid', $researchId, PDO::PARAM_INT);
                $deleteAccessStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
                $deleteAccessStatement->execute();

                if ((int) $deleteAccessStatement->fetchColumn() < 1) {
                    throw new RuntimeException('The selected research record was not found in your assigned campus.');
                }

                $pdo->beginTransaction();

                try {
                    $deleteSimilarityReviews = $pdo->prepare(
                        'DELETE FROM tbltitlesimilarity_review
                         WHERE source_titleid = :source_titleid
                            OR target_titleid = :target_titleid'
                    );
                    $deleteSimilarityReviews->bindValue(':source_titleid', $researchId, PDO::PARAM_INT);
                    $deleteSimilarityReviews->bindValue(':target_titleid', $researchId, PDO::PARAM_INT);
                    $deleteSimilarityReviews->execute();

                    $deleteSimilarityScores = $pdo->prepare(
                        'DELETE FROM tbltitlesimilarity
                         WHERE source_titleid = :source_titleid
                            OR target_titleid = :target_titleid'
                    );
                    $deleteSimilarityScores->bindValue(':source_titleid', $researchId, PDO::PARAM_INT);
                    $deleteSimilarityScores->bindValue(':target_titleid', $researchId, PDO::PARAM_INT);
                    $deleteSimilarityScores->execute();

                    $deletePanelists = $pdo->prepare(
                        'DELETE FROM tblmanuscript_panelists
                         WHERE manuscriptid = :titleid'
                    );
                    $deletePanelists->bindValue(':titleid', $researchId, PDO::PARAM_INT);
                    $deletePanelists->execute();

                    $deleteRecord = $pdo->prepare(
                        'DELETE FROM tblresearches
                         WHERE titleid = :titleid
                           AND campusid = :campusid
                         LIMIT 1'
                    );
                    $deleteRecord->bindValue(':titleid', $researchId, PDO::PARAM_INT);
                    $deleteRecord->bindValue(':campusid', $campusId, PDO::PARAM_INT);
                    $deleteRecord->execute();

                    if ($deleteRecord->rowCount() < 1) {
                        throw new RuntimeException('The selected research record could not be deleted.');
                    }

                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $exception;
                }

                set_flash('coordinator_research_success', 'Campus research record deleted.');
                redirect(coordinator_research_url());
            }

            throw new RuntimeException('The requested action is not supported.');
        } catch (Throwable $exception) {
            if ($postedAction === 'save_research') {
                $formError = $exception->getMessage();
                $selectedRecord = [
                    'titleid' => coordinator_research_positive_int($_POST['titleid'] ?? 0),
                    'title' => coordinator_research_upper_name($_POST['title'] ?? ''),
                    'typeid' => coordinator_research_positive_int($_POST['typeid'] ?? 0),
                    'ayid' => coordinator_research_positive_int($_POST['ayid'] ?? 0),
                    'programid' => coordinator_research_positive_int($_POST['programid'] ?? 0),
                    'sdgs' => coordinator_research_sdg_storage($_POST['sdgs'] ?? []),
                    'status' => trim((string) ($_POST['status'] ?? 'Pending')),
                    'authors' => coordinator_research_upper_name($_POST['authors'] ?? ''),
                    'adviser_accountid' => coordinator_research_positive_int($_POST['adviser_accountid'] ?? 0),
                    'adviser_name' => '',
                    'other_details' => trim((string) ($_POST['other_details'] ?? '')),
                ];
                $editResearchId = (int) $selectedRecord['titleid'];
            } else {
                set_flash('coordinator_research_error', $exception->getMessage());
                redirect(coordinator_research_url());
            }
        }
    }

    $campusStatement = $pdo->prepare(
        'SELECT campusname
         FROM tblcampus
         WHERE campusid = :campusid
         LIMIT 1'
    );
    $campusStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $campusStatement->execute();
    $resolvedCampusLabel = trim((string) $campusStatement->fetchColumn());

    if ($resolvedCampusLabel !== '') {
        $campusLabel = $resolvedCampusLabel;
    }

    $researchTypes = $pdo->query(
        'SELECT researchtypeid, research_type, is_active
         FROM tblresearchtype
         ORDER BY is_active DESC, research_type ASC'
    )->fetchAll();

    $academicYears = $pdo->query(
        'SELECT ayid, ay_from, ay_to
         FROM tblacademic_year
         ORDER BY ay_from DESC, ay_to DESC, ayid DESC'
    )->fetchAll();

    $programStatement = $pdo->prepare(
        "SELECT course.courseid AS programid,
                course.coursecode,
                course.coursedescription,
                course.coursemajor
         FROM tblcourse course
         INNER JOIN tblcollege college
           ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
         WHERE CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = :campusid
         ORDER BY course.coursecode ASC, course.coursedescription ASC, course.courseid ASC"
    );
    $programStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $programStatement->execute();
    $programOptions = $programStatement->fetchAll();

    foreach ($programOptions as $programOption) {
        $programOptionId = isset($programOption['programid']) ? (int) $programOption['programid'] : 0;

        if ($programOptionId > 0) {
            $programOptionMap[$programOptionId] = coordinator_research_program_label($programOption);
        }
    }

    if (!$isRecordsPartialRequest) {
        $adviserStatement = $pdo->prepare(
            'SELECT accountid, acc_name, email, is_enabled, campus
             FROM tblaccount
             ORDER BY CASE
                        WHEN campus = :campusid THEN 0
                        WHEN campus IS NULL OR campus = 0 THEN 1
                        ELSE 2
                      END,
                      is_enabled DESC,
                      acc_name ASC,
                      email ASC,
                      accountid ASC'
        );
        $adviserStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
        $adviserStatement->execute();
        $adviserOptions = $adviserStatement->fetchAll();
    }

    if ($selectedRecord === null && $editResearchId > 0) {
        $selectedRecord = coordinator_research_fetch($pdo, $editResearchId, $campusId);

        if ($selectedRecord === null) {
            $actionError = $actionError ?? 'The selected research record is not available in your campus.';
            $editResearchId = 0;
        }
    }

    $conditions = ['m.campusid = :campusid'];
    $params = ['campusid' => $campusId];

    if ($search !== '') {
        $conditions[] = '(m.title LIKE :search_title
            OR m.authors LIKE :search_authors
            OR m.adviser_name LIKE :search_adviser
            OR a.acc_name LIKE :search_account_adviser
            OR a.email LIKE :search_account_email
            OR p.coursecode LIKE :search_coursecode
            OR p.coursedescription LIKE :search_coursedescription)';
        $searchLike = '%' . $search . '%';
        $params['search_title'] = $searchLike;
        $params['search_authors'] = $searchLike;
        $params['search_adviser'] = $searchLike;
        $params['search_account_adviser'] = $searchLike;
        $params['search_account_email'] = $searchLike;
        $params['search_coursecode'] = $searchLike;
        $params['search_coursedescription'] = $searchLike;
    }

    if ($statusFilter !== '') {
        $conditions[] = 'm.status = :status';
        $params['status'] = $statusFilter;
    }

    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tblresearches m
         LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
         LEFT JOIN tblcourse p ON p.courseid = m.programid'
        . $whereClause
    );

    foreach ($params as $name => $value) {
        $countStatement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $countStatement->execute();
    $totalRecords = (int) $countStatement->fetchColumn();

    $recordsStatement = $pdo->prepare(
        "SELECT m.titleid,
                m.title,
                m.typeid,
                m.authors,
                m.sdgs,
                m.status,
                m.programid,
                m.other_details,
                m.adviser_accountid,
                COALESCE(
                    NULLIF(TRIM(m.adviser_name), ''),
                    NULLIF(TRIM(a.acc_name), ''),
                    NULLIF(TRIM(a.email), '')
                ) AS adviser_name,
                a.acc_name AS adviser_account_name,
                a.email AS adviser_email,
                a.is_enabled AS adviser_is_enabled,
                m.submitted_at,
                m.updated_at,
                rt.research_type,
                p.coursecode,
                p.coursedescription,
                p.coursemajor
         FROM tblresearches m
         LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         LEFT JOIN tblcourse p ON p.courseid = m.programid"
        . $whereClause .
        ' ORDER BY COALESCE(m.updated_at, m.submitted_at) DESC, m.titleid DESC
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $name => $value) {
        $recordsStatement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $recordsStatement->bindValue(':limit', $recordsPerPage, PDO::PARAM_INT);
    $recordsStatement->bindValue(':offset', $recordsOffset, PDO::PARAM_INT);
    $recordsStatement->execute();
    $records = $recordsStatement->fetchAll();
    $nextRecordsOffset = $recordsOffset + count($records);
    $hasMoreRecords = $nextRecordsOffset < $totalRecords;
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

if ($isRecordsPartialRequest) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($pageError !== null) {
        echo json_encode([
            'ok' => false,
            'message' => $pageError,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'html' => implode('', array_map('coordinator_research_record_html', $records)),
        'hasMore' => $hasMoreRecords,
        'nextOffset' => $nextRecordsOffset,
        'total' => $totalRecords,
    ]);
    exit;
}

if (!is_array($selectedRecord)) {
    $selectedRecord = [
        'titleid' => 0,
        'title' => '',
        'typeid' => 0,
        'ayid' => 0,
        'programid' => 0,
        'sdgs' => '',
        'status' => 'Pending',
        'authors' => '',
        'adviser_accountid' => 0,
        'adviser_name' => '',
        'other_details' => '',
    ];
}

$selectedResearchId = coordinator_research_positive_int($selectedRecord['titleid'] ?? 0);
$selectedTypeId = coordinator_research_positive_int($selectedRecord['typeid'] ?? 0);
$selectedAyId = coordinator_research_positive_int($selectedRecord['ayid'] ?? 0);
$selectedProgramId = coordinator_research_positive_int($selectedRecord['programid'] ?? 0);
$selectedSdgCodes = coordinator_research_sdg_codes($selectedRecord['sdgs'] ?? []);
$selectedAdviserAccountId = coordinator_research_positive_int($selectedRecord['adviser_accountid'] ?? 0);
$selectedStatus = trim((string) ($selectedRecord['status'] ?? 'Pending'));
$selectedAdviserOptionLabel = '';

if ($selectedAdviserAccountId > 0) {
    $selectedAdviserAccount = null;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $selectedAdviserAccount = coordinator_research_adviser_account($pdo, $selectedAdviserAccountId, $campusId);
        } catch (Throwable $exception) {
            $selectedAdviserAccount = null;
        }
    }

    $selectedAdviserOptionLabel = $selectedAdviserAccount !== null
        ? coordinator_research_account_option_label($selectedAdviserAccount)
        : coordinator_research_account_option_label([
            'accountid' => $selectedAdviserAccountId,
            'acc_name' => $selectedRecord['adviser_account_name'] ?? $selectedRecord['adviser_name'] ?? '',
            'email' => $selectedRecord['adviser_email'] ?? '',
            'is_enabled' => $selectedRecord['adviser_is_enabled'] ?? 1,
        ]);
}

if (!in_array($selectedStatus, $statusOptions, true)) {
    $selectedStatus = 'Pending';
}

$shouldOpenFormModal = $formError !== null || $selectedResearchId > 0;
$actionSuccessTitle = 'Saved';

if ($actionSuccess !== null) {
    $normalizedSuccess = strtolower($actionSuccess);

    if (strpos($normalizedSuccess, 'deleted') !== false) {
        $actionSuccessTitle = 'Deleted';
    } elseif (strpos($normalizedSuccess, 'updated') !== false) {
        $actionSuccessTitle = 'Updated';
    }
}

$extraStyles = '
.coord-list-card {
  padding: 1.15rem;
}

.coord-list-card {
  width: 100%;
}

.coord-modal-copy {
  margin: 0;
  color: #6b7280;
  line-height: 1.55;
}

#manuscriptRecordModal .modal-dialog {
  max-width: min(72rem, calc(100vw - 2rem));
  margin-top: 1rem;
  margin-bottom: 1rem;
}

#manuscriptRecordModal .modal-content,
#manuscriptRecordModal .coord-manuscript-form {
  max-height: calc(100vh - 2rem);
}

#manuscriptRecordModal .modal-content {
  overflow: hidden;
}

#manuscriptRecordModal .coord-manuscript-form {
  display: flex;
  min-height: 0;
  flex-direction: column;
}

#manuscriptRecordModal .modal-header,
#manuscriptRecordModal .modal-footer {
  flex: 0 0 auto;
}

#manuscriptRecordModal .modal-body {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-color: #cbd5e1 transparent;
  scrollbar-width: thin;
}

#manuscriptRecordModal .modal-body::-webkit-scrollbar {
  width: 0.45rem;
}

#manuscriptRecordModal .modal-body::-webkit-scrollbar-track {
  background: transparent;
}

#manuscriptRecordModal .modal-body::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 999px;
}

.coord-modal-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.95rem;
}

.coord-modal-grid .full-width {
  grid-column: 1 / -1;
}

.coord-form-grid {
  display: grid;
  gap: 0.9rem;
}

.coord-form-grid label {
  display: block;
}

.coord-form-grid span {
  display: block;
  margin-bottom: 0.35rem;
  color: #374151;
  font-weight: 600;
}

.coord-form-grid input,
.coord-form-grid select,
.coord-form-grid textarea {
  width: 100%;
  min-height: 2.75rem;
  border: 1px solid #d1d5db;
  border-radius: 0.7rem;
  background: #ffffff;
  color: #111827;
  padding: 0.62rem 0.82rem;
}

.coord-form-grid textarea {
  min-height: 7.5rem;
}

.coord-uppercase-field {
  text-transform: uppercase;
}

.coord-form-grid select[multiple]:not(.coord-select2-multiple) {
  min-height: 9rem;
}

.coord-form-grid select.coord-select2-multiple {
  min-height: 2.75rem;
}

.coord-select2-field {
  position: relative;
}

.coord-select2-field .select2-container {
  width: 100% !important;
}

.coord-select2-field .select2-container span {
  margin-bottom: 0;
  color: inherit;
  font-weight: inherit;
}

.coord-select2-field .select2-container--default .select2-selection--single {
  min-height: 2.75rem;
  display: flex;
  align-items: center;
  border: 1px solid #d1d5db;
  border-radius: 0.7rem;
  background: #ffffff;
  padding: 0.5rem 2.2rem 0.5rem 0.82rem;
}

.coord-select2-field .select2-container--default.select2-container--focus .select2-selection--single,
.coord-select2-field .select2-container--default.select2-container--open .select2-selection--single {
  border-color: #86efac;
  box-shadow: 0 0 0 0.18rem rgba(34, 197, 94, 0.12);
}

.coord-select2-field .select2-container--default .select2-selection--single .select2-selection__rendered {
  width: 100%;
  padding: 0;
  color: #111827;
  line-height: 1.35;
}

.coord-select2-field .select2-container--default .select2-selection--single .select2-selection__placeholder {
  color: #6b7280;
}

.coord-select2-field .select2-container--default .select2-selection--single .select2-selection__clear {
  margin-right: 0.65rem;
  color: #6b7280;
}

.coord-select2-field .select2-container--default .select2-selection--single .select2-selection__arrow {
  top: 50%;
  right: 0.65rem;
  transform: translateY(-50%);
}

.coord-select2-dropdown {
  z-index: 1065;
  border-color: #d1d5db;
  border-radius: 0.75rem;
  overflow: hidden;
  box-shadow: 0 1rem 2.25rem rgba(15, 23, 42, 0.14);
}

.coord-select2-dropdown .select2-search--dropdown {
  padding: 0.65rem;
}

.coord-select2-dropdown .select2-search__field {
  min-height: 2.45rem;
  border: 1px solid #d1d5db;
  border-radius: 0.55rem;
  padding: 0.45rem 0.65rem;
}

.coord-select2-dropdown .select2-results__option {
  padding: 0.62rem 0.8rem;
  font-size: 0.9rem;
}

.coord-select2-dropdown .select2-results__option--highlighted[aria-selected] {
  background: #15803d;
}

#manuscriptRecordModal .select2-dropdown {
  z-index: 1065;
  border-color: #d1d5db;
  border-radius: 0.75rem;
  overflow: hidden;
  box-shadow: 0 1rem 2.25rem rgba(15, 23, 42, 0.14);
}

#manuscriptRecordModal .select2-search--dropdown {
  padding: 0.65rem;
}

#manuscriptRecordModal .select2-search__field {
  min-height: 2.45rem;
  border: 1px solid #d1d5db;
  border-radius: 0.55rem;
  padding: 0.45rem 0.65rem;
}

#manuscriptRecordModal .select2-results__option {
  padding: 0.62rem 0.8rem;
  font-size: 0.9rem;
}

#manuscriptRecordModal .select2-results__option--highlighted[aria-selected] {
  background: #15803d;
}

.coord-sdg-field {
  position: relative;
}

.coord-sdg-field .select2-container {
  width: 100% !important;
}

.coord-sdg-field .select2-container span {
  margin-bottom: 0;
  color: inherit;
  font-weight: inherit;
}

.coord-sdg-field .select2-container--default .select2-selection--multiple {
  min-height: 2.75rem;
  border: 1px solid #d1d5db;
  border-radius: 0.7rem;
  background: #ffffff;
  padding: 0.2rem 0.35rem;
}

.coord-sdg-field .select2-container--default.select2-container--focus .select2-selection--multiple {
  border-color: #86efac;
  box-shadow: 0 0 0 0.18rem rgba(34, 197, 94, 0.12);
}

.coord-sdg-field .select2-container--default .select2-selection--multiple .select2-selection__choice {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.25rem;
  border: 1px solid #bbf7d0;
  border-radius: 999px;
  background: #f0fdf4;
  color: #14532d;
  font-size: 0.84rem;
  font-weight: 600;
  padding: 0.24rem 0.55rem 0.24rem 1.45rem;
}

.coord-sdg-field .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
  position: absolute;
  left: 0.48rem;
  top: 50%;
  margin: 0;
  border: 0;
  color: #15803d;
  transform: translateY(-50%);
}

.coord-sdg-field .select2-container--default .select2-search--inline .select2-search__field {
  min-height: 2rem;
  margin-top: 0.18rem;
  border: 0 !important;
  outline: 0;
  box-shadow: none;
  padding: 0.2rem;
  font-family: "Public Sans", "Segoe UI", Arial, sans-serif;
}

.coord-sdg-dropdown {
  z-index: 1065;
  border-color: #d1d5db;
  border-radius: 0.75rem;
  overflow: hidden;
  box-shadow: 0 1rem 2.25rem rgba(15, 23, 42, 0.14);
}

.coord-sdg-dropdown .select2-results__option {
  padding: 0.62rem 0.8rem;
  font-size: 0.9rem;
}

.coord-sdg-dropdown .select2-results__option--highlighted[aria-selected] {
  background: #15803d;
}

.coord-form-actions,
.coord-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
  align-items: center;
}

.coord-toolbar {
  justify-content: space-between;
  margin-bottom: 1rem;
}

.coord-toolbar-main h2 {
  color: #283027;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-size: clamp(1.45rem, 1.6vw, 1.8rem);
  font-weight: 700;
  letter-spacing: -0.04em;
}

.coord-filter-form {
  display: grid;
  grid-template-columns: minmax(16rem, 1fr) minmax(11rem, 0.45fr) auto;
  gap: 0.65rem;
  width: 100%;
}

.coord-filter-form input,
.coord-filter-form select {
  min-height: 2.65rem;
  border: 1px solid #d1d5db;
  border-radius: 0.7rem;
  padding: 0.55rem 0.75rem;
}

.coord-record-list {
  display: grid;
  gap: 1rem;
}

.coord-scroll-sentinel {
  min-height: 3rem;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 1rem;
  color: #6b7280;
  font-size: 0.88rem;
  font-weight: 600;
}

.coord-scroll-sentinel[hidden] {
  display: none;
}

.coord-scroll-more {
  border: 1px solid #bbf7d0;
  border-radius: 999px;
  background: #f0fdf4;
  color: #14532d;
  padding: 0.5rem 0.95rem;
  font-weight: 700;
}

.coord-record {
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 0.9rem;
  background: #ffffff;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.coord-record:hover {
  border-color: #bbf7d0;
  box-shadow: 0 1rem 2.5rem rgba(15, 23, 42, 0.08);
}

.coord-record-shell {
  display: grid;
  grid-template-columns: 4.45rem minmax(0, 1fr);
  gap: 1.25rem;
  padding: 1.25rem;
}

.coord-record-visual {
  position: relative;
  width: 4.2rem;
}

.coord-record-avatar {
  width: 4.2rem;
  height: 4.2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  background: var(--coord-avatar-soft, #dcfce7);
  color: var(--coord-avatar-accent, #15803d);
}

.coord-record-avatar i {
  font-size: 1.85rem;
}

.coord-record-main {
  min-width: 0;
}

.coord-record-topbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1.25rem;
}

.coord-record-heading {
  min-width: 0;
}

.coord-record-author {
  margin: 0 0 0.25rem;
  color: #374151;
  font-size: 0.94rem;
  font-weight: 600;
  line-height: 1.35;
}

.coord-record-title {
  margin: 0;
  color: #283027;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-size: clamp(1.12rem, 1.2vw, 1.4rem);
  font-weight: 700;
  letter-spacing: -0.04em;
  line-height: 1.22;
}

.coord-record-subtitle {
  margin: 0.3rem 0 0;
  color: #6d7469;
  font-size: 0.9rem;
}

.coord-record-meta,
.coord-record-copy {
  margin: 0;
  color: #6b7280;
  line-height: 1.55;
}

.coord-record-metrics {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem 0.85rem;
  margin-top: 0.9rem;
}

.coord-record-metric {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: #374151;
  font-size: 0.88rem;
}

.coord-record-metric strong {
  font-weight: 600;
}

.coord-record-metric-icon {
  width: 2.15rem;
  height: 2.15rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #d1d5db;
  border-radius: 999px;
  background: #ffffff;
  color: #4b5563;
  font-size: 1rem;
}

.coord-record-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
  min-height: 2.15rem;
  padding: 0.42rem 0.78rem;
  border: 1px solid #bbf7d0;
  border-radius: 999px;
  background: #f0fdf4;
  color: #15803d;
  font-size: 0.84rem;
  font-weight: 600;
}

.coord-record-status {
  border-color: #dbeafe;
  background: #eff6ff;
  color: #1d4ed8;
}

.coord-record-copy {
  margin-top: 0.9rem;
  color: #374151;
  font-size: 0.9rem;
  line-height: 1.65;
}

.coord-record-copy.empty {
  color: #6b7280;
  font-style: italic;
}

.coord-record-footer {
  display: grid;
  grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.1fr);
  gap: 1rem;
  margin-top: 0.95rem;
  padding-top: 0.95rem;
  border-top: 1px solid #e5e7eb;
}

.coord-record-affiliation {
  display: flex;
  gap: 0.85rem;
  align-items: center;
  min-width: 0;
}

.coord-record-affiliation-mark {
  width: 3rem;
  height: 3rem;
  flex: 0 0 3rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.85rem;
  background: #f3f4f6;
  color: #15803d;
  font-size: 1.2rem;
}

.coord-record-label {
  color: #6b7280;
  font-size: 0.78rem;
  font-weight: 600;
}

.coord-record-domain {
  color: #111827;
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.35;
}

.coord-record-actions {
  flex: 0 0 9rem;
  display: grid;
  gap: 0.65rem;
  align-self: flex-start;
}

.coord-record-actions .coord-btn-secondary,
.coord-record-actions .coord-btn-danger,
.coord-record-actions form {
  width: 100%;
}

.coord-record-actions form {
  margin: 0;
}

@media (max-width: 1199.98px) {
  .coord-filter-form {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 991.98px) {
  .coord-record-topbar {
    flex-direction: column;
  }

  .coord-record-actions {
    width: 100%;
    flex-basis: auto;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .coord-record-footer {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 767.98px) {
  .coord-modal-grid {
    grid-template-columns: 1fr;
  }

  .coord-record-shell {
    grid-template-columns: 1fr;
    padding: 1.15rem;
  }

  .coord-record-visual {
    width: 4rem;
  }

  .coord-record-avatar {
    width: 4rem;
    height: 4rem;
  }
}
';

$extraHead = '';
$extraScripts = '
<script>
jQuery(function ($) {
  var modalElement = document.getElementById("manuscriptRecordModal");
  var shouldOpenModal = ' . ($shouldOpenFormModal ? 'true' : 'false') . ';
  var $modal = $("#manuscriptRecordModal");
  var $sdgSelect = $modal.find(".js-example-basic-multiple");
  var $adviserSelect = $modal.find(".js-example-basic-single");
  var recordList = document.querySelector("[data-coord-record-list]");
  var recordSentinel = document.querySelector("[data-coord-record-sentinel]");
  var recordLoadButton = document.querySelector("[data-coord-record-load-more]");
  var isLoadingRecords = false;

  function initSelect2Control($select, options, forceRefresh) {
    if (!$select.length || typeof $.fn.select2 !== "function") {
      return;
    }

    if ($select.hasClass("select2-hidden-accessible")) {
      if (!forceRefresh) {
        $select.trigger("change.select2");
        return;
      }

      $select.select2("destroy");
    }

    try {
      $select.select2(options);
    } catch (error) {
      console.warn("Select2 initialization failed with custom options; retrying basic mode.", error);
      $select.select2();
    }
  }

  function initSdgSelect(forceRefresh) {
    initSelect2Control($sdgSelect, {
      closeOnSelect: false,
      dropdownParent: $modal,
      placeholder: $sdgSelect.data("placeholder") || "Select SDGs",
      width: "100%"
    }, forceRefresh);
  }

  function initAdviserSelect(forceRefresh) {
    initSelect2Control($adviserSelect, {
      allowClear: true,
      dropdownParent: $modal,
      minimumResultsForSearch: 0,
      placeholder: $adviserSelect.data("placeholder") || "Select adviser",
      width: "100%"
    }, forceRefresh);
  }

  function initSelect2Fields(forceRefresh) {
    initSdgSelect(forceRefresh);
    initAdviserSelect(forceRefresh);
  }

  function openManuscriptModal() {
    if (!modalElement) {
      return;
    }

    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
      return;
    }

    var opener = document.getElementById("manuscriptRecordModalAutoOpen");

    if (opener) {
      opener.click();
    }
  }

  function setRecordLoadingState(isLoading) {
    isLoadingRecords = isLoading;

    if (recordLoadButton) {
      recordLoadButton.disabled = isLoading;
      recordLoadButton.textContent = isLoading ? "Loading..." : "Load more";
    }
  }

  function updateRecordSentinel(hasMore, nextOffset) {
    if (!recordSentinel) {
      return;
    }

    recordSentinel.dataset.hasMore = hasMore ? "1" : "0";
    recordSentinel.dataset.nextOffset = String(nextOffset || 0);
    recordSentinel.hidden = !hasMore;
  }

  function recordRequestUrl() {
    var nextUrl = new URL(window.location.href);
    nextUrl.searchParams.set("partial", "records");
    nextUrl.searchParams.set("offset", recordSentinel ? recordSentinel.dataset.nextOffset || "0" : "0");
    nextUrl.searchParams.delete("edit");
    return nextUrl;
  }

  function loadMoreRecords() {
    if (!recordList || !recordSentinel || recordSentinel.dataset.hasMore !== "1" || isLoadingRecords) {
      return;
    }

    setRecordLoadingState(true);

    fetch(recordRequestUrl().toString(), {
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest"
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Unable to load more records.");
        }

        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.ok) {
          throw new Error(payload && payload.message ? payload.message : "Unable to load more records.");
        }

        if (payload.html) {
          recordList.insertAdjacentHTML("beforeend", payload.html);
        }

        updateRecordSentinel(Boolean(payload.hasMore), payload.nextOffset);
      })
      .catch(function (error) {
        if (recordLoadButton) {
          recordLoadButton.textContent = "Try again";
        }

        console.warn(error);
      })
      .finally(function () {
        setRecordLoadingState(false);
      });
  }

  initSelect2Fields(false);

  if (modalElement) {
    $modal.on("shown.bs.modal", function () {
      initSelect2Fields(true);

      var modalBody = modalElement.querySelector(".modal-body");

      if (modalBody) {
        modalBody.scrollTop = 0;
      }
    });
  }

  if (shouldOpenModal) {
    window.setTimeout(openManuscriptModal, 0);
  }

  document.addEventListener("click", function (event) {
    var eventTarget = event.target;

    if (!eventTarget || !eventTarget.closest) {
      return;
    }

    var editLink = eventTarget.closest("[data-coord-edit-link]");

    if (editLink) {
      var targetUrl = new URL(editLink.href, window.location.href);

      if (targetUrl.href === window.location.href) {
        event.preventDefault();
        openManuscriptModal();
      }
    }
  });

  if (recordLoadButton) {
    recordLoadButton.addEventListener("click", loadMoreRecords);
  }

  if (recordSentinel && "IntersectionObserver" in window) {
    var recordObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          loadMoreRecords();
        }
      });
    }, { rootMargin: "320px 0px" });

    recordObserver.observe(recordSentinel);
  }
});
</script>
';

ob_start();
?>
<?php if ($pageError !== null): ?>
  <div class="coord-alert error" data-coord-swal data-swal-icon="error" data-swal-title="Unable to Load Page" data-swal-text="<?= e($pageError); ?>" hidden><?= e($pageError); ?></div>
<?php endif; ?>
<?php if ($actionSuccess !== null): ?>
  <div class="coord-alert success" data-coord-swal data-swal-icon="success" data-swal-title="<?= e($actionSuccessTitle); ?>" data-swal-text="<?= e($actionSuccess); ?>" hidden><?= e($actionSuccess); ?></div>
<?php endif; ?>
<?php if ($actionError !== null): ?>
  <div class="coord-alert error" data-coord-swal data-swal-icon="error" data-swal-title="Action Failed" data-swal-text="<?= e($actionError); ?>" hidden><?= e($actionError); ?></div>
<?php endif; ?>

<section class="coord-research-page">
  <article class="coord-card coord-list-card">
    <div class="coord-toolbar">
      <div class="coord-toolbar-main">
        <h2 class="mb-1">Campus Manuscript List</h2>
        <p class="coord-muted mb-0">Showing <?= e(number_format(min($nextRecordsOffset, $totalRecords))); ?> of <?= e(number_format($totalRecords)); ?> manuscript record(s) from <?= e($campusLabel); ?>.</p>
      </div>
      <?php if ($selectedResearchId > 0): ?>
        <a class="coord-btn-primary" href="<?= e(coordinator_research_url()); ?>">
          <i class="bx bx-plus"></i>
          Encode Manuscript
        </a>
      <?php else: ?>
        <button class="coord-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#manuscriptRecordModal">
          <i class="bx bx-plus"></i>
          Encode Manuscript
        </button>
      <?php endif; ?>
    </div>

    <form class="coord-filter-form mb-3" method="get" action="<?= e(coordinator_research_url()); ?>">
      <input type="search" name="q" value="<?= e($search); ?>" placeholder="Search manuscript title, author, adviser, or program">
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($statusOptions as $statusOption): ?>
          <option value="<?= e($statusOption); ?>"<?= $statusFilter === $statusOption ? ' selected' : ''; ?>>
            <?= e($statusOption); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="coord-form-actions">
        <button class="coord-btn-primary" type="submit">Filter</button>
        <a class="coord-btn-secondary" href="<?= e(coordinator_research_url()); ?>">Reset</a>
      </div>
    </form>

    <div class="coord-record-list" data-coord-record-list>
      <?php if ($records === []): ?>
        <p class="coord-muted mb-0">No campus manuscript records matched the current view.</p>
      <?php endif; ?>

      <?php foreach ($records as $record): ?>
        <?php
        $recordId = (int) ($record['titleid'] ?? 0);
        $recordTitle = coordinator_research_upper_name($record['title'] ?? '');
        $recordTitle = $recordTitle !== '' ? $recordTitle : 'UNTITLED RESEARCH';
        $recordType = trim((string) ($record['research_type'] ?? ''));
        $recordType = $recordType !== '' ? $recordType : 'Research record';
        $recordProgramLabel = coordinator_research_program_label($record);
        $recordAbstract = trim((string) ($record['other_details'] ?? ''));
        $recordDateSource = trim((string) ($record['submitted_at'] ?? '')) !== ''
            ? (string) ($record['submitted_at'] ?? '')
            : (string) ($record['updated_at'] ?? '');
        $recordDateLabel = coordinator_research_date_label($recordDateSource);
        $recordYearLabel = coordinator_research_year_label($record['submitted_at'] ?? null, $record['updated_at'] ?? null);
        $recordStatus = trim((string) ($record['status'] ?? ''));
        $recordStatus = $recordStatus !== '' ? $recordStatus : 'Pending';
        $recordAvatar = coordinator_research_avatar_palette($recordId);
        $recordSummary = $recordAbstract !== ''
            ? coordinator_research_trim_text($recordAbstract, 360)
            : coordinator_research_abstract_placeholder($record);
        ?>
        <article class="coord-record">
          <div class="coord-record-shell">
            <div class="coord-record-visual">
              <div
                class="coord-record-avatar"
                style="--coord-avatar-accent: <?= e($recordAvatar['accent']); ?>; --coord-avatar-soft: <?= e($recordAvatar['soft']); ?>;"
                aria-hidden="true"
              >
                <i class="bx <?= e($recordAvatar['icon']); ?>"></i>
              </div>
            </div>

            <div class="coord-record-main">
              <div class="coord-record-topbar">
                <div class="coord-record-heading">
                  <p class="coord-record-author"><?= e(coordinator_research_author_label($record['authors'] ?? '')); ?></p>
                  <h3 class="coord-record-title"><?= e($recordTitle); ?></h3>
                  <p class="coord-record-subtitle"><?= e($recordType); ?> | Submitted <?= e($recordDateLabel); ?></p>
                </div>

                <div class="coord-record-actions">
                  <a class="coord-btn-secondary" href="<?= e(coordinator_research_url(['edit' => $recordId])); ?>" data-coord-edit-link>
                    <i class="bx bx-edit"></i>
                    Edit
                  </a>
                    <form
                      method="post"
                      action="<?= e(coordinator_research_url()); ?>"
                      data-coord-confirm
                      data-confirm-title="Delete Manuscript?"
                      data-confirm-text="Delete this campus research record?"
                      data-confirm-button="Delete"
                      data-progress-title="Deleting..."
                      data-progress-text="Removing the campus research record."
                    >
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete_research">
                    <input type="hidden" name="titleid" value="<?= e((string) $recordId); ?>">
                    <button class="coord-btn-danger" type="submit">
                      <i class="bx bx-trash"></i>
                      Delete
                    </button>
                  </form>
                </div>
              </div>

              <div class="coord-record-metrics">
                <span class="coord-record-metric">
                  <span class="coord-record-metric-icon"><i class="bx bx-calendar"></i></span>
                  <strong><?= e($recordYearLabel); ?></strong>
                </span>
                <span class="coord-record-metric">
                  <span class="coord-record-metric-icon"><i class="bx bx-book-content"></i></span>
                  <strong><?= e($recordType); ?></strong>
                </span>
                <span class="coord-record-chip">
                  <i class="bx bx-target-lock"></i>
                  <?= e(coordinator_research_sdg_label($record['sdgs'] ?? '')); ?>
                </span>
                <span class="coord-record-chip coord-record-status">
                  <i class="bx bx-check-shield"></i>
                  <?= e($recordStatus); ?>
                </span>
              </div>

              <p class="coord-record-copy<?= $recordAbstract === '' ? ' empty' : ''; ?>"><?= e($recordSummary); ?></p>

              <div class="coord-record-footer">
                <div class="coord-record-affiliation">
                  <span class="coord-record-affiliation-mark"><i class="bx bx-user"></i></span>
                  <div>
                    <div class="coord-record-label">Adviser</div>
                    <div class="coord-record-domain"><?= e(coordinator_research_adviser_label($record['adviser_name'] ?? '')); ?></div>
                  </div>
                </div>

                <div>
                  <div class="coord-record-label">Research domain</div>
                  <div class="coord-record-domain"><?= e($recordProgramLabel); ?></div>
                </div>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div
      class="coord-scroll-sentinel"
      data-coord-record-sentinel
      data-has-more="<?= $hasMoreRecords ? '1' : '0'; ?>"
      data-next-offset="<?= e((string) $nextRecordsOffset); ?>"
      <?= $hasMoreRecords ? '' : 'hidden'; ?>
    >
      <button class="coord-scroll-more" type="button" data-coord-record-load-more>Load more</button>
    </div>
  </article>

  <?php if ($shouldOpenFormModal): ?>
    <button
      id="manuscriptRecordModalAutoOpen"
      class="d-none"
      type="button"
      data-bs-toggle="modal"
      data-bs-target="#manuscriptRecordModal"
      tabindex="-1"
      aria-hidden="true"
    ></button>
  <?php endif; ?>

  <div class="modal fade" id="manuscriptRecordModal" tabindex="-1" aria-labelledby="manuscriptRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form class="coord-manuscript-form" method="post" action="<?= e(coordinator_research_url()); ?>">
          <div class="modal-header">
            <div>
              <h2 class="modal-title h4" id="manuscriptRecordModalLabel"><?= e($selectedResearchId > 0 ? 'Edit Manuscript Record' : 'Encode Manuscript Record'); ?></h2>
              <p class="coord-modal-copy">Create and maintain campus research, capstone, proposal, and related manuscript records for <?= e($campusLabel); ?>.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <?php if ($formError !== null): ?>
              <div class="coord-alert error" data-coord-swal data-swal-icon="error" data-swal-title="Check Manuscript Details" data-swal-text="<?= e($formError); ?>" hidden><?= e($formError); ?></div>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_research">
            <input type="hidden" name="titleid" value="<?= e((string) $selectedResearchId); ?>">

            <div class="coord-form-grid coord-modal-grid">
              <label class="full-width">
                <span>Research Title</span>
                <input class="coord-uppercase-field" type="text" name="title" maxlength="1000" value="<?= e(coordinator_research_upper_name($selectedRecord['title'] ?? '')); ?>" required>
              </label>

              <label>
                <span>Research Type</span>
                <select name="typeid" required>
                  <option value="">Select research type</option>
                  <?php foreach ($researchTypes as $type): ?>
                    <?php
                    $typeId = (int) ($type['researchtypeid'] ?? 0);
                    $typeLabel = (string) ($type['research_type'] ?? 'Research type');
                    if ((int) ($type['is_active'] ?? 1) !== 1) {
                        $typeLabel .= ' (Inactive)';
                    }
                    ?>
                    <option value="<?= e((string) $typeId); ?>"<?= $typeId === $selectedTypeId ? ' selected' : ''; ?>>
                      <?= e($typeLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label>
                <span>Program / Course</span>
                <select name="programid">
                  <option value="">No specific program</option>
                  <?php foreach ($programOptions as $program): ?>
                    <?php $programId = (int) ($program['programid'] ?? 0); ?>
                    <option value="<?= e((string) $programId); ?>"<?= $programId === $selectedProgramId ? ' selected' : ''; ?>>
                      <?= e(coordinator_research_program_label($program)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label>
                <span>Academic Year</span>
                <select name="ayid">
                  <option value="">Select academic year</option>
                  <?php foreach ($academicYears as $academicYear): ?>
                    <?php $ayId = (int) ($academicYear['ayid'] ?? 0); ?>
                    <option value="<?= e((string) $ayId); ?>"<?= $ayId === $selectedAyId ? ' selected' : ''; ?>>
                      <?= e(trim((string) ($academicYear['ay_from'] ?? '')) . ' - ' . trim((string) ($academicYear['ay_to'] ?? ''))); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label>
                <span>Status</span>
                <select name="status" required>
                  <?php foreach ($statusOptions as $statusOption): ?>
                    <option value="<?= e($statusOption); ?>"<?= $statusOption === $selectedStatus ? ' selected' : ''; ?>>
                      <?= e($statusOption); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="full-width coord-sdg-field">
                <span>SDGs</span>
                <select id="coord-sdg-select" class="coord-select2-multiple js-example-basic-multiple" name="sdgs[]" multiple="multiple" data-placeholder="Select SDGs">
                  <?php foreach ($sdgOptions as $sdgCode => $sdgLabel): ?>
                    <option value="<?= e((string) $sdgCode); ?>"<?= in_array($sdgCode, $selectedSdgCodes, true) ? ' selected' : ''; ?>>
                      <?= e('SDG ' . $sdgCode . ' - ' . $sdgLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label>
                <span>Authors</span>
                <textarea class="coord-uppercase-field" name="authors" maxlength="3000"><?= e(coordinator_research_upper_name($selectedRecord['authors'] ?? '')); ?></textarea>
              </label>

              <label class="coord-select2-field">
                <span>Adviser / Faculty Lead</span>
                <select
                  id="coord-adviser-select"
                  class="coord-select2-single js-example-basic-single"
                  name="adviser_accountid"
                  data-placeholder="Select adviser"
                >
                  <option value=""></option>
                  <?php $selectedAdviserOptionFound = false; ?>
                  <?php foreach ($adviserOptions as $adviserOption): ?>
                    <?php
                    $adviserOptionId = (int) ($adviserOption['accountid'] ?? 0);

                    if ($adviserOptionId < 1) {
                        continue;
                    }

                    if ($adviserOptionId === $selectedAdviserAccountId) {
                        $selectedAdviserOptionFound = true;
                    }
                    ?>
                    <option value="<?= e((string) $adviserOptionId); ?>"<?= $adviserOptionId === $selectedAdviserAccountId ? ' selected' : ''; ?>>
                      <?= e(coordinator_research_account_option_label($adviserOption)); ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($selectedAdviserAccountId > 0 && !$selectedAdviserOptionFound): ?>
                    <option value="<?= e((string) $selectedAdviserAccountId); ?>" selected>
                      <?= e($selectedAdviserOptionLabel !== '' ? $selectedAdviserOptionLabel : 'ACCOUNT #' . $selectedAdviserAccountId); ?>
                    </option>
                  <?php endif; ?>
                </select>
              </label>

              <label class="full-width">
                <span>Abstract / Proposal Details</span>
                <textarea name="other_details" maxlength="5000"><?= e((string) ($selectedRecord['other_details'] ?? '')); ?></textarea>
              </label>
            </div>
          </div>

          <div class="modal-footer">
            <?php if ($selectedResearchId > 0): ?>
              <a class="coord-btn-secondary" href="<?= e(coordinator_research_url()); ?>">Cancel Edit</a>
            <?php else: ?>
              <button class="coord-btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <?php endif; ?>
            <button class="coord-btn-primary" type="submit">
              <i class="bx bx-save"></i>
              <?= e($selectedResearchId > 0 ? 'Save Changes' : 'Save Manuscript'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>
<?php
$mainContent = (string) ob_get_clean();

CoordinatorPage::render([
    'title' => 'Manuscript Management',
    'current_page' => 'research',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
    'campus_label' => $campusLabel,
]);
