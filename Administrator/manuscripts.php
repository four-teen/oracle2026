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
        return 'No additional notes provided yet.';
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
$manuscripts = [];
$pageError = null;
$formError = null;
$detailsFormError = null;
$selectedManuscript = null;
$detailsManuscript = null;
$actionSuccess = get_flash('manuscript_success');
$actionError = get_flash('manuscript_error');
$totalManuscripts = 0;
$withAbstracts = 0;
$withAssignments = 0;
$readyForReview = 0;

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
            $status = administrator_manuscript_status_value($_POST['status'] ?? 'Pending');
            $encoderAccountId = administrator_manuscript_positive_int($_POST['encoder'] ?? 0);
            $programId = administrator_manuscript_positive_int($_POST['programid'] ?? 0);
            $postedSdgCodes = $_POST['sdg_code'] ?? [];
            $manuscriptTitle = trim((string) ($_POST['manuscript_title'] ?? ''));
            $otherDetails = trim((string) ($_POST['other_details'] ?? ''));

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
                throw new RuntimeException('Suggestions / other details must be 5000 characters or fewer.');
            }

            if (administrator_manuscript_text_length($status) > 30) {
                throw new RuntimeException('Status must be 30 characters or fewer.');
            }

            if ($manuscriptId > 0) {
                $currentManuscript = administrator_manuscript_fetch($pdo, $manuscriptId);

                if (!is_array($currentManuscript)) {
                    throw new RuntimeException('The selected research record could not be found.');
                }
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

            if ($encoderAccountId > 0 && Database::findAccountById($encoderAccountId) === null) {
                throw new RuntimeException('The selected encoder account does not exist.');
            }

            $ayIdValue = $ayId > 0 ? $ayId : null;
            $encoderAccountIdValue = $encoderAccountId > 0 ? $encoderAccountId : null;
            $programIdValue = $programId > 0 ? $programId : null;

            if ($manuscriptId > 0) {
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
                $successMessage = 'Research record saved. Continue with the research details next.';
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

            if ($manuscriptId > 0) {
                $saveParameters['manuscriptid'] = $manuscriptId;
            }

            $saveStatement->execute($saveParameters);

            if ($manuscriptId < 1) {
                $manuscriptId = (int) $pdo->lastInsertId();
            }

            set_flash('manuscript_success', $successMessage);
            redirect(administrator_manuscript_url_with_fragment([
                'edit' => $manuscriptId,
                'details' => $manuscriptId,
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

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($postedAction === 'save_manuscript') {
            $formError = $exception->getMessage();
            $selectedManuscript = [
                'manuscriptid' => isset($_POST['manuscriptid']) ? (int) $_POST['manuscriptid'] : 0,
                'researchtypeid' => isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0,
                'campusid' => administrator_manuscript_positive_int($_POST['campusid'] ?? 0),
                'ayid' => administrator_manuscript_positive_int($_POST['ayid'] ?? 0),
                'status' => administrator_manuscript_status_value($_POST['status'] ?? 'Pending'),
                'encoder' => administrator_manuscript_positive_int($_POST['encoder'] ?? 0),
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
        'SELECT courseid AS programid, coursecode, coursedescription, coursemajor, coursecollege, specification
         FROM tblcourse
         ORDER BY coursecode ASC, coursedescription ASC, programid ASC'
    )->fetchAll();

    foreach ($programs as $program) {
        $programId = isset($program['programid']) ? (int) $program['programid'] : 0;

        if ($programId > 0) {
            $programOptionMap[$programId] = administrator_manuscript_program_label($program);
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
$selectedEncoderAccountId = administrator_manuscript_positive_int($selectedManuscript['encoder'] ?? 0);
$selectedStatus = administrator_manuscript_status_value($selectedManuscript['status'] ?? 'Pending');
$selectedAdviserAccountId = administrator_manuscript_single_account_id($detailsManuscript['adviser_accountid'] ?? 0);
$selectedPanelistAccountIds = administrator_manuscript_account_ids($detailsManuscript['panelist_accountids'] ?? []);
$selectedStatisticianAccountId = administrator_manuscript_single_account_id($detailsManuscript['statistician_accountid'] ?? 0);
$selectedEnglishCriticAccountId = administrator_manuscript_single_account_id($detailsManuscript['english_critic_accountid'] ?? 0);
$basicModalTitle = $isEditingBasic ? 'Edit Research Record' : 'Add Research Record';
$detailsModalTitle = 'Manage Research Details';
$shouldOpenBasicModal = $formError !== null;
$shouldOpenDetailsModal = $detailsFormError !== null;

$summaryItems = [
    ['value' => number_format($totalManuscripts), 'title' => 'Total Research Records', 'meta' => 'Saved base records from tblresearches', 'icon' => 'file-text', 'color' => 'primary'],
    ['value' => number_format($withAssignments), 'title' => 'With Assignments', 'meta' => 'Committee selections already assigned', 'icon' => 'users', 'color' => 'success'],
    ['value' => number_format($withAbstracts), 'title' => 'With Abstract', 'meta' => 'Abstract file already uploaded', 'icon' => 'upload', 'color' => 'warning'],
    ['value' => number_format($pendingFollowUp), 'title' => 'Needs Follow-Up', 'meta' => 'Still missing full detailed research data', 'icon' => 'clock', 'color' => 'purple'],
];

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
.manuscripts-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.manuscripts-form-grid .full-width { grid-column: 1 / -1; }
.manuscripts-modal-body .form-label { display: inline-block; margin-bottom: 8px; color: #566a7f; font-size: 1rem; font-weight: 700; letter-spacing: 0; text-transform: none; }
.manuscripts-select, .manuscripts-textarea, .manuscripts-file-input { width: 100%; border: 0; border-radius: 8px; background-color: #eff0f6; color: #171717; }
.manuscripts-select { height: 44px; padding: 0 14px; }
.manuscripts-select[multiple] { min-height: 44px; height: auto; padding: 10px 14px; }
.manuscripts-textarea { min-height: 120px; padding: 14px 16px; resize: vertical; }
.manuscripts-file-input { min-height: 44px; padding: 10px 14px; }
.manuscripts-panel-actions, .manuscripts-detail-actions, .manuscripts-actions, .manuscripts-file-meta, .manuscripts-status-stack { display: flex; gap: 10px; flex-wrap: wrap; }
.manuscripts-panel-actions, .manuscripts-detail-actions { margin-top: 18px; }
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
.manuscripts-record-main { display: flex; align-items: flex-start; gap: 14px; min-width: 0; }
.manuscripts-record-id { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; border-radius: 999px; font-size: 14px; font-weight: 700; color: #2f49d1; background-color: rgba(47, 73, 209, 0.1); }
.manuscripts-record-meta { flex: 0 0 auto; }
.manuscripts-record-body { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.manuscripts-table-title { font-weight: 700; color: #171717; text-transform: uppercase; line-height: 1.45; white-space: normal; }
.manuscripts-record-authors { color: #5e667a; font-size: 14px; font-weight: 600; line-height: 1.55; white-space: normal; }
.manuscripts-table-subtitle { color: #767676; font-size: 12px; }
.manuscripts-record-summary { display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; white-space: normal; }
.manuscripts-record-facts { display: flex; gap: 8px; flex-wrap: wrap; }
.manuscripts-record-fact { display: inline-flex; align-items: center; min-height: 28px; padding: 5px 10px; border-radius: 999px; background-color: rgba(47, 73, 209, 0.08); color: #556079; font-size: 12px; font-weight: 600; }
.manuscripts-meta-divider { color: #b6bdd1; }
.manuscripts-card-actions { min-width: 170px; justify-content: flex-start; align-items: stretch; margin-left: auto; }
.manuscripts-card-actions .manuscripts-inline-btn { width: 100%; }
.manuscripts-empty { padding: 32px 24px; text-align: center; color: #767676; background: #fff; border-radius: 18px; box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12); }
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
  .manuscripts-modal-header { padding: 20px 20px 0; flex-wrap: wrap; }
  .manuscripts-modal-body { padding: 20px; }
  .manuscripts-record-layout { grid-template-columns: 1fr; }
  .manuscripts-card-actions { min-width: 0; margin-left: 0; align-items: flex-start; }
  .manuscripts-card-actions .manuscripts-inline-btn { width: auto; }
}
';

$extraHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" />
';

$extraScripts = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script>
  jQuery(function ($) {
    var shouldOpenBasicModal = __OPEN_BASIC__;
    var shouldOpenDetailsModal = __OPEN_DETAILS__;
    var requestedModal = '__REQUESTED_MODAL__';
    var modalHash = window.location.hash || '';
    var basicModalElement = document.getElementById('manuscriptBasicModal');
    var detailsModalElement = document.getElementById('manuscriptDetailsModal');
    var $basicModal = $(basicModalElement);
    var $detailsModal = $(detailsModalElement);

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

    function initModalSelects($modal) {
      if ($modal.length === 0 || typeof $.fn.select2 !== 'function') {
        return;
      }

      $modal.find('select.manuscripts-select').each(function () {
        var $select = $(this);

        if ($select.hasClass('select2-hidden-accessible')) {
          return;
        }

        $select.select2({
          width: '100%',
          placeholder: selectPlaceholder($select),
          closeOnSelect: !$select.prop('multiple'),
          dropdownParent: $modal
        });
      });
    }

    function openRequestedModal(target) {
      if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal !== 'function') {
        return;
      }

      if (target === 'basic' && basicModalElement) {
        bootstrap.Modal.getOrCreateInstance(basicModalElement).show();
      } else if (target === 'details' && detailsModalElement) {
        bootstrap.Modal.getOrCreateInstance(detailsModalElement).show();
      }
    }

    if (basicModalElement) {
      $basicModal.on('shown.bs.modal', function () {
        initModalSelects($basicModal);
        clearModalState('#manuscript-basic-modal');

        var titleInput = basicModalElement.querySelector('input[name="manuscript_title"]');

        if (titleInput) {
          titleInput.focus();
        }
      });

      $basicModal.on('hidden.bs.modal', function () {
        clearModalState('#manuscript-basic-modal');
      });
    }

    if (detailsModalElement) {
      $(detailsModalElement).on('shown.bs.modal', function () {
        initModalSelects($detailsModal);
        clearModalState('#manuscript-details-modal');

        var adviserInput = detailsModalElement.querySelector('select[name="adviser_accountid"]');

        if (adviserInput) {
          adviserInput.focus();
        }
      });

      $(detailsModalElement).on('hidden.bs.modal', function () {
        clearModalState('#manuscript-details-modal');
      });
    }

    if (shouldOpenBasicModal) {
      openRequestedModal('basic');
    } else if (shouldOpenDetailsModal) {
      openRequestedModal('details');
    } else if (requestedModal === 'basic') {
      openRequestedModal('basic');
    } else if (requestedModal === 'details') {
      openRequestedModal('details');
    } else if (modalHash === '#manuscript-basic-modal') {
      openRequestedModal('basic');
    } else if (modalHash === '#manuscript-details-modal') {
      openRequestedModal('details');
    }

    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#manuscript-basic-modal') {
        openRequestedModal('basic');
      } else if (window.location.hash === '#manuscript-details-modal') {
        openRequestedModal('details');
      }
    });
  });
</script>
HTML;
$extraScripts = str_replace(
    ['__OPEN_BASIC__', '__OPEN_DETAILS__', '__REQUESTED_MODAL__'],
    [$shouldOpenBasicModal ? 'true' : 'false', $shouldOpenDetailsModal ? 'true' : 'false', addslashes($requestedModal)],
    $extraScripts
);

ob_start();
?>
<main class="main users chart-page" id="skip-target">
  <div class="container">
    <div class="main-title-wrapper">
      <h2 class="main-title">Research Management</h2>
    </div>

    <?php if ($actionSuccess !== null): ?>
      <div class="manuscripts-alert success"><?= e($actionSuccess); ?></div>
    <?php endif; ?>

    <?php if ($actionError !== null): ?>
      <div class="manuscripts-alert error"><?= e($actionError); ?></div>
    <?php endif; ?>

    <?php if ($pageError !== null): ?>
      <article class="white-block manuscripts-panel">
        <h3 class="white-block__title">Unable to load research management</h3>
        <p class="manuscripts-muted"><?= e($pageError); ?></p>
      </article>
    <?php endif; ?>

    <div class="row stat-cards">
      <?php foreach ($summaryItems as $summaryItem): ?>
        <div class="col-md-6 col-xl-3">
          <article class="stat-cards-item">
            <div class="stat-cards-icon <?= e($summaryItem['color']); ?>">
              <i data-feather="<?= e($summaryItem['icon']); ?>" aria-hidden="true"></i>
            </div>
            <div class="stat-cards-info">
              <p class="stat-cards-info__num"><?= e($summaryItem['value']); ?></p>
              <p class="stat-cards-info__title"><?= e($summaryItem['title']); ?></p>
              <p class="stat-cards-info__progress"><?= e($summaryItem['meta']); ?></p>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row">
      <div class="col-12">
        <div class="manuscripts-toolbar">
          <div class="manuscripts-toolbar-actions">
            <button
              class="primary-default-btn"
              type="button"
              data-bs-toggle="modal"
              data-bs-target="#manuscriptBasicModal"
            >
              <?= e($basicModalTitle); ?>
            </button>

            <button
              class="secondary-default-btn"
              type="button"
              data-bs-toggle="modal"
              data-bs-target="#manuscriptDetailsModal"
              <?= $isManagingDetails ? '' : 'disabled'; ?>
            >
              <?= e($detailsModalTitle); ?>
            </button>

            <?php if ($isEditingBasic): ?>
              <a class="secondary-default-btn" href="<?= e($cancelBasicEditUrl); ?>">Clear Basic</a>
            <?php endif; ?>

            <?php if ($isManagingDetails): ?>
              <a class="secondary-default-btn" href="<?= e($cancelDetailsUrl); ?>">Clear Details</a>
            <?php endif; ?>
          </div>

          <div class="manuscripts-toolbar-actions">
            <?php if ($isEditingBasic): ?>
              <span class="badge-active">Base record selected</span>
            <?php endif; ?>

            <?php if ($isManagingDetails): ?>
              <span class="<?= e(administrator_manuscript_details_complete($detailsManuscript) ? 'badge-active' : 'badge-disabled'); ?>">
                <?= e(administrator_manuscript_details_complete($detailsManuscript) ? 'Ready for Review' : 'Needs More Details'); ?>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <div class="manuscripts-card-list">
          <?php if ($manuscripts === []): ?>
            <article class="manuscripts-empty">No research records have been saved yet.</article>
          <?php endif; ?>

          <?php foreach ($manuscripts as $record): ?>
            <?php
            $recordId = (int) $record['manuscriptid'];
            $editUrl = administrator_manuscript_url(['edit' => $recordId, 'modal' => 'basic']);
            $manageUrl = administrator_manuscript_url(['edit' => $recordId, 'details' => $recordId, 'modal' => 'details']);
            $abstractUrl = administrator_manuscript_abstract_url((string) ($record['abstract_file_path'] ?? ''));
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
            $programLabel = administrator_manuscript_program_label([
                'programid' => $programId,
                'coursecode' => $record['coursecode'] ?? '',
                'coursedescription' => $record['coursedescription'] ?? '',
                'coursemajor' => $record['coursemajor'] ?? '',
            ]);
            ?>
            <article class="manuscripts-record-card">
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
                      <span><?= e(administrator_manuscript_sdg_label((string) ($record['sdg_code'] ?? ''))); ?></span>
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

                    <div class="manuscripts-status-stack">
                      <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'adviser_accountid') ? 'complete' : 'pending'); ?>"><?= e($adviserSummary); ?></span>
                      <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'panelist_accountids', true) ? 'complete' : 'pending'); ?>"><?= e($panelistSummary); ?></span>
                      <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'statistician_accountid') ? 'complete' : 'pending'); ?>"><?= e($statisticianSummary); ?></span>
                      <span class="manuscripts-detail-pill <?= e(administrator_manuscript_detail_field_complete($record, null, 'english_critic_accountid') ? 'complete' : 'pending'); ?>"><?= e($englishCriticSummary); ?></span>
                      <span class="manuscripts-detail-pill <?= e(administrator_manuscript_has_value((string) ($record['abstract_file_path'] ?? '')) ? 'complete' : 'pending'); ?>"><?= e($abstractSummary); ?></span>
                    </div>
                  </div>
                </div>

                <div class="manuscripts-actions manuscripts-card-actions">
                  <a class="manuscripts-inline-btn" href="<?= e($editUrl); ?>">Edit Basic</a>
                  <a class="manuscripts-inline-btn success" href="<?= e($manageUrl); ?>">Manage Details</a>
                  <?php if ($abstractUrl !== ''): ?>
                    <a class="manuscripts-inline-btn neutral" href="<?= e($abstractUrl); ?>" target="_blank" rel="noopener">Abstract</a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="manuscriptBasicModal" tabindex="-1" aria-labelledby="manuscriptBasicModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable manuscripts-modal-dialog">
      <div class="modal-content manuscripts-modal-content">
        <div class="modal-header manuscripts-modal-header">
          <div class="manuscripts-modal-header-main">
            <h3 class="manuscripts-modal-title" id="manuscriptBasicModalLabel"><?= e($basicModalTitle); ?></h3>
            <p class="manuscripts-modal-copy">Save the research type, campus, academic year, program, one or more SDGs, title, and status first.</p>
          </div>

          <div class="manuscripts-modal-header-actions">
            <?php if ($isEditingBasic): ?>
              <span class="badge-active">Base record selected</span>
            <?php endif; ?>

            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        </div>

        <div class="modal-body manuscripts-modal-body">
          <?php if ($formError !== null): ?>
            <div class="manuscripts-alert error"><?= e($formError); ?></div>
          <?php endif; ?>

          <form method="post" action="<?= e(administrator_manuscript_url_with_fragment(['edit' => $selectedManuscriptId > 0 ? $selectedManuscriptId : null, 'details' => $selectedDetailsManuscriptId > 0 ? $selectedDetailsManuscriptId : null], 'skip-target')); ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_manuscript">
            <input type="hidden" name="manuscriptid" value="<?= e((string) $selectedManuscriptId); ?>">

            <div class="manuscripts-form-grid">
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

              <label class="form-label-wrapper">
                <span class="form-label">Status</span>
                <input class="form-input" type="text" name="status" maxlength="30" value="<?= e($selectedStatus); ?>" placeholder="Pending or On-going" required>
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

              <label class="form-label-wrapper">
                <span class="form-label">Program</span>
                <select class="manuscripts-select" name="programid">
                  <option value="">Select program</option>
                  <?php foreach ($programs as $program): ?>
                    <?php
                    $programId = (int) ($program['programid'] ?? 0);
                    $programLabel = administrator_manuscript_program_label($program);
                    ?>
                    <option value="<?= e((string) $programId); ?>"<?= $programId === $selectedProgramId ? ' selected' : ''; ?>>
                      <?= e($programLabel); ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($selectedProgramId > 0 && !isset($programOptionMap[$selectedProgramId])): ?>
                    <option value="<?= e((string) $selectedProgramId); ?>" selected><?= e('Program #' . $selectedProgramId); ?></option>
                  <?php endif; ?>
                </select>
              </label>

              <label class="form-label-wrapper">
                <span class="form-label">Encoder Account</span>
                <select class="manuscripts-select" name="encoder">
                  <option value="">Use current signed-in account</option>
                  <?php foreach ($accountOptionMap as $accountId => $accountLabel): ?>
                    <option value="<?= e((string) $accountId); ?>"<?= $accountId === $selectedEncoderAccountId ? ' selected' : ''; ?>>
                      <?= e($accountLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="manuscripts-field-note">Leave this blank to use the current signed-in account when available.</span>
              </label>

              <label class="form-label-wrapper">
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
                <span class="form-label">Research Title</span>
                <input class="form-input" type="text" name="manuscript_title" maxlength="1000" value="<?= e((string) ($selectedManuscript['manuscript_title'] ?? '')); ?>" placeholder="Enter research title" required>
              </label>

              <label class="form-label-wrapper full-width">
                <span class="form-label">Suggestions / Other Details</span>
                <textarea class="manuscripts-textarea" name="other_details" maxlength="5000" placeholder="Add a short note, scope, suggestions, or other initial remarks."><?= e((string) ($selectedManuscript['other_details'] ?? '')); ?></textarea>
              </label>
            </div>

            <div class="manuscripts-panel-actions">
              <button class="primary-default-btn" type="submit"><?= e($isEditingBasic ? 'Save Base Changes' : 'Save Research Record'); ?></button>
              <button class="secondary-default-btn" type="button" data-bs-dismiss="modal">Close</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="manuscriptDetailsModal" tabindex="-1" aria-labelledby="manuscriptDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable manuscripts-modal-dialog">
      <div class="modal-content manuscripts-modal-content">
        <div class="modal-header manuscripts-modal-header">
          <div class="manuscripts-modal-header-main">
            <h3 class="manuscripts-modal-title" id="manuscriptDetailsModalLabel"><?= e($detailsModalTitle); ?></h3>
            <p class="manuscripts-modal-copy">Use this second step to assign the adviser, panelists, statistician, English critic, authors, and research abstract.</p>
          </div>

          <div class="manuscripts-modal-header-actions">
            <?php if ($isManagingDetails): ?>
              <span class="<?= e(administrator_manuscript_details_complete($detailsManuscript) ? 'badge-active' : 'badge-disabled'); ?>">
                <?= e(administrator_manuscript_details_complete($detailsManuscript) ? 'Ready for Review' : 'Needs More Details'); ?>
              </span>
            <?php endif; ?>

            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        </div>

        <div class="modal-body manuscripts-modal-body">
          <?php if ($detailsFormError !== null): ?>
            <div class="manuscripts-alert error"><?= e($detailsFormError); ?></div>
          <?php endif; ?>

          <?php if (!$isManagingDetails): ?>
            <p class="manuscripts-muted">Select <strong>Manage Details</strong> from a research row below to continue the second step.</p>
          <?php else: ?>
            <?php $currentAbstractUrl = administrator_manuscript_abstract_url((string) ($detailsManuscript['abstract_file_path'] ?? '')); ?>
            <div class="manuscripts-selected-record">
              <p class="manuscripts-selected-title"><?= e((string) ($detailsManuscript['manuscript_title'] ?? 'Selected research')); ?></p>
              <div class="manuscripts-selected-meta">
                <?= e((string) ($detailsManuscript['research_type'] ?? 'Unknown Type')); ?> | <?= e(administrator_manuscript_sdg_label((string) ($detailsManuscript['sdg_code'] ?? ''))); ?> | <?= e(trim((string) ($detailsManuscript['campus_name'] ?? '')) !== '' ? (string) $detailsManuscript['campus_name'] : 'Campus not set'); ?>
              </div>
            </div>

            <form method="post" action="<?= e(administrator_manuscript_url_with_fragment(['edit' => $selectedManuscriptId > 0 ? $selectedManuscriptId : null, 'details' => $selectedDetailsManuscriptId], 'skip-target')); ?>" enctype="multipart/form-data">
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
                  <?php if ($currentAbstractUrl !== ''): ?>
                    <div class="manuscripts-file-meta">
                      <span class="badge-active"><?= e((string) ($detailsManuscript['abstract_original_name'] ?? 'Abstract uploaded')); ?></span>
                      <a class="manuscripts-inline-btn neutral" href="<?= e($currentAbstractUrl); ?>" target="_blank" rel="noopener">View Abstract</a>
                    </div>
                  <?php else: ?>
                    <span class="manuscripts-field-note">No abstract file uploaded yet.</span>
                  <?php endif; ?>
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

              <div class="manuscripts-detail-actions">
                <button class="primary-default-btn" type="submit">Save Research Details</button>
                <button class="secondary-default-btn" type="button" data-bs-dismiss="modal">Close</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
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
