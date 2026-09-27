<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireProfessor();
Database::ensureManuscriptTable();

$pdo = Database::connection();
$account = Auth::account() ?? [];
$accountId = (int) ($account['accountid'] ?? 0);
$accountName = trim((string) ($account['acc_name'] ?? 'Faculty Member'));
$accountEmail = normalize_email((string) ($account['email'] ?? ''));
$campusId = (int) ($account['campus'] ?? 0);
$programId = (int) ($account['programid'] ?? 0);
$error = null;
$success = get_flash('professor_success');

function professor_url(array $params = []): string
{
    $query = http_build_query($params, '', '&');
    return app_link('professor/') . ($query !== '' ? '?' . $query : '');
}

function professor_positive_int($value): int
{
    return is_scalar($value) && ctype_digit(trim((string) $value)) ? (int) $value : 0;
}

function professor_status(string $value): string
{
    return in_array($value, ['Proposal', 'On-going', 'Completed'], true) ? $value : 'Proposal';
}

function professor_uppercase(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function professor_sdg_options(): array
{
    return [1=>'No Poverty',2=>'Zero Hunger',3=>'Good Health and Well-Being',4=>'Quality Education',5=>'Gender Equality',6=>'Clean Water and Sanitation',7=>'Affordable and Clean Energy',8=>'Decent Work and Economic Growth',9=>'Industry, Innovation and Infrastructure',10=>'Reduced Inequalities',11=>'Sustainable Cities and Communities',12=>'Responsible Consumption and Production',13=>'Climate Action',14=>'Life Below Water',15=>'Life on Land',16=>'Peace, Justice and Strong Institutions',17=>'Partnerships for the Goals'];
}

function professor_integer_selection($value, array $allowed): array
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

function professor_account_selection($value): array
{
    $values = is_array($value) ? $value : [$value];
    $selected = [];
    foreach ($values as $item) {
        $accountId = professor_positive_int($item);
        if ($accountId > 0) {
            $selected[$accountId] = $accountId;
        }
    }
    return array_values($selected);
}

function professor_researcher_names($value): array
{
    $values = is_array($value) ? $value : preg_split('/[\r\n;]+/', (string) $value);
    $names = [];
    foreach ((array) $values as $item) {
        $name = professor_uppercase(trim((string) $item));
        if ($name === '') { continue; }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $names[$key] = $name;
    }
    return array_values($names);
}

function professor_academic_year_label(array $year): string
{
    $from = trim((string) ($year['ay_from'] ?? ''));
    $to = trim((string) ($year['ay_to'] ?? ''));
    if ($from !== '' && $to !== '') { return $from . ' - ' . $to; }
    return $from !== '' ? $from : ($to !== '' ? $to : 'Academic Year #' . (int) ($year['ayid'] ?? 0));
}

function professor_delete_abstract(?string $relativePath): void
{
    $normalized = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');
    if ($normalized === '' || strpos($normalized, 'uploads/manuscript_abstracts/') !== 0) { return; }
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($absolutePath)) { @unlink($absolutePath); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token(trim((string) ($_POST['csrf_token'] ?? '')))) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = trim((string) ($_POST['action'] ?? ''));
        $titleId = professor_positive_int($_POST['titleid'] ?? 0);

        if ($action === 'delete_personal_research') {
            if ($titleId < 1) {
                throw new RuntimeException('Select a valid personal research record to delete.');
            }
            $recordStatement = $pdo->prepare(
                'SELECT titleid, abstract_file_path
                 FROM tblresearches
                 WHERE titleid = :titleid AND owner_accountid = :owner
                 LIMIT 1'
            );
            $recordStatement->execute(['titleid' => $titleId, 'owner' => $accountId]);
            $deleteRecord = $recordStatement->fetch();
            if (!is_array($deleteRecord)) {
                throw new RuntimeException('That personal research record is not owned by your faculty account.');
            }

            $pdo->beginTransaction();
            try {
                $deletePanelists = $pdo->prepare('DELETE FROM tblmanuscript_panelists WHERE manuscriptid = :titleid');
                $deletePanelists->execute(['titleid' => $titleId]);
                $deleteCoauthors = $pdo->prepare('DELETE FROM tblmanuscript_coauthors WHERE manuscriptid = :titleid');
                $deleteCoauthors->execute(['titleid' => $titleId]);
                $deleteResearch = $pdo->prepare(
                    'DELETE FROM tblresearches WHERE titleid = :titleid AND owner_accountid = :owner LIMIT 1'
                );
                $deleteResearch->execute(['titleid' => $titleId, 'owner' => $accountId]);
                if ($deleteResearch->rowCount() < 1) {
                    throw new RuntimeException('The personal research record could not be deleted.');
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }

            professor_delete_abstract((string) ($deleteRecord['abstract_file_path'] ?? ''));
            set_flash('professor_success', 'Personal research record deleted.');
            redirect(professor_url(['view' => 'mine']));
        }

        if ($action === 'delete_student_research') {
            if ($titleId < 1) {
                throw new RuntimeException('Select a valid student research record to delete.');
            }
            $recordStatement = $pdo->prepare(
                'SELECT titleid, abstract_file_path
                 FROM tblresearches
                 WHERE titleid = :titleid AND adviser_accountid = :adviser AND owner_accountid IS NULL
                 LIMIT 1'
            );
            $recordStatement->execute(['titleid' => $titleId, 'adviser' => $accountId]);
            $deleteRecord = $recordStatement->fetch();
            if (!is_array($deleteRecord)) {
                throw new RuntimeException('That student research record is not assigned to your faculty account.');
            }

            $pdo->beginTransaction();
            try {
                $deletePanelists = $pdo->prepare('DELETE FROM tblmanuscript_panelists WHERE manuscriptid = :titleid');
                $deletePanelists->execute(['titleid' => $titleId]);
                $deleteCoauthors = $pdo->prepare('DELETE FROM tblmanuscript_coauthors WHERE manuscriptid = :titleid');
                $deleteCoauthors->execute(['titleid' => $titleId]);
                $deleteResearch = $pdo->prepare(
                    'DELETE FROM tblresearches
                     WHERE titleid = :titleid AND adviser_accountid = :adviser AND owner_accountid IS NULL
                     LIMIT 1'
                );
                $deleteResearch->execute(['titleid' => $titleId, 'adviser' => $accountId]);
                if ($deleteResearch->rowCount() < 1) {
                    throw new RuntimeException('The student research record could not be deleted.');
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }

            professor_delete_abstract((string) ($deleteRecord['abstract_file_path'] ?? ''));
            set_flash('professor_success', 'Student research record deleted.');
            redirect(professor_url(['view' => 'students']));
        }

        if ($action === 'save_student_research') {
            $title = professor_uppercase(trim((string) ($_POST['student_title'] ?? '')));
            $typeId = professor_positive_int($_POST['student_typeid'] ?? 0);
            $academicYearId = professor_positive_int($_POST['student_ayid'] ?? 0);
            $status = professor_status(trim((string) ($_POST['student_status'] ?? 'Proposal')));
            $abstract = trim((string) ($_POST['student_other_details'] ?? ''));
            $sdgCodes = professor_integer_selection($_POST['student_sdgs'] ?? [], professor_sdg_options());
            $studentResearchers = professor_researcher_names($_POST['student_researchers'] ?? []);
            $panelistIds = professor_account_selection($_POST['panelist_accountids'] ?? []);
            $statisticianId = professor_positive_int($_POST['statistician_accountid'] ?? 0);
            $englishCriticId = professor_positive_int($_POST['english_critic_accountid'] ?? 0);

            if ($title === '' || $typeId < 1) {
                throw new RuntimeException('Research title and research type are required.');
            }
            if ($campusId < 1) {
                throw new RuntimeException('Your faculty account does not have an assigned campus. Ask an administrator to update the account before adding student research.');
            }
            if ($programId < 1) {
                throw new RuntimeException('Your faculty account does not have an assigned program. Ask an administrator to update the account before adding student research.');
            }
            if ($studentResearchers === []) {
                throw new RuntimeException('Add at least one student researcher.');
            }
            if ($panelistIds === []) {
                throw new RuntimeException('Select at least one panelist.');
            }
            if ($statisticianId < 1) {
                throw new RuntimeException('Select a statistician.');
            }
            if ($englishCriticId < 1) {
                throw new RuntimeException('Select an English critic.');
            }
            if ($sdgCodes === []) {
                throw new RuntimeException('Select at least one Sustainable Development Goal.');
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

            $roleAccountIds = array_values(array_unique(array_merge($panelistIds, [$statisticianId, $englishCriticId])));
            $rolePlaceholders = implode(',', array_fill(0, count($roleAccountIds), '?'));
            $roleCheck = $pdo->prepare("SELECT accountid FROM tblaccount WHERE accountid IN ($rolePlaceholders) AND accountid <> ?");
            $roleCheck->execute(array_merge($roleAccountIds, [$accountId]));
            $validRoleIds = array_map('intval', $roleCheck->fetchAll(PDO::FETCH_COLUMN));
            $validRoleMap = array_fill_keys($validRoleIds, true);
            foreach ($roleAccountIds as $roleAccountId) {
                if (!isset($validRoleMap[$roleAccountId])) {
                    throw new RuntimeException('Select only valid faculty accounts for the review team.');
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
                if ($titleId > 0) {
                    $statement = $pdo->prepare(
                        'UPDATE tblresearches
                         SET title = :title, normalized_title = :normalized_title, typeid = :typeid,
                             campusid = :campusid, programid = :programid, ayid = :ayid, status = :status,
                             authors = :authors, other_details = :other_details, sdgs = :sdgs,
                             encoder = :encoder, adviser_name = NULL, adviser_accountid = :adviser_accountid,
                             panelists = NULL, panelist_accountids = :panelist_accountids,
                             statisticians = NULL, statistician_accountid = :statistician_accountid,
                             english_critic_name = NULL, english_critic_accountid = :english_critic_accountid,
                             owner_accountid = NULL, is_published = 1, similarity_refreshed_at = NULL
                         WHERE titleid = :titleid AND adviser_accountid = :owner_adviser AND owner_accountid IS NULL'
                    );
                    $statement->execute([
                        'title' => $title, 'normalized_title' => TitleSimilarity::normalizeTitle($title),
                        'typeid' => $typeId, 'campusid' => $campusId, 'programid' => $programId,
                        'ayid' => $academicYearId > 0 ? $academicYearId : null, 'status' => $status,
                        'authors' => $authors, 'other_details' => $abstract !== '' ? $abstract : null,
                        'sdgs' => $sdgs, 'encoder' => $accountId, 'adviser_accountid' => $accountId,
                        'panelist_accountids' => $panelistStorage, 'statistician_accountid' => $statisticianId,
                        'english_critic_accountid' => $englishCriticId, 'titleid' => $titleId,
                        'owner_adviser' => $accountId,
                    ]);
                    $ownershipCheck = $pdo->prepare('SELECT titleid FROM tblresearches WHERE titleid = :id AND adviser_accountid = :adviser AND owner_accountid IS NULL');
                    $ownershipCheck->execute(['id' => $titleId, 'adviser' => $accountId]);
                    if (!$ownershipCheck->fetchColumn()) {
                        throw new RuntimeException('That student research record is not managed by your faculty account.');
                    }
                    $message = 'Student research record updated.';
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO tblresearches
                            (title, normalized_title, typeid, campusid, programid, ayid, status, authors,
                             other_details, sdgs, encoder, adviser_accountid, panelist_accountids,
                             statistician_accountid, english_critic_accountid, owner_accountid, is_published)
                         VALUES
                            (:title, :normalized_title, :typeid, :campusid, :programid, :ayid, :status, :authors,
                             :other_details, :sdgs, :encoder, :adviser_accountid, :panelist_accountids,
                             :statistician_accountid, :english_critic_accountid, NULL, 1)'
                    );
                    $statement->execute([
                        'title' => $title, 'normalized_title' => TitleSimilarity::normalizeTitle($title),
                        'typeid' => $typeId, 'campusid' => $campusId, 'programid' => $programId,
                        'ayid' => $academicYearId > 0 ? $academicYearId : null, 'status' => $status,
                        'authors' => $authors, 'other_details' => $abstract !== '' ? $abstract : null,
                        'sdgs' => $sdgs, 'encoder' => $accountId, 'adviser_accountid' => $accountId,
                        'panelist_accountids' => $panelistStorage, 'statistician_accountid' => $statisticianId,
                        'english_critic_accountid' => $englishCriticId,
                    ]);
                    $titleId = (int) $pdo->lastInsertId();
                    $message = 'Student research record added.';
                }

                $clearPanelists = $pdo->prepare('DELETE FROM tblmanuscript_panelists WHERE manuscriptid = :manuscriptid');
                $clearPanelists->execute(['manuscriptid' => $titleId]);
                $addPanelist = $pdo->prepare('INSERT INTO tblmanuscript_panelists (manuscriptid, accountid) VALUES (:manuscriptid, :accountid)');
                foreach ($panelistIds as $panelistId) {
                    $addPanelist->execute(['manuscriptid' => $titleId, 'accountid' => $panelistId]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }

            set_flash('professor_success', $message);
            redirect(professor_url(['view' => 'students']));
        }

        if ($action === 'save') {
            $title = professor_uppercase(trim((string) ($_POST['title'] ?? '')));
            $typeId = professor_positive_int($_POST['typeid'] ?? 0);
            $selectedCampusId = professor_positive_int($_POST['campusid'] ?? $campusId);
            $selectedProgramId = professor_positive_int($_POST['programid'] ?? $programId);
            $status = professor_status(trim((string) ($_POST['status'] ?? 'Proposal')));
            $abstract = trim((string) ($_POST['other_details'] ?? ''));
            $sdgCodes = professor_integer_selection($_POST['sdgs'] ?? [], professor_sdg_options());
            $sdgs = implode(',', $sdgCodes);
            $requestedCoauthorIds = is_array($_POST['coauthor_accountids'] ?? null) ? $_POST['coauthor_accountids'] : [];
            $coauthorIds = [];
            foreach ($requestedCoauthorIds as $requestedId) {
                $candidateId = professor_positive_int($requestedId);
                if ($candidateId > 0 && $candidateId !== $accountId) { $coauthorIds[$candidateId] = $candidateId; }
            }

            $authorNames = [$accountName];
            if ($coauthorIds !== []) {
                $coauthorPlaceholders = implode(',', array_fill(0, count($coauthorIds), '?'));
                $coauthorCheck = $pdo->prepare(
                    "SELECT accountid, acc_name FROM tblaccount
                     WHERE accountid IN ($coauthorPlaceholders)
                       AND accountid <> ?"
                );
                $coauthorCheck->execute(array_merge(array_values($coauthorIds), [$accountId]));
                $validCoauthors = $coauthorCheck->fetchAll();
                $coauthorIds = [];
                foreach ($validCoauthors as $validCoauthor) {
                    $validId = (int) $validCoauthor['accountid'];
                    $coauthorIds[$validId] = $validId;
                    $authorNames[] = trim((string) $validCoauthor['acc_name']);
                }
            }
            $authors = implode(', ', array_filter($authorNames));

            if ($title === '' || $typeId < 1 || $selectedCampusId < 1) {
                throw new RuntimeException('Title, research type, and campus are required.');
            }
            if ($sdgCodes === []) {
                throw new RuntimeException('Select at least one Sustainable Development Goal.');
            }
            if (strlen($title) > 1000 || strlen($authors) > 3000 || strlen($abstract) > 10000 || strlen($sdgs) > 100) {
                throw new RuntimeException('One or more entries are longer than the allowed limit.');
            }

            if ($titleId > 0) {
                $statement = $pdo->prepare(
                    'UPDATE tblresearches
                     SET title = :title, normalized_title = :normalized_title, typeid = :typeid,
                         campusid = :campusid, programid = :programid, status = :status,
                         authors = :authors, other_details = :other_details, sdgs = :sdgs,
                         similarity_refreshed_at = NULL
                     WHERE titleid = :titleid AND owner_accountid = :owner_accountid'
                );
                $statement->execute([
                    'title' => $title, 'normalized_title' => TitleSimilarity::normalizeTitle($title),
                    'typeid' => $typeId, 'campusid' => $selectedCampusId,
                    'programid' => $selectedProgramId > 0 ? $selectedProgramId : null,
                    'status' => $status, 'authors' => $authors !== '' ? $authors : $accountName,
                    'other_details' => $abstract !== '' ? $abstract : null, 'sdgs' => $sdgs,
                    'titleid' => $titleId, 'owner_accountid' => $accountId,
                ]);
                if ($statement->rowCount() < 1) {
                    $check = $pdo->prepare('SELECT titleid FROM tblresearches WHERE titleid = :id AND owner_accountid = :owner');
                    $check->execute(['id' => $titleId, 'owner' => $accountId]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('That research record is not owned by your faculty account.');
                    }
                }
                $message = 'Your research record was updated.';
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO tblresearches
                        (title, normalized_title, typeid, campusid, programid, status, authors, other_details,
                         sdgs, encoder, owner_accountid, is_published)
                     VALUES
                        (:title, :normalized_title, :typeid, :campusid, :programid, :status, :authors,
                         :other_details, :sdgs, :encoder, :owner_accountid, 0)'
                );
                $statement->execute([
                    'title' => $title, 'normalized_title' => TitleSimilarity::normalizeTitle($title),
                    'typeid' => $typeId, 'campusid' => $selectedCampusId,
                    'programid' => $selectedProgramId > 0 ? $selectedProgramId : null,
                    'status' => $status, 'authors' => $authors !== '' ? $authors : $accountName,
                    'other_details' => $abstract !== '' ? $abstract : null, 'sdgs' => $sdgs,
                    'encoder' => $accountId, 'owner_accountid' => $accountId,
                ]);
                $titleId = (int) $pdo->lastInsertId();
                $message = 'Research saved as private. Publish it when it is ready for the repository.';
            }

            $clearCoauthors = $pdo->prepare('DELETE FROM tblmanuscript_coauthors WHERE manuscriptid = :manuscriptid');
            $clearCoauthors->execute(['manuscriptid' => $titleId]);
            $addCoauthor = $pdo->prepare('INSERT INTO tblmanuscript_coauthors (manuscriptid, accountid) VALUES (:manuscriptid, :accountid)');
            foreach ($coauthorIds as $coauthorId) {
                $addCoauthor->execute(['manuscriptid' => $titleId, 'accountid' => $coauthorId]);
            }

            set_flash('professor_success', $message);
            redirect(professor_url(['view' => 'mine']));
        }

        if ($action === 'visibility') {
            $published = (int) ($_POST['is_published'] ?? 0) === 1 ? 1 : 0;
            $statement = $pdo->prepare(
                'UPDATE tblresearches SET is_published = :published
                 WHERE titleid = :titleid AND owner_accountid = :owner_accountid'
            );
            $statement->execute(['published' => $published, 'titleid' => $titleId, 'owner_accountid' => $accountId]);
            if ($statement->rowCount() < 1) {
                throw new RuntimeException('The research record was not found or already has that visibility.');
            }
            set_flash('professor_success', $published ? 'Research published to the public repository.' : 'Research unpublished and returned to private view.');
            redirect(professor_url(['view' => 'mine']));
        }

        throw new RuntimeException('Unsupported action.');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$view = in_array((string) ($_GET['view'] ?? 'overview'), ['overview', 'mine', 'students', 'assignments'], true)
    ? (string) ($_GET['view'] ?? 'overview') : 'overview';
$editId = professor_positive_int($_GET['edit'] ?? 0);
$studentEditId = professor_positive_int($_GET['student_edit'] ?? 0);
$editRecord = null;
$studentEditRecord = null;
$failedAction = $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null ? trim((string) ($_POST['action'] ?? '')) : '';
$showResearchModal = isset($_GET['add']) || $editId > 0 || $failedAction === 'save';
$showStudentResearchModal = isset($_GET['student_add']) || $studentEditId > 0 || $failedAction === 'save_student_research';
if ($editId > 0) {
    $statement = $pdo->prepare('SELECT * FROM tblresearches WHERE titleid = :id AND owner_accountid = :owner LIMIT 1');
    $statement->execute(['id' => $editId, 'owner' => $accountId]);
    $editRecord = $statement->fetch() ?: null;
    $view = 'mine';
}
if ($studentEditId > 0) {
    $statement = $pdo->prepare('SELECT * FROM tblresearches WHERE titleid = :id AND adviser_accountid = :adviser AND owner_accountid IS NULL LIMIT 1');
    $statement->execute(['id' => $studentEditId, 'adviser' => $accountId]);
    $studentEditRecord = $statement->fetch() ?: null;
    $view = 'students';
}
$modalRecord = $editRecord ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null && trim((string) ($_POST['action'] ?? '')) === 'save') {
    $modalRecord = $_POST;
}
$studentModalRecord = $studentEditRecord ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null && trim((string) ($_POST['action'] ?? '')) === 'save_student_research') {
    $studentModalRecord = $_POST;
}

$types = $pdo->query('SELECT researchtypeid, research_type FROM tblresearchtype ORDER BY research_type')->fetchAll();
$campuses = $pdo->query('SELECT campusid, campusname FROM tblcampus ORDER BY campusname')->fetchAll();
$programs = $pdo->query('SELECT courseid, coursecode, coursedescription FROM tblcourse ORDER BY coursecode, coursedescription')->fetchAll();
$academicYears = $pdo->query('SELECT ayid, ay_from, ay_to FROM tblacademic_year ORDER BY ay_from DESC, ay_to DESC, ayid DESC')->fetchAll();
$facultyStatement = $pdo->prepare(
    "SELECT accountid, acc_name, email, is_enabled FROM tblaccount
     WHERE accountid <> :accountid
     ORDER BY is_enabled DESC, acc_name, email"
);
$facultyStatement->execute(['accountid' => $accountId]);
$facultyOptions = $facultyStatement->fetchAll();
$selectedCoauthorIds = [];
if ($editRecord) {
    $selectedCoauthorStatement = $pdo->prepare('SELECT accountid FROM tblmanuscript_coauthors WHERE manuscriptid = :id ORDER BY accountid');
    $selectedCoauthorStatement->execute(['id' => $editId]);
    $selectedCoauthorIds = array_map('intval', $selectedCoauthorStatement->fetchAll(PDO::FETCH_COLUMN));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    foreach ((array) ($_POST['coauthor_accountids'] ?? []) as $postedCoauthorId) {
        $selectedCoauthorIds[] = (int) $postedCoauthorId;
    }
}
$sdgOptions = professor_sdg_options();
$selectedSdgCodes = professor_integer_selection($modalRecord['sdgs'] ?? [], $sdgOptions);
$selectedStudentSdgCodes = professor_integer_selection($studentModalRecord['student_sdgs'] ?? ($studentModalRecord['sdgs'] ?? []), $sdgOptions);
$selectedStudentPanelistIds = [];
if ($studentEditRecord) {
    $selectedPanelistStatement = $pdo->prepare('SELECT accountid FROM tblmanuscript_panelists WHERE manuscriptid = :id ORDER BY accountid');
    $selectedPanelistStatement->execute(['id' => $studentEditId]);
    $selectedStudentPanelistIds = array_map('intval', $selectedPanelistStatement->fetchAll(PDO::FETCH_COLUMN));
} elseif ($failedAction === 'save_student_research') {
    $selectedStudentPanelistIds = professor_account_selection($_POST['panelist_accountids'] ?? []);
}
$selectedStatisticianId = professor_positive_int($studentModalRecord['statistician_accountid'] ?? 0);
$selectedEnglishCriticId = professor_positive_int($studentModalRecord['english_critic_accountid'] ?? 0);
$selectedStudentResearchers = professor_researcher_names($studentModalRecord['student_researchers'] ?? ($studentModalRecord['authors'] ?? []));
if ($selectedStudentResearchers === []) { $selectedStudentResearchers = ['']; }
$accountCampusName = 'Assigned campus unavailable';
foreach ($campuses as $campus) {
    if ((int) ($campus['campusid'] ?? 0) === $campusId) { $accountCampusName = trim((string) $campus['campusname']); break; }
}
$accountProgramName = 'Assigned program unavailable';
foreach ($programs as $program) {
    if ((int) ($program['courseid'] ?? 0) === $programId) {
        $accountProgramName = trim((string) ($program['coursecode'] . ' - ' . $program['coursedescription']), ' -');
        break;
    }
}

$mineStatement = $pdo->prepare(
    'SELECT DISTINCT m.*, rt.research_type, c.campusname, p.coursecode,
            CASE WHEN m.owner_accountid = :owner_check THEN 1 ELSE 0 END AS is_owner
     FROM tblresearches m
     LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
     LEFT JOIN tblcampus c ON c.campusid = m.campusid
     LEFT JOIN tblcourse p ON p.courseid = m.programid
     LEFT JOIN tblmanuscript_coauthors mc ON mc.manuscriptid = m.titleid
     WHERE m.owner_accountid = :accountid OR mc.accountid = :coauthor_accountid
     ORDER BY m.updated_at DESC, m.titleid DESC'
);
$mineStatement->execute(['owner_check' => $accountId, 'accountid' => $accountId, 'coauthor_accountid' => $accountId]);
$mine = $mineStatement->fetchAll();

$studentResearchStatement = $pdo->prepare(
    'SELECT m.*, rt.research_type, c.campusname, p.coursecode, ay.ay_from, ay.ay_to,
            statistician.acc_name AS statistician_name,
            critic.acc_name AS english_critic_name,
            panel.panelist_names,
            panel.panelist_count
     FROM tblresearches m
     LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
     LEFT JOIN tblcampus c ON c.campusid = m.campusid
     LEFT JOIN tblcourse p ON p.courseid = m.programid
     LEFT JOIN tblacademic_year ay ON ay.ayid = m.ayid
     LEFT JOIN tblaccount statistician ON statistician.accountid = m.statistician_accountid
     LEFT JOIN tblaccount critic ON critic.accountid = m.english_critic_accountid
     LEFT JOIN (
        SELECT mp.manuscriptid, COUNT(*) AS panelist_count,
               GROUP_CONCAT(a.acc_name ORDER BY a.acc_name SEPARATOR \', \') AS panelist_names
        FROM tblmanuscript_panelists mp
        INNER JOIN tblaccount a ON a.accountid = mp.accountid
        GROUP BY mp.manuscriptid
     ) panel ON panel.manuscriptid = m.titleid
     WHERE m.adviser_accountid = :accountid AND m.owner_accountid IS NULL
     ORDER BY m.updated_at DESC, m.titleid DESC'
);
$studentResearchStatement->execute(['accountid' => $accountId]);
$studentResearch = $studentResearchStatement->fetchAll();

// Account IDs are resolved from the signed-in email so assignments survive role changes.
$identityStatement = $pdo->prepare(
    "SELECT accountid FROM tblaccount WHERE REPLACE(LOWER(email), ' ', '') = :email"
);
$identityStatement->execute(['email' => $accountEmail]);
$identityIds = array_values(array_unique(array_map('intval', $identityStatement->fetchAll(PDO::FETCH_COLUMN))));
if ($identityIds === []) {
    $identityIds = [$accountId];
}
$placeholders = implode(',', array_fill(0, count($identityIds), '?'));
$assignmentSql =
    'SELECT DISTINCT m.*, rt.research_type, p.coursecode,
        CASE
          WHEN m.adviser_accountid IN (' . $placeholders . ') THEN \'Adviser\'
          WHEN m.statistician_accountid IN (' . $placeholders . ') THEN \'Statistician\'
          WHEN m.english_critic_accountid IN (' . $placeholders . ') THEN \'English Critic\'
          ELSE \'Panelist\'
        END AS faculty_role
     FROM tblresearches m
     LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
     LEFT JOIN tblcourse p ON p.courseid = m.programid
     LEFT JOIN tblmanuscript_panelists mp ON mp.manuscriptid = m.titleid
     WHERE m.adviser_accountid IN (' . $placeholders . ')
        OR m.statistician_accountid IN (' . $placeholders . ')
        OR m.english_critic_accountid IN (' . $placeholders . ')
        OR mp.accountid IN (' . $placeholders . ')
     ORDER BY m.updated_at DESC, m.titleid DESC';
$assignmentParams = array_merge($identityIds, $identityIds, $identityIds, $identityIds, $identityIds, $identityIds, $identityIds);
$assignmentStatement = $pdo->prepare($assignmentSql);
$assignmentStatement->execute($assignmentParams);
$assignments = $assignmentStatement->fetchAll();

$publishedCount = count(array_filter($mine, static function (array $row): bool {
    return (int) ($row['is_published'] ?? 0) === 1;
}));
$completedCount = count(array_filter($mine, static function (array $row): bool {
    return strcasecmp((string) ($row['status'] ?? ''), 'Completed') === 0;
}));
$proposalCount = 0;
$ongoingCount = 0;
$privateCount = 0;
$withAbstractCount = 0;
foreach ($mine as $researchRow) {
    $researchStatus = strtolower(trim((string) ($researchRow['status'] ?? '')));
    if ($researchStatus === 'proposal' || $researchStatus === 'pending') { $proposalCount++; }
    if ($researchStatus === 'on-going' || $researchStatus === 'ongoing') { $ongoingCount++; }
    if ((int) ($researchRow['is_published'] ?? 0) !== 1) { $privateCount++; }
    if (trim((string) ($researchRow['other_details'] ?? '')) !== '' || trim((string) ($researchRow['abstract_file_path'] ?? '')) !== '') { $withAbstractCount++; }
}
$publicationRate = count($mine) > 0 ? (int) round(($publishedCount / count($mine)) * 100) : 0;
$abstractRate = count($mine) > 0 ? (int) round(($withAbstractCount / count($mine)) * 100) : 0;
$roleCounts = ['Adviser' => 0, 'Panelist' => 0, 'Statistician' => 0, 'English Critic' => 0];
foreach ($assignments as $assignment) {
    $role = (string) ($assignment['faculty_role'] ?? 'Panelist');
    if (isset($roleCounts[$role])) { $roleCounts[$role]++; }
}

?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Faculty Research Workspace</title>
<link rel="icon" href="<?= e(app_link('assets/img/favicon/oracle-favicon.png')); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_link('assets/vendor/fonts/boxicons.css')); ?>">
<link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/core.css')); ?>">
<link rel="stylesheet" href="<?= e(app_link('assets/vendor/libs/select2/select2.min.css')); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
:root{--green:#166534;--soft:#f0fdf4;--line:#e5e7eb;--ink:#17211a;--muted:#647067}*{box-sizing:border-box}body{margin:0;background:#f6f8f7;color:var(--ink);font-family:Public Sans,Segoe UI,sans-serif}.shell{min-height:100vh;display:grid;grid-template-columns:280px 1fr}.side{position:sticky;top:0;height:100vh;padding:20px 16px;background:#fff;border-right:1px solid var(--line);overflow:auto}.brand{display:flex;gap:12px;align-items:center;padding:10px;margin-bottom:20px;color:var(--ink);text-decoration:none}.brand img{width:44px;height:44px;border-radius:12px}.brand strong,.brand span{display:block}.brand span{font-size:12px;color:var(--muted)}.nav{display:grid;gap:10px}.nav a{display:flex;align-items:flex-start;gap:10px;padding:13px;border:1px solid var(--line);border-radius:12px;color:#36413a;text-decoration:none}.nav a i{display:grid;place-items:center;flex:0 0 36px;height:36px;border-radius:10px;background:#ecfdf5;color:#15803d;font-size:18px}.nav-copy strong,.nav-copy small{display:block}.nav-copy strong{font-size:14px}.nav-copy small{margin-top:2px;color:var(--muted);font-size:11px;line-height:1.35}.nav a.active,.nav a:hover{background:var(--soft);border-color:#bbf7d0;color:var(--green)}.logout{margin-top:20px}.logout button{width:100%;padding:11px;border:1px solid var(--line);border-radius:9px;background:white}.main{min-width:0}.top{position:sticky;top:0;z-index:20;display:flex;justify-content:space-between;gap:20px;padding:20px 30px;background:#f6f8f7ee;backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}.top h1{margin:0;font-size:23px}.top p{margin:5px 0 0;color:var(--muted)}.identity{text-align:right}.content{padding:28px 30px}.hero{padding:26px;border-radius:18px;background:linear-gradient(125deg,#14532d,#15803d);color:#fff}.hero h2{margin:0 0 8px;font-size:28px}.hero p{max-width:760px;margin:0;color:#dcfce7}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}.card{padding:18px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 5px 18px #1020180b}.stat-head{display:flex;justify-content:space-between;gap:10px}.stat-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--soft);color:#15803d;font-size:19px}.stat b{display:block;font-size:27px}.stat span{color:var(--muted);font-size:13px}.section-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:24px 0 12px}.section-head h2{margin:0;font-size:20px}.section-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border:0;border-radius:9px;background:var(--green);color:#fff;text-decoration:none;font-weight:700}.btn.secondary{background:#eef2ef;color:#37413a}.btn.warn{background:#fff7ed;color:#9a3412}.grid{display:grid;gap:12px}.record{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center}.record h3{margin:0 0 7px;font-size:17px}.meta{display:flex;flex-wrap:wrap;gap:7px;color:var(--muted);font-size:13px}.pill{padding:4px 8px;border-radius:999px;background:#f1f5f2}.pill.live{background:#dcfce7;color:#166534}.pill.private{background:#fef3c7;color:#92400e}.actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.actions form{margin:0}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field{display:grid;gap:6px}.field.full{grid-column:1/-1}.field label{font-weight:700;font-size:13px}.field input,.field select,.field textarea{width:100%;padding:11px;border:1px solid #d7ddd9;border-radius:9px;background:#fff}.field textarea{min-height:130px;resize:vertical}.alert{padding:13px 15px;border-radius:10px;margin-bottom:16px}.alert.ok{background:#dcfce7;color:#166534}.alert.err{background:#fee2e2;color:#991b1b}.empty{text-align:center;padding:42px;color:var(--muted)}.analytics{display:grid;grid-template-columns:1.4fr 1fr;gap:16px}.analytics h3{margin:0 0 18px;font-size:17px}.bar-row{display:grid;grid-template-columns:110px 1fr 36px;gap:10px;align-items:center;margin:13px 0;font-size:13px}.bar-track{height:9px;border-radius:99px;background:#edf1ee;overflow:hidden}.bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#15803d,#4ade80)}.role-list{display:grid;gap:12px}.role-line{display:flex;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid #edf0ee}.modal-content{border:0;border-radius:16px}.modal-header{padding:20px 24px}.modal-body{padding:22px 24px;max-height:70vh;overflow:auto}.modal-footer{padding:16px 24px}.modal-title-copy{margin:4px 0 0;color:var(--muted);font-size:13px}@media(max-width:900px){.shell{display:block}.side{position:static;height:auto;border-right:0;border-bottom:1px solid var(--line)}.nav{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}.stats{grid-template-columns:repeat(2,1fr)}.analytics{grid-template-columns:1fr}}@media(max-width:600px){.content,.top{padding:18px}.top,.record{display:block}.identity{text-align:left;margin-top:10px}.nav,.stats,.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.actions{justify-content:flex-start;margin-top:14px}.bar-row{grid-template-columns:85px 1fr 30px}}
.research-card{overflow:hidden;padding:0;border:1px solid var(--line);border-radius:15px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.05);transition:.2s}.research-card:hover{border-color:#bbf7d0;box-shadow:0 16px 34px rgba(15,23,42,.08)}.research-card-shell{display:grid;grid-template-columns:72px minmax(0,1fr);gap:20px;padding:20px}.research-avatar{width:68px;height:68px;display:grid;place-items:center;border:1px solid #bbf7d0;border-radius:50%;background:#ecfdf5;color:#15803d;font-size:29px}.research-main{min-width:0}.research-top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.research-author{margin:0 0 5px;color:#374151;font-size:14px;font-weight:700}.research-title{margin:0;color:#243029;font-size:21px;line-height:1.3}.research-subtitle{margin:6px 0 0;color:var(--muted);font-size:13px}.research-metrics{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.research-metric{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:8px;background:#f4f7f5;color:#405047;font-size:12px;font-weight:700}.research-metric i{color:#15803d;font-size:15px}.research-summary{margin:0;color:#56625a;font-size:14px;line-height:1.65}.research-summary.empty{font-style:italic;color:#8a938d}.research-footer{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:17px;padding-top:15px;border-top:1px solid #edf0ee}.research-label{margin-bottom:3px;color:#89918c;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.research-value{color:#334139;font-size:13px;font-weight:700}.research-card .actions{flex:0 0 auto}@media(max-width:700px){.research-card-shell{grid-template-columns:1fr}.research-avatar{width:54px;height:54px}.research-top{display:block}.research-card .actions{justify-content:flex-start;margin-top:14px}.research-footer{grid-template-columns:1fr}}
.research-author{margin:0 0 .25rem;color:#374151;font-family:"Public Sans","Segoe UI",Arial,sans-serif;font-size:.94rem;font-weight:600;line-height:1.35}.research-title{margin:0;color:#283027;font-family:"Space Grotesk","Public Sans",sans-serif;font-size:clamp(1.12rem,1.2vw,1.4rem);font-weight:700;letter-spacing:-.04em;line-height:1.3;text-transform:uppercase}.research-subtitle{margin:.35rem 0 0;color:#6b7280;font-size:.82rem}.research-metric{background:#f0fdf4;color:#14532d}.research-summary{color:#4b5563;font-size:.9rem;line-height:1.65}.research-label{color:#6b7280}.research-value{color:#283027}.field input[name="title"]{text-transform:uppercase}
.hero{border:1px solid #d1fae5;background:radial-gradient(circle at 92% 12%,rgba(34,197,94,.11),transparent 34%),linear-gradient(135deg,#f8fffa 0%,#f0fdf4 56%,#ecfeff 100%);color:#111827;box-shadow:0 18px 38px rgba(76,71,62,.05)}.hero h2{color:#283027;font-family:"Space Grotesk","Public Sans",sans-serif;letter-spacing:-.04em}.hero p{color:#4b5563;line-height:1.65}.btn{background:#66735f}.btn:hover{background:#566250;color:#fff}.nav a i,.stat-icon{background:rgba(123,136,115,.12);color:#66735f}.nav a.active,.nav a:hover{border-color:rgba(123,136,115,.32);background:rgba(123,136,115,.1);color:#465140}.bar-fill{background:linear-gradient(90deg,#7b8873,#a8b097)}.research-avatar{border-color:rgba(123,136,115,.28);background:rgba(123,136,115,.11);color:#66735f}.research-metric{background:rgba(123,136,115,.1);color:#55624d}.research-metric i{color:#66735f}.pill.live{background:rgba(123,136,115,.14);color:#4b5945}
:root{--sksu-green:#00a651;--sksu-green-dark:#007f3d;--sksu-green-soft:#e9fff3;--sksu-orange:#f58220;--sksu-orange-dark:#c85d08;--sksu-orange-soft:#fff3e8}.hero{border-color:rgba(0,166,81,.22);background:radial-gradient(circle at 88% 16%,rgba(245,130,32,.15),transparent 30%),radial-gradient(circle at 8% 0,rgba(0,166,81,.13),transparent 34%),linear-gradient(135deg,#fbfffc 0%,#effcf5 58%,#fff8f1 100%)}.hero:before{content:"";display:block;width:3.25rem;height:.28rem;margin-bottom:1rem;border-radius:999px;background:linear-gradient(90deg,var(--sksu-green),var(--sksu-orange))}.hero h2{color:#173d2b}.btn{background:var(--sksu-green)}.btn:hover{background:var(--sksu-green-dark);color:#fff}.btn.warn{border:1px solid #fed7aa;background:var(--sksu-orange-soft);color:var(--sksu-orange-dark)}.btn.warn:hover{background:var(--sksu-orange);color:#fff}.nav a i,.stat-icon{background:var(--sksu-green-soft);color:var(--sksu-green-dark)}.nav a.active,.nav a:hover{border-color:rgba(0,166,81,.28);background:linear-gradient(135deg,var(--sksu-green-soft),#fffaf5);color:var(--sksu-green-dark)}.nav a.active{box-shadow:inset 3px 0 0 var(--sksu-orange)}.stats .stat:nth-child(even) .stat-icon{background:var(--sksu-orange-soft);color:var(--sksu-orange-dark)}.bar-fill{background:linear-gradient(90deg,var(--sksu-green),#50c878)}.bar-row:nth-child(4n) .bar-fill,.bar-row:nth-child(5n) .bar-fill{background:linear-gradient(90deg,var(--sksu-orange),#ffad5c)}.research-card:hover{border-color:rgba(0,166,81,.34)}.research-avatar{border-color:rgba(0,166,81,.24);background:var(--sksu-green-soft);color:var(--sksu-green-dark)}.research-author{color:var(--sksu-orange-dark)}.research-metric{background:var(--sksu-green-soft);color:#17633d}.research-metric i{color:var(--sksu-green-dark)}.research-metric:nth-child(3){background:var(--sksu-orange-soft);color:var(--sksu-orange-dark)}.research-metric:nth-child(3) i{color:var(--sksu-orange-dark)}.pill.live{background:var(--sksu-green-soft);color:var(--sksu-green-dark)}.pill.private{background:var(--sksu-orange-soft);color:var(--sksu-orange-dark)}.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--sksu-green);box-shadow:0 0 0 .18rem rgba(0,166,81,.12);outline:0}.select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--sksu-green)}
</style>
<style>
.research-entry-modal .select2-container{width:100%!important}
.research-entry-modal .select2-container .select2-selection{border:1px solid #d7ddd9;border-radius:9px;background:#fff;transition:border-color .15s,box-shadow .15s}
.research-entry-modal .select2-container--default.select2-container--focus .select2-selection,.research-entry-modal .select2-container--default.select2-container--open .select2-selection{border-color:var(--sksu-green);box-shadow:0 0 0 .18rem rgba(0,166,81,.12)}
.research-entry-modal .select2-container .select2-selection--single{height:44px;padding:7px 34px 7px 12px}
.research-entry-modal .select2-container .select2-selection--single .select2-selection__rendered{padding:0;color:#17211a;line-height:28px}
.research-entry-modal .select2-container .select2-selection--single .select2-selection__arrow{height:42px;right:8px}
.research-entry-modal .modal-header{border-bottom:0;border-radius:16px 16px 0 0;background:#0f6b45;color:#fff}
.research-entry-modal .modal-header .modal-title{color:#fff}
.research-entry-modal .modal-header .modal-title-copy{color:#d9f5e7}
.research-entry-modal .modal-close-button{display:grid;place-items:center;flex:0 0 38px;width:38px;height:38px;border:0;border-radius:8px;background:rgba(255,255,255,.13);color:#fff;font-size:24px}
.research-entry-modal .modal-close-button:hover{background:rgba(255,255,255,.22)}
.research-entry-modal .select2-container .select2-selection--multiple{display:flex;align-items:center;min-height:44px;padding:4px 10px;cursor:text}
.research-entry-modal .select2-container--default .select2-selection--multiple .select2-selection__rendered{display:flex;align-items:center;flex-wrap:wrap;width:100%;min-width:0;margin:0;padding:0;gap:4px}
.research-entry-modal .select2-container .select2-selection--multiple .select2-selection__choice{margin:3px 5px 3px 0;border:0;border-radius:6px;background:var(--sksu-green-soft);color:var(--sksu-green-dark)}
.research-entry-modal .select2-container--default .select2-selection--multiple .select2-search--inline{flex:1 1 240px;min-width:180px;margin:0}
.research-entry-modal .select2-container--default .select2-selection--multiple .select2-search__field{width:100%!important;height:32px;margin:0;padding:3px 0;border:0!important;box-shadow:none!important;color:#17211a;line-height:26px}
.research-entry-modal .select2-container--default .select2-selection--multiple .select2-search__field::placeholder{color:#6b7280;opacity:1}
.student-research-modal .modal-header{background:#155e75}.student-research-modal .modal-header .modal-title-copy{color:#cffafe}.swal2-container{z-index:2000!important}
.account-assignment{display:flex;align-items:flex-start;gap:11px;padding:12px 14px;border:1px solid #bae6fd;border-radius:9px;background:#f0f9ff;color:#164e63}.account-assignment i{font-size:21px}.account-assignment strong,.account-assignment span{display:block}.account-assignment span{margin-top:2px;color:#4b6772;font-size:12px}
.student-researchers{display:grid;gap:8px}.student-researcher-row{display:grid;grid-template-columns:minmax(0,1fr) 40px;gap:8px}.student-researcher-row input{text-transform:uppercase}.student-researcher-remove{display:grid;place-items:center;width:40px;height:42px;border:1px solid #fecaca;border-radius:8px;background:#fff;color:#b42318;font-size:19px}.student-researcher-remove:disabled{cursor:not-allowed;opacity:.35}.add-student-button{width:max-content;border:1px solid #bae6fd;background:#f0f9ff;color:#155e75}.add-student-button:hover{background:#e0f2fe;color:#155e75}.student-team-note{margin:0;color:var(--muted);font-size:12px}.research-footer.student-footer{grid-template-columns:repeat(2,minmax(0,1fr))}.student-team-value{font-weight:500;line-height:1.5}.student-team-value b{color:#155e75}.btn.danger{border:1px solid #fecaca;background:#fff1f2;color:#b42318}.btn.danger:hover{background:#b42318;color:#fff}
.select2-dropdown{border-color:#d7ddd9;border-radius:9px;overflow:hidden;box-shadow:0 12px 28px rgba(15,23,42,.13)}
.select2-search--dropdown{padding:8px}.select2-search--dropdown .select2-search__field{border:1px solid #cdd6d0!important;border-radius:7px;padding:8px!important;outline:0}.select2-search--dropdown .select2-search__field:focus{border-color:var(--sksu-green)!important;box-shadow:0 0 0 .16rem rgba(0,166,81,.12)}
.sdg-field-header{display:flex;align-items:center;justify-content:space-between;gap:12px}.sdg-count{color:var(--muted);font-size:12px;font-weight:700}.sdg-picker-trigger{width:max-content;min-height:44px;padding:10px 14px;border:1px solid var(--sksu-green);border-radius:9px;background:#fff;color:var(--sksu-green-dark);font-weight:800}.sdg-picker-trigger:hover{background:var(--sksu-green-soft)}.sdg-picker-trigger i{font-size:19px;vertical-align:-2px}.sdg-selected-summary{display:flex;flex-wrap:wrap;gap:7px;min-height:20px}.sdg-selected-item{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:7px;background:#f2f6f3;color:#34443a;font-size:12px;font-weight:700}.sdg-selected-item b{color:var(--sksu-green-dark)}.sdg-empty{color:var(--muted);font-size:13px}.sdg-field-error{display:none;margin:0;color:#b42318;font-size:12px;font-weight:700}.sdg-field-error.visible{display:block}
body.sdg-picker-open{overflow:hidden}.sdg-picker-modal[hidden]{display:none}.sdg-picker-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:24px}.sdg-picker-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.58);backdrop-filter:blur(3px)}.sdg-picker-dialog{position:relative;display:flex;flex-direction:column;width:min(1120px,100%);max-height:calc(100dvh - 48px);overflow:hidden;border-radius:14px;background:#fff;box-shadow:0 28px 80px rgba(0,0,0,.28)}.sdg-picker-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:20px 22px 15px;border-bottom:1px solid var(--line)}.sdg-picker-header h2{margin:0;color:#173d2b;font-size:22px}.sdg-picker-header p{margin:4px 0 0;color:var(--muted);font-size:13px}.sdg-icon-button{display:grid;place-items:center;flex:0 0 38px;width:38px;height:38px;border:0;border-radius:8px;background:#f3f5f4;color:#445149;font-size:22px}.sdg-icon-button:hover{background:#e8eeea}.sdg-picker-tools{display:flex;align-items:center;gap:12px;padding:13px 22px;border-bottom:1px solid var(--line);background:#fafcfb}.sdg-search{position:relative;flex:1}.sdg-search i{position:absolute;top:50%;left:12px;transform:translateY(-50%);color:#68746c;font-size:19px}.sdg-search input{width:100%;height:42px;padding:9px 12px 9px 40px;border:1px solid #cfd8d2;border-radius:8px;background:#fff}.sdg-search input:focus{border-color:var(--sksu-green);box-shadow:0 0 0 .18rem rgba(0,166,81,.12);outline:0}.sdg-picker-counter{white-space:nowrap;color:#3e4b43;font-size:13px;font-weight:800}.sdg-picker-body{overflow:auto;padding:20px 22px}.sdg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:14px}.sdg-option{position:relative;display:block;cursor:pointer}.sdg-option[hidden]{display:none}.sdg-option input{position:absolute;opacity:0;pointer-events:none}.sdg-tile{position:relative;overflow:hidden;border:3px solid transparent;border-radius:8px;background:#f3f4f6;transition:transform .15s,border-color .15s,box-shadow .15s}.sdg-tile img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover}.sdg-check{position:absolute;top:7px;right:7px;display:grid;place-items:center;width:27px;height:27px;border:2px solid #fff;border-radius:50%;background:rgba(17,24,39,.72);color:#fff;font-size:18px;opacity:0;transform:scale(.8);transition:.15s}.sdg-option:hover .sdg-tile{transform:translateY(-2px);box-shadow:0 9px 20px rgba(15,23,42,.18)}.sdg-option input:focus-visible+.sdg-tile{outline:3px solid rgba(0,166,81,.3);outline-offset:2px}.sdg-option input:checked+.sdg-tile{border-color:#111827;box-shadow:0 0 0 2px #fff,0 0 0 5px var(--sksu-green)}.sdg-option input:checked+.sdg-tile .sdg-check{opacity:1;transform:scale(1)}.sdg-picker-empty{display:none;padding:36px;text-align:center;color:var(--muted)}.sdg-picker-empty.visible{display:block}.sdg-picker-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 22px;border-top:1px solid var(--line);background:#fff}.sdg-source{max-width:560px;margin:0;color:#6b746e;font-size:11px;line-height:1.45}.sdg-source a{color:var(--sksu-green-dark);font-weight:700}.sdg-picker-actions{display:flex;align-items:center;gap:8px}.sdg-clear{border:0;background:transparent;color:#526159;font-weight:700}.sdg-apply:disabled{cursor:not-allowed;opacity:.48}
@media(max-width:700px){.sdg-picker-modal{padding:0;place-items:stretch}.sdg-picker-dialog{width:100%;height:100dvh;max-height:none;border-radius:0}.sdg-picker-header{padding:16px}.sdg-picker-tools{align-items:stretch;flex-direction:column;padding:12px 16px}.sdg-picker-counter{align-self:flex-start}.sdg-picker-body{padding:16px}.sdg-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.sdg-picker-footer{align-items:stretch;flex-direction:column;padding:12px 16px}.sdg-source{max-width:none}.sdg-picker-actions{justify-content:flex-end}.sdg-picker-trigger{width:100%}.research-footer.student-footer{grid-template-columns:1fr}}
@media(max-width:420px){.sdg-grid{gap:8px}.sdg-picker-body{padding:12px}.sdg-picker-header h2{font-size:19px}.sdg-picker-actions .btn{padding:9px 11px}}
</style></head><body><div class="shell">
<aside class="side"><a class="brand" href="<?= e(professor_url()); ?>"><img src="<?= e(app_link('assets/img/branding/oracle-logo.png')); ?>" alt=""><div><strong>Oracle</strong><span>Faculty Research</span></div></a>
<nav class="nav" aria-label="Faculty navigation">
<a class="<?= $view === 'overview' ? 'active' : ''; ?>" href="<?= e(professor_url()); ?>"><i class="bx bx-line-chart"></i><span class="nav-copy"><strong>Dashboard</strong><small>Research portfolio and academic service</small></span></a>
<a class="<?= $view === 'mine' ? 'active' : ''; ?>" href="<?= e(professor_url(['view'=>'mine'])); ?>"><i class="bx bx-book"></i><span class="nav-copy"><strong>My Research</strong><small>Create, publish, and maintain your work</small></span></a>
<a class="<?= $view === 'students' ? 'active' : ''; ?>" href="<?= e(professor_url(['view'=>'students'])); ?>"><i class="bx bx-user-voice"></i><span class="nav-copy"><strong>Student Research</strong><small>Add and manage advisee research records</small></span></a>
<a class="<?= $view === 'assignments' ? 'active' : ''; ?>" href="<?= e(professor_url(['view'=>'assignments'])); ?>"><i class="bx bx-group"></i><span class="nav-copy"><strong>Research Assignments</strong><small>Adviser and review-team responsibilities</small></span></a>
</nav><form class="logout" method="post" action="<?= e(app_link('logout.php')); ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>"><button><i class="bx bx-power-off"></i> Log out</button></form></aside>
<main class="main"><header class="top"><div><h1>Faculty Research Portal</h1><p>Research portfolio and academic service management</p></div><div class="identity"><strong><?= e($accountName); ?></strong><p><?= e($accountEmail); ?></p></div></header><div class="content">
<?php if ($view === 'overview'): ?>
<section class="hero"><h2>Research Portfolio</h2><p>A consolidated view of research progress, publication readiness, and academic service assignments.</p></section>
<div class="stats"><div class="card stat"><div class="stat-head"><div><b><?= count($mine); ?></b><span>Total research records</span></div><span class="stat-icon"><i class="bx bx-collection"></i></span></div></div><div class="card stat"><div class="stat-head"><div><b><?= $publishedCount; ?></b><span>Publicly available · <?= $publicationRate; ?>%</span></div><span class="stat-icon"><i class="bx bx-world"></i></span></div></div><div class="card stat"><div class="stat-head"><div><b><?= $completedCount; ?></b><span>Completed research</span></div><span class="stat-icon"><i class="bx bx-check-circle"></i></span></div></div><div class="card stat"><div class="stat-head"><div><b><?= count($assignments); ?></b><span>Academic service assignments</span></div><span class="stat-icon"><i class="bx bx-group"></i></span></div></div></div>
<div class="analytics"><section class="card"><h3>Research Status</h3><?php foreach ([['Proposal',$proposalCount],['In Progress',$ongoingCount],['Completed',$completedCount],['Public',$publishedCount],['Internal',$privateCount]] as $metric): $width=count($mine)>0?(int)round(($metric[1]/count($mine))*100):0; ?><div class="bar-row"><span><?= e($metric[0]); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?= $width; ?>%"></div></div><strong><?= $metric[1]; ?></strong></div><?php endforeach; ?><p class="meta">Record completeness: <?= $abstractRate; ?>% · <?= $withAbstractCount; ?> of <?= count($mine); ?> records include research details</p></section><section class="card"><h3>Academic Service</h3><div class="role-list"><?php foreach($roleCounts as $label=>$count): ?><div class="role-line"><span><?= e($label); ?></span><strong><?= $count; ?></strong></div><?php endforeach; ?></div><div class="section-head"><div><h2>Research Management</h2><p>Maintain your faculty research portfolio.</p></div></div><a class="btn" href="<?= e(professor_url(['view'=>'mine','add'=>1])); ?>"><i class="bx bx-plus"></i>Add Research</a></section></div>
<div class="section-head"><div><h2>Recent Research Activity</h2><p>Latest updates from your research portfolio.</p></div><a class="btn secondary" href="<?= e(professor_url(['view'=>'mine'])); ?>">View All Research</a></div><div class="grid"><?php foreach(array_slice($mine,0,5) as $record): ?><article class="card record"><div><h3><?= e($record['title']); ?></h3><div class="meta"><span class="pill"><?= e((string)($record['status']??'Pending')); ?></span><span class="pill <?= (int)$record['is_published']===1?'live':'private'; ?>"><?= (int)$record['is_published']===1?'Public':'Internal'; ?></span><span>Updated <?= e(date('M j, Y', strtotime((string)$record['updated_at']))); ?></span></div></div></article><?php endforeach; ?><?php if(!$mine): ?><div class="card empty">No research records are available.</div><?php endif; ?></div>
<?php else:
  if ($view === 'assignments') {
      $records = $assignments;
      $sectionTitle = 'Research Assignments';
      $sectionCopy = 'Adviser and review-team responsibilities assigned to your faculty account.';
      $emptyCopy = 'No adviser, panelist, statistician, or English critic assignments match this account yet.';
  } elseif ($view === 'students') {
      $records = $studentResearch;
      $sectionTitle = 'Student Research';
      $sectionCopy = 'Add and maintain research studies conducted by students under your advisership.';
      $emptyCopy = 'You have not added a student research record yet.';
  } else {
      $records = $mine;
      $sectionTitle = 'My Research';
      $sectionCopy = 'Manage personal research proposals, active studies, completed work, and publication visibility.';
      $emptyCopy = 'You have not added personal research yet.';
  }
?>
<div class="section-head"><div><h2><?= e($sectionTitle); ?></h2><p><?= e($sectionCopy); ?></p></div><?php if($view==='mine'): ?><button class="btn" type="button" data-bs-toggle="modal" data-bs-target="#researchModal"><i class="bx bx-plus"></i>Add Personal Research</button><?php elseif($view==='students'): ?><button class="btn" type="button" data-bs-toggle="modal" data-bs-target="#studentResearchModal"><i class="bx bx-plus"></i>Add Student Research</button><?php endif; ?></div>
<div class="grid">
<?php if(!$records): ?><div class="card empty"><i class="bx bx-folder-open bx-lg"></i><p><?= e($emptyCopy); ?></p></div><?php endif; ?>
<?php foreach($records as $record): ?>
<?php
  $recordId = (int) ($record['titleid'] ?? 0);
  $recordAuthors = trim((string) ($record['authors'] ?? ''));
  $recordAuthors = $recordAuthors !== '' ? $recordAuthors : 'Authors not listed';
  if ($view === 'students') { $recordAuthors = professor_uppercase($recordAuthors); }
  $recordType = trim((string) ($record['research_type'] ?? 'Research record'));
  $recordStatus = trim((string) ($record['status'] ?? 'Pending'));
  $recordDate = !empty($record['updated_at']) ? date('M j, Y', strtotime((string) $record['updated_at'])) : 'Date unavailable';
  $recordYear = !empty($record['submitted_at']) ? date('Y', strtotime((string) $record['submitted_at'])) : '—';
  if ($view === 'students' && (trim((string) ($record['ay_from'] ?? '')) !== '' || trim((string) ($record['ay_to'] ?? '')) !== '')) {
      $recordYear = trim((string) ($record['ay_from'] ?? '')) . ' - ' . trim((string) ($record['ay_to'] ?? ''));
      $recordYear = trim($recordYear, ' -');
  }
  $recordSdgs = professor_integer_selection($record['sdgs'] ?? [], professor_sdg_options());
  $recordSdgLabel = $recordSdgs !== [] ? implode(', ', array_map(static function ($code) { return 'SDG ' . $code; }, $recordSdgs)) : 'SDG not set';
  $recordSummary = trim((string) ($record['other_details'] ?? ''));
  if (strlen($recordSummary) > 360) { $recordSummary = substr($recordSummary, 0, 357) . '...'; }
  $recordProgram = trim((string) ($record['coursecode'] ?? ''));
  $recordProgram = $recordProgram !== '' ? $recordProgram : 'Not program-specific';
  $recordCampus = trim((string) ($record['campusname'] ?? ''));
  $recordCampus = $recordCampus !== '' ? $recordCampus : 'Campus not specified';
  $isOwner = (int) ($record['is_owner'] ?? 0) === 1;
  $recordPanelists = trim((string) ($record['panelist_names'] ?? ''));
  $recordPanelists = $recordPanelists !== '' ? $recordPanelists : 'Not assigned';
  $recordStatistician = trim((string) ($record['statistician_name'] ?? ''));
  $recordStatistician = $recordStatistician !== '' ? $recordStatistician : 'Not assigned';
  $recordCritic = trim((string) ($record['english_critic_name'] ?? ''));
  $recordCritic = $recordCritic !== '' ? $recordCritic : 'Not assigned';
?>
<article class="research-card">
  <div class="research-card-shell">
    <div class="research-avatar" aria-hidden="true"><i class="bx bx-book-open"></i></div>
    <div class="research-main">
      <div class="research-top">
        <div>
          <p class="research-author"><?= e($recordAuthors); ?></p>
          <h3 class="research-title"><?= e((string) $record['title']); ?></h3>
          <p class="research-subtitle"><?= e($recordType); ?> · Updated <?= e($recordDate); ?></p>
        </div>
        <?php if ($view === 'mine' && $isOwner): ?>
          <div class="actions">
            <a class="btn secondary" href="<?= e(professor_url(['view'=>'mine','edit'=>$recordId])); ?>"><i class="bx bx-edit"></i>Edit</a>
            <form class="research-delete-form" method="post" data-research-kind="personal" data-research-title="<?= e((string)$record['title']); ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
              <input type="hidden" name="action" value="delete_personal_research">
              <input type="hidden" name="titleid" value="<?= $recordId; ?>">
              <button class="btn danger" type="submit"><i class="bx bx-trash" aria-hidden="true"></i>Remove</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
              <input type="hidden" name="action" value="visibility">
              <input type="hidden" name="titleid" value="<?= $recordId; ?>">
              <input type="hidden" name="is_published" value="<?= (int)$record['is_published']===1?0:1; ?>">
              <button class="btn <?= (int)$record['is_published']===1?'warn':''; ?>" type="submit"><i class="bx <?= (int)$record['is_published']===1?'bx-hide':'bx-show'; ?>"></i><?= (int)$record['is_published']===1?'Unpublish':'Publish'; ?></button>
            </form>
          </div>
        <?php elseif ($view === 'students'): ?>
          <div class="actions">
            <a class="btn secondary" href="<?= e(professor_url(['view'=>'students','student_edit'=>$recordId])); ?>"><i class="bx bx-edit"></i>Edit Record</a>
            <form class="research-delete-form" method="post" data-research-kind="student" data-research-title="<?= e((string)$record['title']); ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
              <input type="hidden" name="action" value="delete_student_research">
              <input type="hidden" name="titleid" value="<?= $recordId; ?>">
              <button class="btn danger" type="submit"><i class="bx bx-trash" aria-hidden="true"></i>Delete</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <div class="research-metrics"><span class="research-metric"><i class="bx bx-calendar"></i><?= e($recordYear); ?></span><span class="research-metric"><i class="bx bx-book-content"></i><?= e($recordType); ?></span><span class="research-metric"><i class="bx bx-target-lock"></i><?= e($recordSdgLabel); ?></span><span class="research-metric"><i class="bx bx-check-shield"></i><?= e($recordStatus); ?></span><?php if($view==='assignments'): ?><span class="pill live"><?= e((string)$record['faculty_role']); ?></span><?php elseif($view==='students'): ?><span class="pill live">Student research</span><?php else: ?><span class="pill <?= (int)$record['is_published']===1?'live':'private'; ?>"><?= (int)$record['is_published']===1?'Published':'Private'; ?></span><?php if(!$isOwner): ?><span class="pill">Co-author · read only</span><?php endif; ?><?php endif; ?></div>
      <p class="research-summary<?= $recordSummary === '' ? ' empty' : ''; ?>"><?= e($recordSummary !== '' ? $recordSummary : 'No abstract or proposal details have been added to this research record.'); ?></p>
      <?php if($view==='students'): ?>
        <div class="research-footer student-footer"><div><div class="research-label">Review team</div><div class="research-value student-team-value"><b>Panel:</b> <?= e($recordPanelists); ?><br><b>Statistician:</b> <?= e($recordStatistician); ?><br><b>English critic:</b> <?= e($recordCritic); ?></div></div><div><div class="research-label">Program and campus</div><div class="research-value"><?= e($recordProgram . ' · ' . $recordCampus); ?></div></div></div>
      <?php else: ?>
        <div class="research-footer"><div><div class="research-label">Research ownership</div><div class="research-value"><?= $view==='assignments' ? e((string)$record['faculty_role']) : ($isOwner ? 'Primary author / owner' : 'Faculty co-author'); ?></div></div><div><div class="research-label">Program and campus</div><div class="research-value"><?= e($recordProgram . ' · ' . $recordCampus); ?></div></div></div>
      <?php endif; ?>
    </div>
  </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade research-entry-modal" id="researchModal" tabindex="-1" aria-labelledby="researchModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form id="research-form" method="post">
        <div class="modal-header">
          <div><h2 class="modal-title h4" id="researchModalTitle"><?= $editRecord ? 'Edit Personal Research' : 'Add Personal Research'; ?></h2><p class="modal-title-copy">Complete the research information and save the record to your personal portfolio.</p></div>
          <button type="button" class="modal-close-button" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x" aria-hidden="true"></i></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="titleid" value="<?= (int)($modalRecord['titleid']??0); ?>">
          <div class="form-grid">
            <div class="field full"><label for="research-title">Research title *</label><input id="research-title" name="title" required maxlength="1000" value="<?= e((string)($modalRecord['title']??'')); ?>"></div>
            <div class="field"><label for="research-type">Research type *</label><select class="js-research-select" id="research-type" name="typeid" required data-placeholder="Select type" data-search-placeholder="Search research types"><option value="">Select type</option><?php foreach($types as $type): ?><option value="<?= (int)$type['researchtypeid']; ?>" <?= (int)($modalRecord['typeid']??0)===(int)$type['researchtypeid']?'selected':''; ?>><?= e($type['research_type']); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="research-stage">Research stage *</label><select class="js-research-select" id="research-stage" name="status" data-placeholder="Select stage" data-search-placeholder="Search research stages"><?php foreach(['Proposal','On-going','Completed'] as $status): ?><option <?= (string)($modalRecord['status']??'Proposal')===$status?'selected':''; ?>><?= e($status); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="research-campus">Campus *</label><select class="js-research-select" id="research-campus" name="campusid" required data-placeholder="Select campus" data-search-placeholder="Search campuses"><option value="">Select campus</option><?php foreach($campuses as $campus): ?><option value="<?= (int)$campus['campusid']; ?>" <?= (int)($modalRecord['campusid']??$campusId)===(int)$campus['campusid']?'selected':''; ?>><?= e($campus['campusname']); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="research-program">Program</label><select class="js-research-select" id="research-program" name="programid" data-placeholder="Select program" data-search-placeholder="Search programs"><option value="0">Not program-specific</option><?php foreach($programs as $program): ?><option value="<?= (int)$program['courseid']; ?>" <?= (int)($modalRecord['programid']??$programId)===(int)$program['courseid']?'selected':''; ?>><?= e(trim($program['coursecode'].' - '.$program['coursedescription'],' -')); ?></option><?php endforeach; ?></select></div>
            <div class="field full"><label>Lead Author</label><div class="pill live"><?= e($accountName); ?> &mdash; <?= e($accountEmail); ?></div><small class="meta">You are assigned as the lead author of this research.</small></div>
            <div class="field full"><label for="faculty-coauthors">Faculty Co-authors</label><select class="js-research-select" id="faculty-coauthors" name="coauthor_accountids[]" multiple data-placeholder="Search and select faculty co-authors" data-search-placeholder="Search faculty by name or email"><?php foreach($facultyOptions as $faculty): ?><?php $facultyName=trim((string)$faculty['acc_name']); $facultyEmail=trim((string)$faculty['email']); ?><option value="<?= (int)$faculty['accountid']; ?>" <?= in_array((int)$faculty['accountid'],$selectedCoauthorIds,true)?'selected':''; ?>><?= e(($facultyName!==''?$facultyName:'Account #'.(int)$faculty['accountid']).($facultyEmail!==''?' - '.$facultyEmail:' - No email')); ?></option><?php endforeach; ?></select><small class="meta">Select additional faculty members who contributed to the research.</small></div>
            <div class="field full"><label for="research-details">Abstract / Research Details</label><textarea id="research-details" name="other_details"><?= e((string)($modalRecord['other_details']??'')); ?></textarea></div>
            <div class="field full" id="sdg-field">
              <div class="sdg-field-header"><label id="sdg-field-label">Sustainable Development Goals *</label><span class="sdg-count" id="sdg-main-count" aria-live="polite"></span></div>
              <button class="sdg-picker-trigger" id="open-sdg-picker" type="button" aria-haspopup="dialog" aria-controls="sdgPickerModal"><i class="bx bx-plus" aria-hidden="true"></i> Select SDGs</button>
              <div class="sdg-selected-summary" id="sdg-selected-summary" aria-live="polite"></div>
              <p class="sdg-field-error" id="sdg-field-error">Select at least one Sustainable Development Goal.</p>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn" type="submit"><i class="bx bx-save"></i><?= $editRecord?'Save Changes':'Save Research'; ?></button></div>
      </form>
    </div>
  </div>
</div>
<div class="modal fade research-entry-modal student-research-modal" id="studentResearchModal" tabindex="-1" aria-labelledby="studentResearchModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form id="student-research-form" method="post">
        <div class="modal-header">
          <div><h2 class="modal-title h4" id="studentResearchModalTitle"><?= $studentEditRecord ? 'Edit Student Research' : 'Add Student Research'; ?></h2><p class="modal-title-copy">Record the student study and assign its academic review team.</p></div>
          <button type="button" class="modal-close-button" data-bs-dismiss="modal" aria-label="Close"><i class="bx bx-x" aria-hidden="true"></i></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
          <input type="hidden" name="action" value="save_student_research">
          <input type="hidden" name="titleid" value="<?= (int)($studentModalRecord['titleid']??0); ?>">
          <div class="form-grid">
            <div class="field full"><label for="student-research-title">Research title *</label><input id="student-research-title" name="student_title" required maxlength="1000" value="<?= e((string)($studentModalRecord['student_title']??$studentModalRecord['title']??'')); ?>"></div>
            <div class="field"><label for="student-research-type">Research type *</label><select class="js-student-select" id="student-research-type" name="student_typeid" required data-placeholder="Select type" data-search-placeholder="Search research types"><option value="">Select type</option><?php foreach($types as $type): ?><option value="<?= (int)$type['researchtypeid']; ?>" <?= (int)($studentModalRecord['student_typeid']??$studentModalRecord['typeid']??0)===(int)$type['researchtypeid']?'selected':''; ?>><?= e($type['research_type']); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="student-research-stage">Research stage *</label><select class="js-student-select" id="student-research-stage" name="student_status" required data-placeholder="Select stage" data-search-placeholder="Search research stages"><?php foreach(['Proposal','On-going','Completed'] as $status): ?><option <?= (string)($studentModalRecord['student_status']??$studentModalRecord['status']??'Proposal')===$status?'selected':''; ?>><?= e($status); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="student-academic-year">Academic year</label><select class="js-student-select" id="student-academic-year" name="student_ayid" data-placeholder="Select academic year" data-search-placeholder="Search academic years"><option value="">Not specified</option><?php foreach($academicYears as $academicYear): ?><option value="<?= (int)$academicYear['ayid']; ?>" <?= (int)($studentModalRecord['student_ayid']??$studentModalRecord['ayid']??0)===(int)$academicYear['ayid']?'selected':''; ?>><?= e(professor_academic_year_label($academicYear)); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Faculty adviser</label><div class="account-assignment"><i class="bx bx-user-check" aria-hidden="true"></i><div><strong><?= e($accountName); ?></strong><span><?= e($accountEmail); ?></span></div></div></div>
            <div class="field full"><label>Student researchers *</label><div class="student-researchers" id="student-researchers"><?php foreach($selectedStudentResearchers as $index=>$studentResearcher): ?><div class="student-researcher-row"><input name="student_researchers[]" required maxlength="180" value="<?= e($studentResearcher); ?>" placeholder="Enter full student name" aria-label="Student researcher <?= $index+1; ?>"><button class="student-researcher-remove" type="button" aria-label="Remove student researcher"><i class="bx bx-trash" aria-hidden="true"></i></button></div><?php endforeach; ?></div><button class="btn add-student-button" id="add-student-researcher" type="button"><i class="bx bx-plus" aria-hidden="true"></i>Add student</button></div>
            <div class="field full"><label for="student-panelists">Panelists *</label><select class="js-student-select" id="student-panelists" name="panelist_accountids[]" multiple required data-placeholder="Search and select panelists" data-search-placeholder="Search panelists by name or email"><?php foreach($facultyOptions as $faculty): ?><?php $facultyName=trim((string)$faculty['acc_name']); $facultyEmail=trim((string)$faculty['email']); ?><option value="<?= (int)$faculty['accountid']; ?>" <?= in_array((int)$faculty['accountid'],$selectedStudentPanelistIds,true)?'selected':''; ?>><?= e(($facultyName!==''?$facultyName:'Account #'.(int)$faculty['accountid']).($facultyEmail!==''?' - '.$facultyEmail:' - No email')); ?></option><?php endforeach; ?></select><p class="student-team-note">Select one or more panelists.</p></div>
            <div class="field"><label for="student-statistician">Statistician *</label><select class="js-student-select" id="student-statistician" name="statistician_accountid" required data-placeholder="Select statistician" data-search-placeholder="Search faculty by name or email"><option value="">Select statistician</option><?php foreach($facultyOptions as $faculty): ?><?php $facultyName=trim((string)$faculty['acc_name']); $facultyEmail=trim((string)$faculty['email']); ?><option value="<?= (int)$faculty['accountid']; ?>" <?= $selectedStatisticianId===(int)$faculty['accountid']?'selected':''; ?>><?= e(($facultyName!==''?$facultyName:'Account #'.(int)$faculty['accountid']).($facultyEmail!==''?' - '.$facultyEmail:' - No email')); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="student-english-critic">English critic *</label><select class="js-student-select" id="student-english-critic" name="english_critic_accountid" required data-placeholder="Select English critic" data-search-placeholder="Search faculty by name or email"><option value="">Select English critic</option><?php foreach($facultyOptions as $faculty): ?><?php $facultyName=trim((string)$faculty['acc_name']); $facultyEmail=trim((string)$faculty['email']); ?><option value="<?= (int)$faculty['accountid']; ?>" <?= $selectedEnglishCriticId===(int)$faculty['accountid']?'selected':''; ?>><?= e(($facultyName!==''?$facultyName:'Account #'.(int)$faculty['accountid']).($facultyEmail!==''?' - '.$facultyEmail:' - No email')); ?></option><?php endforeach; ?></select></div>
            <div class="field full"><label for="student-research-details">Abstract / Research Details</label><textarea id="student-research-details" name="student_other_details" maxlength="5000"><?= e((string)($studentModalRecord['student_other_details']??$studentModalRecord['other_details']??'')); ?></textarea></div>
            <div class="field full" id="student-sdg-field">
              <div class="sdg-field-header"><label>Sustainable Development Goals *</label><span class="sdg-count" id="student-sdg-main-count" aria-live="polite"></span></div>
              <button class="sdg-picker-trigger" id="open-student-sdg-picker" type="button" aria-haspopup="dialog" aria-controls="studentSdgPickerModal"><i class="bx bx-plus" aria-hidden="true"></i> Select SDGs</button>
              <div class="sdg-selected-summary" id="student-sdg-selected-summary" aria-live="polite"></div>
              <p class="sdg-field-error" id="student-sdg-field-error">Select at least one Sustainable Development Goal.</p>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn" type="submit"><i class="bx bx-save"></i><?= $studentEditRecord?'Save Changes':'Save Student Research'; ?></button></div>
      </form>
    </div>
  </div>
</div>
<div class="sdg-picker-modal" id="sdgPickerModal" role="dialog" aria-modal="true" aria-labelledby="sdgPickerTitle" hidden>
  <div class="sdg-picker-backdrop" data-sdg-dismiss></div>
  <section class="sdg-picker-dialog" role="document">
    <header class="sdg-picker-header">
      <div><h2 id="sdgPickerTitle">Choose Sustainable Development Goals</h2><p>Select every goal that directly relates to this research.</p></div>
      <button class="sdg-icon-button" id="close-sdg-picker" type="button" aria-label="Close SDG selection"><i class="bx bx-x" aria-hidden="true"></i></button>
    </header>
    <div class="sdg-picker-tools">
      <label class="sdg-search" for="sdg-search"><i class="bx bx-search" aria-hidden="true"></i><input id="sdg-search" type="search" placeholder="Search by goal number or title" autocomplete="off"></label>
      <span class="sdg-picker-counter" id="sdg-picker-counter" aria-live="polite"></span>
    </div>
    <div class="sdg-picker-body">
      <div class="sdg-grid" id="sdg-grid">
        <?php foreach($sdgOptions as $sdgCode=>$sdgLabel): $sdgImage='assets/img/sdgs/sdg-'.str_pad((string)$sdgCode,2,'0',STR_PAD_LEFT).'.png'; ?>
          <label class="sdg-option" data-search="<?= e(strtolower('sdg '.$sdgCode.' '.$sdgLabel)); ?>">
            <input class="sdg-checkbox" type="checkbox" name="sdgs[]" value="<?= $sdgCode; ?>" form="research-form" data-label="<?= e($sdgLabel); ?>" <?= in_array($sdgCode,$selectedSdgCodes,true)?'checked':''; ?>>
            <span class="sdg-tile"><img src="<?= e(app_link($sdgImage)); ?>" alt=""><span class="sdg-check"><i class="bx bx-check" aria-hidden="true"></i></span></span>
            <span class="visually-hidden"><?= e('SDG '.$sdgCode.': '.$sdgLabel); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="sdg-picker-empty" id="sdg-picker-empty">No goals match your search.</div>
    </div>
    <footer class="sdg-picker-footer">
      <p class="sdg-source">Goal artwork follows the official UN Sustainable Development Goals. <a href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer">View the goals</a>. Use of the icons does not imply United Nations endorsement.</p>
      <div class="sdg-picker-actions"><button class="sdg-clear" id="clear-sdgs" type="button">Clear</button><button class="btn secondary" id="cancel-sdgs" type="button">Cancel</button><button class="btn sdg-apply" id="apply-sdgs" type="button"><i class="bx bx-check" aria-hidden="true"></i>Apply selection</button></div>
    </footer>
  </section>
</div>
<div class="sdg-picker-modal" id="studentSdgPickerModal" role="dialog" aria-modal="true" aria-labelledby="studentSdgPickerTitle" hidden>
  <div class="sdg-picker-backdrop" data-sdg-dismiss></div>
  <section class="sdg-picker-dialog" role="document">
    <header class="sdg-picker-header">
      <div><h2 id="studentSdgPickerTitle">Choose Sustainable Development Goals</h2><p>Select every goal that directly relates to the student research.</p></div>
      <button class="sdg-icon-button" id="close-student-sdg-picker" type="button" aria-label="Close SDG selection"><i class="bx bx-x" aria-hidden="true"></i></button>
    </header>
    <div class="sdg-picker-tools">
      <label class="sdg-search" for="student-sdg-search"><i class="bx bx-search" aria-hidden="true"></i><input id="student-sdg-search" type="search" placeholder="Search by goal number or title" autocomplete="off"></label>
      <span class="sdg-picker-counter" id="student-sdg-picker-counter" aria-live="polite"></span>
    </div>
    <div class="sdg-picker-body">
      <div class="sdg-grid">
        <?php foreach($sdgOptions as $sdgCode=>$sdgLabel): $sdgImage='assets/img/sdgs/sdg-'.str_pad((string)$sdgCode,2,'0',STR_PAD_LEFT).'.png'; ?>
          <label class="sdg-option" data-search="<?= e(strtolower('sdg '.$sdgCode.' '.$sdgLabel)); ?>">
            <input class="sdg-checkbox" type="checkbox" name="student_sdgs[]" value="<?= $sdgCode; ?>" form="student-research-form" data-label="<?= e($sdgLabel); ?>" <?= in_array($sdgCode,$selectedStudentSdgCodes,true)?'checked':''; ?>>
            <span class="sdg-tile"><img src="<?= e(app_link($sdgImage)); ?>" alt=""><span class="sdg-check"><i class="bx bx-check" aria-hidden="true"></i></span></span>
            <span class="visually-hidden"><?= e('SDG '.$sdgCode.': '.$sdgLabel); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="sdg-picker-empty" id="student-sdg-picker-empty">No goals match your search.</div>
    </div>
    <footer class="sdg-picker-footer">
      <p class="sdg-source">Goal artwork follows the official UN Sustainable Development Goals. <a href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer">View the goals</a>. Use of the icons does not imply United Nations endorsement.</p>
      <div class="sdg-picker-actions"><button class="sdg-clear" id="clear-student-sdgs" type="button">Clear</button><button class="btn secondary" id="cancel-student-sdgs" type="button">Cancel</button><button class="btn sdg-apply" id="apply-student-sdgs" type="button"><i class="bx bx-check" aria-hidden="true"></i>Apply selection</button></div>
    </footer>
  </section>
</div>
</div></main></div>
<script src="<?= e(app_link('assets/vendor/libs/jquery/jquery.js')); ?>"></script>
<script src="<?= e(app_link('assets/vendor/libs/select2/select2.min.js')); ?>"></script>
<script src="<?= e(app_link('assets/vendor/js/bootstrap.js')); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var pageAlerts=<?= json_encode(array_values(array_filter([
      $success ? ['icon'=>'success','title'=>'Success','text'=>$success,'toast'=>true] : null,
      $error ? ['icon'=>'error','title'=>'Unable to complete request','text'=>$error,'toast'=>false] : null,
  ])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  function showPageAlert(index){
    if(typeof Swal==='undefined'||!Array.isArray(pageAlerts)||index>=pageAlerts.length){return;}
    var alert=pageAlerts[index]||{};
    var options={icon:alert.icon||'info',title:alert.title||'',text:alert.text||'',confirmButtonColor:'#007f3d'};
    if(alert.toast){options.toast=true;options.position='top-end';options.showConfirmButton=false;options.timer=3200;options.timerProgressBar=true;}
    Swal.fire(options).then(function(){showPageAlert(index+1);});
  }
  showPageAlert(0);

  document.querySelectorAll('.research-delete-form').forEach(function(form){
    form.addEventListener('submit',function(event){
      if(form.dataset.confirmed==='true'){return;}
      event.preventDefault();
      if(typeof Swal==='undefined'){return;}
      var isPersonal=form.dataset.researchKind==='personal';
      var researchTitle=form.dataset.researchTitle||(isPersonal?'this personal research record':'this student research record');
      Swal.fire({
        icon:'warning',
        title:isPersonal?'Remove personal research?':'Delete student research?',
        text:researchTitle+' will be permanently removed, including related assignments and its uploaded abstract.',
        showCancelButton:true,
        reverseButtons:true,
        confirmButtonColor:'#b42318',
        cancelButtonColor:'#647067',
        confirmButtonText:isPersonal?'Remove research':'Delete research',
        cancelButtonText:'Cancel'
      }).then(function(result){
        if(!result.isConfirmed){return;}
        form.dataset.confirmed='true';form.submit();
      });
    });
  });

  if(window.jQuery&&jQuery.fn.select2){
    function initializeSelect2(selector,modalSelector){
      var parentModal=jQuery(modalSelector);
      jQuery(selector).each(function(){
        var select=jQuery(this);
        select.select2({dropdownParent:parentModal,width:'100%',placeholder:select.data('placeholder')||'Select an option',minimumResultsForSearch:0,closeOnSelect:!select.prop('multiple')});
        if(select.prop('multiple')){
          var syncPlaceholder=function(){select.next('.select2-container').find('.select2-search__field').attr('placeholder',select.data('placeholder'));};
          syncPlaceholder();
          select.on('change select2:close',syncPlaceholder);
        }
        select.on('select2:open',function(){
          var input=document.querySelector('.select2-container--open .select2-search__field');
          if(input){input.placeholder=select.data('search-placeholder')||'Search options';}
        });
      });
    }
    initializeSelect2('.js-research-select','#researchModal');
    initializeSelect2('.js-student-select','#studentResearchModal');
  }

  function setupSdgPicker(config){
    var picker=document.getElementById(config.pickerId);
    var openButton=document.getElementById(config.openId);
    var closeButton=document.getElementById(config.closeId);
    var cancelButton=document.getElementById(config.cancelId);
    var applyButton=document.getElementById(config.applyId);
    var clearButton=document.getElementById(config.clearId);
    var searchInput=document.getElementById(config.searchId);
    var counter=document.getElementById(config.counterId);
    var mainCount=document.getElementById(config.mainCountId);
    var summary=document.getElementById(config.summaryId);
    var emptyMessage=document.getElementById(config.emptyId);
    var fieldError=document.getElementById(config.errorId);
    var researchForm=document.getElementById(config.formId);
    if(!picker||!openButton||!researchForm){return;}
    var options=Array.prototype.slice.call(picker.querySelectorAll('.sdg-option'));
    var checkboxes=Array.prototype.slice.call(picker.querySelectorAll('.sdg-checkbox'));
    var selectionSnapshot=[];
    var returnFocus=null;

    function selected(){return checkboxes.filter(function(checkbox){return checkbox.checked;});}
    function updatePicker(){
      var count=selected().length;
      counter.textContent=count+(count===1?' goal selected':' goals selected');
      applyButton.disabled=count===0;
    }
    function renderSummary(){
      var choices=selected();
      summary.textContent='';
      mainCount.textContent=choices.length+' selected';
      if(!choices.length){
        var empty=document.createElement('span');empty.className='sdg-empty';empty.textContent='No goals selected yet.';summary.appendChild(empty);return;
      }
      choices.forEach(function(checkbox){
        var item=document.createElement('span');item.className='sdg-selected-item';
        var number=document.createElement('b');number.textContent='SDG '+checkbox.value;
        var label=document.createElement('span');label.textContent=checkbox.dataset.label;
        item.appendChild(number);item.appendChild(label);summary.appendChild(item);
      });
      fieldError.classList.remove('visible');
    }
    function filterGoals(){
      var query=searchInput.value.trim().toLowerCase();var visible=0;
      options.forEach(function(option){var show=!query||option.dataset.search.indexOf(query)!==-1;option.hidden=!show;if(show){visible++;}});
      emptyMessage.classList.toggle('visible',visible===0);
    }
    function openPicker(){
      selectionSnapshot=checkboxes.map(function(checkbox){return checkbox.checked;});
      returnFocus=document.activeElement;picker.hidden=false;document.body.classList.add('sdg-picker-open');
      searchInput.value='';filterGoals();updatePicker();window.setTimeout(function(){searchInput.focus();},0);
    }
    function closePicker(keepChanges){
      if(!keepChanges){checkboxes.forEach(function(checkbox,index){checkbox.checked=selectionSnapshot[index];});}
      picker.hidden=true;document.body.classList.remove('sdg-picker-open');updatePicker();
      if(returnFocus&&typeof returnFocus.focus==='function'){returnFocus.focus();}
    }
    openButton.addEventListener('click',openPicker);
    closeButton.addEventListener('click',function(){closePicker(false);});
    cancelButton.addEventListener('click',function(){closePicker(false);});
    picker.querySelector('[data-sdg-dismiss]').addEventListener('click',function(){closePicker(false);});
    applyButton.addEventListener('click',function(){if(selected().length){renderSummary();closePicker(true);}});
    clearButton.addEventListener('click',function(){checkboxes.forEach(function(checkbox){checkbox.checked=false;});updatePicker();});
    checkboxes.forEach(function(checkbox){checkbox.addEventListener('change',updatePicker);});
    searchInput.addEventListener('input',filterGoals);
    picker.addEventListener('keydown',function(event){
      if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closePicker(false);return;}
      if(event.key!=='Tab'){return;}
      var focusable=Array.prototype.slice.call(picker.querySelectorAll('button:not([disabled]),input:not([disabled])')).filter(function(element){return !element.closest('[hidden]');});
      if(!focusable.length){return;}var first=focusable[0];var last=focusable[focusable.length-1];
      if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    });
    researchForm.addEventListener('submit',function(event){
      if(selected().length){return;}event.preventDefault();fieldError.classList.add('visible');openPicker();
    });
    renderSummary();updatePicker();
  }

  setupSdgPicker({pickerId:'sdgPickerModal',openId:'open-sdg-picker',closeId:'close-sdg-picker',cancelId:'cancel-sdgs',applyId:'apply-sdgs',clearId:'clear-sdgs',searchId:'sdg-search',counterId:'sdg-picker-counter',mainCountId:'sdg-main-count',summaryId:'sdg-selected-summary',emptyId:'sdg-picker-empty',errorId:'sdg-field-error',formId:'research-form'});
  setupSdgPicker({pickerId:'studentSdgPickerModal',openId:'open-student-sdg-picker',closeId:'close-student-sdg-picker',cancelId:'cancel-student-sdgs',applyId:'apply-student-sdgs',clearId:'clear-student-sdgs',searchId:'student-sdg-search',counterId:'student-sdg-picker-counter',mainCountId:'student-sdg-main-count',summaryId:'student-sdg-selected-summary',emptyId:'student-sdg-picker-empty',errorId:'student-sdg-field-error',formId:'student-research-form'});

  var researcherList=document.getElementById('student-researchers');
  var addResearcherButton=document.getElementById('add-student-researcher');
  function syncResearcherRows(){
    if(!researcherList){return;}
    var rows=researcherList.querySelectorAll('.student-researcher-row');
    rows.forEach(function(row,index){
      var input=row.querySelector('input');var remove=row.querySelector('.student-researcher-remove');
      input.setAttribute('aria-label','Student researcher '+(index+1));remove.disabled=rows.length===1;
    });
  }
  if(researcherList&&addResearcherButton){
    addResearcherButton.addEventListener('click',function(){
      var template=researcherList.querySelector('.student-researcher-row');var row=template.cloneNode(true);
      row.querySelector('input').value='';researcherList.appendChild(row);syncResearcherRows();row.querySelector('input').focus();
    });
    researcherList.addEventListener('click',function(event){
      var button=event.target.closest('.student-researcher-remove');if(!button||button.disabled){return;}
      button.closest('.student-researcher-row').remove();syncResearcherRows();
    });
    syncResearcherRows();
  }
});
</script>
<?php if($showResearchModal): ?><script>document.addEventListener('DOMContentLoaded',function(){var element=document.getElementById('researchModal');if(element&&window.bootstrap){window.bootstrap.Modal.getOrCreateInstance(element).show();}});</script><?php endif; ?>
<?php if($showStudentResearchModal): ?><script>document.addEventListener('DOMContentLoaded',function(){var element=document.getElementById('studentResearchModal');if(element&&window.bootstrap){window.bootstrap.Modal.getOrCreateInstance(element).show();}});</script><?php endif; ?></body></html>
