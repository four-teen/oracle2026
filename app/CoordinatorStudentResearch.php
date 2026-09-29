<?php

declare(strict_types=1);

function coordinator_student_research_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');
    $url = app_link('coordinator/research.php');

    return $query === '' ? $url : $url . '?' . $query;
}

function coordinator_student_positive_int($value): int
{
    return is_scalar($value) && ctype_digit(trim((string) $value)) ? (int) $value : 0;
}

function coordinator_student_uppercase(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function coordinator_student_status(string $value): string
{
    return in_array($value, ['Proposal', 'On-going', 'Completed'], true) ? $value : 'Proposal';
}

function coordinator_student_sdg_options(): array
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

function coordinator_student_integer_selection($value, array $allowed): array
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

function coordinator_student_account_selection($value): array
{
    $values = is_array($value) ? $value : [$value];
    $selected = [];

    foreach ($values as $item) {
        $accountId = coordinator_student_positive_int($item);

        if ($accountId > 0) {
            $selected[$accountId] = $accountId;
        }
    }

    return array_values($selected);
}

function coordinator_student_researcher_names($value): array
{
    $values = is_array($value) ? $value : preg_split('/[\r\n;]+/', (string) $value);
    $researchers = [];

    foreach ((array) $values as $item) {
        $name = coordinator_student_uppercase(trim((string) $item));

        if ($name === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $researchers[$key] = $name;
    }

    return array_values($researchers);
}

function coordinator_student_academic_year_label(array $year): string
{
    $from = trim((string) ($year['ay_from'] ?? ''));
    $to = trim((string) ($year['ay_to'] ?? ''));

    if ($from !== '' && $to !== '') {
        return $from . ' - ' . $to;
    }

    return $from !== '' ? $from : ($to !== '' ? $to : 'Academic Year #' . (int) ($year['ayid'] ?? 0));
}

function coordinator_student_program_label(array $program): string
{
    $code = trim((string) ($program['coursecode'] ?? ''));
    $description = trim((string) ($program['coursedescription'] ?? ''));
    $label = trim($code . ($code !== '' && $description !== '' ? ' - ' : '') . $description);

    return $label !== '' ? $label : 'Program #' . (int) ($program['programid'] ?? $program['courseid'] ?? 0);
}

function coordinator_student_faculty_label(array $faculty): string
{
    $name = trim((string) ($faculty['acc_name'] ?? ''));
    $email = strtolower(trim((string) ($faculty['email'] ?? '')));

    if ($name === '') {
        $name = 'Account #' . (int) ($faculty['accountid'] ?? 0);
    }

    return coordinator_student_uppercase($name) . ($email !== '' ? ' - ' . $email : '');
}

function coordinator_student_delete_abstract(?string $relativePath): void
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');

    if ($normalized === '' || $normalized !== 'uploads/manuscript_abstracts/' . basename($normalized)) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function coordinator_student_excerpt(?string $value, int $limit = 120): string
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

function coordinator_student_save(PDO $pdo, int $assignedCampusId, int $currentAccountId, array $input): array
{
    $titleId = coordinator_student_positive_int($input['titleid'] ?? 0);
    $isEditingStudentResearch = $titleId > 0;
    $title = coordinator_student_uppercase(trim((string) ($input['student_title'] ?? '')));
    $typeId = coordinator_student_positive_int($input['student_typeid'] ?? 0);
    $academicYearId = coordinator_student_positive_int($input['student_ayid'] ?? 0);
    $status = coordinator_student_status(trim((string) ($input['student_status'] ?? 'Proposal')));
    $adviserAccountId = coordinator_student_positive_int($input['adviser_accountid'] ?? 0);
    $campusId = $assignedCampusId;
    $programId = coordinator_student_positive_int($input['programid'] ?? 0);
    $abstract = trim((string) ($input['student_other_details'] ?? ''));
    $sdgCodes = coordinator_student_integer_selection(
        $input['student_sdgs'] ?? [],
        coordinator_student_sdg_options()
    );
    $studentResearchers = coordinator_student_researcher_names($input['student_researchers'] ?? []);
    $panelistIds = coordinator_student_account_selection($input['panelist_accountids'] ?? []);
    $statisticianId = coordinator_student_positive_int($input['statistician_accountid'] ?? 0);
    $englishCriticId = coordinator_student_positive_int($input['english_critic_accountid'] ?? 0);

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

    if (coordinator_student_positive_int($program['campusid'] ?? 0) !== $campusId) {
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
               AND campusid = :scope_campus
             LIMIT 1'
        );
        $existingStatement->execute(['titleid' => $titleId, 'scope_campus' => $assignedCampusId]);

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
            $lock = $pdo->prepare('SELECT titleid FROM tblresearches WHERE titleid = :id AND campusid = :campus AND owner_accountid IS NULL AND adviser_accountid IS NOT NULL FOR UPDATE');
            $lock->execute(['id' => $titleId, 'campus' => $assignedCampusId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('The selected student research record was not found in your assigned campus.');
            }
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
                   AND owner_accountid IS NULL
                   AND campusid = :record_campusid'
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
            'encoder' => $currentAccountId,
            'adviser_accountid' => $adviserAccountId,
            'panelist_accountids' => $panelistStorage,
            'statistician_accountid' => $statisticianId,
            'english_critic_accountid' => $englishCriticId,
        ];

        if ($isEditingStudentResearch) {
            $saveParameters['titleid'] = $titleId;
            $saveParameters['record_campusid'] = $assignedCampusId;
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

    return compact('titleId', 'isEditingStudentResearch', 'message', 'adviserAccountId', 'campusId', 'programId', 'academicYearId', 'typeId', 'status');
}
