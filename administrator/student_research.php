<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireAdmin();
Database::ensureManuscriptTable();

$pdo = Database::connection();
$error = null;
$success = get_flash('administrator_student_research_success');
$continueStatePayload = get_flash('administrator_student_research_continue');
$isAjaxRequest = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';

function administrator_student_research_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');
    $url = app_link('administrator/student_research.php');

    return $query === '' ? $url : $url . '?' . $query;
}

function administrator_student_positive_int($value): int
{
    return is_scalar($value) && ctype_digit(trim((string) $value)) ? (int) $value : 0;
}

function administrator_student_uppercase(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function administrator_student_status(string $value): string
{
    return in_array($value, ['Proposal', 'On-going', 'Completed'], true) ? $value : 'Proposal';
}

function administrator_student_sdg_options(): array
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

function administrator_student_integer_selection($value, array $allowed): array
{
    $values = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
    $selected = [];

    foreach ((array) $values as $item) {
        $normalized = trim((string) $item);

        if ($normalized !== '' && ctype_digit($normalized) && isset($allowed[(int) $normalized])) {
            $selected[(int) $normalized] = (int) $normalized;
        }
    }

    return array_values($selected);
}

function administrator_student_account_selection($value): array
{
    $values = is_array($value) ? $value : [$value];
    $selected = [];

    foreach ($values as $item) {
        $accountId = administrator_student_positive_int($item);

        if ($accountId > 0) {
            $selected[$accountId] = $accountId;
        }
    }

    return array_values($selected);
}

function administrator_student_researcher_names($value): array
{
    $values = is_array($value) ? $value : preg_split('/[\r\n;]+/', (string) $value);
    $researchers = [];

    foreach ((array) $values as $item) {
        $name = administrator_student_uppercase(trim((string) $item));

        if ($name === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $researchers[$key] = $name;
    }

    return array_values($researchers);
}

function administrator_student_academic_year_label(array $year): string
{
    $from = trim((string) ($year['ay_from'] ?? ''));
    $to = trim((string) ($year['ay_to'] ?? ''));

    if ($from !== '' && $to !== '') {
        return $from . ' - ' . $to;
    }

    return $from !== '' ? $from : ($to !== '' ? $to : 'Academic Year #' . (int) ($year['ayid'] ?? 0));
}

function administrator_student_program_label(array $program): string
{
    $code = trim((string) ($program['coursecode'] ?? ''));
    $description = trim((string) ($program['coursedescription'] ?? ''));
    $label = trim($code . ($code !== '' && $description !== '' ? ' - ' : '') . $description);

    return $label !== '' ? $label : 'Program #' . (int) ($program['programid'] ?? $program['courseid'] ?? 0);
}

function administrator_student_faculty_label(array $faculty): string
{
    $name = trim((string) ($faculty['acc_name'] ?? ''));
    $email = strtolower(trim((string) ($faculty['email'] ?? '')));

    if ($name === '') {
        $name = 'Account #' . (int) ($faculty['accountid'] ?? 0);
    }

    return administrator_student_uppercase($name) . ($email !== '' ? ' - ' . $email : '');
}

function administrator_student_delete_abstract(?string $relativePath): void
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '' || strpos($normalized, 'uploads/manuscript_abstracts/') !== 0) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function administrator_student_excerpt(?string $value, int $limit = 120): string
{
    $normalized = trim((string) preg_replace('/\s+/', ' ', (string) $value));

    if ($normalized === '') {
        return 'No abstract or research details added.';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($normalized, 'UTF-8') : strlen($normalized);

    if ($length <= $limit) {
        return $normalized;
    }

    $excerpt = function_exists('mb_substr')
        ? mb_substr($normalized, 0, $limit - 3, 'UTF-8')
        : substr($normalized, 0, $limit - 3);

    return rtrim($excerpt) . '...';
}

$postedAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? trim((string) ($_POST['action'] ?? '')) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token(trim((string) ($_POST['csrf_token'] ?? '')))) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        if ($postedAction === 'delete_student_research') {
            $titleId = administrator_student_positive_int($_POST['titleid'] ?? 0);

            if ($titleId < 1) {
                throw new RuntimeException('Select a valid student research record to delete.');
            }

            $recordStatement = $pdo->prepare(
                'SELECT titleid, abstract_file_path
                 FROM tblresearches
                 WHERE titleid = :titleid
                   AND owner_accountid IS NULL
                   AND adviser_accountid IS NOT NULL
                 LIMIT 1'
            );
            $recordStatement->execute(['titleid' => $titleId]);
            $deleteRecord = $recordStatement->fetch();

            if (!is_array($deleteRecord)) {
                throw new RuntimeException('The selected student research record could not be found.');
            }

            $pdo->beginTransaction();

            try {
                $deletePanelists = $pdo->prepare('DELETE FROM tblmanuscript_panelists WHERE manuscriptid = :titleid');
                $deletePanelists->execute(['titleid' => $titleId]);

                $deleteCoauthors = $pdo->prepare('DELETE FROM tblmanuscript_coauthors WHERE manuscriptid = :titleid');
                $deleteCoauthors->execute(['titleid' => $titleId]);

                $deleteResearch = $pdo->prepare(
                    'DELETE FROM tblresearches
                     WHERE titleid = :titleid
                       AND owner_accountid IS NULL
                       AND adviser_accountid IS NOT NULL
                     LIMIT 1'
                );
                $deleteResearch->execute(['titleid' => $titleId]);

                if ($deleteResearch->rowCount() < 1) {
                    throw new RuntimeException('The student research record could not be deleted.');
                }

                TitleSimilarity::refreshCatalog($pdo);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            administrator_student_delete_abstract((string) ($deleteRecord['abstract_file_path'] ?? ''));
            set_flash('administrator_student_research_success', 'Student research record deleted.');
            redirect(administrator_student_research_url());
        }

        if ($postedAction === 'save_student_research') {
            $titleId = administrator_student_positive_int($_POST['titleid'] ?? 0);
            $isEditingStudentResearch = $titleId > 0;
            $title = administrator_student_uppercase(trim((string) ($_POST['student_title'] ?? '')));
            $typeId = administrator_student_positive_int($_POST['student_typeid'] ?? 0);
            $academicYearId = administrator_student_positive_int($_POST['student_ayid'] ?? 0);
            $status = administrator_student_status(trim((string) ($_POST['student_status'] ?? 'Proposal')));
            $adviserAccountId = administrator_student_positive_int($_POST['adviser_accountid'] ?? 0);
            $campusId = administrator_student_positive_int($_POST['campusid'] ?? 0);
            $programId = administrator_student_positive_int($_POST['programid'] ?? 0);
            $abstract = trim((string) ($_POST['student_other_details'] ?? ''));
            $sdgCodes = administrator_student_integer_selection(
                $_POST['student_sdgs'] ?? [],
                administrator_student_sdg_options()
            );
            $studentResearchers = administrator_student_researcher_names($_POST['student_researchers'] ?? []);
            $panelistIds = administrator_student_account_selection($_POST['panelist_accountids'] ?? []);
            $statisticianId = administrator_student_positive_int($_POST['statistician_accountid'] ?? 0);
            $englishCriticId = administrator_student_positive_int($_POST['english_critic_accountid'] ?? 0);

            if ($title === '' || $typeId < 1) {
                throw new RuntimeException('Research title and research type are required.');
            }

            if ($adviserAccountId < 1) {
                throw new RuntimeException('Select the faculty adviser responsible for this student research.');
            }

            if ($studentResearchers === []) {
                throw new RuntimeException('Add at least one student researcher.');
            }

            if ($panelistIds === []) {
                throw new RuntimeException('Select at least one panelist.');
            }

            if ($statisticianId < 1 || $englishCriticId < 1) {
                throw new RuntimeException('Select both the statistician and English critic.');
            }

            if ($sdgCodes === []) {
                throw new RuntimeException('Select at least one Sustainable Development Goal.');
            }

            $adviserStatement = $pdo->prepare(
                "SELECT a.accountid, a.campus, a.programid, a.acc_name
                 FROM tblaccount a
                 WHERE a.accountid = :accountid
                   AND a.is_enabled = 1
                 LIMIT 1"
            );
            $adviserStatement->execute(['accountid' => $adviserAccountId]);
            $adviser = $adviserStatement->fetch();

            if (!is_array($adviser)) {
                throw new RuntimeException('Select an enabled faculty account as adviser.');
            }

            if ($campusId < 1 || $programId < 1) {
                throw new RuntimeException('Select the campus and program for this student research.');
            }

            $campusCheck = $pdo->prepare('SELECT campusid FROM tblcampus WHERE campusid = :id LIMIT 1');
            $campusCheck->execute(['id' => $campusId]);

            if (!$campusCheck->fetchColumn()) {
                throw new RuntimeException('The selected campus does not exist.');
            }

            $programCheck = $pdo->prepare(
                "SELECT course.courseid,
                        CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid
                 FROM tblcourse course
                 LEFT JOIN tblcollege college
                   ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                 WHERE course.courseid = :id
                 LIMIT 1"
            );
            $programCheck->execute(['id' => $programId]);
            $program = $programCheck->fetch();

            if (!is_array($program)) {
                throw new RuntimeException('The selected program does not exist.');
            }

            if (administrator_student_positive_int($program['campusid'] ?? 0) !== $campusId) {
                throw new RuntimeException('Select a program that belongs to the selected campus.');
            }

            $typeCheck = $pdo->prepare('SELECT researchtypeid FROM tblresearchtype WHERE researchtypeid = :id LIMIT 1');
            $typeCheck->execute(['id' => $typeId]);

            if (!$typeCheck->fetchColumn()) {
                throw new RuntimeException('The selected research type does not exist.');
            }

            if ($academicYearId > 0) {
                $yearCheck = $pdo->prepare('SELECT ayid FROM tblacademic_year WHERE ayid = :id LIMIT 1');
                $yearCheck->execute(['id' => $academicYearId]);

                if (!$yearCheck->fetchColumn()) {
                    throw new RuntimeException('The selected academic year does not exist.');
                }
            }

            $roleAccountIds = array_values(array_unique(array_merge(
                $panelistIds,
                [$statisticianId, $englishCriticId]
            )));

            if (in_array($adviserAccountId, $roleAccountIds, true)) {
                throw new RuntimeException('The faculty adviser cannot also be assigned to the review team.');
            }

            $rolePlaceholders = implode(',', array_fill(0, count($roleAccountIds), '?'));
            $roleCheck = $pdo->prepare(
                "SELECT accountid
                 FROM tblaccount
                 WHERE accountid IN ($rolePlaceholders)
                   AND is_enabled = 1"
            );
            $roleCheck->execute($roleAccountIds);
            $validRoleIds = array_map('intval', $roleCheck->fetchAll(PDO::FETCH_COLUMN));

            if (count($validRoleIds) !== count($roleAccountIds)) {
                throw new RuntimeException('Select only enabled accounts for the review team.');
            }

            if ($titleId > 0) {
                $existingStatement = $pdo->prepare(
                    'SELECT titleid
                     FROM tblresearches
                     WHERE titleid = :titleid
                       AND owner_accountid IS NULL
                       AND adviser_accountid IS NOT NULL
                     LIMIT 1'
                );
                $existingStatement->execute(['titleid' => $titleId]);

                if (!$existingStatement->fetchColumn()) {
                    throw new RuntimeException('The selected student research record could not be found.');
                }
            }

            $authors = implode('; ', $studentResearchers);
            $sdgs = implode(',', $sdgCodes);
            $panelistStorage = implode(',', $panelistIds);

            if (strlen($title) > 1000 || strlen($authors) > 3000 || strlen($abstract) > 5000 || strlen($sdgs) > 100) {
                throw new RuntimeException('One or more entries are longer than the allowed limit.');
            }

            $pdo->beginTransaction();

            try {
                if ($isEditingStudentResearch) {
                    $saveStatement = $pdo->prepare(
                        'UPDATE tblresearches
                         SET title = :title,
                             normalized_title = :normalized_title,
                             typeid = :typeid,
                             campusid = :campusid,
                             programid = :programid,
                             ayid = :ayid,
                             status = :status,
                             authors = :authors,
                             other_details = :other_details,
                             sdgs = :sdgs,
                             encoder = :encoder,
                             adviser_name = NULL,
                             adviser_accountid = :adviser_accountid,
                             panelists = NULL,
                             panelist_accountids = :panelist_accountids,
                             statisticians = NULL,
                             statistician_accountid = :statistician_accountid,
                             english_critic_name = NULL,
                             english_critic_accountid = :english_critic_accountid,
                             owner_accountid = NULL,
                             is_published = 1,
                             similarity_refreshed_at = NULL
                         WHERE titleid = :titleid
                           AND owner_accountid IS NULL'
                    );
                    $message = 'Student research record updated.';
                } else {
                    $saveStatement = $pdo->prepare(
                        'INSERT INTO tblresearches
                            (title, normalized_title, typeid, campusid, programid, ayid, status, authors,
                             other_details, sdgs, encoder, adviser_accountid, panelist_accountids,
                             statistician_accountid, english_critic_accountid, owner_accountid, is_published)
                         VALUES
                            (:title, :normalized_title, :typeid, :campusid, :programid, :ayid, :status, :authors,
                             :other_details, :sdgs, :encoder, :adviser_accountid, :panelist_accountids,
                             :statistician_accountid, :english_critic_accountid, NULL, 1)'
                    );
                    $message = 'Student research record added.';
                }

                $saveParameters = [
                    'title' => $title,
                    'normalized_title' => TitleSimilarity::normalizeTitle($title),
                    'typeid' => $typeId,
                    'campusid' => $campusId,
                    'programid' => $programId,
                    'ayid' => $academicYearId > 0 ? $academicYearId : null,
                    'status' => $status,
                    'authors' => $authors,
                    'other_details' => $abstract !== '' ? $abstract : null,
                    'sdgs' => $sdgs,
                    'encoder' => $adviserAccountId,
                    'adviser_accountid' => $adviserAccountId,
                    'panelist_accountids' => $panelistStorage,
                    'statistician_accountid' => $statisticianId,
                    'english_critic_accountid' => $englishCriticId,
                ];

                if ($isEditingStudentResearch) {
                    $saveParameters['titleid'] = $titleId;
                }

                $saveStatement->execute($saveParameters);

                if (!$isEditingStudentResearch) {
                    $titleId = (int) $pdo->lastInsertId();
                }

                $clearPanelists = $pdo->prepare('DELETE FROM tblmanuscript_panelists WHERE manuscriptid = :manuscriptid');
                $clearPanelists->execute(['manuscriptid' => $titleId]);

                $clearCoauthors = $pdo->prepare('DELETE FROM tblmanuscript_coauthors WHERE manuscriptid = :manuscriptid');
                $clearCoauthors->execute(['manuscriptid' => $titleId]);

                $addPanelist = $pdo->prepare(
                    'INSERT INTO tblmanuscript_panelists (manuscriptid, accountid)
                     VALUES (:manuscriptid, :accountid)'
                );

                foreach ($panelistIds as $panelistId) {
                    $addPanelist->execute([
                        'manuscriptid' => $titleId,
                        'accountid' => $panelistId,
                    ]);
                }

                // Full-catalog similarity scoring is deferred to the dedicated recompute action.
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            if ($isAjaxRequest && !$isEditingStudentResearch) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(201);
                echo json_encode([
                    'ok' => true,
                    'message' => $message,
                    'titleid' => $titleId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            set_flash('administrator_student_research_success', $message);

            if (!$isEditingStudentResearch) {
                set_flash('administrator_student_research_continue', json_encode([
                    'adviser_accountid' => $adviserAccountId,
                    'campusid' => $campusId,
                    'programid' => $programId,
                    'student_ayid' => $academicYearId,
                    'student_typeid' => $typeId,
                    'student_status' => $status,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                redirect(administrator_student_research_url(['continue' => 1]));
            }

            redirect(administrator_student_research_url());
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $error = $exception->getMessage();
    }
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
$campuses = $pdo->query(
    'SELECT campusid, campusname
     FROM tblcampus
     ORDER BY campusname ASC, campusid ASC'
)->fetchAll();
$programs = $pdo->query(
    "SELECT course.courseid AS programid,
            course.coursecode,
            course.coursedescription,
            CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid
     FROM tblcourse course
     LEFT JOIN tblcollege college
       ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
     ORDER BY course.coursecode ASC, course.coursedescription ASC, course.courseid ASC"
)->fetchAll();
$facultyOptions = $pdo->query(
    "SELECT a.accountid,
            a.acc_name,
            a.email,
            a.campus AS campusid,
            a.programid,
            campus.campusname,
            course.coursecode,
            course.coursedescription
     FROM tblaccount a
     LEFT JOIN tblcampus campus ON campus.campusid = a.campus
     LEFT JOIN tblcourse course ON course.courseid = a.programid
     WHERE a.is_enabled = 1
     ORDER BY a.acc_name ASC, a.email ASC, a.accountid ASC"
)->fetchAll();
$reviewerOptions = $pdo->query(
    'SELECT accountid, acc_name, email
     FROM tblaccount
     WHERE is_enabled = 1
     ORDER BY acc_name ASC, email ASC, accountid ASC'
)->fetchAll();
$facultyMap = [];

foreach ($facultyOptions as $faculty) {
    $facultyId = (int) ($faculty['accountid'] ?? 0);

    if ($facultyId > 0) {
        $facultyMap[$facultyId] = $faculty;
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$facultyFilter = administrator_student_positive_int($_GET['faculty'] ?? 0);
$queryConditions = ['m.owner_accountid IS NULL', 'm.adviser_accountid IS NOT NULL'];
$queryParameters = [];

if ($search !== '') {
    $queryConditions[] = '(m.title LIKE :search OR m.authors LIKE :search OR adviser.acc_name LIKE :search)';
    $queryParameters['search'] = '%' . $search . '%';
}

if ($facultyFilter > 0) {
    $queryConditions[] = 'm.adviser_accountid = :faculty_filter';
    $queryParameters['faculty_filter'] = $facultyFilter;
}

$recordsStatement = $pdo->prepare(
    "SELECT m.*,
            rt.research_type,
            campus.campusname,
            course.coursecode,
            course.coursedescription,
            ay.ay_from,
            ay.ay_to,
            adviser.acc_name AS adviser_name_resolved,
            adviser.email AS adviser_email,
            statistician.acc_name AS statistician_name,
            critic.acc_name AS english_critic_name,
            panel.panelist_names,
            panel.panelist_count
     FROM tblresearches m
     LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
     LEFT JOIN tblcampus campus ON campus.campusid = m.campusid
     LEFT JOIN tblcourse course ON course.courseid = m.programid
     LEFT JOIN tblacademic_year ay ON ay.ayid = m.ayid
     LEFT JOIN tblaccount adviser ON adviser.accountid = m.adviser_accountid
     LEFT JOIN tblaccount statistician ON statistician.accountid = m.statistician_accountid
     LEFT JOIN tblaccount critic ON critic.accountid = m.english_critic_accountid
     LEFT JOIN (
        SELECT mp.manuscriptid,
               COUNT(*) AS panelist_count,
               GROUP_CONCAT(a.acc_name ORDER BY a.acc_name SEPARATOR ', ') AS panelist_names
        FROM tblmanuscript_panelists mp
        INNER JOIN tblaccount a ON a.accountid = mp.accountid
        GROUP BY mp.manuscriptid
     ) panel ON panel.manuscriptid = m.titleid
     WHERE " . implode(' AND ', $queryConditions) . "
     ORDER BY m.updated_at DESC, m.titleid DESC"
);
$recordsStatement->execute($queryParameters);
$studentResearchRecords = $recordsStatement->fetchAll();

$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'Proposal' OR status = 'Pending' THEN 1 ELSE 0 END) AS proposals,
            SUM(CASE WHEN status IN ('On-going', 'Ongoing') THEN 1 ELSE 0 END) AS ongoing,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
            COUNT(DISTINCT adviser_accountid) AS faculty_count
     FROM tblresearches
     WHERE owner_accountid IS NULL
       AND adviser_accountid IS NOT NULL"
)->fetch() ?: [];

$editId = administrator_student_positive_int($_GET['edit'] ?? 0);
$modalRecord = [];
$continueState = [];

if (is_string($continueStatePayload) && trim($continueStatePayload) !== '') {
    $decodedContinueState = json_decode($continueStatePayload, true);

    if (is_array($decodedContinueState)) {
        $continueState = $decodedContinueState;
    }
}

if ($editId > 0) {
    $editStatement = $pdo->prepare(
        'SELECT *
         FROM tblresearches
         WHERE titleid = :titleid
           AND owner_accountid IS NULL
           AND adviser_accountid IS NOT NULL
         LIMIT 1'
    );
    $editStatement->execute(['titleid' => $editId]);
    $modalRecord = $editStatement->fetch() ?: [];

    if ($modalRecord === []) {
        $error = 'The selected student research record could not be found.';
        $editId = 0;
    }
}

if ($editId < 1 && $postedAction === '' && isset($_GET['continue']) && $continueState !== []) {
    $modalRecord = $continueState;
}

if ($postedAction === 'save_student_research' && $error !== null) {
    $modalRecord = $_POST;
}

$selectedPanelistIds = [];

if ($editId > 0 && $modalRecord !== []) {
    $panelistStatement = $pdo->prepare(
        'SELECT accountid
         FROM tblmanuscript_panelists
         WHERE manuscriptid = :manuscriptid
         ORDER BY accountid ASC'
    );
    $panelistStatement->execute(['manuscriptid' => $editId]);
    $selectedPanelistIds = array_map('intval', $panelistStatement->fetchAll(PDO::FETCH_COLUMN));
} elseif ($postedAction === 'save_student_research' && $error !== null) {
    $selectedPanelistIds = administrator_student_account_selection($_POST['panelist_accountids'] ?? []);
}

$sdgOptions = administrator_student_sdg_options();
$selectedSdgCodes = administrator_student_integer_selection(
    $modalRecord['student_sdgs'] ?? ($modalRecord['sdgs'] ?? []),
    $sdgOptions
);
$selectedResearchers = administrator_student_researcher_names(
    $modalRecord['student_researchers'] ?? ($modalRecord['authors'] ?? [])
);

if ($selectedResearchers === []) {
    $selectedResearchers = [''];
}

$selectedAdviserId = administrator_student_positive_int(
    $modalRecord['adviser_accountid'] ?? 0
);
$selectedCampusId = administrator_student_positive_int($modalRecord['campusid'] ?? 0);
$selectedProgramId = administrator_student_positive_int($modalRecord['programid'] ?? 0);
$selectedStatisticianId = administrator_student_positive_int(
    $modalRecord['statistician_accountid'] ?? 0
);
$selectedEnglishCriticId = administrator_student_positive_int(
    $modalRecord['english_critic_accountid'] ?? 0
);
$shouldOpenModal = $editId > 0
    || ($postedAction === 'save_student_research' && $error !== null)
    || (isset($_GET['continue']) && $continueState !== []);
$facultyScopePayload = [];

foreach ($facultyMap as $facultyId => $faculty) {
    $campusName = trim((string) ($faculty['campusname'] ?? ''));
    $programCode = trim((string) ($faculty['coursecode'] ?? ''));
    $programDescription = trim((string) ($faculty['coursedescription'] ?? ''));
    $programLabel = trim($programCode . ($programCode !== '' && $programDescription !== '' ? ' - ' : '') . $programDescription);

    $facultyScopePayload[(string) $facultyId] = [
        'campus' => $campusName !== '' ? $campusName : 'Campus not assigned',
        'program' => $programLabel !== '' ? $programLabel : 'Program not assigned',
        'campusid' => administrator_student_positive_int($faculty['campusid'] ?? 0),
        'programid' => administrator_student_positive_int($faculty['programid'] ?? 0),
        'ready' => administrator_student_positive_int($faculty['campusid'] ?? 0) > 0
            && administrator_student_positive_int($faculty['programid'] ?? 0) > 0,
    ];
}

$facultyScopeJson = json_encode(
    $facultyScopePayload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($facultyScopeJson)) {
    $facultyScopeJson = '{}';
}

$extraHead = '<link rel="stylesheet" href="' . e(app_link('assets/vendor/libs/select2/select2.min.css')) . '">' . "\n"
    . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">';

$extraStyles = <<<'CSS'
.student-research-page{padding-bottom:1.5rem}.student-research-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.25rem}.student-research-heading h2{margin:0;color:#26352c;font-size:1.45rem}.student-research-heading p{max-width:48rem;margin:.4rem 0 0;color:#69766e;font-size:.88rem;line-height:1.55}.student-primary-btn,.student-secondary-btn,.student-danger-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:2.55rem;padding:.65rem 1rem;border:1px solid transparent;border-radius:.5rem;font-size:.85rem;font-weight:700;text-decoration:none;white-space:nowrap}.student-primary-btn{background:#008c46;color:#fff}.student-primary-btn:hover{background:#00743a;color:#fff}.student-secondary-btn{border-color:#d9e1dc;background:#fff;color:#3e4b43}.student-secondary-btn:hover{background:#f3f6f4;color:#26352c}.student-danger-btn{border-color:#fecaca;background:#fff5f5;color:#b42318}.student-danger-btn:hover{background:#b42318;color:#fff}.student-icon-btn{display:grid;place-items:center;width:2.45rem;height:2.45rem;padding:0;border:1px solid #d9e1dc;border-radius:.5rem;background:#fff;color:#4f5d54;font-size:1.25rem}.student-icon-btn:hover{background:#edf3ef;color:#26352c}.student-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.8rem;margin-bottom:1rem}.student-stat{display:flex;align-items:center;gap:.75rem;min-width:0;padding:1rem;border:1px solid #e2e8e4;border-radius:.6rem;background:#fff;box-shadow:0 .25rem .8rem rgba(35,55,43,.035)}.student-stat-icon{display:grid;place-items:center;flex:0 0 2.55rem;width:2.55rem;height:2.55rem;border-radius:.55rem;background:#e9fff3;color:#007f3d;font-size:1.25rem}.student-stat:nth-child(3) .student-stat-icon{background:#fff3e8;color:#c85d08}.student-stat strong,.student-stat span{display:block}.student-stat strong{color:#26352c;font-size:1.25rem;line-height:1.1}.student-stat span{margin-top:.2rem;color:#758078;font-size:.72rem}.student-workspace{border:1px solid #e1e7e3;border-radius:.65rem;background:#fff;box-shadow:0 .35rem 1rem rgba(35,55,43,.045);overflow:hidden}.student-toolbar{display:flex;align-items:end;gap:.75rem;padding:1rem;border-bottom:1px solid #e7ece9;background:#fafcfb}.student-filter-field{display:grid;gap:.32rem;min-width:0}.student-filter-field.search{flex:1}.student-filter-field.faculty{flex:0 1 21rem}.student-filter-field label{margin:0;color:#56635b;font-size:.7rem;font-weight:700;text-transform:uppercase}.student-filter-input{width:100%;height:2.55rem;padding:.6rem .75rem;border:1px solid #d6ded9;border-radius:.5rem;background:#fff;color:#26352c}.student-filter-input:focus{border-color:#00a651;box-shadow:0 0 0 .18rem rgba(0,166,81,.12);outline:0}.student-table-wrap{overflow-x:auto}.student-table{width:100%;min-width:1080px;border-collapse:collapse}.student-table th{padding:.8rem 1rem;background:#f7f9f8;color:#6b776f;font-size:.68rem;font-weight:800;letter-spacing:.04em;text-align:left;text-transform:uppercase}.student-table td{padding:1rem;border-top:1px solid #edf0ee;color:#39463e;font-size:.79rem;vertical-align:top}.student-table tbody tr:hover{background:#fbfdfc}.student-title{margin:0 0 .3rem;color:#26352c;font-size:.92rem;font-weight:800;line-height:1.4;text-transform:uppercase}.student-authors{color:#c85d08;font-size:.76rem;font-weight:700;text-transform:uppercase}.student-subcopy{display:block;margin-top:.25rem;color:#7a857e;font-size:.72rem;line-height:1.45}.student-faculty-name{display:block;color:#26352c;font-weight:800;text-transform:uppercase}.student-email{display:block;margin-top:.18rem;color:#6e7972;font-size:.72rem;text-transform:lowercase}.student-scope{display:block;margin-top:.35rem;color:#506057;font-size:.72rem}.student-team{line-height:1.55}.student-team b{color:#155e75}.student-status{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .55rem;border-radius:999px;font-size:.7rem;font-weight:800}.student-status.proposal{background:#fff3e8;color:#ad5007}.student-status.ongoing{background:#e8f5ff;color:#075985}.student-status.completed{background:#e9fff3;color:#007f3d}.student-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:.4rem}.student-row-actions form{margin:0}.student-row-actions .student-secondary-btn,.student-row-actions .student-danger-btn{min-height:2.2rem;padding:.48rem .65rem}.student-empty{padding:3.5rem 1rem;text-align:center}.student-empty i{color:#97a29b;font-size:2.5rem}.student-empty h3{margin:.6rem 0 .25rem;color:#344139;font-size:1rem}.student-empty p{margin:0;color:#7a857e;font-size:.8rem}.student-modal .modal-dialog{max-width:72rem}.student-modal .modal-content{overflow:hidden;border:0;border-radius:.65rem;box-shadow:0 1.5rem 4rem rgba(15,23,42,.24)}.student-modal .modal-header{align-items:flex-start;padding:1.1rem 1.35rem;border:0;border-top:.28rem solid #f58220;background:#173d2b;color:#fff}.student-modal .modal-title{margin:0;color:#fff;font-size:1.2rem}.student-modal .modal-header p{margin:.28rem 0 0;color:#d5e8dc;font-size:.78rem}.student-modal .student-icon-btn{border-color:rgba(255,255,255,.22);background:rgba(255,255,255,.09);color:#fff}.student-modal .student-icon-btn:hover{background:rgba(255,255,255,.17)}.student-modal .modal-body{max-height:calc(100vh - 12rem);padding:1.3rem;overflow-y:auto}.student-modal .modal-footer{padding:.85rem 1.35rem;border-color:#e8ece9;background:#fafcfb}.student-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.student-field{display:grid;align-content:start;gap:.38rem;min-width:0}.student-field.full{grid-column:1/-1}.student-field label,.student-label{margin:0;color:#344139;font-size:.77rem;font-weight:800}.student-field input,.student-field select,.student-field textarea{width:100%;border:1px solid #d2dbd5;border-radius:.5rem;background:#fff;color:#26352c}.student-field input{height:2.7rem;padding:.65rem .75rem}.student-field textarea{min-height:7.5rem;padding:.7rem .75rem;resize:vertical}.student-field input:focus,.student-field textarea:focus{border-color:#00a651;box-shadow:0 0 0 .18rem rgba(0,166,81,.12);outline:0}.student-field input[name="student_title"],.student-researcher-row input{text-transform:uppercase}.student-field-note{margin:0;color:#77827b;font-size:.7rem;line-height:1.45}.faculty-scope{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;padding:.75rem;border:1px solid #d8e6dd;border-radius:.55rem;background:#f5fbf7}.faculty-scope-item{min-width:0}.faculty-scope-item span,.faculty-scope-item strong{display:block}.faculty-scope-item span{color:#718078;font-size:.65rem;font-weight:800;text-transform:uppercase}.faculty-scope-item strong{margin-top:.18rem;color:#274032;font-size:.77rem;line-height:1.4}.faculty-scope-warning{grid-column:1/-1;margin:0;color:#ad5007;font-size:.72rem;font-weight:700}.student-researchers{display:grid;gap:.5rem}.student-researcher-row{display:grid;grid-template-columns:minmax(0,1fr) 2.7rem;gap:.45rem}.student-researcher-remove{display:grid;place-items:center;width:2.7rem;height:2.7rem;padding:0;border:1px solid #fecaca;border-radius:.5rem;background:#fff5f5;color:#b42318;font-size:1.1rem}.student-researcher-remove:disabled{cursor:not-allowed;opacity:.35}.student-add-researcher{justify-self:start;margin-top:.15rem}.sdg-field-header{display:flex;align-items:center;justify-content:space-between;gap:.75rem}.sdg-count{color:#68756d;font-size:.7rem;font-weight:700}.sdg-trigger{justify-self:start;border-color:#00a651;color:#007f3d}.sdg-selected-summary{display:flex;flex-wrap:wrap;gap:.4rem;min-height:1.65rem}.sdg-selected-item{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .5rem;border-radius:.4rem;background:#edf5f0;color:#344139;font-size:.68rem;font-weight:700}.sdg-selected-item b{color:#007f3d}.sdg-empty-copy{color:#78837c;font-size:.73rem}.sdg-field-error{display:none;margin:0;color:#b42318;font-size:.72rem;font-weight:700}.sdg-field-error.visible{display:block}.select2-container{max-width:100%}.select2-container--default .select2-selection--single,.select2-container--default .select2-selection--multiple{min-height:2.7rem;border:1px solid #d2dbd5;border-radius:.5rem}.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--focus .select2-selection--multiple,.select2-container--default.select2-container--open .select2-selection--single,.select2-container--default.select2-container--open .select2-selection--multiple{border-color:#00a651;box-shadow:0 0 0 .18rem rgba(0,166,81,.12)}.select2-container--default .select2-selection--single .select2-selection__rendered{padding:.35rem 2.1rem .35rem .75rem;line-height:2rem}.select2-container--default .select2-selection--single .select2-selection__arrow{height:2.65rem}.select2-container--default .select2-selection--multiple{padding:.18rem .3rem}.select2-container--default .select2-selection--multiple .select2-selection__choice{border:0;border-radius:.35rem;background:#e9fff3;color:#17633d;font-size:.72rem}.select2-dropdown{border-color:#d2dbd5;border-radius:.5rem;box-shadow:0 .8rem 2rem rgba(15,23,42,.14);overflow:hidden}.select2-search--dropdown .select2-search__field{padding:.5rem;border:1px solid #cfd8d2!important;border-radius:.4rem}.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#008c46}.swal2-container{z-index:2500!important}body.admin-sdg-open{overflow:hidden}.admin-sdg-modal[hidden]{display:none}.admin-sdg-modal{position:fixed;inset:0;z-index:2200;display:grid;place-items:center;padding:1.25rem}.admin-sdg-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.62);backdrop-filter:blur(3px)}.admin-sdg-dialog{position:relative;display:flex;flex-direction:column;width:min(70rem,100%);max-height:calc(100dvh - 2.5rem);overflow:hidden;border-radius:.65rem;background:#fff;box-shadow:0 2rem 5rem rgba(0,0,0,.3)}.admin-sdg-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1rem 1.2rem;border-bottom:1px solid #e4e9e6}.admin-sdg-header h3{margin:0;color:#173d2b;font-size:1.15rem}.admin-sdg-header p{margin:.25rem 0 0;color:#6f7a73;font-size:.75rem}.admin-sdg-tools{display:flex;align-items:center;gap:.75rem;padding:.75rem 1.2rem;border-bottom:1px solid #e4e9e6;background:#fafcfb}.admin-sdg-search{position:relative;flex:1}.admin-sdg-search i{position:absolute;top:50%;left:.7rem;transform:translateY(-50%);color:#6d7971;font-size:1.1rem}.admin-sdg-search input{width:100%;height:2.55rem;padding:.55rem .75rem .55rem 2.25rem;border:1px solid #d1dad4;border-radius:.5rem}.admin-sdg-counter{color:#46544b;font-size:.75rem;font-weight:800;white-space:nowrap}.admin-sdg-body{padding:1.1rem 1.2rem;overflow:auto}.admin-sdg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.75rem}.admin-sdg-option{position:relative;display:block;cursor:pointer}.admin-sdg-option[hidden]{display:none}.admin-sdg-option input{position:absolute;opacity:0;pointer-events:none}.admin-sdg-tile{position:relative;overflow:hidden;border:3px solid transparent;border-radius:.45rem;background:#f3f4f6;transition:transform .15s,border-color .15s,box-shadow .15s}.admin-sdg-tile img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover}.admin-sdg-check{position:absolute;top:.4rem;right:.4rem;display:grid;place-items:center;width:1.65rem;height:1.65rem;border:2px solid #fff;border-radius:50%;background:rgba(17,24,39,.75);color:#fff;font-size:1rem;opacity:0;transform:scale(.8);transition:.15s}.admin-sdg-option:hover .admin-sdg-tile{transform:translateY(-2px);box-shadow:0 .55rem 1.2rem rgba(15,23,42,.18)}.admin-sdg-option input:focus-visible+.admin-sdg-tile{outline:3px solid rgba(0,166,81,.28);outline-offset:2px}.admin-sdg-option input:checked+.admin-sdg-tile{border-color:#173d2b;box-shadow:0 0 0 2px #fff,0 0 0 5px #00a651}.admin-sdg-option input:checked+.admin-sdg-tile .admin-sdg-check{opacity:1;transform:scale(1)}.admin-sdg-no-results{display:none;padding:2.5rem;text-align:center;color:#77827b}.admin-sdg-no-results.visible{display:block}.admin-sdg-footer{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.8rem 1.2rem;border-top:1px solid #e4e9e6;background:#fff}.admin-sdg-source{max-width:34rem;margin:0;color:#758078;font-size:.65rem;line-height:1.4}.admin-sdg-source a{color:#007f3d;font-weight:700}.admin-sdg-actions{display:flex;align-items:center;gap:.45rem}.admin-sdg-clear{border:0;background:transparent;color:#526159;font-size:.75rem;font-weight:700}.admin-sdg-apply:disabled{cursor:not-allowed;opacity:.5}
.student-modal .modal-content{max-height:calc(100dvh - 2rem)}#administrator-student-research-form{display:flex;flex:1 1 auto;min-width:0;min-height:0;max-height:calc(100dvh - 2rem);flex-direction:column}.student-modal .modal-header{flex:0 0 auto}.student-modal .modal-body{flex:1 1 auto;min-width:0;min-height:0;max-height:none;overflow-x:hidden}.student-modal .modal-footer{position:relative;z-index:3;flex:0 0 auto;flex-wrap:nowrap;box-shadow:0 -.35rem 1rem rgba(15,23,42,.05)}.student-modal .select2-container{min-width:0!important}.student-modal .select2-selection--single .select2-selection__rendered{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(max-width:1199.98px){.student-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767.98px){.student-research-heading,.student-toolbar{align-items:stretch;flex-direction:column}.student-research-heading .student-primary-btn{align-self:flex-start}.student-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.student-filter-field.faculty{flex-basis:auto}.student-table{min-width:0}.student-table thead{display:none}.student-table,.student-table tbody,.student-table tr,.student-table td{display:block;width:100%}.student-table tr{padding:.85rem 1rem;border-top:1px solid #e7ece9}.student-table tr:first-child{border-top:0}.student-table td{padding:.5rem 0;border:0}.student-table td::before{content:attr(data-label);display:block;margin-bottom:.28rem;color:#718078;font-size:.65rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase}.student-table td:first-child::before{display:none}.student-row-actions{justify-content:flex-start}.student-form-grid{grid-template-columns:1fr}.student-field.full{grid-column:auto}.student-modal .modal-dialog{max-width:none;margin:.5rem}.student-modal .modal-body{max-height:calc(100vh - 10rem);padding:1rem}.admin-sdg-modal{padding:0;place-items:stretch}.admin-sdg-dialog{width:100%;height:100dvh;max-height:none;border-radius:0}.admin-sdg-tools,.admin-sdg-footer{align-items:stretch;flex-direction:column}.admin-sdg-counter{align-self:flex-start}.admin-sdg-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-sdg-actions{justify-content:flex-end}.sdg-trigger{width:100%}}
@media(max-width:767.98px){.student-modal .modal-content,#administrator-student-research-form{max-height:calc(100dvh - 1rem)}.student-modal .modal-footer{padding:.75rem 1rem}}
@media(max-width:420px){.student-stats{grid-template-columns:1fr}.admin-sdg-body{padding:.75rem}.admin-sdg-grid{gap:.5rem}}
CSS;

ob_start();
?>
<main class="container-xxl flex-grow-1 container-p-y student-research-page">
  <section class="student-research-heading" aria-labelledby="student-research-page-title">
    <div>
      <h2 id="student-research-page-title">Student Research</h2>
      <p>Encode studies conducted by students, select the responsible faculty adviser, and maintain the complete academic review team.</p>
    </div>
    <button class="student-primary-btn" type="button" data-bs-toggle="modal" data-bs-target="#studentResearchModal">
      <i class="bx bx-plus" aria-hidden="true"></i>
      Add Student Research
    </button>
  </section>

  <section class="student-stats" aria-label="Student research summary">
    <?php
    $statItems = [
        ['icon' => 'bx-library', 'value' => (int) ($summary['total'] ?? 0), 'label' => 'Total records'],
        ['icon' => 'bx-edit-alt', 'value' => (int) ($summary['proposals'] ?? 0), 'label' => 'Proposals'],
        ['icon' => 'bx-loader-circle', 'value' => (int) ($summary['ongoing'] ?? 0), 'label' => 'On-going'],
        ['icon' => 'bx-check-circle', 'value' => (int) ($summary['completed'] ?? 0), 'label' => 'Completed'],
        ['icon' => 'bx-user-check', 'value' => (int) ($summary['faculty_count'] ?? 0), 'label' => 'Faculty advisers'],
    ];
    ?>
    <?php foreach ($statItems as $statItem): ?>
      <article class="student-stat">
        <span class="student-stat-icon" aria-hidden="true"><i class="bx <?= e($statItem['icon']); ?>"></i></span>
        <div><strong><?= e((string) $statItem['value']); ?></strong><span><?= e($statItem['label']); ?></span></div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="student-workspace">
    <form class="student-toolbar" method="get" action="<?= e(administrator_student_research_url()); ?>">
      <div class="student-filter-field search">
        <label for="student-research-search">Search records</label>
        <input class="student-filter-input" id="student-research-search" type="search" name="q" value="<?= e($search); ?>" placeholder="Search by title, student, or adviser">
      </div>
      <div class="student-filter-field faculty">
        <label for="student-research-faculty-filter">Faculty adviser</label>
        <select class="js-filter-select" id="student-research-faculty-filter" name="faculty" data-placeholder="All faculty advisers">
          <option value="">All faculty advisers</option>
          <?php foreach ($facultyOptions as $faculty): ?>
            <?php $facultyId = (int) ($faculty['accountid'] ?? 0); ?>
            <option value="<?= e((string) $facultyId); ?>"<?= $facultyId === $facultyFilter ? ' selected' : ''; ?>><?= e(administrator_student_faculty_label($faculty)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="student-primary-btn" type="submit"><i class="bx bx-filter-alt" aria-hidden="true"></i>Apply</button>
      <a class="student-secondary-btn" href="<?= e(administrator_student_research_url()); ?>">Reset</a>
    </form>

    <?php if ($studentResearchRecords === []): ?>
      <div class="student-empty">
        <i class="bx bx-folder-open" aria-hidden="true"></i>
        <h3>No student research records found</h3>
        <p><?= $search !== '' || $facultyFilter > 0 ? 'Adjust the filters to see more records.' : 'Add the first student research record to begin.'; ?></p>
      </div>
    <?php else: ?>
      <div class="student-table-wrap">
        <table class="student-table">
          <thead>
            <tr>
              <th scope="col">Research and students</th>
              <th scope="col">Faculty adviser</th>
              <th scope="col">Academic details</th>
              <th scope="col">Review team</th>
              <th scope="col">Stage</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($studentResearchRecords as $record): ?>
            <?php
            $recordId = (int) ($record['titleid'] ?? 0);
            $recordAuthors = administrator_student_uppercase(trim((string) ($record['authors'] ?? '')));
            $recordAuthors = $recordAuthors !== '' ? $recordAuthors : 'STUDENT NAMES NOT LISTED';
            $recordTitle = administrator_student_uppercase(trim((string) ($record['title'] ?? 'Untitled research')));
            $recordAdviser = administrator_student_uppercase(trim((string) ($record['adviser_name_resolved'] ?? 'Faculty not found')));
            $recordEmail = strtolower(trim((string) ($record['adviser_email'] ?? '')));
            $recordCampus = trim((string) ($record['campusname'] ?? 'Campus not assigned'));
            $recordProgram = trim((string) ($record['coursecode'] ?? 'Program not assigned'));
            $recordType = trim((string) ($record['research_type'] ?? 'Research type not set'));
            $recordYear = administrator_student_academic_year_label($record);
            $recordSdgs = administrator_student_integer_selection($record['sdgs'] ?? [], $sdgOptions);
            $recordSdgLabel = $recordSdgs !== [] ? implode(', ', array_map(static function (int $code): string {
                return 'SDG ' . $code;
            }, $recordSdgs)) : 'SDG not set';
            $recordPanelists = trim((string) ($record['panelist_names'] ?? '')) ?: 'Not assigned';
            $recordStatistician = trim((string) ($record['statistician_name'] ?? '')) ?: 'Not assigned';
            $recordCritic = trim((string) ($record['english_critic_name'] ?? '')) ?: 'Not assigned';
            $recordStatus = administrator_student_status(trim((string) ($record['status'] ?? 'Proposal')));
            $statusClass = $recordStatus === 'Completed' ? 'completed' : ($recordStatus === 'On-going' ? 'ongoing' : 'proposal');
            ?>
            <tr>
              <td data-label="Research">
                <p class="student-title"><?= e($recordTitle); ?></p>
                <span class="student-authors"><?= e($recordAuthors); ?></span>
                <span class="student-subcopy"><?= e(administrator_student_excerpt($record['other_details'] ?? '')); ?></span>
              </td>
              <td data-label="Faculty adviser">
                <span class="student-faculty-name"><?= e($recordAdviser); ?></span>
                <?php if ($recordEmail !== ''): ?><span class="student-email"><?= e($recordEmail); ?></span><?php endif; ?>
                <span class="student-scope"><?= e($recordProgram . ' | ' . $recordCampus); ?></span>
              </td>
              <td data-label="Academic details">
                <strong><?= e($recordType); ?></strong>
                <span class="student-subcopy"><?= e($recordYear); ?></span>
                <span class="student-subcopy"><?= e($recordSdgLabel); ?></span>
              </td>
              <td data-label="Review team">
                <div class="student-team"><b>Panel:</b> <?= e($recordPanelists); ?><br><b>Statistician:</b> <?= e($recordStatistician); ?><br><b>English critic:</b> <?= e($recordCritic); ?></div>
              </td>
              <td data-label="Stage"><span class="student-status <?= e($statusClass); ?>"><i class="bx bx-shield-quarter" aria-hidden="true"></i><?= e($recordStatus); ?></span></td>
              <td data-label="Actions">
                <div class="student-row-actions">
                  <a class="student-secondary-btn" href="<?= e(administrator_student_research_url(['edit' => $recordId])); ?>" aria-label="Edit <?= e($recordTitle); ?>"><i class="bx bx-edit" aria-hidden="true"></i>Edit</a>
                  <form class="student-delete-form" method="post" data-research-title="<?= e($recordTitle); ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete_student_research">
                    <input type="hidden" name="titleid" value="<?= e((string) $recordId); ?>">
                    <button class="student-danger-btn" type="submit" aria-label="Delete <?= e($recordTitle); ?>"><i class="bx bx-trash" aria-hidden="true"></i>Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <div class="modal fade student-modal" id="studentResearchModal" tabindex="-1" aria-labelledby="studentResearchModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form id="administrator-student-research-form" method="post">
          <header class="modal-header">
            <div>
              <h3 class="modal-title" id="studentResearchModalTitle"><?= $editId > 0 ? 'Edit Student Research' : 'Add Student Research'; ?></h3>
              <p>Select the faculty member you are encoding for. The record will appear in their Student Research page.</p>
            </div>
            <button class="student-icon-btn" type="button" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x" aria-hidden="true"></i></button>
          </header>
          <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_student_research">
            <input type="hidden" name="titleid" value="<?= e((string) administrator_student_positive_int($modalRecord['titleid'] ?? 0)); ?>">

            <div class="student-form-grid">
              <div class="student-field full">
                <label for="administrator-student-title">Research title *</label>
                <input id="administrator-student-title" name="student_title" required maxlength="1000" value="<?= e((string) ($modalRecord['student_title'] ?? $modalRecord['title'] ?? '')); ?>">
              </div>

              <div class="student-field full">
                <label for="administrator-student-adviser">Faculty adviser *</label>
                <select class="js-student-select" id="administrator-student-adviser" name="adviser_accountid" required data-placeholder="Search and select a faculty adviser" data-search-placeholder="Search faculty by name or email">
                  <option value="">Select faculty adviser</option>
                  <?php foreach ($facultyOptions as $faculty): ?>
                    <?php $facultyId = (int) ($faculty['accountid'] ?? 0); ?>
                    <option value="<?= e((string) $facultyId); ?>"<?= $facultyId === $selectedAdviserId ? ' selected' : ''; ?>><?= e(administrator_student_faculty_label($faculty)); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="student-field-note">Selecting a faculty member fills their saved campus and program when available. You can change either field for this record.</p>
              </div>

              <div class="student-field">
                <label for="administrator-student-campus">Campus *</label>
                <select class="js-student-select" id="administrator-student-campus" name="campusid" required data-placeholder="Select campus" data-search-placeholder="Search campuses">
                  <option value="">Select campus</option>
                  <?php foreach ($campuses as $campus): ?>
                    <?php $campusId = (int) ($campus['campusid'] ?? 0); ?>
                    <option value="<?= e((string) $campusId); ?>"<?= $campusId === $selectedCampusId ? ' selected' : ''; ?>><?= e((string) ($campus['campusname'] ?? 'Campus #' . $campusId)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field">
                <label for="administrator-student-program">Program *</label>
                <select class="js-student-select" id="administrator-student-program" name="programid" required data-placeholder="Select campus first" data-search-placeholder="Search programs">
                  <option value="">Select campus first</option>
                  <?php foreach ($programs as $program): ?>
                    <?php
                    $programId = (int) ($program['programid'] ?? 0);
                    $programCampusId = administrator_student_positive_int($program['campusid'] ?? 0);
                    ?>
                    <option value="<?= e((string) $programId); ?>" data-campusid="<?= e((string) $programCampusId); ?>"<?= $programId === $selectedProgramId ? ' selected' : ''; ?>><?= e(administrator_student_program_label($program)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field">
                <label for="administrator-student-type">Research type *</label>
                <select class="js-student-select" id="administrator-student-type" name="student_typeid" required data-placeholder="Select research type" data-search-placeholder="Search research types">
                  <option value="">Select research type</option>
                  <?php foreach ($researchTypes as $researchType): ?>
                    <?php $researchTypeId = (int) ($researchType['researchtypeid'] ?? 0); ?>
                    <option value="<?= e((string) $researchTypeId); ?>"<?= $researchTypeId === administrator_student_positive_int($modalRecord['student_typeid'] ?? $modalRecord['typeid'] ?? 0) ? ' selected' : ''; ?>><?= e((string) ($researchType['research_type'] ?? 'Research type')); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field">
                <label for="administrator-student-stage">Research stage *</label>
                <select class="js-student-select" id="administrator-student-stage" name="student_status" required data-placeholder="Select research stage" data-search-placeholder="Search research stages">
                  <?php foreach (['Proposal', 'On-going', 'Completed'] as $status): ?>
                    <option value="<?= e($status); ?>"<?= $status === administrator_student_status((string) ($modalRecord['student_status'] ?? $modalRecord['status'] ?? 'Proposal')) ? ' selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field">
                <label for="administrator-student-year">Academic year</label>
                <select class="js-student-select" id="administrator-student-year" name="student_ayid" data-placeholder="Select academic year" data-search-placeholder="Search academic years">
                  <option value="">Not specified</option>
                  <?php foreach ($academicYears as $academicYear): ?>
                    <?php $academicYearId = (int) ($academicYear['ayid'] ?? 0); ?>
                    <option value="<?= e((string) $academicYearId); ?>"<?= $academicYearId === administrator_student_positive_int($modalRecord['student_ayid'] ?? $modalRecord['ayid'] ?? 0) ? ' selected' : ''; ?>><?= e(administrator_student_academic_year_label($academicYear)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field full">
                <span class="student-label">Student researchers *</span>
                <div class="student-researchers" id="administrator-student-researchers">
                  <?php foreach ($selectedResearchers as $index => $researcher): ?>
                    <div class="student-researcher-row">
                      <input name="student_researchers[]" required maxlength="180" value="<?= e($researcher); ?>" placeholder="Enter full student name" aria-label="Student researcher <?= e((string) ($index + 1)); ?>">
                      <button class="student-researcher-remove" type="button" aria-label="Remove student researcher"><i class="bx bx-trash" aria-hidden="true"></i></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <button class="student-secondary-btn student-add-researcher" id="administrator-add-student" type="button"><i class="bx bx-plus" aria-hidden="true"></i>Add student</button>
              </div>

              <div class="student-field full">
                <label for="administrator-student-panelists">Panelists *</label>
                <select class="js-student-select js-review-team-select" id="administrator-student-panelists" name="panelist_accountids[]" multiple required data-placeholder="Search and select panelists" data-search-placeholder="Search faculty by name or email">
                  <?php foreach ($reviewerOptions as $faculty): ?>
                    <?php $facultyId = (int) ($faculty['accountid'] ?? 0); ?>
                    <option value="<?= e((string) $facultyId); ?>"<?= in_array($facultyId, $selectedPanelistIds, true) ? ' selected' : ''; ?>><?= e(administrator_student_faculty_label($faculty)); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="student-field-note">Choose one or more faculty panelists. The adviser is excluded automatically.</p>
              </div>

              <div class="student-field">
                <label for="administrator-student-statistician">Statistician *</label>
                <select class="js-student-select js-review-team-select" id="administrator-student-statistician" name="statistician_accountid" required data-placeholder="Select statistician" data-search-placeholder="Search faculty by name or email">
                  <option value="">Select statistician</option>
                  <?php foreach ($reviewerOptions as $faculty): ?>
                    <?php $facultyId = (int) ($faculty['accountid'] ?? 0); ?>
                    <option value="<?= e((string) $facultyId); ?>"<?= $facultyId === $selectedStatisticianId ? ' selected' : ''; ?>><?= e(administrator_student_faculty_label($faculty)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field">
                <label for="administrator-student-critic">English critic *</label>
                <select class="js-student-select js-review-team-select" id="administrator-student-critic" name="english_critic_accountid" required data-placeholder="Select English critic" data-search-placeholder="Search faculty by name or email">
                  <option value="">Select English critic</option>
                  <?php foreach ($reviewerOptions as $faculty): ?>
                    <?php $facultyId = (int) ($faculty['accountid'] ?? 0); ?>
                    <option value="<?= e((string) $facultyId); ?>"<?= $facultyId === $selectedEnglishCriticId ? ' selected' : ''; ?>><?= e(administrator_student_faculty_label($faculty)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="student-field full">
                <label for="administrator-student-details">Abstract / Research details</label>
                <textarea id="administrator-student-details" name="student_other_details" maxlength="5000" placeholder="Enter the abstract or proposal details."><?= e((string) ($modalRecord['student_other_details'] ?? $modalRecord['other_details'] ?? '')); ?></textarea>
              </div>

              <div class="student-field full" id="administrator-sdg-field">
                <div class="sdg-field-header"><span class="student-label">Sustainable Development Goals *</span><span class="sdg-count" id="administrator-sdg-count" aria-live="polite"></span></div>
                <button class="student-secondary-btn sdg-trigger" id="administrator-open-sdgs" type="button" aria-haspopup="dialog" aria-controls="administratorSdgModal"><i class="bx bx-plus" aria-hidden="true"></i>Select SDGs</button>
                <div class="sdg-selected-summary" id="administrator-sdg-summary" aria-live="polite"></div>
                <p class="sdg-field-error" id="administrator-sdg-error">Select at least one Sustainable Development Goal.</p>
              </div>
            </div>
          </div>
          <footer class="modal-footer">
            <button class="student-secondary-btn" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="student-primary-btn" id="administrator-save-student" type="submit"><i class="bx bx-save" aria-hidden="true"></i><?= $editId > 0 ? 'Save Changes' : 'Save Student Research'; ?></button>
          </footer>
        </form>
      </div>
    </div>
  </div>

  <div class="admin-sdg-modal" id="administratorSdgModal" role="dialog" aria-modal="true" aria-labelledby="administratorSdgTitle" hidden>
    <div class="admin-sdg-backdrop" data-sdg-dismiss></div>
    <section class="admin-sdg-dialog" role="document">
      <header class="admin-sdg-header">
        <div><h3 id="administratorSdgTitle">Choose Sustainable Development Goals</h3><p>Select every goal directly addressed by the student research.</p></div>
        <button class="student-icon-btn" id="administrator-close-sdgs" type="button" aria-label="Close SDG selection"><i class="bx bx-x" aria-hidden="true"></i></button>
      </header>
      <div class="admin-sdg-tools">
        <label class="admin-sdg-search" for="administrator-sdg-search"><i class="bx bx-search" aria-hidden="true"></i><input id="administrator-sdg-search" type="search" placeholder="Search by goal number or title" autocomplete="off"></label>
        <span class="admin-sdg-counter" id="administrator-sdg-picker-count" aria-live="polite"></span>
      </div>
      <div class="admin-sdg-body">
        <div class="admin-sdg-grid">
          <?php foreach ($sdgOptions as $sdgCode => $sdgLabel): ?>
            <?php $sdgImage = 'assets/img/sdgs/sdg-' . str_pad((string) $sdgCode, 2, '0', STR_PAD_LEFT) . '.png'; ?>
            <label class="admin-sdg-option" data-search="<?= e(strtolower('sdg ' . $sdgCode . ' ' . $sdgLabel)); ?>">
              <input class="admin-sdg-checkbox" type="checkbox" name="student_sdgs[]" value="<?= e((string) $sdgCode); ?>" form="administrator-student-research-form" data-label="<?= e($sdgLabel); ?>"<?= in_array($sdgCode, $selectedSdgCodes, true) ? ' checked' : ''; ?>>
              <span class="admin-sdg-tile"><img src="<?= e(app_link($sdgImage)); ?>" alt=""><span class="admin-sdg-check"><i class="bx bx-check" aria-hidden="true"></i></span></span>
              <span class="visually-hidden"><?= e('SDG ' . $sdgCode . ': ' . $sdgLabel); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="admin-sdg-no-results" id="administrator-sdg-no-results">No goals match your search.</div>
      </div>
      <footer class="admin-sdg-footer">
        <p class="admin-sdg-source">Goal artwork follows the official UN Sustainable Development Goals. <a href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer">View the goals</a>. Use of the icons does not imply United Nations endorsement.</p>
        <div class="admin-sdg-actions">
          <button class="admin-sdg-clear" id="administrator-clear-sdgs" type="button">Clear</button>
          <button class="student-secondary-btn" id="administrator-cancel-sdgs" type="button">Cancel</button>
          <button class="student-primary-btn admin-sdg-apply" id="administrator-apply-sdgs" type="button"><i class="bx bx-check" aria-hidden="true"></i>Apply selection</button>
        </div>
      </footer>
    </section>
  </div>
</main>
<?php
$mainContent = (string) ob_get_clean();

ob_start();
?>
<script src="<?= e(app_link('assets/vendor/libs/select2/select2.min.js')); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var pageAlerts = <?= json_encode(array_values(array_filter([
      $success ? ['icon' => 'success', 'title' => 'Success', 'text' => $success, 'toast' => true] : null,
      $error ? ['icon' => 'error', 'title' => 'Unable to complete request', 'text' => $error, 'toast' => false] : null,
  ])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var facultyScope = <?= $facultyScopeJson; ?>;
  var modalElement = document.getElementById('studentResearchModal');
  var researchForm = document.getElementById('administrator-student-research-form');
  var adviserSelect = document.getElementById('administrator-student-adviser');
  var campusSelect = document.getElementById('administrator-student-campus');
  var programSelect = document.getElementById('administrator-student-program');
  var saveButton = document.getElementById('administrator-save-student');
  var modalBody = modalElement ? modalElement.querySelector('.modal-body') : null;
  var savedDuringContinuousEntry = false;

  function showPageAlert(index) {
    if (typeof Swal === 'undefined' || !Array.isArray(pageAlerts) || index >= pageAlerts.length) {
      return;
    }

    var alert = pageAlerts[index] || {};
    var options = {
      icon: alert.icon || 'info',
      title: alert.title || '',
      text: alert.text || '',
      confirmButtonColor: '#007f3d'
    };

    if (alert.toast) {
      options.toast = true;
      options.position = 'top-end';
      options.showConfirmButton = false;
      options.timer = 3200;
      options.timerProgressBar = true;
    }

    Swal.fire(options).then(function () { showPageAlert(index + 1); });
  }

  showPageAlert(0);

  if (window.jQuery && jQuery.fn.select2) {
    jQuery('.js-filter-select').select2({
      width: '100%',
      placeholder: 'All faculty advisers',
      allowClear: true
    });

    jQuery('.js-student-select').each(function () {
      var select = jQuery(this);
      select.select2({
        dropdownParent: jQuery('#studentResearchModal'),
        width: '100%',
        placeholder: select.data('placeholder') || 'Select an option',
        minimumResultsForSearch: 0,
        closeOnSelect: !select.prop('multiple')
      });

      select.on('select2:open', function () {
        var input = document.querySelector('.select2-container--open .select2-search__field');
        if (input) {
          input.placeholder = select.data('search-placeholder') || 'Search options';
        }
      });
    });
  }

  function updateSelect2(select) {
    if (select && window.jQuery && jQuery.fn.select2) {
      jQuery(select).trigger('change.select2');
    }
  }

  function bindSelectChange(select, callback) {
    if (!select) {
      return;
    }

    if (window.jQuery) {
      jQuery(select).on('change.studentResearch', callback);
      return;
    }

    select.addEventListener('change', callback);
  }

  function refreshProgramOptions(preferredProgramId) {
    if (!campusSelect || !programSelect) {
      return;
    }

    var campusId = campusSelect.value;
    var retainedProgramId = preferredProgramId || programSelect.value;
    var retainedProgramAvailable = false;

    Array.prototype.forEach.call(programSelect.options, function (option) {
      if (!option.value) {
        option.textContent = campusId ? 'Select program' : 'Select campus first';
        option.disabled = false;
        option.hidden = false;
        return;
      }

      var belongsToCampus = campusId !== '' && option.dataset.campusid === campusId;
      option.disabled = !belongsToCampus;
      option.hidden = !belongsToCampus;

      if (belongsToCampus && option.value === retainedProgramId) {
        retainedProgramAvailable = true;
      }
    });

    programSelect.value = retainedProgramAvailable ? retainedProgramId : '';
    programSelect.dataset.placeholder = campusId ? 'Select program' : 'Select campus first';
    updateSelect2(programSelect);
  }

  function synchronizeAdviser(applyFacultyDefaults) {
    var adviserId = adviserSelect ? adviserSelect.value : '';
    var scope = adviserId && facultyScope[adviserId] ? facultyScope[adviserId] : null;

    if (applyFacultyDefaults) {
      campusSelect.value = scope && scope.campusid ? String(scope.campusid) : '';
      updateSelect2(campusSelect);
      refreshProgramOptions(scope && scope.programid ? String(scope.programid) : '');
    }

    document.querySelectorAll('.js-review-team-select').forEach(function (select) {
      Array.prototype.forEach.call(select.options, function (option) {
        if (!option.value) {
          return;
        }

        var isAdviser = adviserId !== '' && option.value === adviserId;
        option.disabled = isAdviser;

        if (isAdviser && option.selected) {
          option.selected = false;
        }
      });

      if (window.jQuery && jQuery.fn.select2) {
        jQuery(select).trigger('change.select2');
      }
    });
  }

  if (adviserSelect) {
    bindSelectChange(adviserSelect, function () { synchronizeAdviser(true); });
    synchronizeAdviser(false);
  }

  if (campusSelect) {
    bindSelectChange(campusSelect, function () { refreshProgramOptions(''); });
    refreshProgramOptions(programSelect ? programSelect.value : '');
  }

  var researcherList = document.getElementById('administrator-student-researchers');
  var addResearcherButton = document.getElementById('administrator-add-student');

  function updateResearcherRows() {
    if (!researcherList) {
      return;
    }

    var rows = researcherList.querySelectorAll('.student-researcher-row');
    rows.forEach(function (row, index) {
      var input = row.querySelector('input');
      var removeButton = row.querySelector('.student-researcher-remove');
      input.setAttribute('aria-label', 'Student researcher ' + (index + 1));
      removeButton.disabled = rows.length === 1;
    });
  }

  if (addResearcherButton && researcherList) {
    addResearcherButton.addEventListener('click', function () {
      var row = document.createElement('div');
      row.className = 'student-researcher-row';
      row.innerHTML = '<input name="student_researchers[]" required maxlength="180" placeholder="Enter full student name"><button class="student-researcher-remove" type="button" aria-label="Remove student researcher"><i class="bx bx-trash" aria-hidden="true"></i></button>';
      researcherList.appendChild(row);
      updateResearcherRows();
      row.querySelector('input').focus();
    });

    researcherList.addEventListener('click', function (event) {
      var removeButton = event.target.closest('.student-researcher-remove');
      if (!removeButton || removeButton.disabled) {
        return;
      }

      removeButton.closest('.student-researcher-row').remove();
      updateResearcherRows();
    });

    updateResearcherRows();
  }

  document.querySelectorAll('.student-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.confirmed === 'true') {
        return;
      }

      event.preventDefault();

      if (typeof Swal === 'undefined') {
        return;
      }

      Swal.fire({
        icon: 'warning',
        title: 'Delete student research?',
        text: (form.dataset.researchTitle || 'This research record') + ' will be permanently removed, including its review-team assignments and uploaded abstract.',
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonColor: '#b42318',
        cancelButtonColor: '#647067',
        confirmButtonText: 'Delete research',
        cancelButtonText: 'Cancel'
      }).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        form.dataset.confirmed = 'true';
        form.submit();
      });
    });
  });

  var sdgModal = document.getElementById('administratorSdgModal');
  var openSdgButton = document.getElementById('administrator-open-sdgs');
  var closeSdgButton = document.getElementById('administrator-close-sdgs');
  var cancelSdgButton = document.getElementById('administrator-cancel-sdgs');
  var applySdgButton = document.getElementById('administrator-apply-sdgs');
  var clearSdgButton = document.getElementById('administrator-clear-sdgs');
  var sdgSearch = document.getElementById('administrator-sdg-search');
  var pickerCount = document.getElementById('administrator-sdg-picker-count');
  var mainCount = document.getElementById('administrator-sdg-count');
  var sdgSummary = document.getElementById('administrator-sdg-summary');
  var sdgError = document.getElementById('administrator-sdg-error');
  var noResults = document.getElementById('administrator-sdg-no-results');
  var sdgOptions = sdgModal ? Array.prototype.slice.call(sdgModal.querySelectorAll('.admin-sdg-option')) : [];
  var sdgCheckboxes = sdgModal ? Array.prototype.slice.call(sdgModal.querySelectorAll('.admin-sdg-checkbox')) : [];
  var sdgSnapshot = [];

  function selectedSdgs() {
    return sdgCheckboxes.filter(function (checkbox) { return checkbox.checked; });
  }

  function updateSdgPicker() {
    var count = selectedSdgs().length;
    pickerCount.textContent = count + (count === 1 ? ' goal selected' : ' goals selected');
    applySdgButton.disabled = count === 0;
  }

  function renderSdgSummary() {
    var selections = selectedSdgs();
    sdgSummary.textContent = '';
    mainCount.textContent = selections.length + ' selected';

    if (selections.length === 0) {
      var empty = document.createElement('span');
      empty.className = 'sdg-empty-copy';
      empty.textContent = 'No goals selected yet.';
      sdgSummary.appendChild(empty);
      return;
    }

    selections.forEach(function (checkbox) {
      var item = document.createElement('span');
      item.className = 'sdg-selected-item';
      var number = document.createElement('b');
      number.textContent = 'SDG ' + checkbox.value;
      var label = document.createElement('span');
      label.textContent = checkbox.dataset.label;
      item.appendChild(number);
      item.appendChild(label);
      sdgSummary.appendChild(item);
    });

    sdgError.classList.remove('visible');
  }

  function filterSdgs() {
    var query = sdgSearch.value.trim().toLowerCase();
    var visible = 0;

    sdgOptions.forEach(function (option) {
      var show = query === '' || option.dataset.search.indexOf(query) !== -1;
      option.hidden = !show;
      if (show) {
        visible += 1;
      }
    });

    noResults.classList.toggle('visible', visible === 0);
  }

  function openSdgPicker() {
    sdgSnapshot = sdgCheckboxes.map(function (checkbox) { return checkbox.checked; });
    sdgModal.hidden = false;
    document.body.classList.add('admin-sdg-open');
    sdgSearch.value = '';
    filterSdgs();
    updateSdgPicker();
    window.setTimeout(function () { sdgSearch.focus(); }, 0);
  }

  function closeSdgPicker(restore) {
    if (restore) {
      sdgCheckboxes.forEach(function (checkbox, index) { checkbox.checked = Boolean(sdgSnapshot[index]); });
    }

    sdgModal.hidden = true;
    document.body.classList.remove('admin-sdg-open');
    renderSdgSummary();
    openSdgButton.focus();
  }

  if (sdgModal && openSdgButton) {
    openSdgButton.addEventListener('click', openSdgPicker);
    closeSdgButton.addEventListener('click', function () { closeSdgPicker(true); });
    cancelSdgButton.addEventListener('click', function () { closeSdgPicker(true); });
    applySdgButton.addEventListener('click', function () { closeSdgPicker(false); });
    clearSdgButton.addEventListener('click', function () {
      sdgCheckboxes.forEach(function (checkbox) { checkbox.checked = false; });
      updateSdgPicker();
    });
    sdgSearch.addEventListener('input', filterSdgs);
    sdgCheckboxes.forEach(function (checkbox) { checkbox.addEventListener('change', updateSdgPicker); });
    sdgModal.querySelector('[data-sdg-dismiss]').addEventListener('click', function () { closeSdgPicker(true); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !sdgModal.hidden) {
        closeSdgPicker(true);
      }
    });
    renderSdgSummary();
  }

  function resetRecordFieldsForNextEntry() {
    var titleInput = document.getElementById('administrator-student-title');
    var panelistSelect = document.getElementById('administrator-student-panelists');
    var statisticianSelect = document.getElementById('administrator-student-statistician');
    var criticSelect = document.getElementById('administrator-student-critic');
    var detailsInput = document.getElementById('administrator-student-details');

    titleInput.value = '';
    Array.prototype.forEach.call(panelistSelect.options, function (option) { option.selected = false; });
    statisticianSelect.value = '';
    criticSelect.value = '';
    detailsInput.value = '';

    updateSelect2(panelistSelect);
    updateSelect2(statisticianSelect);
    updateSelect2(criticSelect);

    researcherList.innerHTML = '<div class="student-researcher-row"><input name="student_researchers[]" required maxlength="180" placeholder="Enter full student name" aria-label="Student researcher 1"><button class="student-researcher-remove" type="button" aria-label="Remove student researcher"><i class="bx bx-trash" aria-hidden="true"></i></button></div>';
    updateResearcherRows();

    sdgCheckboxes.forEach(function (checkbox) { checkbox.checked = false; });
    renderSdgSummary();
    updateSdgPicker();

    if (modalBody) {
      modalBody.scrollTop = 0;
    }

    window.setTimeout(function () { titleInput.focus(); }, 0);
  }

  if (researchForm) {
    researchForm.addEventListener('submit', async function (event) {
      researcherList.querySelectorAll('input').forEach(function (input) {
        input.value = input.value.trim().toUpperCase();
      });

      var titleInput = document.getElementById('administrator-student-title');
      titleInput.value = titleInput.value.trim().toUpperCase();

      if (selectedSdgs().length === 0) {
        event.preventDefault();
        sdgError.classList.add('visible');
        openSdgPicker();
        return;
      }

      var titleIdInput = researchForm.querySelector('input[name="titleid"]');
      var isEditing = titleIdInput && titleIdInput.value !== '' && titleIdInput.value !== '0';

      if (isEditing || typeof window.fetch !== 'function') {
        return;
      }

      event.preventDefault();
      var originalButtonContent = saveButton.innerHTML;
      saveButton.disabled = true;
      saveButton.setAttribute('aria-busy', 'true');
      saveButton.innerHTML = '<i class="bx bx-loader-alt bx-spin" aria-hidden="true"></i>Saving...';

      try {
        var response = await window.fetch(researchForm.getAttribute('action') || window.location.href, {
          method: 'POST',
          body: new FormData(researchForm),
          credentials: 'same-origin',
          headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        var payload = await response.json();

        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || 'The student research record could not be saved.');
        }

        savedDuringContinuousEntry = true;
        resetRecordFieldsForNextEntry();

        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'success',
            title: 'Research saved',
            text: payload.message || 'Student research record added.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2600,
            timerProgressBar: true
          });
        }
      } catch (requestError) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'error',
            title: 'Unable to save research',
            text: requestError && requestError.message ? requestError.message : 'The request could not be completed.',
            confirmButtonColor: '#007f3d'
          });
        }
      } finally {
        saveButton.disabled = false;
        saveButton.removeAttribute('aria-busy');
        saveButton.innerHTML = originalButtonContent;
      }
    });
  }

  if (modalElement) {
    modalElement.addEventListener('hidden.bs.modal', function () {
      if (savedDuringContinuousEntry) {
        window.location.href = <?= json_encode(administrator_student_research_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      }
    });
  }

  <?php if ($shouldOpenModal): ?>
  if (modalElement && window.bootstrap) {
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
  }
  <?php endif; ?>
});
</script>
<?php
$extraScripts = (string) ob_get_clean();

AdminPage::render([
    'title' => 'Administrator | Student Research',
    'current_page' => 'student_research',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
]);
