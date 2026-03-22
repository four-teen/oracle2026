<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!isset($referenceManagerEntity) || !is_string($referenceManagerEntity)) {
    throw new RuntimeException('Reference management entity is missing.');
}

function administrator_reference_manager_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function administrator_reference_manager_positive_int($value): int
{
    $normalized = (int) $value;

    return $normalized > 0 ? $normalized : 0;
}

function administrator_reference_manager_url(string $basePath, array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    if ($query === '') {
        return app_link($basePath);
    }

    return app_link($basePath) . '?' . $query;
}

function administrator_reference_manager_navigation_params(
    string $search,
    array $filters,
    int $page = 1,
    ?int $editRecordId = null
): array {
    $parameters = [];

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    foreach ($filters as $key => $value) {
        if (is_int($value) && $value > 0) {
            $parameters[$key] = $value;
        }
    }

    if ($page > 1) {
        $parameters['page'] = $page;
    }

    if ($editRecordId !== null && $editRecordId > 0) {
        $parameters['edit'] = $editRecordId;
    }

    return $parameters;
}

function administrator_reference_manager_count_label(int $count, string $singular, string $plural): string
{
    return number_format($count) . ' ' . ($count === 1 ? $singular : $plural);
}

function administrator_reference_manager_campus_label(array $record): string
{
    $campusId = isset($record['campusid']) ? (int) $record['campusid'] : 0;
    $campusName = trim((string) ($record['campusname'] ?? $record['campus_name'] ?? ''));

    if ($campusName !== '') {
        return $campusName;
    }

    return $campusId > 0 ? 'Campus #' . $campusId : 'Campus not assigned';
}

function administrator_reference_manager_college_campus_id(array $record): int
{
    if (isset($record['campusid'])) {
        return administrator_reference_manager_positive_int($record['campusid']);
    }

    return administrator_reference_manager_positive_int($record['collegecampus'] ?? 0);
}

function administrator_reference_manager_college_label(array $record): string
{
    $collegeId = isset($record['collegeid']) ? (int) $record['collegeid'] : 0;
    $collegeName = trim((string) ($record['collegename'] ?? $record['college_name'] ?? ''));

    if ($collegeName !== '') {
        return $collegeName;
    }

    return $collegeId > 0 ? 'College #' . $collegeId : 'College not assigned';
}

function administrator_reference_manager_college_option_label(array $record): string
{
    $collegeLabel = administrator_reference_manager_college_label($record);
    $campusLabel = trim((string) ($record['campusname'] ?? $record['campus_name'] ?? ''));

    if ($campusLabel === '') {
        return $collegeLabel;
    }

    return $collegeLabel . ' - ' . $campusLabel;
}

function administrator_reference_manager_course_college_id(array $record): int
{
    if (isset($record['collegeid'])) {
        return administrator_reference_manager_positive_int($record['collegeid']);
    }

    return administrator_reference_manager_positive_int($record['coursecollege'] ?? 0);
}

function administrator_reference_manager_course_title(array $record): string
{
    $courseCode = trim((string) ($record['coursecode'] ?? ''));
    $courseDescription = trim((string) ($record['coursedescription'] ?? ''));

    if ($courseCode !== '' && $courseDescription !== '') {
        return $courseCode . ' - ' . $courseDescription;
    }

    if ($courseCode !== '') {
        return $courseCode;
    }

    if ($courseDescription !== '') {
        return $courseDescription;
    }

    $courseId = isset($record['courseid']) ? (int) $record['courseid'] : 0;

    return $courseId > 0 ? 'Course #' . $courseId : 'Course not assigned';
}

function administrator_reference_manager_course_subtitle(array $record): string
{
    $major = trim((string) ($record['coursemajor'] ?? ''));
    $specification = trim((string) ($record['specification'] ?? ''));
    $parts = [];

    if ($major !== '') {
        $parts[] = $major;
    }

    if ($specification !== '') {
        $parts[] = 'Specification ' . $specification;
    }

    if ($parts === []) {
        return 'No additional course details';
    }

    return implode(' | ', $parts);
}

function administrator_reference_manager_config(string $entity): array
{
    switch ($entity) {
        case 'campus':
            return [
                'entity' => 'campus',
                'singular' => 'Campus',
                'plural' => 'Campuses',
                'current_page' => 'campus',
                'title' => 'Administrator | Campus',
                'base_path' => 'administrator/campus.php',
                'flash_prefix' => 'campus',
                'save_action' => 'save_campus',
                'delete_action' => 'delete_campus',
                'create_button' => 'Add Campus',
                'drawer_title' => 'Add Campus',
                'drawer_copy' => 'Save campus names here so colleges, courses, accounts, and manuscript records can use a shared campus list.',
                'drawer_submit_create' => 'Create Campus',
                'drawer_submit_save' => 'Save Campus',
                'search_placeholder' => 'Search campuses',
                'filter_layout' => 'layout-1',
                'delete_title' => 'Delete campus?',
                'delete_text' => 'This will permanently remove the campus if it is not linked to any colleges, accounts, or research records.',
                'empty_colspan' => 5,
                'table_headers' => ['No.', 'Campus', 'Colleges', 'Courses', 'Action'],
            ];
        case 'college':
            return [
                'entity' => 'college',
                'singular' => 'College',
                'plural' => 'Colleges',
                'current_page' => 'college',
                'title' => 'Administrator | College',
                'base_path' => 'administrator/college.php',
                'flash_prefix' => 'college',
                'save_action' => 'save_college',
                'delete_action' => 'delete_college',
                'create_button' => 'Add College',
                'drawer_title' => 'Add College',
                'drawer_copy' => 'Save college records here and assign each one to the campus where it belongs.',
                'drawer_submit_create' => 'Create College',
                'drawer_submit_save' => 'Save College',
                'search_placeholder' => 'Search colleges',
                'filter_layout' => 'layout-2',
                'delete_title' => 'Delete college?',
                'delete_text' => 'This will permanently remove the college if it is not linked to any courses.',
                'empty_colspan' => 5,
                'table_headers' => ['No.', 'College', 'Campus', 'Courses', 'Action'],
            ];
        case 'course':
            return [
                'entity' => 'course',
                'singular' => 'Course',
                'plural' => 'Courses',
                'current_page' => 'course',
                'title' => 'Administrator | Course',
                'base_path' => 'administrator/course.php',
                'flash_prefix' => 'course',
                'save_action' => 'save_course',
                'delete_action' => 'delete_course',
                'create_button' => 'Add Course',
                'drawer_title' => 'Add Course',
                'drawer_copy' => 'Save course codes, descriptions, majors, and college assignments here so manuscript records can reuse the same program list.',
                'drawer_submit_create' => 'Create Course',
                'drawer_submit_save' => 'Save Course',
                'search_placeholder' => 'Search courses',
                'filter_layout' => 'layout-3',
                'delete_title' => 'Delete course?',
                'delete_text' => 'This will permanently remove the course if it is not linked to any accounts or research records.',
                'empty_colspan' => 5,
                'table_headers' => ['No.', 'Course', 'College', 'Research Records', 'Action'],
            ];
    }

    throw new RuntimeException('Unsupported reference management entity: ' . $entity);
}

function administrator_reference_manager_default_filters(string $entity): array
{
    switch ($entity) {
        case 'college':
            return ['campus' => 0];
        case 'course':
            return ['campus' => 0, 'college' => 0];
        default:
            return [];
    }
}

function administrator_reference_manager_request_filters(string $entity): array
{
    $filters = administrator_reference_manager_default_filters($entity);

    foreach ($filters as $key => $value) {
        $filters[$key] = administrator_reference_manager_positive_int($_GET[$key] ?? 0);
    }

    return $filters;
}

function administrator_reference_manager_ensure_tables(string $entity): void
{
    Database::ensureCampusTable();

    if ($entity === 'campus') {
        return;
    }

    Database::ensureCollegeTable();

    if ($entity === 'college') {
        return;
    }

    Database::ensureCourseTable();
}

function administrator_reference_manager_has_account_column(string $column): bool
{
    try {
        return Database::hasAccountColumn($column);
    } catch (Throwable $exception) {
        return false;
    }
}

function administrator_reference_manager_filter_options(PDO $pdo, string $entity): array
{
    $options = [
        'campuses' => [],
        'colleges' => [],
    ];

    if (!in_array($entity, ['college', 'course'], true)) {
        return $options;
    }

    $options['campuses'] = $pdo->query(
        'SELECT campusid, campusname
         FROM tblcampus
         ORDER BY campusname ASC, campusid ASC'
    )->fetchAll();

    if ($entity !== 'course') {
        return $options;
    }

    $options['colleges'] = $pdo->query(
        "SELECT college.collegeid,
                college.collegename,
                college.collegecampus,
                CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid,
                campus.campusname
         FROM tblcollege college
         LEFT JOIN tblcampus campus
           ON campus.campusid = CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED)
         ORDER BY college.collegename ASC, college.collegeid ASC"
    )->fetchAll();

    return $options;
}

function administrator_reference_manager_summary(PDO $pdo, string $entity): array
{
    switch ($entity) {
        case 'campus':
            return [
                'total' => (int) $pdo->query('SELECT COUNT(*) FROM tblcampus')->fetchColumn(),
                'related_one' => (int) $pdo->query('SELECT COUNT(*) FROM tblcollege')->fetchColumn(),
                'related_two' => (int) $pdo->query('SELECT COUNT(*) FROM tblcourse')->fetchColumn(),
            ];
        case 'college':
            return [
                'total' => (int) $pdo->query('SELECT COUNT(*) FROM tblcollege')->fetchColumn(),
                'related_one' => (int) $pdo->query('SELECT COUNT(*) FROM tblcampus')->fetchColumn(),
                'related_two' => (int) $pdo->query('SELECT COUNT(*) FROM tblcourse')->fetchColumn(),
            ];
        case 'course':
            return [
                'total' => (int) $pdo->query('SELECT COUNT(*) FROM tblcourse')->fetchColumn(),
                'related_one' => (int) $pdo->query('SELECT COUNT(*) FROM tblcollege')->fetchColumn(),
                'related_two' => (int) $pdo->query('SELECT COUNT(*) FROM tblcampus')->fetchColumn(),
            ];
    }

    return [
        'total' => 0,
        'related_one' => 0,
        'related_two' => 0,
    ];
}

function administrator_reference_manager_query_parts(string $entity, string $search, array $filters): array
{
    $conditions = [];
    $params = [];
    $joins = '';

    switch ($entity) {
        case 'campus':
            if ($search !== '') {
                $conditions[] = 'campus.campusname LIKE :search';
                $params['search'] = '%' . $search . '%';
            }
            break;
        case 'college':
            $joins = "
             LEFT JOIN tblcampus campus
               ON campus.campusid = CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED)";

            if ($search !== '') {
                $conditions[] = '(college.collegename LIKE :search OR campus.campusname LIKE :search)';
                $params['search'] = '%' . $search . '%';
            }

            if (($filters['campus'] ?? 0) > 0) {
                $conditions[] = "CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = :campus";
                $params['campus'] = (int) $filters['campus'];
            }
            break;
        case 'course':
            $joins = "
             LEFT JOIN tblcollege college
               ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
             LEFT JOIN tblcampus campus
               ON campus.campusid = CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED)";

            if ($search !== '') {
                $conditions[] = '(
                    course.coursecode LIKE :search
                    OR course.coursedescription LIKE :search
                    OR course.coursemajor LIKE :search
                    OR college.collegename LIKE :search
                    OR campus.campusname LIKE :search
                )';
                $params['search'] = '%' . $search . '%';
            }

            if (($filters['campus'] ?? 0) > 0) {
                $conditions[] = "CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = :campus";
                $params['campus'] = (int) $filters['campus'];
            }

            if (($filters['college'] ?? 0) > 0) {
                $conditions[] = "CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED) = :college";
                $params['college'] = (int) $filters['college'];
            }
            break;
    }

    return [
        'joins' => $joins,
        'where_clause' => $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '',
        'params' => $params,
    ];
}

function administrator_reference_manager_bind_query_params(PDOStatement $statement, array $params): void
{
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
            continue;
        }

        $statement->bindValue(':' . $key, (string) $value, PDO::PARAM_STR);
    }
}

function administrator_reference_manager_fetch_records(
    PDO $pdo,
    string $entity,
    string $search,
    array $filters,
    int $page,
    int $perPage
): array {
    $queryParts = administrator_reference_manager_query_parts($entity, $search, $filters);
    $joins = $queryParts['joins'];
    $whereClause = $queryParts['where_clause'];
    $params = $queryParts['params'];

    switch ($entity) {
        case 'campus':
            $countStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblcampus campus'
                . $whereClause
            );
            $listStatement = $pdo->prepare(
                "SELECT campus.campusid,
                        campus.campusname,
                        (
                            SELECT COUNT(*)
                            FROM tblcollege college
                            WHERE CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = campus.campusid
                        ) AS college_count,
                        (
                            SELECT COUNT(*)
                            FROM tblcourse course
                            LEFT JOIN tblcollege college
                              ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                            WHERE CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) = campus.campusid
                        ) AS course_count,
                        (
                            SELECT COUNT(*)
                            FROM tblresearches research
                            WHERE research.campusid = campus.campusid
                        ) AS research_count
                 FROM tblcampus campus"
                . $whereClause .
                ' ORDER BY campus.campusname ASC, campus.campusid ASC
                  LIMIT :limit OFFSET :offset'
            );
            break;
        case 'college':
            $countStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblcollege college'
                . $joins
                . $whereClause
            );
            $listStatement = $pdo->prepare(
                "SELECT college.collegeid,
                        college.collegename,
                        college.collegecampus,
                        campus.campusid,
                        campus.campusname,
                        (
                            SELECT COUNT(*)
                            FROM tblcourse course
                            WHERE CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED) = college.collegeid
                        ) AS course_count
                 FROM tblcollege college"
                . $joins
                . $whereClause .
                ' ORDER BY college.collegename ASC, college.collegeid ASC
                  LIMIT :limit OFFSET :offset'
            );
            break;
        default:
            $countStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblcourse course'
                . $joins
                . $whereClause
            );
            $listStatement = $pdo->prepare(
                "SELECT course.courseid,
                        course.coursecode,
                        course.coursedescription,
                        course.coursemajor,
                        course.coursecollege,
                        course.specification,
                        college.collegeid,
                        college.collegename,
                        campus.campusid,
                        campus.campusname,
                        (
                            SELECT COUNT(*)
                            FROM tblresearches research
                            WHERE research.programid = course.courseid
                        ) AS research_count
                 FROM tblcourse course"
                . $joins
                . $whereClause .
                ' ORDER BY course.coursecode ASC, course.coursedescription ASC, course.courseid ASC
                  LIMIT :limit OFFSET :offset'
            );
            break;
    }

    administrator_reference_manager_bind_query_params($countStatement, $params);
    $countStatement->execute();
    $filteredCount = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    administrator_reference_manager_bind_query_params($listStatement, $params);
    $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();

    return [
        'records' => $listStatement->fetchAll(),
        'filtered_count' => $filteredCount,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

function administrator_reference_manager_fetch_selected_record(PDO $pdo, string $entity, int $recordId): ?array
{
    if ($recordId < 1) {
        return null;
    }

    switch ($entity) {
        case 'campus':
            $statement = $pdo->prepare(
                'SELECT campusid, campusname
                 FROM tblcampus
                 WHERE campusid = :record_id
                 LIMIT 1'
            );
            break;
        case 'college':
            $statement = $pdo->prepare(
                "SELECT college.collegeid,
                        college.collegename,
                        college.collegecampus,
                        campus.campusid,
                        campus.campusname
                 FROM tblcollege college
                 LEFT JOIN tblcampus campus
                   ON campus.campusid = CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED)
                 WHERE college.collegeid = :record_id
                 LIMIT 1"
            );
            break;
        default:
            $statement = $pdo->prepare(
                "SELECT course.courseid,
                        course.coursecode,
                        course.coursedescription,
                        course.coursemajor,
                        course.coursecollege,
                        course.specification,
                        college.collegeid,
                        college.collegename,
                        campus.campusid,
                        campus.campusname
                 FROM tblcourse course
                 LEFT JOIN tblcollege college
                   ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                 LEFT JOIN tblcampus campus
                   ON campus.campusid = CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED)
                 WHERE course.courseid = :record_id
                 LIMIT 1"
            );
            break;
    }

    $statement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
    $statement->execute();
    $record = $statement->fetch();

    return is_array($record) ? $record : null;
}

function administrator_reference_manager_form_state_from_post(string $entity, array $post): array
{
    switch ($entity) {
        case 'campus':
            return [
                'campusid' => administrator_reference_manager_positive_int($post['campusid'] ?? 0),
                'campusname' => trim((string) ($post['campusname'] ?? '')),
            ];
        case 'college':
            return [
                'collegeid' => administrator_reference_manager_positive_int($post['collegeid'] ?? 0),
                'collegename' => trim((string) ($post['collegename'] ?? '')),
                'collegecampus' => administrator_reference_manager_positive_int($post['collegecampus'] ?? 0),
            ];
        default:
            return [
                'courseid' => administrator_reference_manager_positive_int($post['courseid'] ?? 0),
                'coursecode' => trim((string) ($post['coursecode'] ?? '')),
                'coursedescription' => trim((string) ($post['coursedescription'] ?? '')),
                'coursemajor' => trim((string) ($post['coursemajor'] ?? '')),
                'coursecollege' => administrator_reference_manager_positive_int($post['coursecollege'] ?? 0),
                'specification' => trim((string) ($post['specification'] ?? '0')),
            ];
    }
}

function administrator_reference_manager_record_for_script(string $entity, array $record): array
{
    switch ($entity) {
        case 'campus':
            return [
                'id' => isset($record['campusid']) ? (int) $record['campusid'] : 0,
                'campusid' => isset($record['campusid']) ? (int) $record['campusid'] : 0,
                'campusname' => (string) ($record['campusname'] ?? ''),
            ];
        case 'college':
            return [
                'id' => isset($record['collegeid']) ? (int) $record['collegeid'] : 0,
                'collegeid' => isset($record['collegeid']) ? (int) $record['collegeid'] : 0,
                'collegename' => (string) ($record['collegename'] ?? ''),
                'collegecampus' => administrator_reference_manager_college_campus_id($record),
            ];
        default:
            return [
                'id' => isset($record['courseid']) ? (int) $record['courseid'] : 0,
                'courseid' => isset($record['courseid']) ? (int) $record['courseid'] : 0,
                'coursecode' => (string) ($record['coursecode'] ?? ''),
                'coursedescription' => (string) ($record['coursedescription'] ?? ''),
                'coursemajor' => (string) ($record['coursemajor'] ?? ''),
                'coursecollege' => administrator_reference_manager_course_college_id($record),
                'specification' => (string) ($record['specification'] ?? '0'),
            ];
    }
}

function administrator_reference_manager_default_record(string $entity, array $filters): array
{
    switch ($entity) {
        case 'campus':
            return [
                'campusid' => 0,
                'campusname' => '',
            ];
        case 'college':
            return [
                'collegeid' => 0,
                'collegename' => '',
                'collegecampus' => administrator_reference_manager_positive_int($filters['campus'] ?? 0),
            ];
        default:
            return [
                'courseid' => 0,
                'coursecode' => '',
                'coursedescription' => '',
                'coursemajor' => '',
                'coursecollege' => administrator_reference_manager_positive_int($filters['college'] ?? 0),
                'specification' => '0',
            ];
    }
}

function administrator_reference_manager_require_campus(PDO $pdo, int $campusId): void
{
    $statement = $pdo->prepare(
        'SELECT campusid
         FROM tblcampus
         WHERE campusid = :campusid
         LIMIT 1'
    );
    $statement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $statement->execute();

    if ((int) $statement->fetchColumn() < 1) {
        throw new RuntimeException('The selected campus was not found.');
    }
}

function administrator_reference_manager_require_college(PDO $pdo, int $collegeId): void
{
    $statement = $pdo->prepare(
        'SELECT collegeid
         FROM tblcollege
         WHERE collegeid = :collegeid
         LIMIT 1'
    );
    $statement->bindValue(':collegeid', $collegeId, PDO::PARAM_INT);
    $statement->execute();

    if ((int) $statement->fetchColumn() < 1) {
        throw new RuntimeException('The selected college was not found.');
    }
}

function administrator_reference_manager_save_record(PDO $pdo, string $entity, array $post): array
{
    switch ($entity) {
        case 'campus':
            $campusId = administrator_reference_manager_positive_int($post['campusid'] ?? 0);
            $campusName = trim((string) ($post['campusname'] ?? ''));

            if ($campusName === '') {
                throw new RuntimeException('Campus name is required.');
            }

            if (administrator_reference_manager_text_length($campusName) > 25) {
                throw new RuntimeException('Campus name must be 25 characters or fewer.');
            }

            if ($campusId > 0) {
                $current = administrator_reference_manager_fetch_selected_record($pdo, 'campus', $campusId);

                if (!is_array($current)) {
                    throw new RuntimeException('The selected campus was not found.');
                }
            }

            $duplicateStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblcampus
                 WHERE LOWER(TRIM(campusname)) = LOWER(TRIM(:campusname))
                   AND campusid <> :campusid'
            );
            $duplicateStatement->bindValue(':campusname', $campusName, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
            $duplicateStatement->execute();

            if ((int) $duplicateStatement->fetchColumn() > 0) {
                throw new RuntimeException('That campus already exists.');
            }

            if ($campusId > 0) {
                $saveStatement = $pdo->prepare(
                    'UPDATE tblcampus
                     SET campusname = :campusname
                     WHERE campusid = :campusid'
                );
                $saveStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
                $message = 'Campus updated.';
            } else {
                $saveStatement = $pdo->prepare(
                    'INSERT INTO tblcampus (campusname)
                     VALUES (:campusname)'
                );
                $message = 'Campus created.';
            }

            $saveStatement->bindValue(':campusname', $campusName, PDO::PARAM_STR);
            $saveStatement->execute();

            return ['message' => $message];
        case 'college':
            $collegeId = administrator_reference_manager_positive_int($post['collegeid'] ?? 0);
            $collegeName = trim((string) ($post['collegename'] ?? ''));
            $campusId = administrator_reference_manager_positive_int($post['collegecampus'] ?? 0);

            if ($collegeName === '') {
                throw new RuntimeException('College name is required.');
            }

            if (administrator_reference_manager_text_length($collegeName) > 255) {
                throw new RuntimeException('College name must be 255 characters or fewer.');
            }

            if ($campusId < 1) {
                throw new RuntimeException('Select a campus for this college.');
            }

            administrator_reference_manager_require_campus($pdo, $campusId);

            if ($collegeId > 0) {
                $current = administrator_reference_manager_fetch_selected_record($pdo, 'college', $collegeId);

                if (!is_array($current)) {
                    throw new RuntimeException('The selected college was not found.');
                }
            }

            $duplicateStatement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM tblcollege
                 WHERE LOWER(TRIM(collegename)) = LOWER(TRIM(:collegename))
                   AND TRIM(COALESCE(collegecampus, '')) = TRIM(:collegecampus)
                   AND collegeid <> :collegeid"
            );
            $duplicateStatement->bindValue(':collegename', $collegeName, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':collegecampus', (string) $campusId, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':collegeid', $collegeId, PDO::PARAM_INT);
            $duplicateStatement->execute();

            if ((int) $duplicateStatement->fetchColumn() > 0) {
                throw new RuntimeException('That college already exists for the selected campus.');
            }

            if ($collegeId > 0) {
                $saveStatement = $pdo->prepare(
                    'UPDATE tblcollege
                     SET collegename = :collegename,
                         collegecampus = :collegecampus
                     WHERE collegeid = :collegeid'
                );
                $saveStatement->bindValue(':collegeid', $collegeId, PDO::PARAM_INT);
                $message = 'College updated.';
            } else {
                $saveStatement = $pdo->prepare(
                    'INSERT INTO tblcollege (collegename, collegecampus)
                     VALUES (:collegename, :collegecampus)'
                );
                $message = 'College created.';
            }

            $saveStatement->bindValue(':collegename', $collegeName, PDO::PARAM_STR);
            $saveStatement->bindValue(':collegecampus', (string) $campusId, PDO::PARAM_STR);
            $saveStatement->execute();

            return ['message' => $message];
        default:
            $courseId = administrator_reference_manager_positive_int($post['courseid'] ?? 0);
            $courseCode = trim((string) ($post['coursecode'] ?? ''));
            $courseDescription = trim((string) ($post['coursedescription'] ?? ''));
            $courseMajor = trim((string) ($post['coursemajor'] ?? ''));
            $collegeId = administrator_reference_manager_positive_int($post['coursecollege'] ?? 0);
            $specification = trim((string) ($post['specification'] ?? '0'));

            if ($courseCode === '') {
                throw new RuntimeException('Course code is required.');
            }

            if (administrator_reference_manager_text_length($courseCode) > 30) {
                throw new RuntimeException('Course code must be 30 characters or fewer.');
            }

            if ($courseDescription === '') {
                throw new RuntimeException('Course description is required.');
            }

            if (administrator_reference_manager_text_length($courseDescription) > 100) {
                throw new RuntimeException('Course description must be 100 characters or fewer.');
            }

            if (administrator_reference_manager_text_length($courseMajor) > 50) {
                throw new RuntimeException('Course major must be 50 characters or fewer.');
            }

            if ($collegeId < 1) {
                throw new RuntimeException('Select a college for this course.');
            }

            administrator_reference_manager_require_college($pdo, $collegeId);

            if ($specification === '') {
                $specification = '0';
            }

            if (administrator_reference_manager_text_length($specification) > 15) {
                throw new RuntimeException('Specification must be 15 characters or fewer.');
            }

            if ($courseId > 0) {
                $current = administrator_reference_manager_fetch_selected_record($pdo, 'course', $courseId);

                if (!is_array($current)) {
                    throw new RuntimeException('The selected course was not found.');
                }
            }

            $duplicateStatement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM tblcourse
                 WHERE LOWER(TRIM(coursecode)) = LOWER(TRIM(:coursecode))
                   AND LOWER(TRIM(COALESCE(coursemajor, ''))) = LOWER(TRIM(:coursemajor))
                   AND TRIM(COALESCE(coursecollege, '')) = TRIM(:coursecollege)
                   AND courseid <> :courseid"
            );
            $duplicateStatement->bindValue(':coursecode', $courseCode, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':coursemajor', $courseMajor, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':coursecollege', (string) $collegeId, PDO::PARAM_STR);
            $duplicateStatement->bindValue(':courseid', $courseId, PDO::PARAM_INT);
            $duplicateStatement->execute();

            if ((int) $duplicateStatement->fetchColumn() > 0) {
                throw new RuntimeException('That course already exists for the selected college.');
            }

            if ($courseId > 0) {
                $saveStatement = $pdo->prepare(
                    'UPDATE tblcourse
                     SET coursecode = :coursecode,
                         coursedescription = :coursedescription,
                         coursemajor = :coursemajor,
                         coursecollege = :coursecollege,
                         specification = :specification
                     WHERE courseid = :courseid'
                );
                $saveStatement->bindValue(':courseid', $courseId, PDO::PARAM_INT);
                $message = 'Course updated.';
            } else {
                $saveStatement = $pdo->prepare(
                    'INSERT INTO tblcourse (
                        coursecode,
                        coursedescription,
                        coursemajor,
                        coursecollege,
                        specification
                     ) VALUES (
                        :coursecode,
                        :coursedescription,
                        :coursemajor,
                        :coursecollege,
                        :specification
                     )'
                );
                $message = 'Course created.';
            }

            $saveStatement->bindValue(':coursecode', $courseCode, PDO::PARAM_STR);
            $saveStatement->bindValue(':coursedescription', $courseDescription, PDO::PARAM_STR);
            $saveStatement->bindValue(':coursemajor', $courseMajor, PDO::PARAM_STR);
            $saveStatement->bindValue(':coursecollege', (string) $collegeId, PDO::PARAM_STR);
            $saveStatement->bindValue(':specification', $specification, PDO::PARAM_STR);
            $saveStatement->execute();

            return ['message' => $message];
    }
}

function administrator_reference_manager_delete_record(PDO $pdo, string $entity, int $recordId): string
{
    if ($recordId < 1) {
        throw new RuntimeException('The selected record is invalid.');
    }

    switch ($entity) {
        case 'campus':
            $record = administrator_reference_manager_fetch_selected_record($pdo, 'campus', $recordId);

            if (!is_array($record)) {
                throw new RuntimeException('The selected campus was not found.');
            }

            $collegeStatement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM tblcollege
                 WHERE CAST(NULLIF(TRIM(COALESCE(collegecampus, '')), '') AS UNSIGNED) = :record_id"
            );
            $collegeStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $collegeStatement->execute();
            $collegeCount = (int) $collegeStatement->fetchColumn();

            $researchStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblresearches
                 WHERE campusid = :record_id'
            );
            $researchStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $researchStatement->execute();
            $researchCount = (int) $researchStatement->fetchColumn();

            $accountCount = 0;

            if (administrator_reference_manager_has_account_column('campus')) {
                $accountStatement = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM tblaccount
                     WHERE CAST(NULLIF(TRIM(COALESCE(campus, '')), '') AS UNSIGNED) = :record_id"
                );
                $accountStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
                $accountStatement->execute();
                $accountCount = (int) $accountStatement->fetchColumn();
            }

            $usageItems = [];

            if ($collegeCount > 0) {
                $usageItems[] = administrator_reference_manager_count_label($collegeCount, 'college', 'colleges');
            }

            if ($researchCount > 0) {
                $usageItems[] = administrator_reference_manager_count_label($researchCount, 'research record', 'research records');
            }

            if ($accountCount > 0) {
                $usageItems[] = administrator_reference_manager_count_label($accountCount, 'account', 'accounts');
            }

            if ($usageItems !== []) {
                throw new RuntimeException(
                    'This campus cannot be deleted because it is linked to ' . implode(', ', $usageItems) . '.'
                );
            }

            $deleteStatement = $pdo->prepare(
                'DELETE FROM tblcampus
                 WHERE campusid = :record_id
                 LIMIT 1'
            );
            $deleteStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $deleteStatement->execute();

            if ($deleteStatement->rowCount() < 1) {
                throw new RuntimeException('The selected campus could not be deleted.');
            }

            return 'Campus deleted.';
        case 'college':
            $record = administrator_reference_manager_fetch_selected_record($pdo, 'college', $recordId);

            if (!is_array($record)) {
                throw new RuntimeException('The selected college was not found.');
            }

            $courseStatement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM tblcourse
                 WHERE CAST(NULLIF(TRIM(COALESCE(coursecollege, '')), '') AS UNSIGNED) = :record_id"
            );
            $courseStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $courseStatement->execute();
            $courseCount = (int) $courseStatement->fetchColumn();

            if ($courseCount > 0) {
                throw new RuntimeException(
                    'This college cannot be deleted because it is linked to '
                    . administrator_reference_manager_count_label($courseCount, 'course', 'courses')
                    . '.'
                );
            }

            $deleteStatement = $pdo->prepare(
                'DELETE FROM tblcollege
                 WHERE collegeid = :record_id
                 LIMIT 1'
            );
            $deleteStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $deleteStatement->execute();

            if ($deleteStatement->rowCount() < 1) {
                throw new RuntimeException('The selected college could not be deleted.');
            }

            return 'College deleted.';
        default:
            $record = administrator_reference_manager_fetch_selected_record($pdo, 'course', $recordId);

            if (!is_array($record)) {
                throw new RuntimeException('The selected course was not found.');
            }

            $researchStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblresearches
                 WHERE programid = :record_id'
            );
            $researchStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $researchStatement->execute();
            $researchCount = (int) $researchStatement->fetchColumn();

            $accountCount = 0;

            if (administrator_reference_manager_has_account_column('programid')) {
                $accountStatement = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM tblaccount
                     WHERE CAST(NULLIF(TRIM(COALESCE(programid, '')), '') AS UNSIGNED) = :record_id"
                );
                $accountStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
                $accountStatement->execute();
                $accountCount = (int) $accountStatement->fetchColumn();
            }

            $usageItems = [];

            if ($researchCount > 0) {
                $usageItems[] = administrator_reference_manager_count_label($researchCount, 'research record', 'research records');
            }

            if ($accountCount > 0) {
                $usageItems[] = administrator_reference_manager_count_label($accountCount, 'account', 'accounts');
            }

            if ($usageItems !== []) {
                throw new RuntimeException(
                    'This course cannot be deleted because it is linked to ' . implode(', ', $usageItems) . '.'
                );
            }

            $deleteStatement = $pdo->prepare(
                'DELETE FROM tblcourse
                 WHERE courseid = :record_id
                 LIMIT 1'
            );
            $deleteStatement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $deleteStatement->execute();

            if ($deleteStatement->rowCount() < 1) {
                throw new RuntimeException('The selected course could not be deleted.');
            }

            return 'Course deleted.';
    }
}

$config = administrator_reference_manager_config($referenceManagerEntity);
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filters = administrator_reference_manager_request_filters($referenceManagerEntity);
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$editRecordId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($page < 1) {
    $page = 1;
}

if ($editRecordId < 1) {
    $editRecordId = 0;
}

$records = [];
$selectedRecord = null;
$pageError = null;
$formError = null;
$filteredCount = 0;
$totalPages = 1;
$perPage = 20;
$summaryCounts = [
    'total' => 0,
    'related_one' => 0,
    'related_two' => 0,
];
$filterOptions = [
    'campuses' => [],
    'colleges' => [],
];
$actionSuccess = get_flash($config['flash_prefix'] . '_success');
$actionError = get_flash($config['flash_prefix'] . '_error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string) ($_POST['action'] ?? ''));

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $pdo = Database::connection();
        administrator_reference_manager_ensure_tables($referenceManagerEntity);

        if ($postedAction === $config['save_action']) {
            $saveResult = administrator_reference_manager_save_record($pdo, $referenceManagerEntity, $_POST);
            set_flash($config['flash_prefix'] . '_success', (string) ($saveResult['message'] ?? ($config['singular'] . ' saved.')));
            redirect(administrator_reference_manager_url(
                $config['base_path'],
                administrator_reference_manager_navigation_params($search, $filters, $page)
            ));
        }

        if ($postedAction === $config['delete_action']) {
            $recordId = administrator_reference_manager_positive_int(
                $_POST[$referenceManagerEntity . 'id'] ?? 0
            );
            $message = administrator_reference_manager_delete_record($pdo, $referenceManagerEntity, $recordId);
            set_flash($config['flash_prefix'] . '_success', $message);
            redirect(administrator_reference_manager_url(
                $config['base_path'],
                administrator_reference_manager_navigation_params($search, $filters, $page)
            ));
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($postedAction === $config['save_action']) {
            $formError = $exception->getMessage();
            $selectedRecord = administrator_reference_manager_form_state_from_post($referenceManagerEntity, $_POST);
            $editRecordId = administrator_reference_manager_positive_int(
                $selectedRecord[$referenceManagerEntity . 'id'] ?? 0
            );
        } else {
            set_flash($config['flash_prefix'] . '_error', $exception->getMessage());
            redirect(administrator_reference_manager_url(
                $config['base_path'],
                administrator_reference_manager_navigation_params($search, $filters, $page)
            ));
        }
    }
}

try {
    $pdo = Database::connection();
    administrator_reference_manager_ensure_tables($referenceManagerEntity);
    $summaryCounts = administrator_reference_manager_summary($pdo, $referenceManagerEntity);
    $filterOptions = administrator_reference_manager_filter_options($pdo, $referenceManagerEntity);

    $queryResult = administrator_reference_manager_fetch_records(
        $pdo,
        $referenceManagerEntity,
        $search,
        $filters,
        $page,
        $perPage
    );
    $records = $queryResult['records'];
    $filteredCount = (int) $queryResult['filtered_count'];
    $totalPages = (int) $queryResult['total_pages'];
    $page = (int) $queryResult['page'];

    if ($selectedRecord === null && $editRecordId > 0) {
        $selectedRecord = administrator_reference_manager_fetch_selected_record($pdo, $referenceManagerEntity, $editRecordId);

        if (!is_array($selectedRecord)) {
            $actionError = $actionError ?? ('The selected ' . strtolower($config['singular']) . ' could not be loaded.');
            $editRecordId = 0;
        }
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$selectedRecordId = is_array($selectedRecord)
    ? administrator_reference_manager_positive_int($selectedRecord[$referenceManagerEntity . 'id'] ?? 0)
    : 0;
$isEditing = $selectedRecordId > 0;
$drawerSubmitLabel = $isEditing ? $config['drawer_submit_save'] : $config['drawer_submit_create'];
$shouldOpenEditorDrawer = $formError !== null || $isEditing;
$persistedFilters = administrator_reference_manager_navigation_params($search, $filters);
$pageFiltersWithoutEditor = administrator_reference_manager_navigation_params($search, $filters, $page);
$pageActionUrl = administrator_reference_manager_url($config['base_path'], $pageFiltersWithoutEditor);
$drawerDefaults = administrator_reference_manager_default_record($referenceManagerEntity, $filters);
$scriptRecords = [];

foreach ($records as $record) {
    $scriptRecords[] = administrator_reference_manager_record_for_script($referenceManagerEntity, $record);
}

$scriptRecordsJson = json_encode(
    $scriptRecords,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($scriptRecordsJson)) {
    $scriptRecordsJson = '[]';
}

$drawerDefaultsJson = json_encode(
    $drawerDefaults,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($drawerDefaultsJson)) {
    $drawerDefaultsJson = '{}';
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
        'title' => 'Unable to save ' . strtolower($config['singular']),
        'text' => $formError,
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

switch ($referenceManagerEntity) {
    case 'campus':
        $summaryItems = [
            [
                'value' => number_format((int) $summaryCounts['total']),
                'title' => 'Total Campuses',
                'meta' => 'All saved campus records',
                'icon' => 'map-pin',
                'color' => 'primary',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_one']),
                'title' => 'Colleges',
                'meta' => 'College records linked to campuses',
                'icon' => 'layers',
                'color' => 'success',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_two']),
                'title' => 'Courses',
                'meta' => 'Course records linked through colleges',
                'icon' => 'book-open',
                'color' => 'warning',
            ],
            [
                'value' => number_format($filteredCount),
                'title' => 'Visible Results',
                'meta' => 'Current search results on this page',
                'icon' => 'filter',
                'color' => 'purple',
            ],
        ];
        break;
    case 'college':
        $summaryItems = [
            [
                'value' => number_format((int) $summaryCounts['total']),
                'title' => 'Total Colleges',
                'meta' => 'All saved college records',
                'icon' => 'briefcase',
                'color' => 'primary',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_one']),
                'title' => 'Campuses',
                'meta' => 'Available campuses for assignment',
                'icon' => 'map-pin',
                'color' => 'success',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_two']),
                'title' => 'Courses',
                'meta' => 'Course records linked to colleges',
                'icon' => 'book-open',
                'color' => 'warning',
            ],
            [
                'value' => number_format($filteredCount),
                'title' => 'Visible Results',
                'meta' => 'Current search and campus filter results',
                'icon' => 'filter',
                'color' => 'purple',
            ],
        ];
        break;
    default:
        $summaryItems = [
            [
                'value' => number_format((int) $summaryCounts['total']),
                'title' => 'Total Courses',
                'meta' => 'All saved course records',
                'icon' => 'book',
                'color' => 'primary',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_one']),
                'title' => 'Colleges',
                'meta' => 'Available college records for assignment',
                'icon' => 'briefcase',
                'color' => 'success',
            ],
            [
                'value' => number_format((int) $summaryCounts['related_two']),
                'title' => 'Campuses',
                'meta' => 'Campus records linked through colleges',
                'icon' => 'map-pin',
                'color' => 'warning',
            ],
            [
                'value' => number_format($filteredCount),
                'title' => 'Visible Results',
                'meta' => 'Current search and filter results',
                'icon' => 'filter',
                'color' => 'purple',
            ],
        ];
        break;
}

$extraStyles = '
.reference-manager-panel {
  padding: 24px;
  margin-bottom: 24px;
}

.reference-manager-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}

.reference-manager-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.reference-manager-filter-form {
  display: grid;
  gap: 14px;
  align-items: center;
  width: 100%;
}

.reference-manager-filter-form.layout-1 {
  grid-template-columns: minmax(320px, 1fr) auto;
}

.reference-manager-filter-form.layout-2 {
  grid-template-columns: minmax(280px, 1.5fr) minmax(180px, 0.85fr) auto;
}

.reference-manager-filter-form.layout-3 {
  grid-template-columns: minmax(240px, 1.4fr) minmax(180px, 0.8fr) minmax(220px, 0.95fr) auto;
}

.reference-manager-filter-form > * {
  min-width: 0;
}

.reference-manager-filter-form .search-wrapper {
  min-width: 0;
  position: relative;
  display: block;
}

.reference-manager-filter-form .search-wrapper i,
.reference-manager-filter-form .search-wrapper svg {
  position: absolute;
  top: 50%;
  left: 14px;
  transform: translateY(-50%);
  width: 18px;
  height: 18px;
  color: #8592a3;
  pointer-events: none;
  z-index: 1;
}

.reference-manager-filter-form .search-wrapper .form-input {
  width: 100%;
  margin-bottom: 0;
  padding-left: 44px;
}

.reference-manager-filter-form .form-input,
.reference-manager-filter-form .reference-manager-select,
.reference-manager-drawer-form .form-input,
.reference-manager-drawer-form .reference-manager-select {
  width: 100%;
  height: 44px;
  border: 0;
  border-radius: 8px;
  background-color: #eff0f6;
  color: #171717;
  margin-bottom: 0;
  padding: 0 14px;
}

.reference-manager-filter-actions {
  display: flex;
  gap: 10px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.reference-manager-filter-actions .secondary-default-btn,
.reference-manager-filter-actions .primary-default-btn {
  min-height: 44px;
}

.reference-manager-table-title {
  font-weight: 600;
  color: #171717;
}

.reference-manager-table-subtitle,
.reference-manager-muted,
.reference-manager-field-note {
  margin-top: 4px;
  color: #767676;
  font-size: 12px;
  line-height: 1.6;
}

.reference-manager-count-pill {
  min-width: 42px;
  padding: 7px 12px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(47, 73, 209, 0.1);
  color: #2f49d1;
  font-size: 13px;
  font-weight: 700;
}

.reference-manager-actions-column,
.reference-manager-actions-cell {
  text-align: right;
}

.reference-manager-actions-cell {
  width: 1%;
  white-space: nowrap;
}

.reference-manager-actions {
  display: flex;
  gap: 8px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.reference-manager-inline-form {
  margin: 0;
}

.reference-manager-inline-btn {
  min-height: 36px;
  padding: 8px 14px;
  border: 0;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  color: #2f49d1;
  background-color: rgba(47, 73, 209, 0.1);
}

.reference-manager-inline-btn:hover {
  background-color: rgba(47, 73, 209, 0.16);
  color: #2f49d1;
}

.reference-manager-inline-btn.danger {
  color: #fff;
  background-color: #d14343;
}

.reference-manager-inline-btn.danger:hover {
  background-color: #b93434;
  color: #fff;
}

.reference-manager-empty {
  padding: 32px 24px;
  text-align: center;
  color: #767676;
}

.reference-manager-pagination {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  margin-top: 20px;
}

.reference-manager-pagination a,
.reference-manager-pagination span {
  min-width: 40px;
  height: 40px;
  padding: 0 14px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background-color: #fff;
  color: #171717;
  box-shadow: 0px 1px 0px #dadbe4;
}

.reference-manager-pagination span.active {
  background-color: #2f49d1;
  color: #fff;
}

.reference-manager-drawer {
  width: min(700px, 100vw);
  border: 0;
}

.offcanvas {
  transition: transform 0.34s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: transform;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
}

.offcanvas-backdrop {
  backdrop-filter: blur(4px);
  transition: opacity 0.24s ease;
  will-change: opacity;
}

.reference-manager-drawer.show:not(.hiding) {
  box-shadow: -20px 0 48px rgba(17, 24, 39, 0.16);
}

.reference-manager-drawer-header {
  padding: 24px 24px 0;
  border-bottom: 0;
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.reference-manager-drawer-main {
  min-width: 0;
}

.reference-manager-drawer-title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
  color: #566a7f;
}

.reference-manager-drawer-copy {
  margin: 6px 0 0;
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.reference-manager-drawer-header-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
}

.reference-manager-drawer-body {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.reference-manager-drawer-form {
  display: grid;
  gap: 16px;
}

.reference-manager-drawer-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 8px;
}

.reference-manager-drawer-form textarea.form-input {
  min-height: 110px;
  height: auto;
  padding: 14px;
  resize: vertical;
}

@media (max-width: 1199px) {
  .reference-manager-filter-form.layout-3 {
    grid-template-columns: minmax(0, 1fr) minmax(180px, 220px) minmax(220px, 240px) auto;
  }
}

@media (max-width: 991px) {
  .reference-manager-drawer-header {
    padding: 20px 20px 0;
    flex-wrap: wrap;
  }

  .reference-manager-drawer-body {
    padding: 20px;
  }

  .reference-manager-filter-form,
  .reference-manager-filter-form.layout-1,
  .reference-manager-filter-form.layout-2,
  .reference-manager-filter-form.layout-3 {
    grid-template-columns: 1fr;
  }

  .reference-manager-filter-actions {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .reference-manager-actions {
    flex-wrap: wrap;
  }

  .reference-manager-pagination {
    justify-content: flex-start;
  }
}
';

$extraHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
';

$extraScripts = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
  jQuery(function ($) {
    var shouldOpenEditorDrawer = __OPEN_EDITOR_DRAWER__;
    var drawerTitle = '__DRAWER_TITLE__';
    var createSubmitLabel = '__CREATE_SUBMIT__';
    var saveSubmitLabel = '__SAVE_SUBMIT__';
    var deleteTitle = '__DELETE_TITLE__';
    var deleteText = '__DELETE_TEXT__';
    var records = __RECORDS__;
    var drawerDefaults = __DRAWER_DEFAULTS__;
    var swalAlerts = __SWAL_ALERTS__;
    var drawerElement = document.getElementById('referenceManagerDrawer');
    var drawerForm = drawerElement ? drawerElement.querySelector('form') : null;
    var idInput = drawerForm ? drawerForm.querySelector('input[data-drawer-id]') : null;
    var drawerFields = drawerForm ? Array.prototype.slice.call(drawerForm.querySelectorAll('[data-drawer-field]')) : [];
    var drawerTitleElement = document.getElementById('referenceManagerDrawerLabel');
    var drawerSubmitButton = drawerForm ? drawerForm.querySelector('button[type=\"submit\"]') : null;
    var drawerInstance = null;
    var recordMap = {};
    var modalHash = window.location.hash || '';

    if (Array.isArray(records)) {
      records.forEach(function (record) {
        var recordId = parseInt(record && record.id, 10);

        if (recordId > 0) {
          recordMap[recordId] = record;
        }
      });
    }

    if (drawerElement && typeof bootstrap !== 'undefined' && typeof bootstrap.Offcanvas === 'function') {
      drawerInstance = bootstrap.Offcanvas.getOrCreateInstance(drawerElement);
    }

    function normalizedId(value) {
      var parsedValue = parseInt(value, 10);

      return !isNaN(parsedValue) && parsedValue > 0 ? parsedValue : 0;
    }

    function drawerState(record) {
      if (record && typeof record === 'object') {
        return record;
      }

      if (drawerDefaults && typeof drawerDefaults === 'object') {
        return drawerDefaults;
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

    function clearDrawerState(expectedHash) {
      if (window.history && typeof window.history.replaceState === 'function') {
        var nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete('edit');

        if (expectedHash && nextUrl.hash === expectedHash) {
          nextUrl.hash = '';
        }

        window.history.replaceState(null, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
      }
    }

    function populateDrawer(record) {
      var state = drawerState(record);
      var recordId = normalizedId(state.id);

      if (idInput) {
        idInput.value = recordId > 0 ? String(recordId) : '';
      }

      drawerFields.forEach(function (field) {
        var fieldKey = field.getAttribute('data-drawer-field');
        var nextValue = Object.prototype.hasOwnProperty.call(state, fieldKey) ? state[fieldKey] : '';
        field.value = nextValue === null ? '' : String(nextValue);
      });

      if (drawerTitleElement) {
        drawerTitleElement.textContent = drawerTitle;
      }

      if (drawerSubmitButton) {
        drawerSubmitButton.textContent = recordId > 0 ? saveSubmitLabel : createSubmitLabel;
      }
    }

    function openDrawer() {
      if (drawerInstance) {
        drawerInstance.show();
      }
    }

    function initDeleteConfirmation() {
      $(document).on('submit', '.reference-delete-form', function (event) {
        var form = this;

        if (form.dataset.confirmed === 'true' || typeof Swal === 'undefined') {
          return true;
        }

        event.preventDefault();

        Swal.fire({
          icon: 'warning',
          title: deleteTitle,
          text: deleteText,
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

    $(document).on('click', '[data-reference-open-add]', function () {
      populateDrawer(drawerDefaults);
    });

    $(document).on('click', '[data-reference-edit-trigger]', function (event) {
      var recordId = normalizedId($(this).data('recordId'));
      var record = recordMap[recordId] || null;

      if (!record || !drawerInstance) {
        return;
      }

      event.preventDefault();
      populateDrawer(record);
      openDrawer();
    });

    if (drawerElement) {
      $(drawerElement).on('shown.bs.offcanvas', function () {
        clearDrawerState('#reference-manager-drawer');

        if (drawerFields.length > 0) {
          drawerFields[0].focus();
        }
      });

      $(drawerElement).on('hidden.bs.offcanvas', function () {
        clearDrawerState('#reference-manager-drawer');
        populateDrawer(drawerDefaults);
      });
    }

    initDeleteConfirmation();
    showSwalAlerts(0);

    if (shouldOpenEditorDrawer) {
      openDrawer();
    } else if (modalHash === '#reference-manager-drawer') {
      openDrawer();
    }

    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#reference-manager-drawer') {
        openDrawer();
      }
    });
  });
</script>
HTML;

$extraScripts = str_replace(
    [
        '__OPEN_EDITOR_DRAWER__',
        '__DRAWER_TITLE__',
        '__CREATE_SUBMIT__',
        '__SAVE_SUBMIT__',
        '__DELETE_TITLE__',
        '__DELETE_TEXT__',
        '__RECORDS__',
        '__DRAWER_DEFAULTS__',
        '__SWAL_ALERTS__',
    ],
    [
        $shouldOpenEditorDrawer ? 'true' : 'false',
        addslashes($config['drawer_title']),
        addslashes($config['drawer_submit_create']),
        addslashes($config['drawer_submit_save']),
        addslashes($config['delete_title']),
        addslashes($config['delete_text']),
        $scriptRecordsJson,
        $drawerDefaultsJson,
        $swalAlertsJson,
    ],
    $extraScripts
);

ob_start();
?>
<main class="main users chart-page" id="skip-target">
  <div class="container">
    <?php if ($pageError !== null): ?>
      <article class="white-block reference-manager-panel">
        <h3 class="white-block__title">Unable to load <?= e(strtolower($config['plural'])); ?></h3>
        <p class="reference-manager-muted"><?= e($pageError); ?></p>
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
        <div class="reference-manager-toolbar">
          <div class="reference-manager-toolbar-actions">
            <button
              class="primary-default-btn"
              type="button"
              data-reference-open-add
              data-bs-toggle="offcanvas"
              data-bs-target="#referenceManagerDrawer"
            >
              <?= e($config['create_button']); ?>
            </button>
          </div>
        </div>

        <div class="sort-bar">
          <form class="reference-manager-filter-form <?= e($config['filter_layout']); ?>" method="get" action="<?= e(app_link($config['base_path'])); ?>">
            <label class="search-wrapper">
              <i data-feather="search" aria-hidden="true"></i>
              <input class="form-input" type="text" name="q" value="<?= e($search); ?>" placeholder="<?= e($config['search_placeholder']); ?>">
            </label>

            <?php if ($referenceManagerEntity === 'college'): ?>
              <select class="reference-manager-select" name="campus">
                <option value="">All campuses</option>
                <?php foreach ($filterOptions['campuses'] as $campusOption): ?>
                  <?php $campusOptionId = isset($campusOption['campusid']) ? (int) $campusOption['campusid'] : 0; ?>
                  <option value="<?= e((string) $campusOptionId); ?>"<?= $campusOptionId === (int) ($filters['campus'] ?? 0) ? ' selected' : ''; ?>>
                    <?= e(administrator_reference_manager_campus_label($campusOption)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>

            <?php if ($referenceManagerEntity === 'course'): ?>
              <select class="reference-manager-select" name="campus">
                <option value="">All campuses</option>
                <?php foreach ($filterOptions['campuses'] as $campusOption): ?>
                  <?php $campusOptionId = isset($campusOption['campusid']) ? (int) $campusOption['campusid'] : 0; ?>
                  <option value="<?= e((string) $campusOptionId); ?>"<?= $campusOptionId === (int) ($filters['campus'] ?? 0) ? ' selected' : ''; ?>>
                    <?= e(administrator_reference_manager_campus_label($campusOption)); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <select class="reference-manager-select" name="college">
                <option value="">All colleges</option>
                <?php foreach ($filterOptions['colleges'] as $collegeOption): ?>
                  <?php $collegeOptionId = isset($collegeOption['collegeid']) ? (int) $collegeOption['collegeid'] : 0; ?>
                  <option value="<?= e((string) $collegeOptionId); ?>"<?= $collegeOptionId === (int) ($filters['college'] ?? 0) ? ' selected' : ''; ?>>
                    <?= e(administrator_reference_manager_college_option_label($collegeOption)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>

            <div class="reference-manager-filter-actions">
              <button class="primary-default-btn" type="submit">Apply Filter</button>
              <a class="secondary-default-btn" href="<?= e(app_link($config['base_path'])); ?>">Reset</a>
            </div>
          </form>
        </div>

        <div class="users-table table-wrapper">
          <table class="posts-table">
            <thead>
              <tr class="users-table-info">
                <?php foreach ($config['table_headers'] as $headerIndex => $headerLabel): ?>
                  <th<?= $headerIndex === count($config['table_headers']) - 1 ? ' class="reference-manager-actions-column"' : ''; ?>><?= e($headerLabel); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if ($records === []): ?>
                <tr>
                  <td class="reference-manager-empty" colspan="<?= e((string) $config['empty_colspan']); ?>">No <?= e(strtolower($config['plural'])); ?> matched the current filters.</td>
                </tr>
              <?php endif; ?>

              <?php $rowNumber = (($page - 1) * $perPage) + 1; ?>
              <?php foreach ($records as $record): ?>
                <?php
                $recordId = administrator_reference_manager_positive_int($record[$referenceManagerEntity . 'id'] ?? 0);
                $editUrl = administrator_reference_manager_url(
                    $config['base_path'],
                    administrator_reference_manager_navigation_params($search, $filters, $page, $recordId)
                );
                ?>
                <tr>
                  <td><?= e((string) $rowNumber); ?></td>

                  <?php if ($referenceManagerEntity === 'campus'): ?>
                    <td>
                      <div class="reference-manager-table-title"><?= e(administrator_reference_manager_campus_label($record)); ?></div>
                      <div class="reference-manager-table-subtitle"><?= e(administrator_reference_manager_count_label((int) ($record['research_count'] ?? 0), 'research record', 'research records')); ?></div>
                    </td>
                    <td><span class="reference-manager-count-pill"><?= e((string) number_format((int) ($record['college_count'] ?? 0))); ?></span></td>
                    <td><span class="reference-manager-count-pill"><?= e((string) number_format((int) ($record['course_count'] ?? 0))); ?></span></td>
                  <?php elseif ($referenceManagerEntity === 'college'): ?>
                    <td>
                      <div class="reference-manager-table-title"><?= e(administrator_reference_manager_college_label($record)); ?></div>
                    </td>
                    <td>
                      <div class="reference-manager-table-title"><?= e(administrator_reference_manager_campus_label($record)); ?></div>
                    </td>
                    <td><span class="reference-manager-count-pill"><?= e((string) number_format((int) ($record['course_count'] ?? 0))); ?></span></td>
                  <?php else: ?>
                    <td>
                      <div class="reference-manager-table-title"><?= e(administrator_reference_manager_course_title($record)); ?></div>
                      <div class="reference-manager-table-subtitle"><?= e(administrator_reference_manager_course_subtitle($record)); ?></div>
                    </td>
                    <td>
                      <div class="reference-manager-table-title"><?= e(administrator_reference_manager_college_label($record)); ?></div>
                      <div class="reference-manager-table-subtitle"><?= e(administrator_reference_manager_campus_label($record)); ?></div>
                    </td>
                    <td><span class="reference-manager-count-pill"><?= e((string) number_format((int) ($record['research_count'] ?? 0))); ?></span></td>
                  <?php endif; ?>

                  <td class="reference-manager-actions-cell">
                    <div class="reference-manager-actions">
                      <a
                        class="reference-manager-inline-btn"
                        href="<?= e($editUrl); ?>"
                        data-reference-edit-trigger
                        data-record-id="<?= e((string) $recordId); ?>"
                      >
                        Edit
                      </a>

                      <form
                        class="reference-manager-inline-form reference-delete-form"
                        method="post"
                        action="<?= e($pageActionUrl); ?>"
                      >
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="<?= e($config['delete_action']); ?>">
                        <input type="hidden" name="<?= e($referenceManagerEntity . 'id'); ?>" value="<?= e((string) $recordId); ?>">
                        <button class="reference-manager-inline-btn danger" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php $rowNumber++; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
          <div class="reference-manager-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= e(administrator_reference_manager_url($config['base_path'], array_merge($persistedFilters, ['page' => $page - 1]))); ?>">Previous</a>
            <?php endif; ?>

            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
              <?php if ($pageNumber === $page): ?>
                <span class="active"><?= e((string) $pageNumber); ?></span>
              <?php else: ?>
                <a href="<?= e(administrator_reference_manager_url($config['base_path'], array_merge($persistedFilters, ['page' => $pageNumber]))); ?>"><?= e((string) $pageNumber); ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= e(administrator_reference_manager_url($config['base_path'], array_merge($persistedFilters, ['page' => $page + 1]))); ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-end reference-manager-drawer" tabindex="-1" id="referenceManagerDrawer" aria-labelledby="referenceManagerDrawerLabel">
    <div class="offcanvas-header reference-manager-drawer-header">
      <div class="reference-manager-drawer-main">
        <h3 class="reference-manager-drawer-title" id="referenceManagerDrawerLabel"><?= e($config['drawer_title']); ?></h3>
        <p class="reference-manager-drawer-copy"><?= e($config['drawer_copy']); ?></p>
      </div>

      <div class="reference-manager-drawer-header-actions">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
    </div>

    <div class="offcanvas-body reference-manager-drawer-body">
      <form method="post" action="<?= e($pageActionUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?= e($config['save_action']); ?>">
        <input
          type="hidden"
          name="<?= e($referenceManagerEntity . 'id'); ?>"
          value="<?= e((string) $selectedRecordId); ?>"
          data-drawer-id
        >

        <div class="reference-manager-drawer-form">
          <?php if ($referenceManagerEntity === 'campus'): ?>
            <label class="form-label-wrapper">
              <span class="form-label">Campus Name</span>
              <input
                class="form-input"
                type="text"
                name="campusname"
                maxlength="25"
                value="<?= e((string) ($selectedRecord['campusname'] ?? '')); ?>"
                data-drawer-field="campusname"
                placeholder="Example: Isulan"
                required
              >
              <span class="reference-manager-field-note">Keep campus names short and recognizable for the rest of the system.</span>
            </label>
          <?php elseif ($referenceManagerEntity === 'college'): ?>
            <label class="form-label-wrapper">
              <span class="form-label">College Name</span>
              <input
                class="form-input"
                type="text"
                name="collegename"
                maxlength="255"
                value="<?= e((string) ($selectedRecord['collegename'] ?? '')); ?>"
                data-drawer-field="collegename"
                placeholder="Example: College of Computer Studies"
                required
              >
            </label>

            <label class="form-label-wrapper">
              <span class="form-label">Campus</span>
              <select class="reference-manager-select" name="collegecampus" data-drawer-field="collegecampus" required>
                <option value="">Select campus</option>
                <?php foreach ($filterOptions['campuses'] as $campusOption): ?>
                  <?php $campusOptionId = isset($campusOption['campusid']) ? (int) $campusOption['campusid'] : 0; ?>
                  <option value="<?= e((string) $campusOptionId); ?>"<?= $campusOptionId === administrator_reference_manager_college_campus_id($selectedRecord ?? []) ? ' selected' : ''; ?>>
                    <?= e(administrator_reference_manager_campus_label($campusOption)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php else: ?>
            <label class="form-label-wrapper">
              <span class="form-label">Course Code</span>
              <input
                class="form-input"
                type="text"
                name="coursecode"
                maxlength="30"
                value="<?= e((string) ($selectedRecord['coursecode'] ?? '')); ?>"
                data-drawer-field="coursecode"
                placeholder="Example: BSIT"
                required
              >
            </label>

            <label class="form-label-wrapper">
              <span class="form-label">Course Description</span>
              <input
                class="form-input"
                type="text"
                name="coursedescription"
                maxlength="100"
                value="<?= e((string) ($selectedRecord['coursedescription'] ?? '')); ?>"
                data-drawer-field="coursedescription"
                placeholder="Example: Bachelor of Science in Information Technology"
                required
              >
            </label>

            <label class="form-label-wrapper">
              <span class="form-label">Course Major</span>
              <input
                class="form-input"
                type="text"
                name="coursemajor"
                maxlength="50"
                value="<?= e((string) ($selectedRecord['coursemajor'] ?? '')); ?>"
                data-drawer-field="coursemajor"
                placeholder="Optional major or track"
              >
              <span class="reference-manager-field-note">Leave blank when the course does not have a specific major or track.</span>
            </label>

            <label class="form-label-wrapper">
              <span class="form-label">College</span>
              <select class="reference-manager-select" name="coursecollege" data-drawer-field="coursecollege" required>
                <option value="">Select college</option>
                <?php foreach ($filterOptions['colleges'] as $collegeOption): ?>
                  <?php $collegeOptionId = isset($collegeOption['collegeid']) ? (int) $collegeOption['collegeid'] : 0; ?>
                  <option value="<?= e((string) $collegeOptionId); ?>"<?= $collegeOptionId === administrator_reference_manager_course_college_id($selectedRecord ?? []) ? ' selected' : ''; ?>>
                    <?= e(administrator_reference_manager_college_option_label($collegeOption)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="form-label-wrapper">
              <span class="form-label">Specification</span>
              <input
                class="form-input"
                type="text"
                name="specification"
                maxlength="15"
                value="<?= e((string) ($selectedRecord['specification'] ?? '0')); ?>"
                data-drawer-field="specification"
                placeholder="Example: 0"
              >
              <span class="reference-manager-field-note">This keeps the existing course specification value available for the current database structure.</span>
            </label>
          <?php endif; ?>
        </div>

        <div class="reference-manager-drawer-actions">
          <button class="primary-default-btn" type="submit"><?= e($drawerSubmitLabel); ?></button>
          <button class="secondary-default-btn" type="button" data-bs-dismiss="offcanvas">Close</button>
        </div>
      </form>
    </div>
  </div>
</main>
<?php
$mainContent = (string) ob_get_clean();

AdminPage::render([
    'title' => $config['title'],
    'current_page' => $config['current_page'],
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_scripts' => $extraScripts,
    'extra_styles' => $extraStyles,
]);
