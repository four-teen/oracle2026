<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function administrator_manuscript_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    if ($query === '') {
        return app_link('administrator/manuscripts.php');
    }

    return app_link('administrator/manuscripts.php') . '?' . $query;
}

function administrator_manuscript_url_with_fragment(array $parameters, string $fragment): string
{
    $url = administrator_manuscript_url($parameters);
    $normalizedFragment = ltrim(trim($fragment), '#');

    if ($normalizedFragment === '') {
        return $url;
    }

    return $url . '#' . $normalizedFragment;
}

function administrator_manuscript_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function administrator_manuscript_positive_int($value): int
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

function administrator_manuscript_datetime_label(?string $value): string
{
    $trimmed = trim((string) $value);

    if ($trimmed === '') {
        return 'Not available';
    }

    $timestamp = strtotime($trimmed);

    if ($timestamp === false) {
        return $trimmed;
    }

    return date('M j, Y g:i A', $timestamp);
}

function administrator_manuscript_preview(?string $value, int $limit = 120): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
    $normalized = is_string($normalized) ? $normalized : '';

    if ($normalized === '') {
        return 'No abstract provided yet.';
    }

    if (administrator_manuscript_text_length($normalized) <= $limit) {
        return $normalized;
    }

    if (function_exists('mb_substr')) {
        return rtrim(mb_substr($normalized, 0, $limit - 1, 'UTF-8')) . '...';
    }

    return rtrim(substr($normalized, 0, $limit - 1)) . '...';
}

function administrator_manuscript_sdg_options(): array
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

function administrator_manuscript_sdg_codes($value): array
{
    $options = administrator_manuscript_sdg_options();
    $rawValues = [];

    if (is_array($value)) {
        $rawValues = $value;
    } else {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return [];
        }

        $splitValues = preg_split('/\s*,\s*/', $normalized);
        $rawValues = is_array($splitValues) ? $splitValues : [];
    }

    $selectedCodes = [];

    foreach ($rawValues as $rawValue) {
        if (is_array($rawValue)) {
            continue;
        }

        $sdgCode = (int) trim((string) $rawValue);

        if ($sdgCode > 0 && isset($options[$sdgCode])) {
            $selectedCodes[$sdgCode] = $sdgCode;
        }
    }

    ksort($selectedCodes);

    return array_values($selectedCodes);
}

function administrator_manuscript_sdg_storage_value($codes): string
{
    return implode(',', administrator_manuscript_sdg_codes($codes));
}

function administrator_manuscript_sdg_label($value): string
{
    $options = administrator_manuscript_sdg_options();
    $selectedCodes = administrator_manuscript_sdg_codes($value);

    if ($selectedCodes === []) {
        return 'SDG not set';
    }

    $labels = [];

    foreach ($selectedCodes as $sdgCode) {
        $labels[] = 'SDG ' . $sdgCode . ' - ' . $options[$sdgCode];
    }

    return implode(', ', $labels);
}

function administrator_manuscript_single_account_id($value): int
{
    return administrator_manuscript_positive_int($value);
}

function administrator_manuscript_account_ids($value): array
{
    $rawValues = [];

    if (is_array($value)) {
        $rawValues = $value;
    } else {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return [];
        }

        $splitValues = preg_split('/\s*,\s*/', $normalized);
        $rawValues = is_array($splitValues) ? $splitValues : [];
    }

    $selectedIds = [];

    foreach ($rawValues as $rawValue) {
        if (is_array($rawValue)) {
            continue;
        }

        $normalized = trim((string) $rawValue);

        if ($normalized === '' || !ctype_digit($normalized)) {
            continue;
        }

        $accountId = (int) $normalized;

        if ($accountId > 0 && !isset($selectedIds[$accountId])) {
            $selectedIds[$accountId] = $accountId;
        }
    }

    return array_values($selectedIds);
}

function administrator_manuscript_account_storage_value($ids): string
{
    return implode(',', administrator_manuscript_account_ids($ids));
}

function administrator_manuscript_account_option_label(array $account): string
{
    $accountId = isset($account['accountid']) ? (int) $account['accountid'] : 0;
    $label = administrator_manuscript_account_option_name($account);
    $email = trim((string) ($account['email'] ?? ''));

    if ($email !== '' && strcasecmp($label, $email) !== 0) {
        $label .= ' (' . $email . ')';
    }

    if (isset($account['is_enabled']) && (int) $account['is_enabled'] !== 1) {
        $label .= ' (Disabled)';
    }

    return $label;
}

function administrator_manuscript_account_option_name(array $account): string
{
    $accountId = isset($account['accountid']) ? (int) $account['accountid'] : 0;
    $name = trim((string) ($account['acc_name'] ?? ''));
    $email = trim((string) ($account['email'] ?? ''));
    return $name !== '' ? $name : ($email !== '' ? $email : 'Account #' . $accountId);
}

function administrator_manuscript_panelist_map(PDO $pdo, array $manuscriptIds): array
{
    $normalizedIds = [];

    foreach ($manuscriptIds as $manuscriptId) {
        $normalizedManuscriptId = (int) $manuscriptId;

        if ($normalizedManuscriptId > 0) {
            $normalizedIds[$normalizedManuscriptId] = $normalizedManuscriptId;
        }
    }

    if ($normalizedIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
    $statement = $pdo->prepare(
        'SELECT manuscriptid, accountid
         FROM tblmanuscript_panelists
         WHERE manuscriptid IN (' . $placeholders . ')
         ORDER BY manuscriptid ASC, manuscript_panelistid ASC, accountid ASC'
    );

    $parameterIndex = 1;

    foreach (array_values($normalizedIds) as $normalizedId) {
        $statement->bindValue($parameterIndex, $normalizedId, PDO::PARAM_INT);
        $parameterIndex++;
    }

    $statement->execute();

    $panelistMap = [];

    foreach ($statement->fetchAll() as $panelistRow) {
        $manuscriptId = isset($panelistRow['manuscriptid']) ? (int) $panelistRow['manuscriptid'] : 0;
        $accountId = isset($panelistRow['accountid']) ? (int) $panelistRow['accountid'] : 0;

        if ($manuscriptId < 1 || $accountId < 1) {
            continue;
        }

        if (!isset($panelistMap[$manuscriptId])) {
            $panelistMap[$manuscriptId] = [];
        }

        $panelistMap[$manuscriptId][$accountId] = $accountId;
    }

    foreach ($panelistMap as $manuscriptId => $accountIds) {
        $panelistMap[$manuscriptId] = array_values($accountIds);
    }

    return $panelistMap;
}

function administrator_manuscript_apply_panelist_map(array $manuscript, array $panelistMap): array
{
    $manuscriptId = isset($manuscript['manuscriptid']) ? (int) $manuscript['manuscriptid'] : 0;

    if ($manuscriptId > 0 && isset($panelistMap[$manuscriptId])) {
        $manuscript['panelist_accountids'] = administrator_manuscript_account_storage_value($panelistMap[$manuscriptId]);
    }

    return $manuscript;
}

function administrator_manuscript_resolved_account_name(
    array $manuscript,
    string $accountColumn,
    array $accountNameMap,
    ?string $legacyColumn = null
): string {
    $accountId = administrator_manuscript_single_account_id($manuscript[$accountColumn] ?? 0);

    if ($accountId > 0 && isset($accountNameMap[$accountId])) {
        return $accountNameMap[$accountId];
    }

    if ($legacyColumn === null || $legacyColumn === '') {
        return '';
    }

    return trim((string) ($manuscript[$legacyColumn] ?? ''));
}

function administrator_manuscript_resolved_account_names(
    array $manuscript,
    string $accountColumn,
    array $accountNameMap,
    ?string $legacyColumn = null
): string {
    $accountIds = administrator_manuscript_account_ids($manuscript[$accountColumn] ?? []);

    if ($accountIds !== []) {
        $resolvedNames = [];

        foreach ($accountIds as $accountId) {
            if (isset($accountNameMap[$accountId])) {
                $resolvedNames[] = $accountNameMap[$accountId];
            }
        }

        if ($resolvedNames !== []) {
            return implode(', ', $resolvedNames);
        }
    }

    if ($legacyColumn === null || $legacyColumn === '') {
        return '';
    }

    return trim((string) ($manuscript[$legacyColumn] ?? ''));
}

function administrator_manuscript_compact_value(?string $value, string $fallback, int $limit = 90): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
    $normalized = is_string($normalized) ? $normalized : '';

    if ($normalized === '') {
        return $fallback;
    }

    if (administrator_manuscript_text_length($normalized) <= $limit) {
        return $normalized;
    }

    if (function_exists('mb_substr')) {
        return rtrim(mb_substr($normalized, 0, $limit - 3, 'UTF-8')) . '...';
    }

    return rtrim(substr($normalized, 0, $limit - 3)) . '...';
}

function administrator_manuscript_assignment_label(
    string $role,
    ?string $value,
    string $fallback = 'Pending',
    int $limit = 90
): string {
    return $role . ': ' . administrator_manuscript_compact_value($value, $fallback, $limit);
}

function administrator_manuscript_detail_field_complete(
    array $manuscript,
    ?string $legacyColumn = null,
    ?string $accountColumn = null,
    bool $multipleAccounts = false
): bool {
    if ($accountColumn !== null && $accountColumn !== '') {
        if ($multipleAccounts) {
            if (administrator_manuscript_account_ids($manuscript[$accountColumn] ?? []) !== []) {
                return true;
            }
        } elseif (administrator_manuscript_single_account_id($manuscript[$accountColumn] ?? 0) > 0) {
            return true;
        }
    }

    if ($legacyColumn === null || $legacyColumn === '') {
        return false;
    }

    return administrator_manuscript_has_value((string) ($manuscript[$legacyColumn] ?? ''));
}

function administrator_manuscript_has_value(?string $value): bool
{
    return trim((string) $value) !== '';
}

function administrator_manuscript_academic_year_label($academicYearFrom, $academicYearTo, int $academicYearId = 0): string
{
    $from = trim((string) $academicYearFrom);
    $to = trim((string) $academicYearTo);

    if ($from !== '' && $to !== '') {
        return $from . ' - ' . $to;
    }

    if ($from !== '' || $to !== '') {
        return $from !== '' ? $from : $to;
    }

    return $academicYearId > 0 ? 'AY #' . $academicYearId : 'Academic year not set';
}

function administrator_manuscript_program_label(array $program): string
{
    $programId = isset($program['programid']) ? (int) $program['programid'] : (isset($program['courseid']) ? (int) $program['courseid'] : 0);
    $courseCode = trim((string) ($program['coursecode'] ?? $program['program_code'] ?? ''));
    $courseDescription = trim((string) ($program['coursedescription'] ?? $program['program_description'] ?? ''));
    $courseMajor = trim((string) ($program['coursemajor'] ?? $program['program_major'] ?? ''));

    $labelParts = [];

    if ($courseCode !== '') {
        $labelParts[] = $courseCode;
    }

    if ($courseDescription !== '') {
        $labelParts[] = $courseDescription;
    }

    $label = implode(' - ', $labelParts);

    if ($label === '') {
        $label = $programId > 0 ? 'Program #' . $programId : 'Program not set';
    }

    if ($courseMajor !== '') {
        $label .= ' (' . $courseMajor . ')';
    }

    return $label;
}

function administrator_manuscript_status_value(?string $value): string
{
    $normalized = trim((string) $value);

    return $normalized !== '' ? $normalized : 'Pending';
}

function administrator_manuscript_details_complete(array $manuscript): bool
{
    return administrator_manuscript_single_account_id($manuscript['adviser_accountid'] ?? 0) > 0
        && administrator_manuscript_account_ids($manuscript['panelist_accountids'] ?? []) !== []
        && administrator_manuscript_single_account_id($manuscript['statistician_accountid'] ?? 0) > 0
        && administrator_manuscript_single_account_id($manuscript['english_critic_accountid'] ?? 0) > 0
        && administrator_manuscript_has_value((string) ($manuscript['authors'] ?? ''))
        && administrator_manuscript_has_value((string) ($manuscript['abstract_file_path'] ?? ''));
}

function administrator_manuscript_select_base_sql(): string
{
    return 'SELECT m.titleid AS manuscriptid,
                   m.typeid AS researchtypeid,
                   m.sdgs AS sdg_code,
                   m.title AS manuscript_title,
                   m.other_details,
                   m.campusid,
                   m.ayid,
                   m.status,
                   m.encoder,
                   m.programid,
                     m.adviser_name,
                     m.adviser_accountid,
                     m.panelists,
                     m.panelist_accountids,
                     m.statisticians,
                     m.statistician_accountid,
                     m.english_critic_name,
                     m.english_critic_accountid,
                     m.authors,
                     m.abstract_file_path,
                    m.abstract_original_name,
                   m.submitted_at AS created_at,
                   m.updated_at,
                   rt.research_type,
                   c.campusname AS campus_name,
                   ay.ay_from,
                   ay.ay_to,
                   p.coursecode,
                   p.coursedescription,
                   p.coursemajor,
                    COALESCE(NULLIF(TRIM(COALESCE(e.acc_name, \'\')), \'\'), NULLIF(TRIM(COALESCE(e.email, \'\')), \'\')) AS encoder_name
             FROM tblresearches m
             LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
             LEFT JOIN tblcampus c ON c.campusid = m.campusid
             LEFT JOIN tblacademic_year ay ON ay.ayid = m.ayid
             LEFT JOIN tblcourse p ON p.courseid = m.programid
             LEFT JOIN tblaccount e ON e.accountid = m.encoder';
}

function administrator_manuscript_fetch(PDO $pdo, int $manuscriptId): ?array
{
    if ($manuscriptId < 1) {
        return null;
    }

    $statement = $pdo->prepare(
        administrator_manuscript_select_base_sql()
        . ' WHERE m.titleid = :manuscriptid
            LIMIT 1'
    );
    $statement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
    $statement->execute();

    $manuscript = $statement->fetch();

    if (!is_array($manuscript)) {
        return null;
    }

    return administrator_manuscript_apply_panelist_map(
        $manuscript,
        administrator_manuscript_panelist_map($pdo, [$manuscriptId])
    );
}

function administrator_manuscript_upload_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'manuscript_abstracts';
}

function administrator_manuscript_relative_upload_path(string $fileName): string
{
    return 'uploads/manuscript_abstracts/' . ltrim(str_replace('\\', '/', $fileName), '/');
}

function administrator_manuscript_delete_abstract(?string $relativePath): void
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

function administrator_manuscript_store_abstract(array $file, ?string $currentRelativePath = null): array
{
    $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose an abstract file to upload or keep the current file.');
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The abstract file could not be uploaded. Please try again.');
    }

    $temporaryFile = (string) ($file['tmp_name'] ?? '');

    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) {
        throw new RuntimeException('The uploaded abstract file is invalid.');
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    $fileSize = isset($file['size']) ? (int) $file['size'] : 0;
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

    if ($originalName === '') {
        throw new RuntimeException('The uploaded abstract file is missing a file name.');
    }

    if (!in_array($extension, ['pdf', 'doc', 'docx', 'txt'], true)) {
        throw new RuntimeException('Only PDF, DOC, DOCX, and TXT abstract files are allowed.');
    }

    if ($fileSize < 1) {
        throw new RuntimeException('The uploaded abstract file is empty.');
    }

    if ($fileSize > 5 * 1024 * 1024) {
        throw new RuntimeException('The abstract file must be 5 MB or smaller.');
    }

    $uploadDirectory = administrator_manuscript_upload_directory();

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The abstract upload directory could not be created.');
    }

    $storedFileName = sprintf(
        'manuscript_abstract_%s_%s.%s',
        date('YmdHis'),
        bin2hex(random_bytes(8)),
        $extension
    );
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedFileName;

    if (!move_uploaded_file($temporaryFile, $destination)) {
        throw new RuntimeException('The abstract file could not be stored on the server.');
    }

    administrator_manuscript_delete_abstract($currentRelativePath);

    return [
        'relative_path' => administrator_manuscript_relative_upload_path($storedFileName),
        'original_name' => $originalName,
    ];
}

function administrator_manuscript_abstract_url(?string $relativePath): string
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '') {
        return '';
    }

    return app_link($normalized);
}

$sdgOptions = administrator_manuscript_sdg_options();
$currentAccount = Auth::account();
$currentAccountId = is_array($currentAccount) && isset($currentAccount['accountid'])
    ? (int) $currentAccount['accountid']
    : 0;
$editManuscriptId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$detailsManuscriptId = isset($_GET['details']) ? (int) $_GET['details'] : 0;
$campuses = [];
$campusNameMap = [];
$accountOptions = [];
$accountOptionMap = [];
$accountNameMap = [];

if ($editManuscriptId < 1) {
    $editManuscriptId = 0;
}

if ($detailsManuscriptId < 1) {
    $detailsManuscriptId = 0;
}

$researchTypes = [];
$academicYears = [];
$programs = [];
$academicYearOptionMap = [];
$programOptionMap = [];
$programCampusMap = [];
$manuscripts = [];
$pageError = null;
$formError = null;
$detailsFormError = null;
$selectedManuscript = null;
$detailsManuscript = null;
$actionSuccess = get_flash('manuscript_success');
$actionError = get_flash('manuscript_error');
$continueEncodingState = [];
$totalManuscripts = 0;
$withAbstracts = 0;
$withAssignments = 0;
$readyForReview = 0;

$continueEncodingStatePayload = get_flash('manuscript_continue_state');

if (is_string($continueEncodingStatePayload) && trim($continueEncodingStatePayload) !== '') {
    $decodedContinueEncodingState = json_decode($continueEncodingStatePayload, true);

    if (is_array($decodedContinueEncodingState)) {
        $continueEncodingState = [
            'manuscriptid' => 0,
            'researchtypeid' => isset($decodedContinueEncodingState['researchtypeid']) ? (int) $decodedContinueEncodingState['researchtypeid'] : 0,
            'campusid' => administrator_manuscript_positive_int($decodedContinueEncodingState['campusid'] ?? 0),
            'ayid' => administrator_manuscript_positive_int($decodedContinueEncodingState['ayid'] ?? 0),
            'status' => 'Pending',
            'encoder' => $currentAccountId,
            'programid' => administrator_manuscript_positive_int($decodedContinueEncodingState['programid'] ?? 0),
            'sdg_code' => administrator_manuscript_sdg_storage_value($decodedContinueEncodingState['sdg_code'] ?? []),
            'manuscript_title' => '',
            'other_details' => '',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string) ($_POST['action'] ?? ''));
    $currentManuscript = null;

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $pdo = Database::connection();
        Database::ensureManuscriptTable();
        Database::ensureAccountStatusColumn();

        if ($postedAction === 'save_manuscript') {
            $manuscriptId = isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0;
            $researchTypeId = isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0;
            $campusId = administrator_manuscript_positive_int($_POST['campusid'] ?? 0);
            $ayId = administrator_manuscript_positive_int($_POST['ayid'] ?? 0);
            $programId = administrator_manuscript_positive_int($_POST['programid'] ?? 0);
            $postedSdgCodes = $_POST['sdg_code'] ?? [];
            $manuscriptTitle = trim((string) ($_POST['manuscript_title'] ?? ''));
            $otherDetails = trim((string) ($_POST['other_details'] ?? ''));
            $isEditingExistingManuscript = $manuscriptId > 0;

            if ($isEditingExistingManuscript) {
                $currentManuscript = administrator_manuscript_fetch($pdo, $manuscriptId);

                if (!is_array($currentManuscript)) {
                    throw new RuntimeException('The selected research record could not be found.');
                }
            }

            $status = $isEditingExistingManuscript
                ? administrator_manuscript_status_value($currentManuscript['status'] ?? 'Pending')
                : 'Pending';
            $encoderAccountId = $currentAccountId > 0
                ? $currentAccountId
                : administrator_manuscript_positive_int($currentManuscript['encoder'] ?? 0);

            if ($encoderAccountId < 1 && $currentAccountId > 0) {
                $encoderAccountId = $currentAccountId;
            }

            if (!is_array($postedSdgCodes)) {
                $postedSdgCodes = [$postedSdgCodes];
            }

            if ($researchTypeId < 1) {
                throw new RuntimeException('Select a research type before saving the research record.');
            }

            if ($campusId < 1) {
                throw new RuntimeException('Select a campus before saving the research record.');
            }

            $sdgCodes = [];

            foreach ($postedSdgCodes as $postedSdgCode) {
                if (is_array($postedSdgCode)) {
                    throw new RuntimeException('Select only valid SDGs before saving the research record.');
                }

                $normalizedSdgCode = trim((string) $postedSdgCode);

                if ($normalizedSdgCode === '') {
                    continue;
                }

                if (!ctype_digit($normalizedSdgCode)) {
                    throw new RuntimeException('Select only valid SDGs before saving the research record.');
                }

                $sdgCode = (int) $normalizedSdgCode;

                if (!isset($sdgOptions[$sdgCode])) {
                    throw new RuntimeException('Select only valid SDGs before saving the research record.');
                }

                $sdgCodes[$sdgCode] = $sdgCode;
            }

            if ($sdgCodes === []) {
                throw new RuntimeException('Select at least one SDG before saving the research record.');
            }

            ksort($sdgCodes);
            $sdgCodeValue = administrator_manuscript_sdg_storage_value(array_values($sdgCodes));

            if ($manuscriptTitle === '') {
                throw new RuntimeException('Research title is required.');
            }

            if (administrator_manuscript_text_length($manuscriptTitle) > 1000) {
                throw new RuntimeException('Research title must be 1000 characters or fewer.');
            }

            if (administrator_manuscript_text_length($otherDetails) > 5000) {
                throw new RuntimeException('Abstract must be 5000 characters or fewer.');
            }

            if (administrator_manuscript_text_length($status) > 30) {
                throw new RuntimeException('Status must be 30 characters or fewer.');
            }

            $researchTypeStatement = $pdo->prepare(
                'SELECT researchtypeid
                 FROM tblresearchtype
                 WHERE researchtypeid = :researchtypeid
                 LIMIT 1'
            );
            $researchTypeStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $researchTypeStatement->execute();

            if ((int) $researchTypeStatement->fetchColumn() < 1) {
                throw new RuntimeException('The selected research type does not exist.');
            }

            $campusStatement = $pdo->prepare(
                'SELECT campusid
                 FROM tblcampus
                 WHERE campusid = :campusid
                 LIMIT 1'
            );
            $campusStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
            $campusStatement->execute();

            if ((int) $campusStatement->fetchColumn() < 1) {
                throw new RuntimeException('The selected campus does not exist.');
            }

            if ($programId > 0) {
                $programStatement = $pdo->prepare(
                    "SELECT course.courseid AS programid,
                            CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid
                     FROM tblcourse course
                     LEFT JOIN tblcollege college
                       ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                     WHERE course.courseid = :programid
                     LIMIT 1"
                );
                $programStatement->bindValue(':programid', $programId, PDO::PARAM_INT);
                $programStatement->execute();
                $programRow = $programStatement->fetch();

                if (!is_array($programRow)) {
                    throw new RuntimeException('The selected program does not exist.');
                }

                $programCampusId = administrator_manuscript_positive_int($programRow['campusid'] ?? 0);
                $existingProgramId = administrator_manuscript_positive_int($currentManuscript['programid'] ?? 0);
                $existingCampusId = administrator_manuscript_positive_int($currentManuscript['campusid'] ?? 0);
                $isKeepingExistingLegacyProgram = $isEditingExistingManuscript
                    && $existingProgramId === $programId
                    && $existingCampusId === $campusId;

                if ($programCampusId > 0 && $programCampusId !== $campusId && !$isKeepingExistingLegacyProgram) {
                    throw new RuntimeException('Select a program that belongs to the selected campus.');
                }
            }

            if ($encoderAccountId > 0 && Database::findAccountById($encoderAccountId) === null) {
                throw new RuntimeException('The selected encoder account does not exist.');
            }

            $ayIdValue = $ayId > 0 ? $ayId : null;
            $encoderAccountIdValue = $encoderAccountId > 0 ? $encoderAccountId : null;
            $programIdValue = $programId > 0 ? $programId : null;

            if ($isEditingExistingManuscript) {
                $saveStatement = $pdo->prepare(
                    'UPDATE tblresearches
                     SET typeid = :researchtypeid,
                         campusid = :campusid,
                         ayid = :ayid,
                         status = :status,
                         encoder = :encoder,
                         programid = :programid,
                         sdgs = :sdg_code,
                         title = :manuscript_title,
                         other_details = :other_details
                     WHERE titleid = :manuscriptid'
                );
                $successMessage = 'Research record updated.';
            } else {
                $saveStatement = $pdo->prepare(
                    'INSERT INTO tblresearches (typeid, campusid, ayid, status, encoder, programid, sdgs, title, other_details)
                     VALUES (:researchtypeid, :campusid, :ayid, :status, :encoder, :programid, :sdg_code, :manuscript_title, :other_details)'
                );
                $successMessage = 'Research record saved. Continue encoding the next research record.';
            }

            $saveParameters = [
                'researchtypeid' => $researchTypeId,
                'campusid' => $campusId,
                'ayid' => $ayIdValue,
                'status' => $status,
                'encoder' => $encoderAccountIdValue,
                'programid' => $programIdValue,
                'sdg_code' => $sdgCodeValue,
                'manuscript_title' => $manuscriptTitle,
                'other_details' => $otherDetails !== '' ? $otherDetails : null,
            ];

            if ($isEditingExistingManuscript) {
                $saveParameters['manuscriptid'] = $manuscriptId;
            }

            $pdo->beginTransaction();

            try {
                $saveStatement->execute($saveParameters);

                if ($manuscriptId < 1) {
                    $manuscriptId = (int) $pdo->lastInsertId();
                }

                TitleSimilarity::refreshCatalog($pdo);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            set_flash('manuscript_success', $successMessage);

            if ($isEditingExistingManuscript) {
                redirect(administrator_manuscript_url_with_fragment([
                    'edit' => $manuscriptId,
                    'details' => $manuscriptId,
                ], 'skip-target'));
            }

            set_flash('manuscript_continue_state', json_encode([
                'researchtypeid' => $researchTypeId,
                'campusid' => $campusId,
                'ayid' => $ayId,
                'programid' => $programId,
                'sdg_code' => array_values($sdgCodes),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            redirect(administrator_manuscript_url_with_fragment([
                'details' => $manuscriptId,
                'modal' => 'basic',
            ], 'skip-target'));
        }

        if ($postedAction === 'save_manuscript_details') {
            $manuscriptId = isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0;
            $adviserAccountId = administrator_manuscript_single_account_id($_POST['adviser_accountid'] ?? 0);
            $postedPanelistAccountIds = $_POST['panelist_accountids'] ?? [];
            $statisticianAccountId = administrator_manuscript_single_account_id($_POST['statistician_accountid'] ?? 0);
            $englishCriticAccountId = administrator_manuscript_single_account_id($_POST['english_critic_accountid'] ?? 0);
            $authors = trim((string) ($_POST['authors'] ?? ''));

            if (!is_array($postedPanelistAccountIds)) {
                $postedPanelistAccountIds = [$postedPanelistAccountIds];
            }

            if ($manuscriptId < 1) {
                throw new RuntimeException('Select a valid research record before saving the detailed information.');
            }

            if (administrator_manuscript_text_length($authors) > 3000) {
                throw new RuntimeException('Authors must be 3000 characters or fewer.');
            }

            $currentManuscript = administrator_manuscript_fetch($pdo, $manuscriptId);

            if (!is_array($currentManuscript)) {
                throw new RuntimeException('The selected research record could not be found.');
            }

            $accountOptions = $pdo->query(
                'SELECT accountid, acc_name, email, is_enabled
                 FROM tblaccount
                 ORDER BY is_enabled DESC, acc_name ASC, email ASC, accountid ASC'
            )->fetchAll();

            $accountOptionMap = [];

            foreach ($accountOptions as $accountOption) {
                $accountOptionId = isset($accountOption['accountid']) ? (int) $accountOption['accountid'] : 0;

                if ($accountOptionId > 0) {
                    $accountOptionMap[$accountOptionId] = administrator_manuscript_account_option_label($accountOption);
                }
            }

            if ($adviserAccountId < 1 || !isset($accountOptionMap[$adviserAccountId])) {
                throw new RuntimeException('Select a valid adviser.');
            }

            $panelistAccountIds = administrator_manuscript_account_ids($postedPanelistAccountIds);

            if ($panelistAccountIds === []) {
                throw new RuntimeException('Select at least one panelist.');
            }

            foreach ($panelistAccountIds as $panelistAccountId) {
                if (!isset($accountOptionMap[$panelistAccountId])) {
                    throw new RuntimeException('Select only valid panelists.');
                }
            }

            if ($statisticianAccountId < 1 || !isset($accountOptionMap[$statisticianAccountId])) {
                throw new RuntimeException('Select a valid statistician.');
            }

            if ($englishCriticAccountId < 1 || !isset($accountOptionMap[$englishCriticAccountId])) {
                throw new RuntimeException('Select a valid English critic.');
            }

            if ($authors === '') {
                throw new RuntimeException('Enter at least one author.');
            }

            $abstractRelativePath = (string) ($currentManuscript['abstract_file_path'] ?? '');
            $abstractOriginalName = (string) ($currentManuscript['abstract_original_name'] ?? '');
            $abstractFile = $_FILES['abstract_file'] ?? null;

            if (is_array($abstractFile) && (int) ($abstractFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploadedAbstract = administrator_manuscript_store_abstract($abstractFile, $abstractRelativePath);
                $abstractRelativePath = (string) $uploadedAbstract['relative_path'];
                $abstractOriginalName = (string) $uploadedAbstract['original_name'];
            }

            $pdo->beginTransaction();

            try {
                $panelistAccountIdValue = administrator_manuscript_account_storage_value($panelistAccountIds);
                $detailsStatement = $pdo->prepare(
                    'UPDATE tblresearches
                     SET adviser_name = NULL,
                         adviser_accountid = :adviser_accountid,
                         panelists = NULL,
                         panelist_accountids = :panelist_accountids,
                         statisticians = NULL,
                         statistician_accountid = :statistician_accountid,
                         english_critic_name = NULL,
                         english_critic_accountid = :english_critic_accountid,
                         authors = :authors,
                         abstract_file_path = :abstract_file_path,
                         abstract_original_name = :abstract_original_name
                     WHERE titleid = :manuscriptid'
                );
                $detailsStatement->execute([
                    'adviser_accountid' => $adviserAccountId,
                    'panelist_accountids' => $panelistAccountIdValue !== '' ? $panelistAccountIdValue : null,
                    'statistician_accountid' => $statisticianAccountId,
                    'english_critic_accountid' => $englishCriticAccountId,
                    'authors' => $authors,
                    'abstract_file_path' => $abstractRelativePath !== '' ? $abstractRelativePath : null,
                    'abstract_original_name' => $abstractOriginalName !== '' ? $abstractOriginalName : null,
                    'manuscriptid' => $manuscriptId,
                ]);

                $clearPanelistsStatement = $pdo->prepare(
                    'DELETE FROM tblmanuscript_panelists
                     WHERE manuscriptid = :manuscriptid'
                );
                $clearPanelistsStatement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
                $clearPanelistsStatement->execute();

                $insertPanelistStatement = $pdo->prepare(
                    'INSERT INTO tblmanuscript_panelists (manuscriptid, accountid)
                     VALUES (:manuscriptid, :accountid)'
                );

                foreach ($panelistAccountIds as $panelistAccountId) {
                    $insertPanelistStatement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
                    $insertPanelistStatement->bindValue(':accountid', $panelistAccountId, PDO::PARAM_INT);
                    $insertPanelistStatement->execute();
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            set_flash('manuscript_success', 'Research details updated.');
            redirect(administrator_manuscript_url_with_fragment([
                'edit' => $manuscriptId,
                'details' => $manuscriptId,
            ], 'skip-target'));
        }

        if ($postedAction === 'delete_manuscript') {
            $manuscriptId = isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0;

            if ($manuscriptId < 1) {
                throw new RuntimeException('Select a valid research record before deleting it.');
            }

            $currentManuscript = administrator_manuscript_fetch($pdo, $manuscriptId);

            if (!is_array($currentManuscript)) {
                throw new RuntimeException('The selected research record could not be found.');
            }

            $abstractRelativePath = (string) ($currentManuscript['abstract_file_path'] ?? '');

            $pdo->beginTransaction();

            try {
                $deletePanelistsStatement = $pdo->prepare(
                    'DELETE FROM tblmanuscript_panelists
                     WHERE manuscriptid = :manuscriptid'
                );
                $deletePanelistsStatement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
                $deletePanelistsStatement->execute();

                $deleteManuscriptStatement = $pdo->prepare(
                    'DELETE FROM tblresearches
                     WHERE titleid = :manuscriptid
                     LIMIT 1'
                );
                $deleteManuscriptStatement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
                $deleteManuscriptStatement->execute();

                if ($deleteManuscriptStatement->rowCount() < 1) {
                    throw new RuntimeException('The selected research record could not be deleted.');
                }

                TitleSimilarity::refreshCatalog($pdo);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            administrator_manuscript_delete_abstract($abstractRelativePath);
            set_flash('manuscript_success', 'Research record deleted.');
            redirect(administrator_manuscript_url_with_fragment([], 'skip-target'));
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($postedAction === 'save_manuscript') {
            $currentStatus = is_array($currentManuscript)
                ? administrator_manuscript_status_value($currentManuscript['status'] ?? 'Pending')
                : 'Pending';
            $currentEncoderId = $currentAccountId > 0
                ? $currentAccountId
                : administrator_manuscript_positive_int($currentManuscript['encoder'] ?? 0);
            $formError = $exception->getMessage();
            $selectedManuscript = [
                'manuscriptid' => isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0,
                'researchtypeid' => isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0,
                'campusid' => administrator_manuscript_positive_int($_POST['campusid'] ?? 0),
                'ayid' => administrator_manuscript_positive_int($_POST['ayid'] ?? 0),
                'status' => $currentStatus,
                'encoder' => $currentEncoderId,
                'programid' => administrator_manuscript_positive_int($_POST['programid'] ?? 0),
                'sdg_code' => administrator_manuscript_sdg_storage_value($_POST['sdg_code'] ?? []),
                'manuscript_title' => trim((string) ($_POST['manuscript_title'] ?? '')),
                'other_details' => trim((string) ($_POST['other_details'] ?? '')),
                'created_at' => isset($currentManuscript['created_at']) ? (string) $currentManuscript['created_at'] : '',
                'updated_at' => isset($currentManuscript['updated_at']) ? (string) $currentManuscript['updated_at'] : '',
            ];
            $editManuscriptId = (int) ($selectedManuscript['manuscriptid'] ?? 0);
        } elseif ($postedAction === 'save_manuscript_details') {
            $detailsFormError = $exception->getMessage();
            $detailsManuscript = array_merge(
                is_array($currentManuscript) ? $currentManuscript : [],
                [
                    'manuscriptid' => isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0,
                    'adviser_accountid' => administrator_manuscript_single_account_id($_POST['adviser_accountid'] ?? 0),
                    'panelist_accountids' => administrator_manuscript_account_storage_value($_POST['panelist_accountids'] ?? []),
                    'statistician_accountid' => administrator_manuscript_single_account_id($_POST['statistician_accountid'] ?? 0),
                    'english_critic_accountid' => administrator_manuscript_single_account_id($_POST['english_critic_accountid'] ?? 0),
                    'authors' => trim((string) ($_POST['authors'] ?? '')),
                ]
            );
            $detailsManuscriptId = (int) ($detailsManuscript['manuscriptid'] ?? 0);
        } else {
            set_flash('manuscript_error', $exception->getMessage());
            redirect(administrator_manuscript_url());
        }
    }
}

try {
    $pdo = Database::connection();
    Database::ensureManuscriptTable();
    Database::ensureAccountStatusColumn();

    $campuses = $pdo->query(
        'SELECT campusid, campusname
         FROM tblcampus
         ORDER BY campusname ASC, campusid ASC'
    )->fetchAll();

    foreach ($campuses as $campus) {
        $campusId = isset($campus['campusid']) ? (int) $campus['campusid'] : 0;

        if ($campusId > 0) {
            $campusNameMap[$campusId] = trim((string) ($campus['campusname'] ?? 'Campus #' . $campusId));
        }
    }

    $academicYears = $pdo->query(
        'SELECT ayid, ay_from, ay_to
         FROM tblacademic_year
         ORDER BY ay_from DESC, ay_to DESC, ayid DESC'
    )->fetchAll();

    foreach ($academicYears as $academicYear) {
        $academicYearId = isset($academicYear['ayid']) ? (int) $academicYear['ayid'] : 0;

        if ($academicYearId > 0) {
            $academicYearOptionMap[$academicYearId] = administrator_manuscript_academic_year_label(
                $academicYear['ay_from'] ?? '',
                $academicYear['ay_to'] ?? '',
                $academicYearId
            );
        }
    }

    $programs = $pdo->query(
        "SELECT course.courseid AS programid,
                course.coursecode,
                course.coursedescription,
                course.coursemajor,
                course.coursecollege,
                course.specification,
                CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid
         FROM tblcourse course
         LEFT JOIN tblcollege college
           ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
         ORDER BY course.coursecode ASC, course.coursedescription ASC, programid ASC"
    )->fetchAll();

    foreach ($programs as $program) {
        $programId = isset($program['programid']) ? (int) $program['programid'] : 0;

        if ($programId > 0) {
            $programOptionMap[$programId] = administrator_manuscript_program_label($program);
            $programCampusMap[$programId] = administrator_manuscript_positive_int($program['campusid'] ?? 0);
        }
    }

    $researchTypes = $pdo->query(
        'SELECT researchtypeid, research_type, is_active
         FROM tblresearchtype
         ORDER BY is_active DESC, research_type ASC'
    )->fetchAll();

    $accountOptions = $pdo->query(
        'SELECT accountid, acc_name, email, is_enabled
         FROM tblaccount
         ORDER BY is_enabled DESC, acc_name ASC, email ASC, accountid ASC'
    )->fetchAll();

    foreach ($accountOptions as $accountOption) {
        $accountOptionId = isset($accountOption['accountid']) ? (int) $accountOption['accountid'] : 0;

        if ($accountOptionId > 0) {
            $accountOptionMap[$accountOptionId] = administrator_manuscript_account_option_label($accountOption);
            $accountNameMap[$accountOptionId] = administrator_manuscript_account_option_name($accountOption);
        }
    }

    $totalManuscripts = (int) $pdo->query('SELECT COUNT(*) FROM tblresearches')->fetchColumn();
    $withAbstracts = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM tblresearches
         WHERE TRIM(COALESCE(abstract_file_path, '')) <> ''"
    )->fetchColumn();
    $withAssignments = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM tblresearches m
         WHERE COALESCE(m.adviser_accountid, 0) > 0
            OR EXISTS (
                SELECT 1
                FROM tblmanuscript_panelists mp
                WHERE mp.manuscriptid = m.titleid
            )
            OR TRIM(COALESCE(m.panelist_accountids, '')) <> ''
            OR COALESCE(m.statistician_accountid, 0) > 0
            OR COALESCE(m.english_critic_accountid, 0) > 0"
    )->fetchColumn();
    $readyForReview = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM tblresearches m
         WHERE COALESCE(m.adviser_accountid, 0) > 0
           AND (
                EXISTS (
                    SELECT 1
                    FROM tblmanuscript_panelists mp
                    WHERE mp.manuscriptid = m.titleid
                )
                OR TRIM(COALESCE(m.panelist_accountids, '')) <> ''
           )
           AND COALESCE(m.statistician_accountid, 0) > 0
           AND COALESCE(m.english_critic_accountid, 0) > 0
           AND TRIM(COALESCE(m.authors, '')) <> ''
           AND TRIM(COALESCE(m.abstract_file_path, '')) <> ''"
    )->fetchColumn();

    $manuscripts = $pdo->query(
        administrator_manuscript_select_base_sql()
        . ' ORDER BY m.submitted_at DESC, m.titleid DESC'
    )->fetchAll();

    $panelistRelationshipMap = administrator_manuscript_panelist_map(
        $pdo,
        array_map(
            static function (array $manuscript): int {
                return isset($manuscript['manuscriptid']) ? (int) $manuscript['manuscriptid'] : 0;
            },
            $manuscripts
        )
    );
    $manuscripts = array_map(
        static function (array $manuscript) use ($panelistRelationshipMap): array {
            return administrator_manuscript_apply_panelist_map($manuscript, $panelistRelationshipMap);
        },
        $manuscripts
    );

    if ($selectedManuscript === null && $editManuscriptId > 0) {
        $selectedManuscript = administrator_manuscript_fetch($pdo, $editManuscriptId);
    }

    if ($selectedManuscript === null && $editManuscriptId < 1 && $continueEncodingState !== []) {
        $selectedManuscript = $continueEncodingState;
    }

    if ($detailsManuscript === null && $detailsManuscriptId > 0) {
        $detailsManuscript = administrator_manuscript_fetch($pdo, $detailsManuscriptId);
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$selectedManuscriptId = is_array($selectedManuscript) && isset($selectedManuscript['manuscriptid'])
    ? (int) $selectedManuscript['manuscriptid']
    : 0;
$selectedDetailsManuscriptId = is_array($detailsManuscript) && isset($detailsManuscript['manuscriptid'])
    ? (int) $detailsManuscript['manuscriptid']
    : 0;
$requestedModal = trim((string) ($_GET['modal'] ?? ''));

if ($requestedModal !== 'basic' && $requestedModal !== 'details') {
    $requestedModal = '';
}

$isEditingBasic = $selectedManuscriptId > 0;
$isManagingDetails = $selectedDetailsManuscriptId > 0;
$pendingFollowUp = max(0, $totalManuscripts - $readyForReview);

if (!is_array($selectedManuscript)) {
    $selectedManuscript = [];
}

if (!is_array($detailsManuscript)) {
    $detailsManuscript = [];
}

if (trim((string) ($selectedManuscript['status'] ?? '')) === '') {
    $selectedManuscript['status'] = 'Pending';
}

if ((int) ($selectedManuscript['encoder'] ?? 0) < 1 && $currentAccountId > 0) {
    $selectedManuscript['encoder'] = $currentAccountId;
}

$selectedSdgCodes = administrator_manuscript_sdg_codes($selectedManuscript['sdg_code'] ?? []);
$selectedCampusId = administrator_manuscript_positive_int($selectedManuscript['campusid'] ?? 0);
$selectedAyId = administrator_manuscript_positive_int($selectedManuscript['ayid'] ?? 0);
$selectedProgramId = administrator_manuscript_positive_int($selectedManuscript['programid'] ?? 0);
$selectedAdviserAccountId = administrator_manuscript_single_account_id($detailsManuscript['adviser_accountid'] ?? 0);
$selectedPanelistAccountIds = administrator_manuscript_account_ids($detailsManuscript['panelist_accountids'] ?? []);
$selectedStatisticianAccountId = administrator_manuscript_single_account_id($detailsManuscript['statistician_accountid'] ?? 0);
$selectedEnglishCriticAccountId = administrator_manuscript_single_account_id($detailsManuscript['english_critic_accountid'] ?? 0);
$basicModalTitle = $isEditingBasic ? 'Edit Research Record' : 'Add Research Record';
$detailsModalTitle = 'Manage Research Details';
$shouldOpenBasicModal = $formError !== null || $continueEncodingState !== [];
$shouldOpenDetailsModal = $detailsFormError !== null;

$programOptionsForScript = [];

foreach ($programs as $program) {
    $programId = isset($program['programid']) ? (int) $program['programid'] : 0;

    if ($programId < 1) {
        continue;
    }

    $programOptionsForScript[] = [
        'id' => (string) $programId,
        'label' => $programOptionMap[$programId] ?? administrator_manuscript_program_label($program),
        'campusid' => $programCampusMap[$programId] ?? 0,
    ];
}

$programOptionsJson = json_encode(
    $programOptionsForScript,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($programOptionsJson)) {
    $programOptionsJson = '[]';
}

$basicDrawerDefaults = [
    'manuscriptid' => 0,
    'researchtypeid' => $isEditingBasic ? 0 : (int) ($selectedManuscript['researchtypeid'] ?? 0),
    'campusid' => $isEditingBasic ? 0 : $selectedCampusId,
    'ayid' => $isEditingBasic ? 0 : $selectedAyId,
    'programid' => $isEditingBasic ? 0 : $selectedProgramId,
    'program_label' => !$isEditingBasic && $selectedProgramId > 0
        ? ($programOptionMap[$selectedProgramId] ?? '')
        : '',
    'sdg_codes' => $isEditingBasic ? [] : array_values($selectedSdgCodes),
    'manuscript_title' => $isEditingBasic ? '' : (string) ($selectedManuscript['manuscript_title'] ?? ''),
    'other_details' => $isEditingBasic ? '' : (string) ($selectedManuscript['other_details'] ?? ''),
];

$basicDrawerDefaultsJson = json_encode(
    $basicDrawerDefaults,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($basicDrawerDefaultsJson)) {
    $basicDrawerDefaultsJson = '{}';
}

$manuscriptRecordsForScript = [];

foreach ($manuscripts as $record) {
    $recordId = isset($record['manuscriptid']) ? (int) $record['manuscriptid'] : 0;

    if ($recordId < 1) {
        continue;
    }

    $recordCampusId = administrator_manuscript_positive_int($record['campusid'] ?? 0);
    $recordAyId = administrator_manuscript_positive_int($record['ayid'] ?? 0);
    $recordProgramId = administrator_manuscript_positive_int($record['programid'] ?? 0);
    $recordCampusLabel = trim((string) ($record['campus_name'] ?? ''));

    if ($recordCampusLabel === '') {
        $recordCampusLabel = $recordCampusId > 0 ? 'Campus #' . $recordCampusId : 'Campus not set';
    }

    $recordProgramLabel = administrator_manuscript_program_label([
        'programid' => $recordProgramId,
        'coursecode' => $record['coursecode'] ?? '',
        'coursedescription' => $record['coursedescription'] ?? '',
        'coursemajor' => $record['coursemajor'] ?? '',
    ]);

    $manuscriptRecordsForScript[] = [
        'id' => $recordId,
        'manuscript_title' => (string) ($record['manuscript_title'] ?? ''),
        'researchtypeid' => isset($record['researchtypeid']) ? (int) $record['researchtypeid'] : 0,
        'campusid' => $recordCampusId,
        'ayid' => $recordAyId,
        'programid' => $recordProgramId,
        'program_label' => $recordProgramLabel,
        'sdg_codes' => administrator_manuscript_sdg_codes($record['sdg_code'] ?? []),
        'other_details' => (string) ($record['other_details'] ?? ''),
        'research_type_label' => (string) ($record['research_type'] ?? 'Unknown Type'),
        'sdg_label' => administrator_manuscript_sdg_label((string) ($record['sdg_code'] ?? '')),
        'campus_label' => $recordCampusLabel,
        'adviser_accountid' => administrator_manuscript_single_account_id($record['adviser_accountid'] ?? 0),
        'panelist_accountids' => administrator_manuscript_account_ids($record['panelist_accountids'] ?? []),
        'statistician_accountid' => administrator_manuscript_single_account_id($record['statistician_accountid'] ?? 0),
        'english_critic_accountid' => administrator_manuscript_single_account_id($record['english_critic_accountid'] ?? 0),
        'authors' => (string) ($record['authors'] ?? ''),
        'abstract_file_url' => administrator_manuscript_abstract_url((string) ($record['abstract_file_path'] ?? '')),
        'abstract_original_name' => (string) ($record['abstract_original_name'] ?? ''),
    ];
}

$manuscriptRecordsJson = json_encode(
    $manuscriptRecordsForScript,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($manuscriptRecordsJson)) {
    $manuscriptRecordsJson = '[]';
}

$swalAlerts = [];

if ($actionSuccess !== null) {
    $swalAlerts[] = [
        'icon' => 'success',
        'title' => 'Done',
        'text' => $actionSuccess,
        'toast' => true,
    ];
}

if ($actionError !== null) {
    $swalAlerts[] = [
        'icon' => 'error',
        'title' => 'Something went wrong',
        'text' => $actionError,
        'toast' => false,
    ];
}

if ($formError !== null) {
    $swalAlerts[] = [
        'icon' => 'error',
        'title' => 'Unable to save research record',
        'text' => $formError,
        'toast' => false,
    ];
}

if ($detailsFormError !== null) {
    $swalAlerts[] = [
        'icon' => 'error',
        'title' => 'Unable to save research details',
        'text' => $detailsFormError,
        'toast' => false,
    ];
}

$swalAlertsJson = json_encode(
    $swalAlerts,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($swalAlertsJson)) {
    $swalAlertsJson = '[]';
}

$initialManuscriptBatchSize = 12;

$cancelBasicEditUrl = $selectedDetailsManuscriptId > 0
    ? administrator_manuscript_url(['details' => $selectedDetailsManuscriptId])
    : administrator_manuscript_url();
$cancelDetailsUrl = $selectedManuscriptId > 0
    ? administrator_manuscript_url(['edit' => $selectedManuscriptId])
    : administrator_manuscript_url();

$extraStyles = '
.manuscripts-alert { margin-bottom: 18px; padding: 14px 16px; border-radius: 12px; font-size: 14px; font-weight: 600; }
.manuscripts-alert.success { color: #237752; background-color: rgba(75, 222, 151, 0.14); }
.manuscripts-alert.error { color: #a64040; background-color: rgba(242, 100, 100, 0.14); }
.manuscripts-panel { padding: 24px; margin-bottom: 24px; }
.manuscripts-panel-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.manuscripts-panel-copy, .manuscripts-muted, .manuscripts-field-note, .manuscripts-toolbar-note { color: #767676; font-size: 13px; line-height: 1.6; }
.manuscripts-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.manuscripts-toolbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.manuscripts-toolbar-search { flex: 1 1 360px; display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; min-width: min(100%, 320px); }
.manuscripts-search-field { position: relative; flex: 1 1 320px; max-width: 420px; margin: 0; }
.manuscripts-search-field i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #8b95a7; font-size: 1rem; pointer-events: none; }
.manuscripts-search-input { min-height: 44px; padding-left: 42px; border: 1px solid #dbe1ef; border-radius: 14px; background: #ffffff; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04); }
.manuscripts-search-input:focus { border-color: rgba(47, 73, 209, 0.34); box-shadow: 0 0 0 4px rgba(47, 73, 209, 0.08); }
.manuscripts-search-summary { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 6px 12px; border-radius: 999px; background: rgba(47, 73, 209, 0.08); color: #42506a; font-size: 12px; font-weight: 700; white-space: nowrap; }
.manuscripts-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.manuscripts-form-grid .full-width { grid-column: 1 / -1; }
.manuscripts-drawer-body .form-label, .manuscripts-modal-body .form-label { display: inline-block; margin-bottom: 8px; color: #566a7f; font-size: 1rem; font-weight: 700; letter-spacing: 0; text-transform: none; }
.manuscripts-select, .manuscripts-textarea, .manuscripts-file-input { width: 100%; border: 0; border-radius: 8px; background-color: #eff0f6; color: #171717; }
.manuscripts-select { height: 44px; padding: 0 14px; }
.manuscripts-select[multiple] { min-height: 44px; height: auto; padding: 10px 14px; }
.manuscripts-textarea { min-height: 120px; padding: 14px 16px; resize: vertical; }
.manuscripts-file-input { min-height: 44px; padding: 10px 14px; }
.manuscripts-panel-actions, .manuscripts-detail-actions, .manuscripts-actions, .manuscripts-file-meta, .manuscripts-status-stack, .manuscripts-status-row, .manuscripts-record-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.manuscripts-panel-actions, .manuscripts-detail-actions { margin-top: 18px; }
.manuscripts-basic-drawer { width: min(720px, 100vw); border: 0; }
.manuscripts-details-drawer { width: min(960px, 100vw); border: 0; }
.offcanvas { transition: transform 0.34s cubic-bezier(0.22, 1, 0.36, 1); will-change: transform; backface-visibility: hidden; -webkit-backface-visibility: hidden; }
.offcanvas-backdrop { backdrop-filter: blur(4px); transition: opacity 0.24s ease; will-change: opacity; }
.offcanvas.showing, .offcanvas.show:not(.hiding) { box-shadow: -20px 0 48px rgba(17, 24, 39, 0.16); }
.manuscripts-drawer-header { padding: 24px 24px 0; border-bottom: 0; display: flex; align-items: flex-start; gap: 16px; }
.manuscripts-drawer-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.manuscripts-drawer-actions { margin-top: 22px; }
.manuscripts-modal-dialog { max-width: min(1240px, calc(100vw - 2rem)); margin: 1rem auto; }
.manuscripts-modal-content { border: 0; border-radius: 18px; overflow: hidden; }
.manuscripts-modal-header { padding: 24px 24px 0; border-bottom: 0; display: flex; align-items: flex-start; gap: 16px; }
.manuscripts-modal-header-main { min-width: 0; }
.manuscripts-modal-title { margin: 0; font-size: 1.35rem; font-weight: 700; color: #566a7f; }
.manuscripts-modal-copy { margin: 6px 0 0; color: #767676; font-size: 13px; line-height: 1.6; }
.manuscripts-modal-header-actions { margin-left: auto; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.manuscripts-modal-body { padding: 24px; }
.manuscripts-selected-record { margin-bottom: 22px; padding: 18px 20px; border: 1px solid rgba(47, 73, 209, 0.12); border-radius: 16px; background: linear-gradient(135deg, rgba(47, 73, 209, 0.08), rgba(47, 73, 209, 0.03)); }
.manuscripts-selected-title { margin: 0; color: #2e405d; font-size: 1.75rem; font-weight: 800; line-height: 1.25; }
.manuscripts-selected-meta { margin-top: 8px; color: #67748f; font-size: 0.95rem; line-height: 1.6; }
.manuscripts-inline-btn { min-height: 36px; padding: 8px 14px; border: 0; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; line-height: 1.2; color: #2f49d1; background-color: rgba(47, 73, 209, 0.1); }
.manuscripts-inline-btn:hover { background-color: rgba(47, 73, 209, 0.16); color: #2f49d1; }
.manuscripts-inline-btn.success { color: #237752; background-color: rgba(75, 222, 151, 0.14); }
.manuscripts-inline-btn.success:hover { background-color: rgba(75, 222, 151, 0.2); }
.manuscripts-inline-btn.neutral { color: #5e667a; background-color: rgba(118, 118, 118, 0.12); }
.manuscripts-inline-btn.neutral:hover { background-color: rgba(118, 118, 118, 0.18); }
.manuscripts-detail-pill { display: inline-flex; align-items: center; justify-content: flex-start; min-height: 30px; max-width: 100%; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; text-align: left; white-space: normal; }
.manuscripts-detail-pill.complete { color: #237752; background-color: rgba(75, 222, 151, 0.14); }
.manuscripts-detail-pill.pending { color: #5e667a; background-color: rgba(118, 118, 118, 0.12); }
.manuscripts-card-list { display: grid; grid-template-columns: 1fr; gap: 18px; }
.manuscripts-record-card { background: #fff; border-radius: 18px; box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12); padding: 22px 24px; }
.manuscripts-record-layout { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 18px; align-items: start; }
.manuscripts-record-main { display: flex; align-items: flex-start; gap: 14px; min-width: 0; width: 100%; flex: 1 1 auto; }
.manuscripts-record-id { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; border-radius: 999px; font-size: 14px; font-weight: 700; color: #2f49d1; background-color: rgba(47, 73, 209, 0.1); }
.manuscripts-record-meta { flex: 0 0 auto; }
.manuscripts-record-body { display: flex; flex-direction: column; gap: 10px; min-width: 0; width: 100%; flex: 1 1 auto; }
.manuscripts-table-title { font-weight: 700; color: #171717; text-transform: uppercase; line-height: 1.45; white-space: normal; }
.manuscripts-record-authors { color: #5e667a; font-size: 14px; font-weight: 600; line-height: 1.55; white-space: normal; }
.manuscripts-table-subtitle { color: #767676; font-size: 12px; }
.manuscripts-record-summary { display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; white-space: normal; }
.manuscripts-record-facts { display: flex; gap: 8px; flex-wrap: wrap; }
.manuscripts-record-fact { display: inline-flex; align-items: center; min-height: 28px; padding: 5px 10px; border-radius: 999px; background-color: rgba(47, 73, 209, 0.08); color: #556079; font-size: 12px; font-weight: 600; }
.manuscripts-meta-divider { color: #b6bdd1; }
.manuscripts-status-row { justify-content: space-between; align-items: flex-start; gap: 14px; width: 100%; }
.manuscripts-status-stack { flex: 1 1 520px; }
.manuscripts-record-actions { margin-left: auto; justify-content: flex-end; align-items: flex-start; gap: 8px; flex: 0 0 auto; }
.manuscripts-action-pill { display: inline-flex; align-items: center; justify-content: center; min-height: 30px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.2; border: 0; text-decoration: none; white-space: nowrap; }
.manuscripts-action-pill.primary { color: #2f49d1; background-color: rgba(47, 73, 209, 0.12); }
.manuscripts-action-pill.primary:hover { color: #2440d0; background-color: rgba(47, 73, 209, 0.18); }
.manuscripts-action-pill.success { color: #1f7a58; background-color: rgba(75, 222, 151, 0.18); }
.manuscripts-action-pill.success:hover { color: #18684b; background-color: rgba(75, 222, 151, 0.24); }
.manuscripts-action-pill.neutral { color: #5e667a; background-color: rgba(118, 118, 118, 0.12); }
.manuscripts-action-pill.neutral:hover { color: #4b5565; background-color: rgba(118, 118, 118, 0.18); }
.manuscripts-action-pill.danger { color: #a64040; background-color: rgba(242, 100, 100, 0.14); }
.manuscripts-action-pill.danger:hover { color: #8c3333; background-color: rgba(242, 100, 100, 0.22); }
.manuscripts-action-form { display: inline-flex; margin: 0; }
.manuscripts-empty { padding: 32px 24px; text-align: center; color: #767676; background: #fff; border-radius: 18px; box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12); }
.manuscripts-record-card.is-filtered-out { display: none; }
.manuscripts-record-card.is-pending-load { display: none; }
.manuscripts-scroll-sentinel { display: flex; align-items: center; justify-content: center; min-height: 54px; color: #8592a3; font-size: 13px; font-weight: 600; }
.manuscripts-scroll-sentinel[hidden] { display: none; }
.swal2-popup { border-radius: 18px; }
.swal2-toast { box-shadow: 0 0.9rem 2.4rem rgba(67, 89, 113, 0.18); }
.select2-container { width: 100% !important; }
.select2-container--default .select2-selection--single { min-height: 44px; height: auto; border: 0; border-radius: 8px; background-color: #eff0f6; padding: 6px 10px; display: flex; align-items: center; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 1.5; padding-left: 4px; padding-right: 28px; color: #171717; width: 100%; }
.select2-container--default .select2-selection--single .select2-selection__placeholder { color: #767676; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 100%; right: 10px; top: 0; }
.select2-container--default.select2-container--focus .select2-selection--single { border: 0; box-shadow: 0 0 0 2px rgba(47, 73, 209, 0.15); }
.select2-container--default .select2-selection--multiple { min-height: 44px; border: 0; border-radius: 8px; background-color: #eff0f6; padding: 6px 10px; }
.select2-container--default.select2-container--focus .select2-selection--multiple { border: 0; box-shadow: 0 0 0 2px rgba(47, 73, 209, 0.15); }
.select2-container--default .select2-selection--multiple .select2-selection__choice { position: relative; border: 0; border-radius: 999px; margin-top: 4px; background-color: #2f49d1; color: #ffffff; padding: 4px 10px 4px 24px; }
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove { position: absolute; left: 8px; top: 50%; border: 0; color: #ffffff; transform: translateY(-50%); }
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { background: transparent; color: #d7e0ff; }
.select2-container--default .select2-search--inline .select2-search__field { margin-top: 4px; color: #171717; }
.select2-dropdown { border: 1px solid #d7dbec; border-radius: 12px; overflow: hidden; }
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable { background-color: #2f49d1; }
@media (max-width: 991px) {
  .manuscripts-form-grid { grid-template-columns: 1fr; }
  .manuscripts-toolbar-search { width: 100%; justify-content: flex-start; }
  .manuscripts-search-field { max-width: none; }
  .manuscripts-search-summary { justify-content: flex-start; }
  .manuscripts-drawer-header { padding: 20px 20px 0; flex-wrap: wrap; }
  .manuscripts-drawer-body { padding: 20px; }
  .manuscripts-modal-header { padding: 20px 20px 0; flex-wrap: wrap; }
  .manuscripts-modal-body { padding: 20px; }
  .manuscripts-record-layout { grid-template-columns: 1fr; }
  .manuscripts-status-row { align-items: flex-start; }
  .manuscripts-record-actions { margin-left: 0; justify-content: flex-start; }
}
';

$extraHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
';

$extraScripts = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script>
  jQuery(function ($) {
    var shouldOpenBasicModal = __OPEN_BASIC__;
    var shouldOpenDetailsModal = __OPEN_DETAILS__;
    var requestedModal = '__REQUESTED_MODAL__';
    var programOptions = __PROGRAM_OPTIONS__;
    var manuscriptRecords = __MANUSCRIPT_RECORDS__;
    var basicDrawerDefaults = __BASIC_DRAWER_DEFAULTS__;
    var swalAlerts = __SWAL_ALERTS__;
    var initialBatchSize = __INITIAL_BATCH_SIZE__;
    var modalHash = window.location.hash || '';
    var basicDrawerElement = document.getElementById('manuscriptBasicDrawer');
    var detailsDrawerElement = document.getElementById('manuscriptDetailsDrawer');
    var cardListElement = document.querySelector('[data-manuscript-list]');
    var scrollSentinelElement = document.getElementById('manuscriptsScrollSentinel');
    var searchInputElement = document.querySelector('[data-manuscript-search-input]');
    var searchSummaryElement = document.querySelector('[data-manuscript-search-summary]');
    var searchEmptyElement = document.querySelector('[data-manuscript-no-results]');
    var $basicDrawer = $(basicDrawerElement);
    var $detailsDrawer = $(detailsDrawerElement);
    var $campusSelect = $basicDrawer.find('select[name="campusid"]');
    var $programSelect = $basicDrawer.find('select[name="programid"]');
    var $basicResearchTypeSelect = $basicDrawer.find('select[name="researchtypeid"]');
    var $basicAySelect = $basicDrawer.find('select[name="ayid"]');
    var $basicSdgSelect = $basicDrawer.find('select[name="sdg_code[]"]');
    var $detailsAdviserSelect = $detailsDrawer.find('select[name="adviser_accountid"]');
    var $detailsStatisticianSelect = $detailsDrawer.find('select[name="statistician_accountid"]');
    var $detailsEnglishCriticSelect = $detailsDrawer.find('select[name="english_critic_accountid"]');
    var $detailsPanelistSelect = $detailsDrawer.find('select[name="panelist_accountids[]"]');
    var initialProgramValue = $programSelect.length ? $.trim(String($programSelect.val() || '')) : '';
    var initialProgramLabel = initialProgramValue !== ''
      ? $.trim($programSelect.find('option:selected').text())
      : '';
    var currentProgramFallbackLabel = initialProgramLabel;
    var preserveInitialProgramSelection = true;
    var basicDrawerForm = basicDrawerElement ? basicDrawerElement.querySelector('form') : null;
    var detailsDrawerForm = detailsDrawerElement ? detailsDrawerElement.querySelector('form') : null;
    var basicDrawerTitleElement = document.getElementById('manuscriptBasicDrawerLabel');
    var basicDrawerSubmitButton = basicDrawerForm ? basicDrawerForm.querySelector('button[type="submit"]') : null;
    var detailsDrawerSubmitButton = detailsDrawerForm ? detailsDrawerForm.querySelector('button[type="submit"]') : null;
    var detailsSelectedRecordElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-selected-record]') : null;
    var detailsSelectedTitleElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-selected-title]') : null;
    var detailsSelectedMetaElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-selected-meta]') : null;
    var detailsEmptyCopyElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-empty-copy]') : null;
    var detailsAbstractBadgeElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-abstract-badge]') : null;
    var detailsAbstractLinkElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-abstract-link]') : null;
    var detailsAbstractEmptyElement = detailsDrawerElement ? detailsDrawerElement.querySelector('[data-details-abstract-empty]') : null;
    var manuscriptRecordMap = {};
    var basicDrawerInstance = null;
    var detailsDrawerInstance = null;

    if (Array.isArray(manuscriptRecords)) {
      manuscriptRecords.forEach(function (record) {
        var recordId = parseInt(record && record.id, 10);

        if (recordId > 0) {
          manuscriptRecordMap[recordId] = record;
        }
      });
    }

    if (typeof bootstrap !== 'undefined' && typeof bootstrap.Offcanvas === 'function') {
      if (basicDrawerElement) {
        basicDrawerInstance = bootstrap.Offcanvas.getOrCreateInstance(basicDrawerElement);
      }

      if (detailsDrawerElement) {
        detailsDrawerInstance = bootstrap.Offcanvas.getOrCreateInstance(detailsDrawerElement);
      }
    }

    function selectPlaceholder($select) {
      var explicitPlaceholder = $.trim(String($select.data('placeholder') || ''));

      if (explicitPlaceholder !== '') {
        return explicitPlaceholder;
      }

      var $blankOption = $select.find('option').filter(function () {
        return $(this).val() === '';
      }).first();

      return $.trim($blankOption.text());
    }

    function escapeHtml(value) {
      return $('<div>').text(String(value || '')).html();
    }

    function normalizeSearchTerm(value) {
      return $.trim(String(value || '')).toLowerCase();
    }

    function normalizedId(value) {
      var parsedValue = parseInt(value, 10);

      return !isNaN(parsedValue) && parsedValue > 0 ? parsedValue : 0;
    }

    function normalizedString(value) {
      return $.trim(String(value || ''));
    }

    function normalizedArray(values) {
      if (!Array.isArray(values)) {
        return [];
      }

      return values.map(function (value) {
        return normalizedString(value);
      }).filter(function (value) {
        return value !== '';
      });
    }

    function setSelectValue($select, value) {
      if ($select.length === 0) {
        return;
      }

      $select.val(value);

      if ($select.hasClass('select2-hidden-accessible')) {
        $select.trigger('change.select2');
      } else {
        $select.trigger('change');
      }
    }

    function setMultiSelectValue($select, values) {
      if ($select.length === 0) {
        return;
      }

      var normalizedValues = normalizedArray(values);
      $select.val(normalizedValues);

      if ($select.hasClass('select2-hidden-accessible')) {
        $select.trigger('change.select2');
      } else {
        $select.trigger('change');
      }
    }

    function basicDrawerState(record) {
      if (record && typeof record === 'object') {
        return record;
      }

      if (basicDrawerDefaults && typeof basicDrawerDefaults === 'object') {
        return basicDrawerDefaults;
      }

      return {};
    }

    function showSwalAlerts(index) {
      if (typeof Swal === 'undefined' || !Array.isArray(swalAlerts) || index >= swalAlerts.length) {
        return;
      }

      var alert = swalAlerts[index] || {};
      var options = {
        icon: alert.icon || 'info',
        title: alert.title || '',
        text: alert.text || '',
        confirmButtonColor: '#2f49d1'
      };

      if (alert.toast) {
        options.toast = true;
        options.position = 'top-end';
        options.showConfirmButton = false;
        options.timer = 3200;
        options.timerProgressBar = true;
      }

      Swal.fire(options).then(function () {
        showSwalAlerts(index + 1);
      });
    }

    function clearModalState(expectedHash) {
      if (window.history && typeof window.history.replaceState === 'function') {
        var nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete('modal');

        if (expectedHash && nextUrl.hash === expectedHash) {
          nextUrl.hash = '';
        }

        window.history.replaceState(null, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
      }
    }

    function enhanceSelect($select, $container, forceRefresh) {
      if ($select.length === 0 || $container.length === 0 || typeof $.fn.select2 !== 'function') {
        return;
      }

      if ($select.hasClass('select2-hidden-accessible') && !forceRefresh) {
        $select.trigger('change.select2');
        return;
      }

      if ($select.hasClass('select2-hidden-accessible')) {
        $select.select2('destroy');
      }

      $select.select2({
        width: '100%',
        placeholder: selectPlaceholder($select),
        closeOnSelect: !$select.prop('multiple'),
        dropdownParent: $container
      });
    }

    function initPanelSelects($container, forceRefresh) {
      if ($container.length === 0 || typeof $.fn.select2 !== 'function') {
        return;
      }

      $container.find('select.manuscripts-select').each(function () {
        enhanceSelect($(this), $container, !!forceRefresh);
      });
    }

    function syncProgramOptions(selectedValue, preserveCurrentInvalid, refreshSelect2) {
      if ($programSelect.length === 0) {
        return;
      }

      var campusId = $.trim(String($campusSelect.val() || ''));
      var normalizedSelectedValue = $.trim(String(selectedValue || ''));
      var availablePrograms = [];
      var placeholderText = 'Select campus first';
      var hasSelectedValue = false;
      var optionMarkup = [];

      if (campusId !== '') {
        availablePrograms = $.grep(programOptions, function (program) {
          var programCampusId = $.trim(String(program.campusid || ''));

          return programCampusId === '' || programCampusId === '0' || programCampusId === campusId;
        });

        placeholderText = availablePrograms.length > 0
          ? 'Select program'
          : 'No programs available for the selected campus';
      }

      optionMarkup.push('<option value="">' + escapeHtml(placeholderText) + '</option>');

      $.each(availablePrograms, function (_, program) {
        var programId = $.trim(String(program.id || ''));
        var isSelected = normalizedSelectedValue !== '' && programId === normalizedSelectedValue;

        if (isSelected) {
          hasSelectedValue = true;
        }

        optionMarkup.push(
          '<option value="' + escapeHtml(programId) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(program.label || '') + '</option>'
        );
      });

      if (!hasSelectedValue && preserveCurrentInvalid && normalizedSelectedValue !== '' && currentProgramFallbackLabel !== '') {
        optionMarkup.push(
          '<option value="' + escapeHtml(normalizedSelectedValue) + '" selected>' + escapeHtml(currentProgramFallbackLabel + ' (current selection)') + '</option>'
        );
        hasSelectedValue = true;
      }

      $programSelect.html(optionMarkup.join(''));
      $programSelect.attr('data-placeholder', placeholderText);
      $programSelect.prop('disabled', campusId === '' || availablePrograms.length === 0);
      $programSelect.val(hasSelectedValue ? normalizedSelectedValue : '');

      if (refreshSelect2) {
        enhanceSelect($programSelect, $basicDrawer, true);
      }
    }

    function populateBasicDrawer(record) {
      if (!basicDrawerForm) {
        return;
      }

      var state = basicDrawerState(record);
      var manuscriptId = normalizedId(state.manuscriptid || state.id);
      var researchTitle = String(state.manuscript_title || '');
      var researchTypeId = normalizedString(state.researchtypeid);
      var campusId = normalizedString(state.campusid);
      var ayId = normalizedString(state.ayid);
      var programId = normalizedString(state.programid);
      var abstractText = String(state.other_details || '');

      currentProgramFallbackLabel = normalizedString(state.program_label);
      basicDrawerForm.querySelector('input[name="manuscriptid"]').value = manuscriptId > 0 ? String(manuscriptId) : '';

      if (basicDrawerTitleElement) {
        basicDrawerTitleElement.textContent = manuscriptId > 0 ? 'Edit Research Record' : 'Add Research Record';
      }

      if (basicDrawerSubmitButton) {
        basicDrawerSubmitButton.textContent = manuscriptId > 0 ? 'Save Base Changes' : 'Save and Continue';
      }

      var titleInput = basicDrawerForm.querySelector('input[name="manuscript_title"]');
      var abstractInput = basicDrawerForm.querySelector('textarea[name="other_details"]');

      if (titleInput) {
        titleInput.value = researchTitle;
      }

      if (abstractInput) {
        abstractInput.value = abstractText;
      }

      setSelectValue($basicResearchTypeSelect, researchTypeId);
      setSelectValue($basicAySelect, ayId);
      setSelectValue($campusSelect, campusId);
      syncProgramOptions(programId, programId !== '', true);
      setMultiSelectValue($basicSdgSelect, state.sdg_codes || []);
    }

    function populateDetailsAbstractState(record) {
      if (!detailsAbstractBadgeElement || !detailsAbstractLinkElement || !detailsAbstractEmptyElement) {
        return;
      }

      var abstractFileUrl = normalizedString(record && record.abstract_file_url);
      var abstractOriginalName = normalizedString(record && record.abstract_original_name) || 'Abstract uploaded';

      if (abstractFileUrl !== '') {
        detailsAbstractBadgeElement.hidden = false;
        detailsAbstractBadgeElement.textContent = abstractOriginalName;
        detailsAbstractLinkElement.hidden = false;
        detailsAbstractLinkElement.href = abstractFileUrl;
        detailsAbstractEmptyElement.hidden = true;
        return;
      }

      detailsAbstractBadgeElement.hidden = true;
      detailsAbstractBadgeElement.textContent = '';
      detailsAbstractLinkElement.hidden = true;
      detailsAbstractLinkElement.removeAttribute('href');
      detailsAbstractEmptyElement.hidden = false;
    }

    function populateDetailsDrawer(record) {
      if (!detailsDrawerForm || !record || typeof record !== 'object') {
        return;
      }

      var manuscriptId = normalizedId(record.id || record.manuscriptid);
      var detailsFileInput = detailsDrawerForm.querySelector('input[name="abstract_file"]');
      var authorsInput = detailsDrawerForm.querySelector('textarea[name="authors"]');

      detailsDrawerForm.querySelector('input[name="manuscriptid"]').value = manuscriptId > 0 ? String(manuscriptId) : '';

      if (detailsSelectedRecordElement) {
        detailsSelectedRecordElement.hidden = manuscriptId < 1;
      }

      if (detailsEmptyCopyElement) {
        detailsEmptyCopyElement.hidden = manuscriptId > 0;
      }

      if (detailsSelectedTitleElement) {
        detailsSelectedTitleElement.textContent = normalizedString(record.manuscript_title) || 'Selected research';
      }

      if (detailsSelectedMetaElement) {
        detailsSelectedMetaElement.textContent = [
          normalizedString(record.research_type_label) || 'Unknown Type',
          normalizedString(record.sdg_label) || 'SDG not set',
          normalizedString(record.campus_label) || 'Campus not set'
        ].join(' | ');
      }

      populateDetailsAbstractState(record);
      setSelectValue($detailsAdviserSelect, normalizedString(record.adviser_accountid));
      setSelectValue($detailsStatisticianSelect, normalizedString(record.statistician_accountid));
      setSelectValue($detailsEnglishCriticSelect, normalizedString(record.english_critic_accountid));
      setMultiSelectValue($detailsPanelistSelect, record.panelist_accountids || []);

      if (authorsInput) {
        authorsInput.value = String(record.authors || '');
      }

      if (detailsFileInput) {
        detailsFileInput.value = '';
      }

      if (detailsDrawerSubmitButton) {
        detailsDrawerSubmitButton.disabled = manuscriptId < 1;
      }
    }

    function updateSearchSummary(matchCount, totalCount, activeSearchTerm) {
      if (!searchSummaryElement) {
        return;
      }

      if (totalCount < 1) {
        searchSummaryElement.textContent = 'No research records';
        return;
      }

      if (activeSearchTerm === '') {
        searchSummaryElement.textContent = totalCount === 1
          ? '1 research record'
          : totalCount + ' research records';
        return;
      }

      searchSummaryElement.textContent = matchCount === 1
        ? '1 matching record'
        : matchCount + ' matching records';
    }

    function initRecordList() {
      if (!cardListElement) {
        updateSearchSummary(0, 0, '');
        return;
      }

      var cards = Array.prototype.slice.call(cardListElement.querySelectorAll('[data-manuscript-card]'));
      var visibleCount = Math.min(initialBatchSize, cards.length);
      var activeSearchTerm = '';
      var observer = null;

      if (cards.length === 0) {
        if (scrollSentinelElement) {
          scrollSentinelElement.hidden = true;
        }

        if (searchInputElement) {
          searchInputElement.disabled = true;
        }

        if (searchEmptyElement) {
          searchEmptyElement.hidden = true;
        }

        updateSearchSummary(0, 0, '');
        return;
      }

      function matchingCards() {
        return cards.filter(function (card) {
          if (activeSearchTerm === '') {
            return true;
          }

          return normalizeSearchTerm(card.getAttribute('data-manuscript-search')).indexOf(activeSearchTerm) !== -1;
        });
      }

      function renderCards(resetVisibleCount) {
        var matches = matchingCards();

        if (resetVisibleCount) {
          visibleCount = Math.min(initialBatchSize, matches.length);
        }

        cards.forEach(function (card) {
          card.classList.remove('is-pending-load');
          card.classList.add('is-filtered-out');
          card.setAttribute('aria-hidden', 'true');
        });

        matches.forEach(function (card, index) {
          card.classList.remove('is-filtered-out');

          if (index < visibleCount) {
            card.classList.remove('is-pending-load');
            card.removeAttribute('aria-hidden');
            return;
          }

          card.classList.add('is-pending-load');
          card.setAttribute('aria-hidden', 'true');
        });

        if (searchEmptyElement) {
          searchEmptyElement.hidden = matches.length !== 0;
        }

        updateSearchSummary(matches.length, cards.length, activeSearchTerm);

        if (!scrollSentinelElement) {
          return;
        }

        if (matches.length > visibleCount) {
          scrollSentinelElement.hidden = false;
          scrollSentinelElement.textContent = activeSearchTerm === ''
            ? 'Loading more research records...'
            : 'Loading more matching research records...';

          if (observer) {
            observer.observe(scrollSentinelElement);
          }

          return;
        }

        scrollSentinelElement.hidden = true;

        if (observer) {
          observer.unobserve(scrollSentinelElement);
        }
      }

      function revealNextBatch() {
        var matches = matchingCards();
        var nextVisibleCount = Math.min(visibleCount + initialBatchSize, matches.length);

        if (nextVisibleCount === visibleCount) {
          return;
        }

        visibleCount = nextVisibleCount;
        renderCards(false);
      }

      if (scrollSentinelElement && typeof IntersectionObserver === 'function') {
        observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              revealNextBatch();
            }
          });
        }, {
          root: null,
          rootMargin: '0px 0px 320px 0px',
          threshold: 0
        });
      } else {
        visibleCount = cards.length;
      }

      if (searchInputElement) {
        searchInputElement.addEventListener('input', function () {
          activeSearchTerm = normalizeSearchTerm(searchInputElement.value);
          renderCards(true);
        });
      }

      renderCards(true);
    }

    function initDeleteConfirmation() {
      $(document).on('submit', '.manuscripts-delete-form', function (event) {
        var form = this;

        if (form.dataset.confirmed === 'true' || typeof Swal === 'undefined') {
          return true;
        }

        event.preventDefault();

        Swal.fire({
          icon: 'warning',
          title: 'Delete research record?',
          text: 'This will remove the base record, assignments, and uploaded abstract.',
          showCancelButton: true,
          confirmButtonColor: '#d14343',
          cancelButtonColor: '#8592a3',
          confirmButtonText: 'Yes, delete it',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }

          form.dataset.confirmed = 'true';
          form.submit();
        });

        return false;
      });
    }

    function openRequestedModal(target) {
      if (target === 'basic' && basicDrawerInstance) {
        basicDrawerInstance.show();
      } else if (target === 'details' && detailsDrawerInstance) {
        detailsDrawerInstance.show();
      }
    }

    $(document).on('click', '[data-manuscript-open-add]', function () {
      populateBasicDrawer(basicDrawerDefaults);
    });

    $(document).on('click', '[data-manuscript-edit-trigger]', function (event) {
      var recordId = normalizedId($(this).data('recordId'));
      var record = manuscriptRecordMap[recordId] || null;

      if (!record) {
        return;
      }

      event.preventDefault();
      populateBasicDrawer(record);
      openRequestedModal('basic');
    });

    $(document).on('click', '[data-manuscript-details-trigger]', function (event) {
      var recordId = normalizedId($(this).data('recordId'));
      var record = manuscriptRecordMap[recordId] || null;

      if (!record) {
        return;
      }

      event.preventDefault();
      populateDetailsDrawer(record);
      openRequestedModal('details');
    });

    if (basicDrawerElement) {
      syncProgramOptions(initialProgramValue, preserveInitialProgramSelection, false);
      initPanelSelects($basicDrawer, false);
      preserveInitialProgramSelection = false;

      $campusSelect.on('change', function () {
        syncProgramOptions('', false, true);
      });

      $basicDrawer.on('shown.bs.offcanvas', function () {
        clearModalState('#manuscript-basic-drawer');

        var titleInput = basicDrawerElement.querySelector('input[name="manuscript_title"]');

        if (titleInput) {
          titleInput.focus();
        }
      });

      $basicDrawer.on('hidden.bs.offcanvas', function () {
        clearModalState('#manuscript-basic-drawer');
      });
    }

    if (detailsDrawerElement) {
      initPanelSelects($detailsDrawer, false);

      $detailsDrawer.on('shown.bs.offcanvas', function () {
        clearModalState('#manuscript-details-drawer');

        var adviserInput = detailsDrawerElement.querySelector('select[name="adviser_accountid"]');

        if (adviserInput) {
          adviserInput.focus();
        }
      });

      $detailsDrawer.on('hidden.bs.offcanvas', function () {
        clearModalState('#manuscript-details-drawer');
      });
    }

    initDeleteConfirmation();
    initRecordList();
    showSwalAlerts(0);

    if (shouldOpenBasicModal) {
      openRequestedModal('basic');
    } else if (shouldOpenDetailsModal) {
      openRequestedModal('details');
    } else if (requestedModal === 'basic') {
      openRequestedModal('basic');
    } else if (requestedModal === 'details') {
      openRequestedModal('details');
    } else if (modalHash === '#manuscript-basic-drawer') {
      openRequestedModal('basic');
    } else if (modalHash === '#manuscript-details-drawer' || modalHash === '#manuscript-details-modal') {
      openRequestedModal('details');
    }

    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#manuscript-basic-drawer') {
        openRequestedModal('basic');
      } else if (window.location.hash === '#manuscript-details-drawer' || window.location.hash === '#manuscript-details-modal') {
        openRequestedModal('details');
      }
    });
  });
</script>
HTML;
$extraScripts = str_replace(
    ['__OPEN_BASIC__', '__OPEN_DETAILS__', '__REQUESTED_MODAL__', '__PROGRAM_OPTIONS__', '__MANUSCRIPT_RECORDS__', '__BASIC_DRAWER_DEFAULTS__', '__SWAL_ALERTS__', '__INITIAL_BATCH_SIZE__'],
    [$shouldOpenBasicModal ? 'true' : 'false', $shouldOpenDetailsModal ? 'true' : 'false', addslashes($requestedModal), $programOptionsJson, $manuscriptRecordsJson, $basicDrawerDefaultsJson, $swalAlertsJson, (string) $initialManuscriptBatchSize],
    $extraScripts
);

ob_start();
?>
<main class="main users chart-page" id="skip-target">
  <div class="container">
    <?php if ($pageError !== null): ?>
      <article class="white-block manuscripts-panel">
        <h3 class="white-block__title">Unable to load research management</h3>
        <p class="manuscripts-muted"><?= e($pageError); ?></p>
      </article>
    <?php endif; ?>

    <div class="row">
      <div class="col-12">
        <div class="manuscripts-toolbar">
          <div class="manuscripts-toolbar-actions">
            <button
              class="primary-default-btn"
              type="button"
              data-manuscript-open-add
              data-bs-toggle="offcanvas"
              data-bs-target="#manuscriptBasicDrawer"
            >
              Add Research Record
            </button>
          </div>

          <div class="manuscripts-toolbar-search">
            <label class="manuscripts-search-field" for="manuscriptRecordSearch">
              <i class="bx bx-search" aria-hidden="true"></i>
              <input
                class="form-input manuscripts-search-input"
                type="search"
                id="manuscriptRecordSearch"
                data-manuscript-search-input
                placeholder="Search title, author, campus, program, or SDG"
                autocomplete="off"
              >
            </label>
            <span class="manuscripts-search-summary" data-manuscript-search-summary>
              <?= e(count($manuscripts) === 1 ? '1 research record' : count($manuscripts) . ' research records'); ?>
            </span>
          </div>
        </div>

        <div class="manuscripts-card-list" data-manuscript-list>
          <?php if ($manuscripts === []): ?>
            <article class="manuscripts-empty">No research records have been saved yet.</article>
          <?php endif; ?>

          <?php foreach ($manuscripts as $recordIndex => $record): ?>
            <?php
            $recordId = (int) $record['manuscriptid'];
            $editUrl = administrator_manuscript_url(['edit' => $recordId, 'modal' => 'basic']);
            $manageUrl = administrator_manuscript_url(['edit' => $recordId, 'details' => $recordId, 'modal' => 'details']);
            $adviserSummary = administrator_manuscript_assignment_label(
                'Adviser',
                administrator_manuscript_resolved_account_name($record, 'adviser_accountid', $accountNameMap, 'adviser_name'),
                'Pending',
                72
            );
            $panelistSummary = administrator_manuscript_assignment_label(
                'Panelists',
                administrator_manuscript_resolved_account_names($record, 'panelist_accountids', $accountNameMap, 'panelists'),
                'Pending',
                96
            );
            $statisticianSummary = administrator_manuscript_assignment_label(
                'Statistician',
                administrator_manuscript_resolved_account_name($record, 'statistician_accountid', $accountNameMap, 'statisticians'),
                'Pending',
                72
            );
            $englishCriticSummary = administrator_manuscript_assignment_label(
                'English Critic',
                administrator_manuscript_resolved_account_name($record, 'english_critic_accountid', $accountNameMap, 'english_critic_name'),
                'Pending',
                72
            );
            $authorsLabel = trim((string) ($record['authors'] ?? ''));
            $abstractSummary = administrator_manuscript_assignment_label(
                'Abstract',
                administrator_manuscript_has_value((string) ($record['abstract_file_path'] ?? ''))
                    ? ((string) ($record['abstract_original_name'] ?? '') !== '' ? (string) $record['abstract_original_name'] : 'Uploaded')
                    : '',
                'Missing',
                60
            );
            $campusLabel = trim((string) ($record['campus_name'] ?? ''));
            if ($campusLabel === '') {
                $campusId = administrator_manuscript_positive_int($record['campusid'] ?? 0);
                $campusLabel = $campusId > 0 ? 'Campus #' . $campusId : 'Campus not set';
            }
            $statusLabel = administrator_manuscript_status_value($record['status'] ?? 'Pending');
            $ayId = administrator_manuscript_positive_int($record['ayid'] ?? 0);
            $ayLabel = administrator_manuscript_academic_year_label(
                $record['ay_from'] ?? '',
                $record['ay_to'] ?? '',
                $ayId
            );
            $programId = administrator_manuscript_positive_int($record['programid'] ?? 0);
            $sdgLabel = administrator_manuscript_sdg_label((string) ($record['sdg_code'] ?? ''));
            $programLabel = administrator_manuscript_program_label([
                'programid' => $programId,
                'coursecode' => $record['coursecode'] ?? '',
                'coursedescription' => $record['coursedescription'] ?? '',
                'coursemajor' => $record['coursemajor'] ?? '',
            ]);
            $searchBlob = trim((string) preg_replace(
                '/\s+/',
                ' ',
                implode(
                    ' ',
                    array_filter(
                        [
                            (string) $recordId,
                            (string) ($record['manuscript_title'] ?? ''),
                            $authorsLabel,
                            (string) ($record['research_type'] ?? ''),
                            $sdgLabel,
                            $campusLabel,
                            $statusLabel,
                            $ayLabel,
                            $programLabel,
                            $adviserSummary,
                            $panelistSummary,
                            $statisticianSummary,
                            $englishCriticSummary,
                            $abstractSummary,
                        ],
                        static function ($value): bool {
                            return trim((string) $value) !== '';
                        }
                    )
                )
            ));
            ?>
            <article class="manuscripts-record-card<?= $recordIndex >= $initialManuscriptBatchSize ? ' is-pending-load' : ''; ?>" data-manuscript-card data-manuscript-search="<?= e($searchBlob); ?>"<?= $recordIndex >= $initialManuscriptBatchSize ? ' aria-hidden="true"' : ''; ?>>
              <div class="manuscripts-record-layout">
                <div class="manuscripts-record-main">
                  <div class="manuscripts-record-meta">
                    <span class="manuscripts-record-id"><?= e((string) $recordId); ?></span>
                  </div>

                  <div class="manuscripts-record-body">
                    <div class="manuscripts-table-title"><?= e((string) ($record['manuscript_title'] ?? 'Untitled Research')); ?></div>
                    <?php if ($authorsLabel !== ''): ?>
                      <div class="manuscripts-record-authors"><?= e($authorsLabel); ?></div>
                    <?php endif; ?>
                    <div class="manuscripts-table-subtitle manuscripts-record-summary">
                      <span><?= e((string) ($record['research_type'] ?? 'Unknown Type')); ?></span>
                      <span class="manuscripts-meta-divider">|</span>
                      <span><?= e($sdgLabel); ?></span>
                      <span class="manuscripts-meta-divider">|</span>
                      <span>Created <?= e(administrator_manuscript_datetime_label((string) ($record['created_at'] ?? ''))); ?></span>
                    </div>

                    <div class="manuscripts-record-facts">
                      <span class="manuscripts-record-fact"><?= e('Campus: ' . $campusLabel); ?></span>
                      <span class="manuscripts-record-fact"><?= e('Status: ' . $statusLabel); ?></span>
                      <?php if ($ayId > 0): ?>
                        <span class="manuscripts-record-fact"><?= e('Academic Year: ' . $ayLabel); ?></span>
                      <?php endif; ?>
                      <?php if ($programId > 0): ?>
                        <span class="manuscripts-record-fact"><?= e('Program: ' . $programLabel); ?></span>
                      <?php endif; ?>
                    </div>

                    <div class="manuscripts-status-row">
                      <div class="manuscripts-status-stack">
                        <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'adviser_accountid') ? 'complete' : 'pending'); ?>"><?= e($adviserSummary); ?></span>
                        <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'panelist_accountids', true) ? 'complete' : 'pending'); ?>"><?= e($panelistSummary); ?></span>
                        <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'statistician_accountid') ? 'complete' : 'pending'); ?>"><?= e($statisticianSummary); ?></span>
                        <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'english_critic_accountid') ? 'complete' : 'pending'); ?>"><?= e($englishCriticSummary); ?></span>
                        <span class="manuscripts-detail-pill <?= e(administrator_manuscript_has_value((string) ($record['abstract_file_path'] ?? '')) ? 'complete' : 'pending'); ?>"><?= e($abstractSummary); ?></span>
                      </div>

                      <div class="manuscripts-record-actions">
                        <a class="manuscripts-action-pill primary" href="<?= e($editUrl); ?>" data-manuscript-edit-trigger data-record-id="<?= e((string) $recordId); ?>">Edit Basic</a>
                        <a class="manuscripts-action-pill success" href="<?= e($manageUrl); ?>" data-manuscript-details-trigger data-record-id="<?= e((string) $recordId); ?>">Manage Details</a>
                        <form class="manuscripts-action-form manuscripts-delete-form" method="post" action="<?= e(administrator_manuscript_url_with_fragment([], 'skip-target')); ?>">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="delete_manuscript">
                          <input type="hidden" name="manuscriptid" value="<?= e((string) $recordId); ?>">
                          <button class="manuscripts-action-pill danger" type="submit">Delete</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <article class="manuscripts-empty" data-manuscript-no-results hidden>No research records match your search.</article>
        <?php if ($manuscripts !== []): ?>
          <div class="manuscripts-scroll-sentinel" id="manuscriptsScrollSentinel" hidden>Loading more research records...</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-end manuscripts-basic-drawer" tabindex="-1" id="manuscriptBasicDrawer" aria-labelledby="manuscriptBasicDrawerLabel">
    <div class="offcanvas-header manuscripts-drawer-header">
      <div class="manuscripts-modal-header-main">
        <h3 class="manuscripts-modal-title" id="manuscriptBasicDrawerLabel"><?= e($basicModalTitle); ?></h3>
        <p class="manuscripts-modal-copy">Start with the research title, then continue with type, campus, program, SDG, academic year, and abstract. New saves stay in this drawer for continuous encoding.</p>
      </div>

      <div class="manuscripts-modal-header-actions">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
    </div>

    <div class="offcanvas-body manuscripts-drawer-body">
      <form method="post" action="<?= e(administrator_manuscript_url_with_fragment([], 'skip-target')); ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_manuscript">
        <input type="hidden" name="manuscriptid" value="<?= e((string) $selectedManuscriptId); ?>">

        <div class="manuscripts-form-grid">
          <label class="form-label-wrapper full-width">
            <span class="form-label">Research Title</span>
            <input class="form-input" type="text" name="manuscript_title" maxlength="1000" value="<?= e((string) ($selectedManuscript['manuscript_title'] ?? '')); ?>" placeholder="Enter research title" required>
          </label>

          <label class="form-label-wrapper">
            <span class="form-label">Research Type</span>
            <select class="manuscripts-select" name="researchtypeid" required>
              <option value="">Select research type</option>
              <?php foreach ($researchTypes as $researchType): ?>
                <?php
                $researchTypeId = (int) ($researchType['researchtypeid'] ?? 0);
                $researchTypeLabel = (string) ($researchType['research_type'] ?? 'Unknown Type');
                if ((int) ($researchType['is_active'] ?? 1) !== 1) {
                    $researchTypeLabel .= ' (Inactive)';
                }
                ?>
                <option value="<?= e((string) $researchTypeId); ?>"<?= $researchTypeId === (int) ($selectedManuscript['researchtypeid'] ?? 0) ? ' selected' : ''; ?>>
                  <?= e($researchTypeLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-label-wrapper">
            <span class="form-label">Academic Year</span>
            <select class="manuscripts-select" name="ayid">
              <option value="">Select academic year</option>
              <?php foreach ($academicYears as $academicYear): ?>
                <?php
                $academicYearId = (int) ($academicYear['ayid'] ?? 0);
                $academicYearLabel = administrator_manuscript_academic_year_label(
                    $academicYear['ay_from'] ?? '',
                    $academicYear['ay_to'] ?? '',
                    $academicYearId
                );
                ?>
                <option value="<?= e((string) $academicYearId); ?>"<?= $academicYearId === $selectedAyId ? ' selected' : ''; ?>>
                  <?= e($academicYearLabel); ?>
                </option>
              <?php endforeach; ?>
              <?php if ($selectedAyId > 0 && !isset($academicYearOptionMap[$selectedAyId])): ?>
                <option value="<?= e((string) $selectedAyId); ?>" selected><?= e('AY #' . $selectedAyId); ?></option>
              <?php endif; ?>
            </select>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Campus</span>
            <select class="manuscripts-select" name="campusid" required>
              <option value="">Select campus</option>
              <?php foreach ($campuses as $campus): ?>
                <?php
                $campusId = (int) ($campus['campusid'] ?? 0);
                $campusLabel = trim((string) ($campus['campusname'] ?? 'Campus #' . $campusId));
                ?>
                <option value="<?= e((string) $campusId); ?>"<?= $campusId === $selectedCampusId ? ' selected' : ''; ?>>
                  <?= e($campusLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Program</span>
            <select class="manuscripts-select" name="programid" data-placeholder="<?= e($selectedCampusId > 0 ? 'Select program' : 'Select campus first'); ?>"<?= $selectedCampusId > 0 ? '' : ' disabled'; ?>>
              <option value=""><?= e($selectedCampusId > 0 ? 'Select program' : 'Select campus first'); ?></option>
              <?php foreach ($programs as $program): ?>
                <?php
                $programId = (int) ($program['programid'] ?? 0);
                $programLabel = administrator_manuscript_program_label($program);
                $programCampusId = $programCampusMap[$programId] ?? 0;
                ?>
                <option value="<?= e((string) $programId); ?>" data-campusid="<?= e((string) $programCampusId); ?>"<?= $programId === $selectedProgramId ? ' selected' : ''; ?>>
                  <?= e($programLabel); ?>
                </option>
              <?php endforeach; ?>
              <?php if ($selectedProgramId > 0 && !isset($programOptionMap[$selectedProgramId])): ?>
                <option value="<?= e((string) $selectedProgramId); ?>" selected><?= e('Program #' . $selectedProgramId); ?></option>
              <?php endif; ?>
            </select>
            <span class="manuscripts-field-note">Choose the campus first so only matching programs appear.</span>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">SDGs</span>
            <select class="manuscripts-select" id="manuscript-sdg-select" name="sdg_code[]" multiple required data-placeholder="Select one or more SDGs">
              <?php foreach ($sdgOptions as $sdgCode => $sdgName): ?>
                <option value="<?= e((string) $sdgCode); ?>"<?= in_array($sdgCode, $selectedSdgCodes, true) ? ' selected' : ''; ?>>
                  <?= e('SDG ' . $sdgCode . ' - ' . $sdgName); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="manuscripts-field-note">You can choose multiple SDGs for one research record.</span>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Abstract</span>
            <textarea class="manuscripts-textarea" name="other_details" maxlength="5000" placeholder="Enter the research abstract for this record."><?= e((string) ($selectedManuscript['other_details'] ?? '')); ?></textarea>
          </label>
        </div>

        <div class="manuscripts-panel-actions manuscripts-drawer-actions">
          <button class="primary-default-btn" type="submit"><?= e($isEditingBasic ? 'Save Base Changes' : 'Save and Continue'); ?></button>
          <button class="secondary-default-btn" type="button" data-bs-dismiss="offcanvas">Close</button>
        </div>
      </form>
    </div>
  </div>

  <div class="offcanvas offcanvas-end manuscripts-details-drawer" tabindex="-1" id="manuscriptDetailsDrawer" aria-labelledby="manuscriptDetailsDrawerLabel">
    <div class="offcanvas-header manuscripts-drawer-header">
      <div class="manuscripts-modal-header-main">
        <h3 class="manuscripts-modal-title" id="manuscriptDetailsDrawerLabel"><?= e($detailsModalTitle); ?></h3>
        <p class="manuscripts-modal-copy">Assign the adviser, panelists, statistician, English critic, authors, and uploaded abstract from this second drawer.</p>
      </div>

      <div class="manuscripts-modal-header-actions">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
    </div>

    <div class="offcanvas-body manuscripts-drawer-body">
      <?php $currentAbstractUrl = administrator_manuscript_abstract_url((string) ($detailsManuscript['abstract_file_path'] ?? '')); ?>
      <p class="manuscripts-muted" data-details-empty-copy<?= $isManagingDetails ? ' hidden' : ''; ?>>Select <strong>Manage Details</strong> from a research row below to continue the second step.</p>

      <div class="manuscripts-selected-record" data-details-selected-record<?= $isManagingDetails ? '' : ' hidden'; ?>>
        <p class="manuscripts-selected-title" data-details-selected-title><?= e((string) ($detailsManuscript['manuscript_title'] ?? 'Selected research')); ?></p>
        <div class="manuscripts-selected-meta" data-details-selected-meta>
          <?= e((string) ($detailsManuscript['research_type'] ?? 'Unknown Type')); ?> | <?= e(administrator_manuscript_sdg_label((string) ($detailsManuscript['sdg_code'] ?? ''))); ?> | <?= e(trim((string) ($detailsManuscript['campus_name'] ?? '')) !== '' ? (string) $detailsManuscript['campus_name'] : 'Campus not set'); ?>
        </div>
      </div>

      <form method="post" action="<?= e(administrator_manuscript_url_with_fragment([], 'skip-target')); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_manuscript_details">
        <input type="hidden" name="manuscriptid" value="<?= e((string) $selectedDetailsManuscriptId); ?>">

        <div class="manuscripts-form-grid">
          <label class="form-label-wrapper">
            <span class="form-label">Select Adviser</span>
            <select class="manuscripts-select" name="adviser_accountid">
              <option value="">Select Adviser</option>
              <?php foreach ($accountOptionMap as $accountId => $accountLabel): ?>
                <option value="<?= e((string) $accountId); ?>"<?= $accountId === $selectedAdviserAccountId ? ' selected' : ''; ?>>
                  <?= e($accountLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="form-label-wrapper">
            <span class="form-label">Current Abstract File</span>
            <div class="manuscripts-file-meta">
              <span class="badge-active" data-details-abstract-badge<?= $currentAbstractUrl !== '' ? '' : ' hidden'; ?>><?= e((string) ($detailsManuscript['abstract_original_name'] ?? 'Abstract uploaded')); ?></span>
              <a class="manuscripts-inline-btn neutral" data-details-abstract-link<?= $currentAbstractUrl !== '' ? ' href="' . e($currentAbstractUrl) . '"' : ''; ?> target="_blank" rel="noopener"<?= $currentAbstractUrl !== '' ? '' : ' hidden'; ?>>View Abstract</a>
              <span class="manuscripts-field-note" data-details-abstract-empty<?= $currentAbstractUrl === '' ? '' : ' hidden'; ?>>No abstract file uploaded yet.</span>
            </div>
          </div>

          <label class="form-label-wrapper">
            <span class="form-label">Select Statistician</span>
            <select class="manuscripts-select" name="statistician_accountid">
              <option value="">Select Statistician</option>
              <?php foreach ($accountOptionMap as $accountId => $accountLabel): ?>
                <option value="<?= e((string) $accountId); ?>"<?= $accountId === $selectedStatisticianAccountId ? ' selected' : ''; ?>>
                  <?= e($accountLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-label-wrapper">
            <span class="form-label">Select English Critic</span>
            <select class="manuscripts-select" name="english_critic_accountid">
              <option value="">Select English Critic</option>
              <?php foreach ($accountOptionMap as $accountId => $accountLabel): ?>
                <option value="<?= e((string) $accountId); ?>"<?= $accountId === $selectedEnglishCriticAccountId ? ' selected' : ''; ?>>
                  <?= e($accountLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Select Panelists</span>
            <select
              class="manuscripts-select"
              id="manuscript-panelists-select"
              name="panelist_accountids[]"
              multiple
              data-placeholder="Select Panelists"
            >
              <?php foreach ($accountOptionMap as $accountId => $accountLabel): ?>
                <option value="<?= e((string) $accountId); ?>"<?= in_array($accountId, $selectedPanelistAccountIds, true) ? ' selected' : ''; ?>>
                  <?= e($accountLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="manuscripts-field-note">Select one or more panelists.</span>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Authors</span>
            <textarea class="manuscripts-textarea" name="authors" maxlength="3000" placeholder="Add authors. Separate names with new lines or commas."><?= e((string) ($detailsManuscript['authors'] ?? '')); ?></textarea>
            <span class="manuscripts-field-note">Authors are free input. Separate names with new lines or commas.</span>
          </label>

          <label class="form-label-wrapper full-width">
            <span class="form-label">Upload Research Abstract</span>
            <input class="manuscripts-file-input" type="file" name="abstract_file" accept=".pdf,.doc,.docx,.txt">
            <span class="manuscripts-field-note">Accepted formats: PDF, DOC, DOCX, or TXT. Maximum size: 5 MB.</span>
          </label>
        </div>

        <div class="manuscripts-detail-actions manuscripts-drawer-actions">
          <button class="primary-default-btn" type="submit"<?= $isManagingDetails ? '' : ' disabled'; ?>>Save Research Details</button>
          <button class="secondary-default-btn" type="button" data-bs-dismiss="offcanvas">Close</button>
        </div>
      </form>
    </div>
  </div>
</main>
<?php
$mainContent = (string) ob_get_clean();

AdminPage::render([
    'title' => 'Administrator | Research Management',
    'current_page' => 'manuscripts',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_scripts' => $extraScripts,
    'extra_styles' => $extraStyles,
]);
