<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireExtensionCoordinator();

function extension_positive_int($value): int
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

function extension_text($value, int $maxLength = 255): string
{
    if (is_array($value)) {
        return '';
    }

    $text = trim((string) $value);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = is_string($text) ? $text : '';

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function extension_long_text($value): string
{
    if (is_array($value)) {
        return '';
    }

    return trim((string) $value);
}

function extension_date_or_null($value): ?string
{
    if (is_array($value)) {
        return null;
    }

    $date = trim((string) $value);

    if ($date === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Enter dates using the YYYY-MM-DD format.');
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));

    if (!checkdate($month, $day, $year)) {
        throw new RuntimeException('Enter a valid calendar date.');
    }

    return $date;
}

function extension_money_or_null($value): ?string
{
    if (is_array($value)) {
        return null;
    }

    $amount = str_replace(',', '', trim((string) $value));

    if ($amount === '') {
        return null;
    }

    if (!is_numeric($amount) || (float) $amount < 0) {
        throw new RuntimeException('Enter a valid non-negative project budget.');
    }

    return number_format((float) $amount, 2, '.', '');
}

function extension_roman(int $number): string
{
    if ($number < 1) {
        return '';
    }

    $map = [
        'M' => 1000,
        'CM' => 900,
        'D' => 500,
        'CD' => 400,
        'C' => 100,
        'XC' => 90,
        'L' => 50,
        'XL' => 40,
        'X' => 10,
        'IX' => 9,
        'V' => 5,
        'IV' => 4,
        'I' => 1,
    ];
    $roman = '';

    foreach ($map as $symbol => $value) {
        while ($number >= $value) {
            $roman .= $symbol;
            $number -= $value;
        }
    }

    return $roman;
}

function extension_project_attachment_upload_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'extension_projects';
}

function extension_project_attachment_relative_path(string $fileName): string
{
    return 'uploads/extension_projects/' . ltrim(str_replace('\\', '/', $fileName), '/');
}

function extension_project_delete_attachment(?string $relativePath): void
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '' || strpos($normalized, 'uploads/extension_projects/') !== 0) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function extension_project_store_attachment(array $file, ?string $currentRelativePath = null): array
{
    $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a PDF attachment or keep the current file.');
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The extension project PDF could not be uploaded. Please try again.');
    }

    $temporaryFile = (string) ($file['tmp_name'] ?? '');

    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) {
        throw new RuntimeException('The uploaded extension project PDF is invalid.');
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    $fileSize = isset($file['size']) ? (int) $file['size'] : 0;
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

    if ($originalName === '') {
        throw new RuntimeException('The uploaded extension project PDF is missing a file name.');
    }

    if ($extension !== 'pdf') {
        throw new RuntimeException('Only PDF extension project attachments are allowed.');
    }

    if ($fileSize < 1) {
        throw new RuntimeException('The uploaded extension project PDF is empty.');
    }

    if ($fileSize > 10 * 1024 * 1024) {
        throw new RuntimeException('The extension project PDF must be 10 MB or smaller.');
    }

    $uploadDirectory = extension_project_attachment_upload_directory();

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The extension project upload directory could not be created.');
    }

    $storedFileName = sprintf(
        'extension_project_%s_%s.pdf',
        date('YmdHis'),
        bin2hex(random_bytes(8))
    );
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedFileName;

    if (!move_uploaded_file($temporaryFile, $destination)) {
        throw new RuntimeException('The extension project PDF could not be stored on the server.');
    }

    extension_project_delete_attachment($currentRelativePath);

    return [
        'relative_path' => extension_project_attachment_relative_path($storedFileName),
        'original_name' => $originalName,
    ];
}

function extension_project_attachment_url(?string $relativePath): string
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '') {
        return '';
    }

    return app_link($normalized);
}

function extension_status_options(): array
{
    return [
        'Draft',
        'Submitted',
        'Approved',
        'Ongoing',
        'Completed',
        'Archived',
    ];
}

function extension_sdg_options(): array
{
    return [
        1 => 'SDG 1 - No Poverty',
        2 => 'SDG 2 - Zero Hunger',
        3 => 'SDG 3 - Good Health and Well-Being',
        4 => 'SDG 4 - Quality Education',
        5 => 'SDG 5 - Gender Equality',
        6 => 'SDG 6 - Clean Water and Sanitation',
        7 => 'SDG 7 - Affordable and Clean Energy',
        8 => 'SDG 8 - Decent Work and Economic Growth',
        9 => 'SDG 9 - Industry, Innovation and Infrastructure',
        10 => 'SDG 10 - Reduced Inequalities',
        11 => 'SDG 11 - Sustainable Cities and Communities',
        12 => 'SDG 12 - Responsible Consumption and Production',
        13 => 'SDG 13 - Climate Action',
        14 => 'SDG 14 - Life Below Water',
        15 => 'SDG 15 - Life on Land',
        16 => 'SDG 16 - Peace, Justice and Strong Institutions',
        17 => 'SDG 17 - Partnerships for the Goals',
    ];
}

function extension_sdg_codes($value): array
{
    $rawValues = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
    $rawValues = is_array($rawValues) ? $rawValues : [];
    $validOptions = extension_sdg_options();
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

        if (isset($validOptions[$code])) {
            $codes[$code] = $code;
        }
    }

    return array_values($codes);
}

function extension_sdg_storage(array $codes): string
{
    return implode(',', array_map('strval', extension_sdg_codes($codes)));
}

function extension_sdg_badges($value): string
{
    $codes = extension_sdg_codes($value);

    if ($codes === []) {
        return '<span class="extension-pill neutral">No SDGs tagged</span>';
    }

    $badges = [];

    foreach ($codes as $code) {
        $badges[] = '<span class="extension-pill">SDG ' . e((string) $code) . '</span>';
    }

    return implode(' ', $badges);
}

function extension_date_label(?string $value, string $emptyLabel = 'Not set'): string
{
    $normalized = trim((string) $value);

    if ($normalized === '') {
        return $emptyLabel;
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('M j, Y', $timestamp) : $normalized;
}

function extension_money_label($value, string $emptyLabel = 'Not set'): string
{
    if ($value === null || trim((string) $value) === '') {
        return $emptyLabel;
    }

    return 'PHP ' . number_format((float) $value, 2);
}

function extension_percent_label(int $part, int $total): string
{
    if ($total < 1) {
        return '0%';
    }

    $percentage = ($part / $total) * 100;

    return $percentage >= 10 ? number_format($percentage, 0) . '%' : number_format($percentage, 1) . '%';
}

function extension_status_tone(string $status): string
{
    $normalized = strtolower(trim($status));

    if ($normalized === 'completed') {
        return 'success';
    }

    if ($normalized === 'ongoing') {
        return 'primary';
    }

    if ($normalized === 'approved') {
        return 'info';
    }

    if ($normalized === 'submitted') {
        return 'warning';
    }

    if ($normalized === 'archived') {
        return 'neutral';
    }

    return 'muted';
}

function extension_status_badge(string $status): string
{
    return '<span class="extension-status extension-status--' . e(extension_status_tone($status)) . '">' . e($status) . '</span>';
}

function extension_short_text(string $value, int $limit = 64): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    $normalized = is_string($normalized) ? $normalized : trim($value);

    if ($normalized === '' || strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, $limit - 3)) . '...';
}

function extension_components_from_text(string $text): array
{
    $lines = preg_split('/\R/', $text);
    $lines = is_array($lines) ? $lines : [];
    $components = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line, 3));
        $components[] = [
            'component_title' => extension_text($parts[0] ?? '', 255),
            'component_leader_name' => extension_text($parts[1] ?? '', 150),
            'expected_output' => extension_long_text($parts[2] ?? ''),
        ];
    }

    return array_values(array_filter($components, static function (array $component): bool {
        return $component['component_title'] !== '';
    }));
}

function extension_components_to_text(array $components): string
{
    $lines = [];

    foreach ($components as $component) {
        $line = trim((string) ($component['component_title'] ?? ''));
        $leader = trim((string) ($component['component_leader_name'] ?? ''));
        $output = trim((string) ($component['expected_output'] ?? ''));

        if ($line === '') {
            continue;
        }

        if ($leader !== '' || $output !== '') {
            $line .= ' | ' . $leader;
        }

        if ($output !== '') {
            $line .= ' | ' . $output;
        }

        $lines[] = $line;
    }

    return implode("\n", $lines);
}

function extension_studies_from_input($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $studies = [];

    foreach ($value as $studyRow) {
        if (!is_array($studyRow)) {
            continue;
        }

        $title = extension_text($studyRow['study_title'] ?? '', 255);

        if ($title === '') {
            continue;
        }

        $studies[] = [
            'study_title' => $title,
            'study_leader_name' => extension_text($studyRow['study_leader_name'] ?? '', 255),
            'rationale' => extension_long_text($studyRow['rationale'] ?? ''),
            'study_note' => extension_long_text($studyRow['study_note'] ?? ''),
            'general_objective' => extension_long_text($studyRow['general_objective'] ?? ''),
            'specific_objectives' => extension_long_text($studyRow['specific_objectives'] ?? ($studyRow['objectives'] ?? '')),
            'methodology' => extension_long_text($studyRow['methodology'] ?? ''),
            'process' => extension_long_text($studyRow['process'] ?? ($studyRow['methodology_process'] ?? '')),
            'counterpart_support' => extension_long_text($studyRow['counterpart_support'] ?? ''),
            'expected_outputs' => extension_long_text($studyRow['expected_outputs'] ?? ''),
        ];
    }

    return $studies;
}

function extension_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    return $query === '' ? app_link('extension/projects.php') : app_link('extension/projects.php') . '?' . $query;
}

function extension_project_defaults(): array
{
    return [
        'extension_projectid' => 0,
        'project_title' => '',
        'program_title' => '',
        'project_leader_name' => '',
        'co_project_leader_name' => '',
        'source_fund' => '',
        'total_budget' => '',
        'start_date' => '',
        'end_date' => '',
        'duration_months' => '',
        'cooperating_agency' => '',
        'sdgs' => '',
        'status' => 'Draft',
        'summary' => '',
        'literature_review' => '',
        'methodology' => '',
        'counterpart_support' => '',
        'expected_outputs' => '',
        'attachment_file_path' => '',
        'attachment_original_name' => '',
    ];
}

$pdo = Database::connection();
Database::ensureExtensionProjectTables();

$search = isset($_GET['q']) ? extension_text($_GET['q'], 100) : '';
$statusFilter = isset($_GET['status']) ? extension_text($_GET['status'], 30) : '';
$editProjectId = isset($_GET['edit']) ? extension_positive_int($_GET['edit']) : 0;
$isCreating = isset($_GET['new']);
$pageError = null;
$editorError = null;
$actionSuccess = get_flash('extension_success');
$actionError = get_flash('extension_error');
$selectedProject = null;
$selectedComponents = [];
$selectedStudies = [];
$projects = [];
$metrics = [
    'total' => 0,
    'draft' => 0,
    'submitted' => 0,
    'approved' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'archived' => 0,
    'budget_total' => 0.0,
    'with_budget' => 0,
    'with_agency' => 0,
    'with_sdgs' => 0,
    'components' => 0,
    'studies' => 0,
    'latest_update' => null,
];
$statusBreakdown = [];
$agencyBreakdown = [];
$recentProjects = [];
$upcomingProjects = [];
$topSdgItems = [];

if (!in_array($statusFilter, extension_status_options(), true)) {
    $statusFilter = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        if ($action === 'delete_project') {
            $projectId = extension_positive_int($_POST['extension_projectid'] ?? 0);

            if ($projectId < 1) {
                throw new RuntimeException('The selected extension project is invalid.');
            }

            $attachmentStatement = $pdo->prepare(
                'SELECT attachment_file_path
                 FROM tblextension_projects
                 WHERE extension_projectid = :extension_projectid
                 LIMIT 1'
            );
            $attachmentStatement->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
            $attachmentStatement->execute();
            $projectAttachmentPath = $attachmentStatement->fetchColumn();

            $deleteStatement = $pdo->prepare(
                'DELETE FROM tblextension_projects
                 WHERE extension_projectid = :extension_projectid'
            );
            $deleteStatement->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
            $deleteStatement->execute();

            extension_project_delete_attachment(is_string($projectAttachmentPath) ? $projectAttachmentPath : null);

            set_flash('extension_success', 'Extension project record was deleted.');
            redirect(extension_url());
        }

        if ($action !== 'save_project') {
            throw new RuntimeException('The requested action is not supported.');
        }

        $projectId = extension_positive_int($_POST['extension_projectid'] ?? 0);
        $existingProject = null;

        if ($projectId > 0) {
            $existingStatement = $pdo->prepare(
                'SELECT *
                 FROM tblextension_projects
                 WHERE extension_projectid = :extension_projectid
                 LIMIT 1'
            );
            $existingStatement->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
            $existingStatement->execute();
            $existingProject = $existingStatement->fetch();

            if (!is_array($existingProject)) {
                throw new RuntimeException('The selected extension project could not be loaded.');
            }
        }

        $projectTitle = extension_text($_POST['project_title'] ?? '', 255);
        $programTitle = extension_text($_POST['program_title'] ?? ($existingProject['program_title'] ?? ''), 255);
        $projectLeader = extension_text($_POST['project_leader_name'] ?? '', 150);
        $coProjectLeader = extension_text($_POST['co_project_leader_name'] ?? '', 150);
        $sourceFund = extension_text($_POST['source_fund'] ?? '', 100);
        $totalBudget = extension_money_or_null($_POST['total_budget'] ?? '');
        $startDate = extension_date_or_null($_POST['start_date'] ?? '');
        $endDate = extension_date_or_null($_POST['end_date'] ?? '');
        $durationMonths = extension_positive_int($_POST['duration_months'] ?? 0);
        $cooperatingAgency = extension_text($_POST['cooperating_agency'] ?? '', 255);
        $sdgs = isset($_POST['sdgs'])
            ? extension_sdg_storage(extension_sdg_codes($_POST['sdgs'] ?? []))
            : (string) ($existingProject['sdgs'] ?? '');
        $status = extension_text($_POST['status'] ?? ($existingProject['status'] ?? 'Draft'), 30);
        $saveMode = extension_text($_POST['save_mode'] ?? 'save', 30);

        if ($status === '' || $saveMode === 'draft') {
            $status = 'Draft';
        }

        $summary = extension_long_text($_POST['summary'] ?? ($existingProject['summary'] ?? ''));
        $literatureReview = extension_long_text($_POST['literature_review'] ?? '');
        $methodology = extension_long_text($_POST['methodology'] ?? '');
        $counterpartSupport = extension_long_text($_POST['counterpart_support'] ?? ($existingProject['counterpart_support'] ?? ''));
        $expectedOutputs = extension_long_text($_POST['expected_outputs'] ?? '');
        $components = isset($_POST['components'])
            ? extension_components_from_text(extension_long_text($_POST['components'] ?? ''))
            : null;
        $studies = extension_studies_from_input($_POST['studies'] ?? []);
        $attachmentRelativePath = (string) ($existingProject['attachment_file_path'] ?? '');
        $attachmentOriginalName = (string) ($existingProject['attachment_original_name'] ?? '');
        $attachmentFile = $_FILES['project_attachment'] ?? null;

        if ($projectTitle === '') {
            throw new RuntimeException('Project title is required.');
        }

        if (!in_array($status, extension_status_options(), true)) {
            throw new RuntimeException('Select a valid project status.');
        }

        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new RuntimeException('End date cannot be earlier than the start date.');
        }

        if (is_array($attachmentFile) && (int) ($attachmentFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadedAttachment = extension_project_store_attachment($attachmentFile, $attachmentRelativePath);
            $attachmentRelativePath = (string) $uploadedAttachment['relative_path'];
            $attachmentOriginalName = (string) $uploadedAttachment['original_name'];
        }

        $account = Auth::account() ?? [];
        $createdBy = isset($account['accountid']) ? (int) $account['accountid'] : 0;

        if ($projectId > 0) {
            $saveStatement = $pdo->prepare(
                'UPDATE tblextension_projects
                 SET project_title = :project_title,
                     program_title = :program_title,
                     project_leader_name = :project_leader_name,
                     co_project_leader_name = :co_project_leader_name,
                     source_fund = :source_fund,
                     total_budget = :total_budget,
                     start_date = :start_date,
                     end_date = :end_date,
                     duration_months = :duration_months,
                     cooperating_agency = :cooperating_agency,
                     sdgs = :sdgs,
                     status = :status,
                     summary = :summary,
                     literature_review = :literature_review,
                     methodology = :methodology,
                     counterpart_support = :counterpart_support,
                     expected_outputs = :expected_outputs,
                     attachment_file_path = :attachment_file_path,
                     attachment_original_name = :attachment_original_name
                 WHERE extension_projectid = :extension_projectid'
            );
            $saveStatement->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
        } else {
            $saveStatement = $pdo->prepare(
                'INSERT INTO tblextension_projects (
                    project_title,
                    program_title,
                    project_leader_name,
                    co_project_leader_name,
                    source_fund,
                    total_budget,
                    start_date,
                    end_date,
                    duration_months,
                    cooperating_agency,
                    sdgs,
                    status,
                    summary,
                    literature_review,
                    methodology,
                    counterpart_support,
                    expected_outputs,
                    attachment_file_path,
                    attachment_original_name,
                    created_by
                 ) VALUES (
                    :project_title,
                    :program_title,
                    :project_leader_name,
                    :co_project_leader_name,
                    :source_fund,
                    :total_budget,
                    :start_date,
                    :end_date,
                    :duration_months,
                    :cooperating_agency,
                    :sdgs,
                    :status,
                    :summary,
                    :literature_review,
                    :methodology,
                    :counterpart_support,
                    :expected_outputs,
                    :attachment_file_path,
                    :attachment_original_name,
                    :created_by
                 )'
            );
            $createdBy > 0
                ? $saveStatement->bindValue(':created_by', $createdBy, PDO::PARAM_INT)
                : $saveStatement->bindValue(':created_by', null, PDO::PARAM_NULL);
        }

        $saveStatement->bindValue(':project_title', $projectTitle);
        $saveStatement->bindValue(':program_title', $programTitle !== '' ? $programTitle : null, $programTitle !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':project_leader_name', $projectLeader !== '' ? $projectLeader : null, $projectLeader !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':co_project_leader_name', $coProjectLeader !== '' ? $coProjectLeader : null, $coProjectLeader !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':source_fund', $sourceFund !== '' ? $sourceFund : null, $sourceFund !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':total_budget', $totalBudget, $totalBudget !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':start_date', $startDate, $startDate !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':end_date', $endDate, $endDate !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $durationMonths > 0
            ? $saveStatement->bindValue(':duration_months', $durationMonths, PDO::PARAM_INT)
            : $saveStatement->bindValue(':duration_months', null, PDO::PARAM_NULL);
        $saveStatement->bindValue(':cooperating_agency', $cooperatingAgency !== '' ? $cooperatingAgency : null, $cooperatingAgency !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':sdgs', $sdgs !== '' ? $sdgs : null, $sdgs !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':status', $status);
        $saveStatement->bindValue(':summary', $summary !== '' ? $summary : null, $summary !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':literature_review', $literatureReview !== '' ? $literatureReview : null, $literatureReview !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':methodology', $methodology !== '' ? $methodology : null, $methodology !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':counterpart_support', $counterpartSupport !== '' ? $counterpartSupport : null, $counterpartSupport !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':expected_outputs', $expectedOutputs !== '' ? $expectedOutputs : null, $expectedOutputs !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':attachment_file_path', $attachmentRelativePath !== '' ? $attachmentRelativePath : null, $attachmentRelativePath !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->bindValue(':attachment_original_name', $attachmentOriginalName !== '' ? $attachmentOriginalName : null, $attachmentOriginalName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $saveStatement->execute();

        if ($projectId < 1) {
            $projectId = (int) $pdo->lastInsertId();
        }

        if (is_array($components)) {
            $deleteComponents = $pdo->prepare(
                'DELETE FROM tblextension_project_components
                 WHERE extension_projectid = :extension_projectid'
            );
            $deleteComponents->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
            $deleteComponents->execute();
        }

        if (is_array($components) && $components !== []) {
            $insertComponent = $pdo->prepare(
                'INSERT INTO tblextension_project_components (
                    extension_projectid,
                    component_title,
                    component_leader_name,
                    expected_output,
                    sort_order
                 ) VALUES (
                    :extension_projectid,
                    :component_title,
                    :component_leader_name,
                    :expected_output,
                    :sort_order
                 )'
            );

            foreach ($components as $index => $component) {
                $insertComponent->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
                $insertComponent->bindValue(':component_title', $component['component_title']);
                $insertComponent->bindValue(
                    ':component_leader_name',
                    $component['component_leader_name'] !== '' ? $component['component_leader_name'] : null,
                    $component['component_leader_name'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
                );
                $insertComponent->bindValue(
                    ':expected_output',
                    $component['expected_output'] !== '' ? $component['expected_output'] : null,
                    $component['expected_output'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
                );
                $insertComponent->bindValue(':sort_order', $index + 1, PDO::PARAM_INT);
                $insertComponent->execute();
            }
        }

        $deleteStudies = $pdo->prepare(
            'DELETE FROM tblextension_project_studies
             WHERE extension_projectid = :extension_projectid'
        );
        $deleteStudies->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
        $deleteStudies->execute();

        if ($studies !== []) {
            $insertStudy = $pdo->prepare(
                'INSERT INTO tblextension_project_studies (
                    extension_projectid,
                    study_title,
                    study_leader_name,
                    rationale,
                    study_note,
                    general_objective,
                    specific_objectives,
                    methodology,
                    methodology_process,
                    counterpart_support,
                    expected_outputs,
                    sort_order
                 ) VALUES (
                    :extension_projectid,
                    :study_title,
                    :study_leader_name,
                    :rationale,
                    :study_note,
                    :general_objective,
                    :specific_objectives,
                    :methodology,
                    :methodology_process,
                    :counterpart_support,
                    :expected_outputs,
                    :sort_order
                 )'
            );

            foreach ($studies as $studyIndex => $study) {
                $insertStudy->bindValue(':extension_projectid', $projectId, PDO::PARAM_INT);
                $insertStudy->bindValue(':study_title', $study['study_title']);
                $insertStudy->bindValue(':study_leader_name', $study['study_leader_name'] !== '' ? $study['study_leader_name'] : null, $study['study_leader_name'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':rationale', $study['rationale'] !== '' ? $study['rationale'] : null, $study['rationale'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':study_note', $study['study_note'] !== '' ? $study['study_note'] : null, $study['study_note'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':general_objective', $study['general_objective'] !== '' ? $study['general_objective'] : null, $study['general_objective'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':specific_objectives', $study['specific_objectives'] !== '' ? $study['specific_objectives'] : null, $study['specific_objectives'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':methodology', $study['methodology'] !== '' ? $study['methodology'] : null, $study['methodology'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':methodology_process', $study['process'] !== '' ? $study['process'] : null, $study['process'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':counterpart_support', $study['counterpart_support'] !== '' ? $study['counterpart_support'] : null, $study['counterpart_support'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':expected_outputs', $study['expected_outputs'] !== '' ? $study['expected_outputs'] : null, $study['expected_outputs'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStudy->bindValue(':sort_order', $studyIndex + 1, PDO::PARAM_INT);
                $insertStudy->execute();
            }
        }

        set_flash('extension_success', 'Extension project record was saved.');
        redirect(extension_url());
    } catch (Throwable $exception) {
        if ($action === 'save_project') {
            $editorError = $exception->getMessage();
            $selectedProject = array_merge(extension_project_defaults(), [
                'extension_projectid' => extension_positive_int($_POST['extension_projectid'] ?? 0),
                'project_title' => extension_text($_POST['project_title'] ?? '', 255),
                'program_title' => extension_text($_POST['program_title'] ?? '', 255),
                'project_leader_name' => extension_text($_POST['project_leader_name'] ?? '', 150),
                'co_project_leader_name' => extension_text($_POST['co_project_leader_name'] ?? '', 150),
                'source_fund' => extension_text($_POST['source_fund'] ?? '', 100),
                'total_budget' => extension_text($_POST['total_budget'] ?? '', 30),
                'start_date' => extension_text($_POST['start_date'] ?? '', 10),
                'end_date' => extension_text($_POST['end_date'] ?? '', 10),
                'duration_months' => extension_text($_POST['duration_months'] ?? '', 10),
                'cooperating_agency' => extension_text($_POST['cooperating_agency'] ?? '', 255),
                'sdgs' => extension_sdg_storage(extension_sdg_codes($_POST['sdgs'] ?? [])),
                'status' => extension_text($_POST['status'] ?? 'Draft', 30),
                'summary' => extension_long_text($_POST['summary'] ?? ''),
                'literature_review' => extension_long_text($_POST['literature_review'] ?? ''),
                'methodology' => extension_long_text($_POST['methodology'] ?? ''),
                'counterpart_support' => extension_long_text($_POST['counterpart_support'] ?? ''),
                'expected_outputs' => extension_long_text($_POST['expected_outputs'] ?? ''),
                'attachment_file_path' => '',
                'attachment_original_name' => '',
            ]);
            $selectedComponents = isset($_POST['components'])
                ? extension_components_from_text(extension_long_text($_POST['components'] ?? ''))
                : [];
            $selectedStudies = extension_studies_from_input($_POST['studies'] ?? []);
            $isCreating = (int) $selectedProject['extension_projectid'] < 1;
        } else {
            set_flash('extension_error', $exception->getMessage());
            redirect(extension_url());
        }
    }
}

try {
    $summary = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status = 'Submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) AS archived,
                COALESCE(SUM(total_budget), 0) AS budget_total,
                SUM(CASE WHEN total_budget IS NOT NULL AND total_budget > 0 THEN 1 ELSE 0 END) AS with_budget,
                SUM(CASE WHEN TRIM(COALESCE(cooperating_agency, '')) <> '' THEN 1 ELSE 0 END) AS with_agency,
                SUM(CASE WHEN TRIM(COALESCE(sdgs, '')) <> '' THEN 1 ELSE 0 END) AS with_sdgs,
                MAX(updated_at) AS latest_update
         FROM tblextension_projects"
    )->fetch();

    if (is_array($summary)) {
        foreach (['total', 'draft', 'submitted', 'approved', 'ongoing', 'completed', 'archived', 'with_budget', 'with_agency', 'with_sdgs'] as $metricKey) {
            $metrics[$metricKey] = (int) ($summary[$metricKey] ?? 0);
        }

        $metrics['budget_total'] = (float) ($summary['budget_total'] ?? 0);
        $metrics['latest_update'] = isset($summary['latest_update']) ? (string) $summary['latest_update'] : null;
    }

    $metrics['components'] = (int) $pdo->query('SELECT COUNT(*) FROM tblextension_project_components')->fetchColumn();
    $metrics['studies'] = (int) $pdo->query('SELECT COUNT(*) FROM tblextension_project_studies')->fetchColumn();

    $statusBreakdownRows = $pdo->query(
        'SELECT status,
                COUNT(*) AS total,
                COALESCE(SUM(total_budget), 0) AS budget_total
         FROM tblextension_projects
         GROUP BY status'
    )->fetchAll();
    $statusBreakdownMap = [];

    foreach ($statusBreakdownRows as $statusRow) {
        $statusName = trim((string) ($statusRow['status'] ?? 'Draft'));
        $statusBreakdownMap[$statusName] = [
            'status' => $statusName,
            'total' => (int) ($statusRow['total'] ?? 0),
            'budget_total' => (float) ($statusRow['budget_total'] ?? 0),
        ];
    }

    foreach (extension_status_options() as $statusOption) {
        $statusBreakdown[] = $statusBreakdownMap[$statusOption] ?? [
            'status' => $statusOption,
            'total' => 0,
            'budget_total' => 0.0,
        ];
    }

    $agencyBreakdown = $pdo->query(
        "SELECT cooperating_agency AS label,
                COUNT(*) AS total,
                COALESCE(SUM(total_budget), 0) AS budget_total
         FROM tblextension_projects
         WHERE TRIM(COALESCE(cooperating_agency, '')) <> ''
         GROUP BY cooperating_agency
         ORDER BY total DESC, cooperating_agency ASC
         LIMIT 5"
    )->fetchAll();

    $recentProjects = $pdo->query(
        'SELECT extension_projectid,
                project_title,
                status,
                project_leader_name,
                updated_at
         FROM tblextension_projects
         ORDER BY updated_at DESC, extension_projectid DESC
         LIMIT 5'
    )->fetchAll();

    $upcomingProjects = $pdo->query(
        "SELECT extension_projectid,
                project_title,
                status,
                end_date,
                project_leader_name
         FROM tblextension_projects
         WHERE end_date IS NOT NULL
           AND status IN ('Approved', 'Ongoing')
           AND end_date >= CURDATE()
         ORDER BY end_date ASC, extension_projectid DESC
         LIMIT 5"
    )->fetchAll();

    $sdgRows = $pdo->query(
        "SELECT sdgs
         FROM tblextension_projects
         WHERE TRIM(COALESCE(sdgs, '')) <> ''"
    )->fetchAll();
    $sdgCounts = [];

    foreach ($sdgRows as $sdgRow) {
        foreach (extension_sdg_codes($sdgRow['sdgs'] ?? '') as $sdgCode) {
            $sdgCounts[$sdgCode] = ($sdgCounts[$sdgCode] ?? 0) + 1;
        }
    }

    arsort($sdgCounts);

    foreach (array_slice($sdgCounts, 0, 5, true) as $sdgCode => $sdgCount) {
        $topSdgItems[] = [
            'code' => (int) $sdgCode,
            'label' => extension_sdg_options()[(int) $sdgCode] ?? 'SDG ' . (string) $sdgCode,
            'total' => (int) $sdgCount,
        ];
    }

    if ($selectedProject === null && $editProjectId > 0) {
        $projectStatement = $pdo->prepare(
            'SELECT *
             FROM tblextension_projects
             WHERE extension_projectid = :extension_projectid
             LIMIT 1'
        );
        $projectStatement->bindValue(':extension_projectid', $editProjectId, PDO::PARAM_INT);
        $projectStatement->execute();
        $project = $projectStatement->fetch();

        if (is_array($project)) {
            $selectedProject = array_merge(extension_project_defaults(), $project);
            $componentStatement = $pdo->prepare(
                'SELECT component_title, component_leader_name, expected_output
                 FROM tblextension_project_components
                 WHERE extension_projectid = :extension_projectid
                 ORDER BY sort_order ASC, extension_componentid ASC'
            );
            $componentStatement->bindValue(':extension_projectid', $editProjectId, PDO::PARAM_INT);
            $componentStatement->execute();
            $selectedComponents = $componentStatement->fetchAll();

            $studyStatement = $pdo->prepare(
                'SELECT *
                 FROM tblextension_project_studies
                 WHERE extension_projectid = :extension_projectid
                 ORDER BY sort_order ASC, extension_studyid ASC'
            );
            $studyStatement->bindValue(':extension_projectid', $editProjectId, PDO::PARAM_INT);
            $studyStatement->execute();
            $selectedStudies = $studyStatement->fetchAll();
        } else {
            $actionError = $actionError ?? 'The selected extension project could not be loaded.';
        }
    }

    if ($selectedProject === null && $isCreating) {
        $selectedProject = extension_project_defaults();
    }

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = '(project_title LIKE :search_project OR program_title LIKE :search_program OR project_leader_name LIKE :search_leader OR cooperating_agency LIKE :search_agency)';
        $params['search_project'] = '%' . $search . '%';
        $params['search_program'] = '%' . $search . '%';
        $params['search_leader'] = '%' . $search . '%';
        $params['search_agency'] = '%' . $search . '%';
    }

    if ($statusFilter !== '') {
        $conditions[] = 'status = :status';
        $params['status'] = $statusFilter;
    }

    $whereClause = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $listStatement = $pdo->prepare(
        'SELECT project.*,
                COALESCE(component_counts.component_total, 0) AS component_total,
                COALESCE(study_counts.study_total, 0) AS study_total
         FROM tblextension_projects project
         LEFT JOIN (
             SELECT extension_projectid, COUNT(*) AS component_total
             FROM tblextension_project_components
             GROUP BY extension_projectid
         ) component_counts
           ON component_counts.extension_projectid = project.extension_projectid
         LEFT JOIN (
             SELECT extension_projectid,
                    COUNT(*) AS study_total
             FROM tblextension_project_studies
             GROUP BY extension_projectid
         ) study_counts
           ON study_counts.extension_projectid = project.extension_projectid'
        . $whereClause .
        ' ORDER BY project.updated_at DESC, project.extension_projectid DESC
          LIMIT 50'
    );

    foreach ($params as $key => $value) {
        $listStatement->bindValue(':' . $key, $value);
    }

    $listStatement->execute();
    $projects = $listStatement->fetchAll();
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$statusOptions = extension_status_options();
$sdgOptions = extension_sdg_options();
$formProject = $selectedProject ?? extension_project_defaults();
$formComponents = $selectedProject !== null ? $selectedComponents : [];
$formStudies = $selectedProject !== null ? $selectedStudies : [];
$formSdgCodes = extension_sdg_codes($formProject['sdgs'] ?? '');
$formAttachmentUrl = extension_project_attachment_url((string) ($formProject['attachment_file_path'] ?? ''));
$formAttachmentName = trim((string) ($formProject['attachment_original_name'] ?? ''));
$formAttachmentName = $formAttachmentName !== '' ? $formAttachmentName : 'Full extension project PDF';
$modalShouldOpen = $selectedProject !== null || $editorError !== null;
$modalTitle = (int) ($formProject['extension_projectid'] ?? 0) > 0 ? 'Edit Extension Project' : 'Encode Extension Project';
$statusChartData = [
    'labels' => array_map(static function (array $row): string {
        return (string) ($row['status'] ?? 'Draft');
    }, $statusBreakdown),
    'counts' => array_map(static function (array $row): int {
        return (int) ($row['total'] ?? 0);
    }, $statusBreakdown),
];
$statusChartJson = json_encode($statusChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$statusChartJson = is_string($statusChartJson) ? $statusChartJson : '{"labels":[],"counts":[]}';
$activeProjectCount = $metrics['approved'] + $metrics['ongoing'];
$budgetCoverage = extension_percent_label($metrics['with_budget'], $metrics['total']);
$partnerCoverage = extension_percent_label($metrics['with_agency'], $metrics['total']);
$sdgCoverage = extension_percent_label($metrics['with_sdgs'], $metrics['total']);
$completionRate = extension_percent_label($metrics['completed'], $metrics['total']);
$dashboardSummaryCards = [
    [
        'label' => 'Total Projects',
        'value' => number_format($metrics['total']),
        'copy' => 'Registry records encoded for extension monitoring.',
        'icon' => 'bx-network-chart',
    ],
    [
        'label' => 'Active Pipeline',
        'value' => number_format($activeProjectCount),
        'copy' => 'Approved and ongoing projects ready for coordination.',
        'icon' => 'bx-git-branch',
    ],
    [
        'label' => 'Funded Projects',
        'value' => $budgetCoverage,
        'copy' => number_format($metrics['with_budget']) . ' record(s) include budget details.',
        'icon' => 'bx-wallet',
    ],
    [
        'label' => 'SDG Coverage',
        'value' => $sdgCoverage,
        'copy' => number_format($metrics['with_sdgs']) . ' project(s) are tagged to SDG outcomes.',
        'icon' => 'bx-target-lock',
    ],
];

$extraStyles = <<<'CSS'
.extension-dashboard-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(19rem, 0.72fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-hero-panel {
  position: relative;
  min-height: 14rem;
  padding: 1.35rem;
  overflow: hidden;
  border-left: 4px solid #0f766e;
}

.extension-hero-content {
  position: relative;
  z-index: 1;
  max-width: 52rem;
}

.extension-hero-eyebrow,
.extension-section-eyebrow {
  margin: 0 0 0.45rem;
  color: #0f766e;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.extension-hero-title {
  margin: 0;
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-size: 1.55rem;
  font-weight: 700;
  line-height: 1.22;
}

.extension-hero-copy {
  max-width: 47rem;
  margin: 0.7rem 0 0;
  color: #4b5563;
  line-height: 1.65;
}

.extension-hero-actions,
.extension-actions,
.extension-project-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
}

.extension-hero-actions {
  margin-top: 1.1rem;
}

.extension-hero-watermark {
  position: absolute;
  right: 1.1rem;
  bottom: 0.85rem;
  color: rgba(15, 118, 110, 0.08);
  font-size: 8rem;
  line-height: 1;
}

.extension-scope-panel {
  padding: 1.15rem;
  display: grid;
  gap: 0.8rem;
  align-content: start;
}

.extension-scope-main {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.extension-scope-icon,
.extension-stat-icon,
.extension-mini-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  border-radius: 8px;
  background: #ccfbf1;
  color: #0f766e;
}

.extension-scope-icon {
  width: 3rem;
  height: 3rem;
  font-size: 1.45rem;
}

.extension-scope-label,
.extension-stat-label,
.extension-tiny-label {
  display: block;
  color: #6b7280;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.extension-scope-value,
.extension-stat-value,
.extension-signal-value {
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-weight: 700;
  line-height: 1.15;
}

.extension-scope-value {
  display: block;
  margin-top: 0.2rem;
  font-size: 1.35rem;
}

.extension-scope-copy,
.extension-panel-copy,
.extension-stat-copy,
.extension-row-meta,
.extension-project-meta,
.extension-project-summary,
.extension-editor-note,
.extension-muted-copy {
  color: #6b7280;
  line-height: 1.55;
}

.extension-scope-copy,
.extension-muted-copy {
  margin: 0;
}

.extension-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-stat-card {
  min-height: 10.25rem;
  padding: 1.05rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.extension-stat-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.85rem;
}

.extension-stat-icon,
.extension-mini-icon {
  width: 2.55rem;
  height: 2.55rem;
  font-size: 1.22rem;
}

.extension-stat-value {
  display: block;
  margin-top: 0.95rem;
  font-size: 1.85rem;
}

.extension-stat-copy {
  margin: 0.28rem 0 0;
  font-size: 0.86rem;
}

.extension-analytics-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.42fr) minmax(20rem, 0.72fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-panel {
  padding: 1.15rem;
}

.extension-panel-header,
.extension-registry-head,
.extension-project-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.extension-panel-header {
  margin-bottom: 0.95rem;
}

.extension-panel-title,
.extension-registry-title,
.extension-editor h3,
.extension-project h3 {
  margin: 0;
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-weight: 700;
  line-height: 1.25;
}

.extension-panel-title,
.extension-registry-title {
  font-size: 1.05rem;
}

.extension-editor h3,
.extension-project h3 {
  font-size: 1rem;
}

.extension-project h3 {
  font-size: clamp(1.05rem, 1vw, 1.22rem);
}

.extension-panel-copy {
  margin: 0.25rem 0 0;
  font-size: 0.88rem;
}

.extension-status-chart {
  min-height: 330px;
}

.extension-chart-empty,
.extension-empty {
  min-height: 12rem;
  display: grid;
  place-items: center;
  padding: 1.5rem;
  color: #6b7280;
  text-align: center;
}

.extension-signal-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.extension-signal {
  padding: 0.9rem;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #fbfefd;
}

.extension-signal-value {
  display: block;
  margin-top: 0.35rem;
  font-size: 1.2rem;
}

.extension-signal-copy {
  margin: 0.35rem 0 0;
  color: #6b7280;
  font-size: 0.82rem;
  line-height: 1.5;
}

.extension-overview-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-row-list {
  display: grid;
  gap: 0.75rem;
}

.extension-row {
  display: grid;
  gap: 0.45rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid #eef2f7;
}

.extension-row:last-child {
  border-bottom: 0;
}

.extension-row-main {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.extension-row-title {
  color: #111827;
  font-weight: 700;
  line-height: 1.4;
}

.extension-row-meta {
  font-size: 0.82rem;
}

.extension-progress-track {
  width: 100%;
  height: 0.5rem;
  overflow: hidden;
  border-radius: 999px;
  background: #eef2f7;
}

.extension-progress-value {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #0f766e;
}

.extension-status,
.extension-pill,
.extension-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 1.85rem;
  padding: 0.32rem 0.65rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
  line-height: 1.2;
}

.extension-chip {
  gap: 0.35rem;
  max-width: 100%;
  background: #f0fdfa;
  color: #0f766e;
  text-align: left;
}

.extension-pill {
  margin: 0.2rem 0.25rem 0 0;
  background: #ecfdf5;
  color: #0f766e;
}

.extension-pill.neutral {
  background: #f1f5f9;
  color: #64748b;
}

.extension-status--primary {
  background: #ecfeff;
  color: #0e7490;
}

.extension-status--info {
  background: #eff6ff;
  color: #1d4ed8;
}

.extension-status--success {
  background: #ecfdf5;
  color: #047857;
}

.extension-status--warning {
  background: #fffbeb;
  color: #b45309;
}

.extension-status--neutral,
.extension-status--muted {
  background: #f1f5f9;
  color: #64748b;
}

.extension-registry-head {
  margin: 1.25rem 0 0.85rem;
}

.extension-registry-head--standalone {
  margin-top: 0;
}

.extension-registry-copy {
  max-width: 56rem;
  margin: 0.35rem 0 0;
  color: #6b7280;
  line-height: 1.6;
}

.extension-filter {
  display: grid;
  grid-template-columns: minmax(16rem, 1fr) minmax(12rem, 16rem) auto auto;
  gap: 0.65rem;
  padding: 0.9rem;
  margin-bottom: 1rem;
}

.extension-input,
.extension-select,
.extension-textarea {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #ffffff;
  color: #111827;
  font: inherit;
}

.extension-input,
.extension-select {
  min-height: 2.65rem;
  padding: 0 0.8rem;
}

.extension-textarea {
  min-height: 7.4rem;
  padding: 0.8rem;
  resize: vertical;
}

.extension-input:focus,
.extension-select:focus,
.extension-textarea:focus {
  border-color: #0f766e;
  outline: 3px solid rgba(15, 118, 110, 0.14);
}

.extension-modal-open {
  overflow: hidden;
}

.extension-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 12000;
  display: grid;
  place-items: center;
  padding: 1.5rem;
  background: rgba(15, 23, 42, 0.52);
}

.extension-modal-backdrop[hidden] {
  display: none;
}

.extension-modal {
  width: min(72rem, 100%);
  max-height: calc(100vh - 3rem);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 1.5rem 4rem rgba(15, 23, 42, 0.22);
}

.extension-modal-header,
.extension-modal-footer {
  flex: 0 0 auto;
  padding: 1rem 1.15rem;
  background: #ffffff;
}

.extension-modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  border-bottom: 1px solid #e5e7eb;
}

.extension-modal-title {
  margin: 0;
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-size: 1.2rem;
  font-weight: 700;
}

.extension-modal-copy {
  margin: 0.3rem 0 0;
  color: #6b7280;
  line-height: 1.55;
}

.extension-modal-close {
  width: 2.35rem;
  height: 2.35rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 2.35rem;
  border: 1px solid #dbe4ef;
  border-radius: 8px;
  background: #ffffff;
  color: #334155;
  font-size: 1.2rem;
  text-decoration: none;
}

.extension-modal-form {
  min-height: 0;
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
}

.extension-modal-body {
  min-height: 0;
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 1rem 1.15rem;
}

.extension-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.65rem;
  border-top: 1px solid #e5e7eb;
}

.extension-editor-note {
  display: block;
  margin: 0.25rem 0 1rem;
}

.extension-proposal-sections {
  display: grid;
  gap: 0.95rem;
}

.extension-proposal-section {
  display: grid;
  gap: 0.85rem;
  padding: 0.95rem;
  border: 1px solid #dbe4ef;
  border-radius: 8px;
  background: #ffffff;
}

.extension-proposal-section-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}

.extension-proposal-roman {
  min-width: 2.4rem;
  min-height: 2.4rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: #ecfdf5;
  color: #0f766e;
  font-size: 0.78rem;
  font-weight: 900;
  letter-spacing: 0;
}

.extension-proposal-title {
  margin: 0;
  color: #111827;
  font-size: 1rem;
  font-weight: 800;
}

.extension-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.9rem;
}

.extension-form-grid label,
.extension-form-grid .extension-field {
  display: block;
}

.extension-form-grid .full {
  grid-column: 1 / -1;
}

.extension-modal-body span.label {
  display: block;
  margin-bottom: 0.4rem;
  color: #334155;
  font-weight: 700;
}

.extension-file-control {
  display: grid;
  gap: 0.55rem;
}

.extension-file-input {
  padding: 0.72rem 0.8rem;
}

.extension-file-current {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: #0f766e;
  font-size: 0.88rem;
  font-weight: 800;
  text-decoration: none;
}

.extension-file-empty {
  color: #64748b;
  font-size: 0.88rem;
}

.extension-sdgs {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.55rem;
  padding: 0.85rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
}

.extension-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #334155;
  font-size: 0.88rem;
  line-height: 1.4;
}

.extension-study-builder {
  display: grid;
  gap: 0.85rem;
}

.extension-study-builder-head,
.extension-study-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.85rem;
  flex-wrap: wrap;
}

.extension-study-builder-title {
  margin: 0;
  color: #111827;
  font-size: 0.95rem;
  font-weight: 800;
}

.extension-study-card {
  display: grid;
  gap: 0.85rem;
  padding: 0.9rem;
  border: 1px solid #dbe4ef;
  border-radius: 8px;
  background: #fbfefd;
}

.extension-study-card-title {
  margin: 0;
  color: #111827;
  font-weight: 800;
  font-size: 0.92rem;
}

.extension-study-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.extension-mini-btn,
.extension-mini-danger {
  min-height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.32rem 0.65rem;
  border-radius: 8px;
  border: 1px solid transparent;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 800;
  cursor: pointer;
}

.extension-mini-btn {
  background: #f0fdfa;
  color: #0f766e;
  border-color: #99f6e4;
}

.extension-mini-danger {
  background: #fef2f2;
  color: #991b1b;
  border-color: #fecaca;
}

.extension-empty-study-note {
  padding: 0.9rem;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  color: #64748b;
  text-align: center;
}

.extension-actions {
  margin-top: 1rem;
}

.extension-list {
  display: grid;
  gap: 1rem;
}

.extension-project {
  overflow: hidden;
  padding: 0;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.extension-project:hover {
  border-color: #99f6e4;
  box-shadow: 0 1rem 2.5rem rgba(15, 23, 42, 0.08);
}

.extension-project-shell {
  display: grid;
  grid-template-columns: 4rem minmax(0, 1fr);
  gap: 1.1rem;
  padding: 1.15rem;
}

.extension-project-visual {
  width: 3.8rem;
}

.extension-project-avatar {
  width: 3.8rem;
  height: 3.8rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #ccfbf1;
  border-radius: 999px;
  background: #f0fdfa;
  color: #0f766e;
  font-size: 1.55rem;
}

.extension-project-main {
  min-width: 0;
}

.extension-project-heading {
  min-width: 0;
}

.extension-project-meta,
.extension-project-summary {
  margin: 0.45rem 0 0;
}

.extension-project-summary {
  color: #4b5563;
  font-size: 0.9rem;
  line-height: 1.65;
}

.extension-project-summary.empty {
  color: #6b7280;
  font-style: italic;
}

.extension-project-leaders {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.85rem;
  margin: 0.35rem 0 0;
  color: #374151;
  font-size: 0.9rem;
  line-height: 1.45;
}

.extension-project-leaders strong {
  color: #111827;
  font-weight: 700;
}

.extension-project-chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.55rem;
  margin-top: 0.75rem;
}

.extension-project-detail-toggle {
  margin-top: 0.9rem;
  padding-top: 0.85rem;
  border-top: 1px solid #e5e7eb;
}

.extension-project-detail-toggle > summary {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
  min-height: 2.2rem;
  padding: 0.42rem 0.78rem;
  border: 1px solid #99f6e4;
  border-radius: 999px;
  background: #f0fdfa;
  color: #0f766e;
  font-size: 0.84rem;
  font-weight: 800;
  list-style: none;
  cursor: pointer;
}

.extension-project-detail-toggle > summary::-webkit-details-marker {
  display: none;
}

.extension-project-detail-toggle .hide-details-label {
  display: none;
}

.extension-project-detail-toggle[open] .show-details-label {
  display: none;
}

.extension-project-detail-toggle[open] .hide-details-label {
  display: inline;
}

.extension-project-detail-body {
  display: grid;
  gap: 0.85rem;
  margin-top: 0.85rem;
}

.extension-project-detail-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.85rem 1rem;
}

.extension-project-detail-item span,
.extension-project-detail-text span {
  display: block;
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.extension-project-detail-item strong,
.extension-project-detail-text p {
  display: block;
  margin-top: 0.28rem;
  color: #111827;
  line-height: 1.35;
}

.extension-project-detail-text p {
  margin-bottom: 0;
  color: #374151;
  line-height: 1.65;
}

.extension-inline-sdgs {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

@media (max-width: 1199.98px) {
  .extension-dashboard-grid,
  .extension-project-detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .extension-dashboard-hero,
  .extension-analytics-grid,
  .extension-overview-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 767.98px) {
  .extension-dashboard-grid,
  .extension-project-detail-grid,
  .extension-signal-grid,
  .extension-filter,
  .extension-form-grid {
    grid-template-columns: 1fr;
  }

  .extension-panel-header,
  .extension-registry-head,
  .extension-project-top,
  .extension-row-main {
    display: block;
  }

  .extension-hero-watermark {
    display: none;
  }

  .extension-project-shell {
    grid-template-columns: 1fr;
  }

  .extension-project-visual {
    display: none;
  }

  .extension-modal-backdrop {
    padding: 0.75rem;
  }

  .extension-modal {
    max-height: calc(100vh - 1.5rem);
  }

  .extension-modal-footer {
    align-items: stretch;
    flex-direction: column-reverse;
  }
}
CSS;

$extraScripts = '<script>
(function () {
  var modal = document.querySelector("[data-extension-project-modal]");

  if (!modal) {
    return;
  }

  var closeUrl = modal.getAttribute("data-close-url") || window.location.pathname;

  function syncBody() {
    document.body.classList.toggle("extension-modal-open", !modal.hidden);
  }

  function closeModal(event) {
    if (event) {
      event.preventDefault();
    }

    modal.hidden = true;
    syncBody();

    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, closeUrl);
    }
  }

  syncBody();

  modal.addEventListener("click", function (event) {
    if (event.target === modal || event.target.closest("[data-extension-project-modal-close]")) {
      closeModal(event);
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal(event);
    }
  });

  var studyBuilder = document.querySelector("[data-study-builder]");
  var studyTemplate = document.getElementById("extension-study-template");

  function toRoman(number) {
    var map = [
      ["M", 1000],
      ["CM", 900],
      ["D", 500],
      ["CD", 400],
      ["C", 100],
      ["XC", 90],
      ["L", 50],
      ["XL", 40],
      ["X", 10],
      ["IX", 9],
      ["V", 5],
      ["IV", 4],
      ["I", 1]
    ];
    var roman = "";

    map.forEach(function (entry) {
      while (number >= entry[1]) {
        roman += entry[0];
        number -= entry[1];
      }
    });

    return roman;
  }

  function refreshStudyLabels() {
    if (!studyBuilder) {
      return;
    }

    studyBuilder.querySelectorAll("[data-study-card]").forEach(function (studyCard, index) {
      var title = studyCard.querySelector(".extension-study-card-title");

      if (title) {
        title.textContent = "Study " + toRoman(index + 1);
      }
    });
  }

  function syncEmptyStudyNote() {
    if (!studyBuilder) {
      return;
    }

    var note = studyBuilder.querySelector("[data-empty-study-note]");

    if (note) {
      note.hidden = studyBuilder.querySelector("[data-study-card]") !== null;
    }
  }

  function addStudy() {
    if (!studyBuilder || !studyTemplate) {
      return;
    }

    var list = studyBuilder.querySelector("[data-study-list]");
    var index = Number(studyBuilder.dataset.nextStudyIndex || "0");
    var html = studyTemplate.innerHTML.replaceAll("__studyIndex__", String(index));

    studyBuilder.dataset.nextStudyIndex = String(index + 1);
    list.insertAdjacentHTML("beforeend", html);
    syncEmptyStudyNote();
    refreshStudyLabels();

    var newStudy = list.querySelector("[data-study-card]:last-child");
    var firstInput = newStudy ? newStudy.querySelector("input, textarea, select") : null;

    if (firstInput) {
      firstInput.focus();
    }
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("[data-add-study]")) {
      event.preventDefault();
      addStudy();
      return;
    }

    var removeStudyButton = event.target.closest("[data-remove-study]");

    if (removeStudyButton) {
      event.preventDefault();
      var studyCard = removeStudyButton.closest("[data-study-card]");

      if (studyCard) {
        studyCard.remove();
        syncEmptyStudyNote();
        refreshStudyLabels();
      }
      return;
    }
  });

  syncEmptyStudyNote();
  refreshStudyLabels();
})();
</script>';

ob_start();
?>
<?php if ($actionSuccess !== null): ?>
  <div class="extension-alert success"><?= e($actionSuccess); ?></div>
<?php endif; ?>

<?php if ($actionError !== null): ?>
  <div class="extension-alert error"><?= e($actionError); ?></div>
<?php endif; ?>

<?php if ($pageError !== null): ?>
  <div class="extension-alert error"><?= e($pageError); ?></div>
<?php endif; ?>

<div id="extension-project-registry" class="extension-registry-head extension-registry-head--standalone">
  <div>
    <p class="extension-section-eyebrow">Project Registry</p>
    <h2 class="extension-registry-title">Extension Project Records</h2>
    <p class="extension-registry-copy">Encode and maintain proposal details, studies, proponents, funding, timeline, methodology, outputs, and project attachments.</p>
  </div>
  <a class="extension-btn" href="<?= e(extension_url(['new' => '1'])); ?>">
    <i class="bx bx-plus"></i>
    Encode Project
  </a>
</div>
<div
  class="extension-modal-backdrop"
  data-extension-project-modal
  data-close-url="<?= e(extension_url()); ?>"
  <?= $modalShouldOpen ? '' : 'hidden'; ?>
>
  <div class="extension-modal" role="dialog" aria-modal="true" aria-labelledby="extension-project-modal-title">
    <div class="extension-modal-header">
      <div>
        <h2 id="extension-project-modal-title" class="extension-modal-title"><?= e($modalTitle); ?></h2>
        <p class="extension-modal-copy">Create and maintain extension project details in a focused workspace while keeping the registry page clean.</p>
      </div>
      <a class="extension-modal-close" href="<?= e(extension_url()); ?>" data-extension-project-modal-close aria-label="Close project form">
        <i class="bx bx-x"></i>
      </a>
    </div>

    <form class="extension-modal-form" method="post" action="<?= e(extension_url()); ?>" enctype="multipart/form-data">
      <div class="extension-modal-body">
        <p class="extension-editor-note">Extension project proposal details</p>

        <?php if ($editorError !== null): ?>
          <div class="extension-alert error"><?= e($editorError); ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_project">
        <input type="hidden" name="extension_projectid" value="<?= e((string) ($formProject['extension_projectid'] ?? 0)); ?>">
        <input type="hidden" name="status" value="<?= e((string) ($formProject['status'] ?? 'Draft')); ?>">

        <div class="extension-proposal-sections">
          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">I</span>
              <h3 class="extension-proposal-title">Title</h3>
            </div>
            <div class="extension-form-grid">
              <label class="full">
                <span class="label">Project Title</span>
                <input class="extension-input" type="text" name="project_title" maxlength="255" value="<?= e((string) ($formProject['project_title'] ?? '')); ?>" required>
              </label>
            </div>
            <div class="extension-study-builder" data-study-builder data-next-study-index="<?= e((string) max(1, count($formStudies))); ?>">
              <div class="extension-study-builder-head">
                <div>
                  <h3 class="extension-study-builder-title">Studies Management</h3>
                </div>
                <button class="extension-mini-btn" type="button" data-add-study>
                  <i class="bx bx-plus"></i>
                  Add Study
                </button>
              </div>

              <div class="extension-study-list" data-study-list>
                <?php if ($formStudies === []): ?>
                  <div class="extension-empty-study-note" data-empty-study-note>No studies added yet.</div>
                <?php endif; ?>
                <?php foreach ($formStudies as $studyIndex => $study): ?>
                  <div class="extension-study-card" data-study-card data-study-index="<?= e((string) $studyIndex); ?>">
                    <div class="extension-study-card-head">
                      <h4 class="extension-study-card-title">Study <?= e(extension_roman($studyIndex + 1)); ?></h4>
                      <div class="extension-study-actions">
                        <button class="extension-mini-danger" type="button" data-remove-study>
                          <i class="bx bx-trash"></i>
                          Remove Study
                        </button>
                      </div>
                    </div>

                    <div class="extension-form-grid">
                      <label class="full">
                        <span class="label">Study Title</span>
                        <input class="extension-input" type="text" name="studies[<?= e((string) $studyIndex); ?>][study_title]" maxlength="255" value="<?= e((string) ($study['study_title'] ?? '')); ?>">
                      </label>
                      <label>
                        <span class="label">Study Leader/s</span>
                        <input class="extension-input" type="text" name="studies[<?= e((string) $studyIndex); ?>][study_leader_name]" maxlength="255" value="<?= e((string) ($study['study_leader_name'] ?? '')); ?>">
                      </label>
                    </div>

                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">II</span>
              <h3 class="extension-proposal-title">Proponent</h3>
            </div>
            <div class="extension-form-grid">
              <label>
                <span class="label">Project Leader</span>
                <input class="extension-input" type="text" name="project_leader_name" maxlength="150" value="<?= e((string) ($formProject['project_leader_name'] ?? '')); ?>">
              </label>
              <label>
                <span class="label">Co-Project Leader</span>
                <input class="extension-input" type="text" name="co_project_leader_name" maxlength="150" value="<?= e((string) ($formProject['co_project_leader_name'] ?? '')); ?>">
              </label>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">III</span>
              <h3 class="extension-proposal-title">Source of Fund</h3>
            </div>
            <div class="extension-form-grid">
              <label class="full">
                <span class="label">Source of Fund</span>
                <input class="extension-input" type="text" name="source_fund" maxlength="100" value="<?= e((string) ($formProject['source_fund'] ?? '')); ?>">
              </label>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">IV</span>
              <h3 class="extension-proposal-title">Total Budgetary Requirements</h3>
            </div>
            <div class="extension-form-grid">
              <label class="full">
                <span class="label">Total Budget</span>
                <input class="extension-input" type="number" name="total_budget" min="0" step="0.01" value="<?= e((string) ($formProject['total_budget'] ?? '')); ?>">
              </label>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">V</span>
              <h3 class="extension-proposal-title">Time/Duration</h3>
            </div>
            <div class="extension-form-grid">
              <label>
                <span class="label">Start Date</span>
                <input class="extension-input" type="date" name="start_date" value="<?= e((string) ($formProject['start_date'] ?? '')); ?>">
              </label>
              <label>
                <span class="label">End Date</span>
                <input class="extension-input" type="date" name="end_date" value="<?= e((string) ($formProject['end_date'] ?? '')); ?>">
              </label>
              <label class="full">
                <span class="label">Duration in Months</span>
                <input class="extension-input" type="number" name="duration_months" min="0" step="1" value="<?= e((string) ($formProject['duration_months'] ?? '')); ?>">
              </label>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">VI</span>
              <h3 class="extension-proposal-title">Cooperating Agency</h3>
            </div>
            <div class="extension-form-grid">
              <label class="full">
                <span class="label">Cooperating Agency</span>
                <input class="extension-input" type="text" name="cooperating_agency" maxlength="255" value="<?= e((string) ($formProject['cooperating_agency'] ?? '')); ?>">
              </label>
            </div>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">VII</span>
              <h3 class="extension-proposal-title">Literature Review</h3>
            </div>
            <label>
              <span class="label">Literature Review</span>
              <textarea class="extension-textarea" name="literature_review"><?= e((string) ($formProject['literature_review'] ?? '')); ?></textarea>
            </label>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">VIII</span>
              <h3 class="extension-proposal-title">Full Extension Project Attachment</h3>
            </div>
            <label class="extension-file-control">
              <span class="label">PDF Attachment</span>
              <input class="extension-input extension-file-input" type="file" name="project_attachment" accept="application/pdf,.pdf">
              <?php if ($formAttachmentUrl !== ''): ?>
                <a class="extension-file-current" href="<?= e($formAttachmentUrl); ?>" target="_blank" rel="noopener">
                  <i class="bx bx-file"></i>
                  <?= e($formAttachmentName); ?>
                </a>
              <?php else: ?>
                <span class="extension-file-empty">No PDF attachment uploaded.</span>
              <?php endif; ?>
            </label>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">IX</span>
              <h3 class="extension-proposal-title">Methodology</h3>
            </div>
            <label>
              <span class="label">Methodology</span>
              <textarea class="extension-textarea" name="methodology"><?= e((string) ($formProject['methodology'] ?? '')); ?></textarea>
            </label>
          </section>

          <section class="extension-proposal-section">
            <div class="extension-proposal-section-head">
              <span class="extension-proposal-roman">X</span>
              <h3 class="extension-proposal-title">Expected Outputs</h3>
            </div>
            <label>
              <span class="label">Expected Outputs</span>
              <textarea class="extension-textarea" name="expected_outputs"><?= e((string) ($formProject['expected_outputs'] ?? '')); ?></textarea>
            </label>
          </section>
        </div>
      </div>

      <div class="extension-modal-footer">
        <a class="extension-btn-secondary" href="<?= e(extension_url()); ?>" data-extension-project-modal-close>Cancel</a>
        <button class="extension-btn-secondary" type="submit" name="save_mode" value="draft">
          <i class="bx bx-save"></i>
          Save as Draft
        </button>
        <button class="extension-btn" type="submit">
          <i class="bx bx-save"></i>
          Save Project
        </button>
      </div>
    </form>
  </div>
</div>

<template id="extension-study-template">
  <div class="extension-study-card" data-study-card data-study-index="__studyIndex__">
    <div class="extension-study-card-head">
      <h4 class="extension-study-card-title">Study</h4>
      <div class="extension-study-actions">
        <button class="extension-mini-danger" type="button" data-remove-study>
          <i class="bx bx-trash"></i>
          Remove Study
        </button>
      </div>
    </div>
    <div class="extension-form-grid">
      <label class="full">
        <span class="label">Study Title</span>
        <input class="extension-input" type="text" name="studies[__studyIndex__][study_title]" maxlength="255">
      </label>
      <label>
        <span class="label">Study Leader/s</span>
        <input class="extension-input" type="text" name="studies[__studyIndex__][study_leader_name]" maxlength="255">
      </label>
    </div>
  </div>
</template>

<form class="extension-card extension-filter" method="get" action="<?= e(extension_url()); ?>">
  <input class="extension-input" type="text" name="q" value="<?= e($search); ?>" placeholder="Search project, program, leader, or agency">
  <select class="extension-select" name="status">
    <option value="">All statuses</option>
    <?php foreach ($statusOptions as $statusOption): ?>
      <option value="<?= e($statusOption); ?>"<?= $statusFilter === $statusOption ? ' selected' : ''; ?>><?= e($statusOption); ?></option>
    <?php endforeach; ?>
  </select>
  <button class="extension-btn" type="submit">Filter</button>
  <a class="extension-btn-secondary" href="<?= e(extension_url()); ?>">Reset</a>
</form>

<section class="extension-list" aria-label="Extension project list">
  <?php if ($projects === []): ?>
    <article class="extension-card extension-empty">No extension project records matched the current filter.</article>
  <?php endif; ?>

  <?php foreach ($projects as $project): ?>
    <?php
    $projectId = (int) $project['extension_projectid'];
    $durationLabel = (int) ($project['duration_months'] ?? 0) > 0
        ? (string) ((int) $project['duration_months']) . ' month(s)'
        : 'Not set';
    $budgetLabel = $project['total_budget'] !== null && $project['total_budget'] !== ''
        ? 'PHP ' . number_format((float) $project['total_budget'], 2)
        : 'Not set';
    $projectTitle = trim((string) ($project['project_title'] ?? ''));
    $projectTitle = $projectTitle !== '' ? $projectTitle : 'Untitled extension project';
    $programLabel = trim((string) ($project['program_title'] ?? ''));
    $programLabel = $programLabel !== '' ? $programLabel : 'No program title';
    $leaderLabel = trim((string) ($project['project_leader_name'] ?? ''));
    $leaderLabel = $leaderLabel !== '' ? $leaderLabel : 'Not assigned';
    $coLeaderLabel = trim((string) ($project['co_project_leader_name'] ?? ''));
    $coLeaderLabel = $coLeaderLabel !== '' ? $coLeaderLabel : 'Not assigned';
    $studyTotal = (int) ($project['study_total'] ?? 0);
    $summaryText = trim((string) ($project['literature_review'] ?? ''));
    $summaryText = $summaryText !== '' ? $summaryText : trim((string) ($project['summary'] ?? ''));
    $summaryPreview = $summaryText !== ''
        ? extension_short_text($summaryText, 220)
        : 'Literature review is not yet available.';
    $summaryClass = $summaryText === '' ? ' empty' : '';
    $methodologyText = trim((string) ($project['methodology'] ?? ''));
    $attachmentUrl = extension_project_attachment_url((string) ($project['attachment_file_path'] ?? ''));
    $attachmentName = trim((string) ($project['attachment_original_name'] ?? ''));
    $attachmentName = $attachmentName !== '' ? $attachmentName : 'Full extension project PDF';
    ?>
    <article class="extension-card extension-project">
      <div class="extension-project-shell">
        <div class="extension-project-visual" aria-hidden="true">
          <span class="extension-project-avatar"><i class="bx bx-network-chart"></i></span>
        </div>

        <div class="extension-project-main">
          <div class="extension-project-top">
            <div class="extension-project-heading">
              <h3><?= e($projectTitle); ?></h3>
              <p class="extension-project-leaders">
                <span>Leader: <strong><?= e($leaderLabel); ?></strong></span>
                <span>Co-leader: <strong><?= e($coLeaderLabel); ?></strong></span>
              </p>
              <div class="extension-project-chips">
                <span class="extension-chip"><i class="bx bx-folder-open"></i><?= e($programLabel); ?></span>
                <?= extension_status_badge((string) ($project['status'] ?? 'Draft')); ?>
                <span class="extension-inline-sdgs"><?= extension_sdg_badges($project['sdgs'] ?? ''); ?></span>
                <span class="extension-chip"><i class="bx bx-layer"></i><?= e(number_format($studyTotal)); ?> study/studies</span>
              </div>
            </div>

            <div class="extension-project-actions">
              <a class="extension-btn-secondary" href="<?= e(extension_url(['edit' => $projectId])); ?>">
                <i class="bx bx-edit"></i>
                Edit
              </a>
              <form
                method="post"
                data-extension-confirm
                data-confirm-title="Delete Extension Project?"
                data-confirm-text="This will permanently delete the project record and studies."
                data-confirm-button="Delete Project"
              >
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="extension_projectid" value="<?= e((string) $projectId); ?>">
                <button class="extension-btn-danger" type="submit">
                  <i class="bx bx-trash"></i>
                  Delete
                </button>
              </form>
            </div>
          </div>

          <p class="extension-project-summary<?= e($summaryClass); ?>"><?= e($summaryPreview); ?></p>

          <details class="extension-project-detail-toggle">
            <summary>
              <i class="bx bx-show"></i>
              <span class="show-details-label">Show Full Details</span>
              <span class="hide-details-label">Hide Full Details</span>
            </summary>
            <div class="extension-project-detail-body">
              <div class="extension-project-detail-grid">
                <div class="extension-project-detail-item">
                  <span>Agency</span>
                  <strong><?= e((string) ($project['cooperating_agency'] ?: 'Not set')); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>Budget</span>
                  <strong><?= e($budgetLabel); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>Duration</span>
                  <strong><?= e($durationLabel); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>Start Date</span>
                  <strong><?= e((string) ($project['start_date'] ?: 'Not set')); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>End Date</span>
                  <strong><?= e((string) ($project['end_date'] ?: 'Not set')); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>Studies</span>
                  <strong><?= e(number_format($studyTotal)); ?></strong>
                </div>
                <div class="extension-project-detail-item">
                  <span>Fund Source</span>
                  <strong><?= e((string) ($project['source_fund'] ?: 'Not set')); ?></strong>
                </div>
              </div>

              <div class="extension-project-detail-text">
                <span>Literature Review</span>
                <p><?= e($summaryText !== '' ? $summaryText : 'Literature review is not yet available.'); ?></p>
              </div>
              <div class="extension-project-detail-text">
                <span>Methodology</span>
                <p><?= e($methodologyText !== '' ? $methodologyText : 'Not set'); ?></p>
              </div>
              <div class="extension-project-detail-text">
                <span>Expected Outputs</span>
                <p><?= e(trim((string) ($project['expected_outputs'] ?? '')) !== '' ? (string) $project['expected_outputs'] : 'Not set'); ?></p>
              </div>
              <div class="extension-project-detail-text">
                <span>Attachment</span>
                <?php if ($attachmentUrl !== ''): ?>
                  <p><a class="extension-file-current" href="<?= e($attachmentUrl); ?>" target="_blank" rel="noopener"><?= e($attachmentName); ?></a></p>
                <?php else: ?>
                  <p>Not uploaded</p>
                <?php endif; ?>
              </div>
            </div>
          </details>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php
$mainContent = (string) ob_get_clean();

ExtensionPage::render([
    'title' => 'Extension Project Registry',
    'current_page' => 'projects',
    'main_content' => $mainContent,
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
]);
