<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireAdmin();

function administrator_research_type_status_label(bool $isActive): string
{
    return $isActive ? 'Active' : 'Inactive';
}

function administrator_research_type_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    if ($query === '') {
        return app_link('administrator/research_type.php');
    }

    return app_link('administrator/research_type.php') . '?' . $query;
}

function administrator_research_type_navigation_params(
    string $search,
    string $statusFilter,
    int $page = 1,
    ?int $editResearchTypeId = null
): array {
    $parameters = [];

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    if ($statusFilter !== '') {
        $parameters['status'] = $statusFilter;
    }

    if ($page > 1) {
        $parameters['page'] = $page;
    }

    if ($editResearchTypeId !== null && $editResearchTypeId > 0) {
        $parameters['edit'] = $editResearchTypeId;
    }

    return $parameters;
}

function administrator_research_type_is_active(array $researchType): bool
{
    return !array_key_exists('is_active', $researchType) || (int) $researchType['is_active'] === 1;
}

function administrator_research_type_name_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function administrator_research_type_datetime_label(?string $value): string
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

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$editResearchTypeId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($page < 1) {
    $page = 1;
}

if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

if ($editResearchTypeId < 1) {
    $editResearchTypeId = 0;
}

$researchTypes = [];
$totalResearchTypes = 0;
$activeResearchTypes = 0;
$inactiveResearchTypes = 0;
$filteredResearchTypes = 0;
$pageError = null;
$formError = null;
$selectedResearchType = null;
$actionSuccess = get_flash('research_type_success');
$actionError = get_flash('research_type_error');
$totalPages = 1;
$perPage = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string) ($_POST['action'] ?? ''));
    $currentResearchType = null;

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $pdo = Database::connection();
        Database::ensureResearchTypeTable();

        if ($postedAction === 'save_research_type') {
            $researchTypeId = isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0;
            $researchTypeName = trim((string) ($_POST['research_type'] ?? ''));

            if ($researchTypeName === '') {
                throw new RuntimeException('Research type name is required.');
            }

            if (administrator_research_type_name_length($researchTypeName) > 100) {
                throw new RuntimeException('Research type name must be 100 characters or fewer.');
            }

            if ($researchTypeId > 0) {
                $currentStatement = $pdo->prepare(
                    'SELECT researchtypeid, research_type, is_active, created_at, updated_at
                     FROM tblresearchtype
                     WHERE researchtypeid = :researchtypeid
                     LIMIT 1'
                );
                $currentStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
                $currentStatement->execute();
                $currentResearchType = $currentStatement->fetch();

                if (!is_array($currentResearchType)) {
                    throw new RuntimeException('The selected research type was not found.');
                }
            }

            $duplicateStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblresearchtype
                 WHERE LOWER(TRIM(research_type)) = LOWER(TRIM(:research_type))
                   AND researchtypeid <> :researchtypeid'
            );
            $duplicateStatement->bindValue(':research_type', $researchTypeName);
            $duplicateStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $duplicateStatement->execute();

            if ((int) $duplicateStatement->fetchColumn() > 0) {
                throw new RuntimeException('That research type already exists.');
            }

            if ($researchTypeId > 0) {
                $saveStatement = $pdo->prepare(
                    'UPDATE tblresearchtype
                     SET research_type = :research_type
                     WHERE researchtypeid = :researchtypeid'
                );
                $saveStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
                $successMessage = 'Research type updated.';
            } else {
                $saveStatement = $pdo->prepare(
                    'INSERT INTO tblresearchtype (research_type, is_active)
                     VALUES (:research_type, 1)'
                );
                $successMessage = 'Research type created.';
            }

            $saveStatement->bindValue(':research_type', $researchTypeName);
            $saveStatement->execute();

            if ($researchTypeId < 1) {
                $researchTypeId = (int) $pdo->lastInsertId();
            }

            set_flash('research_type_success', $successMessage);
            redirect(administrator_research_type_url(administrator_research_type_navigation_params(
                $search,
                $statusFilter,
                $page
            )));
        }

        if ($postedAction === 'toggle_research_type') {
            $researchTypeId = isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0;
            $targetStatus = isset($_POST['target_status']) ? (int) $_POST['target_status'] : -1;

            if ($researchTypeId < 1) {
                throw new RuntimeException('The selected research type is invalid.');
            }

            if (!in_array($targetStatus, [0, 1], true)) {
                throw new RuntimeException('The selected status is invalid.');
            }

            $toggleStatement = $pdo->prepare(
                'UPDATE tblresearchtype
                 SET is_active = :is_active
                 WHERE researchtypeid = :researchtypeid'
            );
            $toggleStatement->bindValue(':is_active', $targetStatus, PDO::PARAM_INT);
            $toggleStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $toggleStatement->execute();

            set_flash(
                'research_type_success',
                $targetStatus === 1 ? 'Research type activated.' : 'Research type set to inactive.'
            );
            redirect(administrator_research_type_url(administrator_research_type_navigation_params(
                $search,
                $statusFilter,
                $page
            )));
        }

        if ($postedAction === 'delete_research_type') {
            $researchTypeId = isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0;

            if ($researchTypeId < 1) {
                throw new RuntimeException('The selected research type is invalid.');
            }

            $currentStatement = $pdo->prepare(
                'SELECT researchtypeid, research_type
                 FROM tblresearchtype
                 WHERE researchtypeid = :researchtypeid
                 LIMIT 1'
            );
            $currentStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $currentStatement->execute();
            $currentResearchType = $currentStatement->fetch();

            if (!is_array($currentResearchType)) {
                throw new RuntimeException('The selected research type was not found.');
            }

            $usageStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblresearches
                 WHERE typeid = :researchtypeid'
            );
            $usageStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $usageStatement->execute();
            $usageCount = (int) $usageStatement->fetchColumn();

            if ($usageCount > 0) {
                throw new RuntimeException(
                    'This research type cannot be deleted because it is assigned to '
                    . number_format($usageCount)
                    . ' research record'
                    . ($usageCount === 1 ? '' : 's')
                    . '.'
                );
            }

            $deleteStatement = $pdo->prepare(
                'DELETE FROM tblresearchtype
                 WHERE researchtypeid = :researchtypeid
                 LIMIT 1'
            );
            $deleteStatement->bindValue(':researchtypeid', $researchTypeId, PDO::PARAM_INT);
            $deleteStatement->execute();

            if ($deleteStatement->rowCount() < 1) {
                throw new RuntimeException('The selected research type could not be deleted.');
            }

            set_flash('research_type_success', 'Research type deleted.');
            redirect(administrator_research_type_url(administrator_research_type_navigation_params(
                $search,
                $statusFilter,
                $page
            )));
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($postedAction === 'save_research_type') {
            $formError = $exception->getMessage();
            $selectedResearchType = [
                'researchtypeid' => isset($_POST['researchtypeid']) ? (int) $_POST['researchtypeid'] : 0,
                'research_type' => trim((string) ($_POST['research_type'] ?? '')),
                'is_active' => isset($currentResearchType['is_active']) ? (int) $currentResearchType['is_active'] : 1,
                'created_at' => isset($currentResearchType['created_at']) ? (string) $currentResearchType['created_at'] : '',
                'updated_at' => isset($currentResearchType['updated_at']) ? (string) $currentResearchType['updated_at'] : '',
            ];
            $editResearchTypeId = isset($selectedResearchType['researchtypeid'])
                ? (int) $selectedResearchType['researchtypeid']
                : 0;
        } else {
            set_flash('research_type_error', $exception->getMessage());
            redirect(administrator_research_type_url(administrator_research_type_navigation_params(
                $search,
                $statusFilter,
                $page
            )));
        }
    }
}

try {
    $pdo = Database::connection();
    Database::ensureResearchTypeTable();

    $totalResearchTypes = (int) $pdo->query('SELECT COUNT(*) FROM tblresearchtype')->fetchColumn();
    $activeResearchTypes = (int) $pdo->query('SELECT COUNT(*) FROM tblresearchtype WHERE is_active = 1')->fetchColumn();
    $inactiveResearchTypes = (int) $pdo->query('SELECT COUNT(*) FROM tblresearchtype WHERE is_active = 0')->fetchColumn();

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = 'research_type LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    if ($statusFilter === 'active') {
        $conditions[] = 'is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = 'is_active = 0';
    }

    $whereClause = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM tblresearchtype' . $whereClause);

    foreach ($params as $key => $value) {
        $countStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $countStatement->execute();
    $filteredResearchTypes = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredResearchTypes / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStatement = $pdo->prepare(
        'SELECT researchtypeid, research_type, is_active, created_at, updated_at
         FROM tblresearchtype'
        . $whereClause .
        ' ORDER BY research_type ASC
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $listStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();
    $researchTypes = $listStatement->fetchAll();

    if ($selectedResearchType === null && $editResearchTypeId > 0) {
        $selectedStatement = $pdo->prepare(
            'SELECT researchtypeid, research_type, is_active, created_at, updated_at
             FROM tblresearchtype
             WHERE researchtypeid = :researchtypeid
             LIMIT 1'
        );
        $selectedStatement->bindValue(':researchtypeid', $editResearchTypeId, PDO::PARAM_INT);
        $selectedStatement->execute();
        $selectedResearchType = $selectedStatement->fetch();

        if (!is_array($selectedResearchType)) {
            $actionError = $actionError ?? 'The selected research type could not be loaded.';
            $editResearchTypeId = 0;
        }
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$selectedResearchTypeId = is_array($selectedResearchType) && isset($selectedResearchType['researchtypeid'])
    ? (int) $selectedResearchType['researchtypeid']
    : 0;
$isEditing = $selectedResearchTypeId > 0;
$drawerTitle = 'Add Research Type';
$drawerSubmitLabel = $isEditing ? 'Save Research Type' : 'Create Type';
$shouldOpenEditorDrawer = $formError !== null || $isEditing;

$summaryItems = [
    [
        'value' => number_format($totalResearchTypes),
        'title' => 'Total Types',
        'meta' => 'All saved research type records',
        'icon' => 'folder',
        'color' => 'primary',
    ],
    [
        'value' => number_format($activeResearchTypes),
        'title' => 'Active Types',
        'meta' => 'Available for future research forms',
        'icon' => 'check-circle',
        'color' => 'success',
    ],
    [
        'value' => number_format($inactiveResearchTypes),
        'title' => 'Inactive Types',
        'meta' => 'Hidden from future active selections',
        'icon' => 'slash',
        'color' => 'warning',
    ],
    [
        'value' => number_format($filteredResearchTypes),
        'title' => 'Visible Results',
        'meta' => 'Current search and status filters',
        'icon' => 'filter',
        'color' => 'purple',
    ],
];

$persistedFilters = administrator_research_type_navigation_params($search, $statusFilter);
$pageFiltersWithoutEditor = administrator_research_type_navigation_params($search, $statusFilter, $page);
$pageActionUrl = administrator_research_type_url($pageFiltersWithoutEditor);

$researchTypeDrawerDefaults = [
    'researchtypeid' => 0,
    'research_type' => '',
];

$researchTypeRecords = [];

foreach ($researchTypes as $record) {
    $researchTypeRecords[] = [
        'researchtypeid' => isset($record['researchtypeid']) ? (int) $record['researchtypeid'] : 0,
        'research_type' => (string) ($record['research_type'] ?? ''),
    ];
}

$researchTypeRecordsJson = json_encode(
    $researchTypeRecords,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($researchTypeRecordsJson)) {
    $researchTypeRecordsJson = '[]';
}

$researchTypeDrawerDefaultsJson = json_encode(
    $researchTypeDrawerDefaults,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($researchTypeDrawerDefaultsJson)) {
    $researchTypeDrawerDefaultsJson = '{}';
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
        'title' => 'Unable to save research type',
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

$extraStyles = '
.research-types-alert {
  margin-bottom: 18px;
  padding: 14px 16px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
}

.research-types-alert.success {
  color: #237752;
  background-color: rgba(75, 222, 151, 0.14);
}

.research-types-alert.error {
  color: #a64040;
  background-color: rgba(242, 100, 100, 0.14);
}

.research-types-panel {
  padding: 24px;
  margin-bottom: 24px;
}

.research-types-panel-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}

.research-types-panel-copy {
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.research-types-panel-grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(220px, 1fr);
  gap: 16px;
}

.research-types-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}

.research-types-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.research-types-drawer {
  width: min(680px, 100vw);
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

.research-types-drawer.show:not(.hiding) {
  box-shadow: -20px 0 48px rgba(17, 24, 39, 0.16);
}

.research-types-drawer-header {
  padding: 24px 24px 0;
  border-bottom: 0;
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.research-types-drawer-main {
  min-width: 0;
}

.research-types-drawer-title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
  color: #566a7f;
}

.research-types-drawer-copy {
  margin: 6px 0 0;
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.research-types-drawer-header-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
}

.research-types-drawer-body {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.research-types-drawer-form {
  display: grid;
  gap: 16px;
}

.research-types-filter-form {
  display: grid;
  grid-template-columns: minmax(320px, 1.8fr) minmax(180px, 0.8fr) auto;
  gap: 14px;
  align-items: center;
  width: 100%;
}

.research-types-filter-form > * {
  min-width: 0;
}

.research-types-filter-form .search-wrapper {
  min-width: 0;
  position: relative;
  display: block;
}

.research-types-filter-form .search-wrapper i,
.research-types-filter-form .search-wrapper svg {
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

.research-types-filter-form .search-wrapper .form-input {
  width: 100%;
  margin-bottom: 0;
  padding-left: 44px;
}

.research-types-panel-grid .form-label-wrapper,
.research-types-drawer-form .form-label-wrapper {
  width: 100%;
}

.research-types-filter-form .form-input,
.research-types-filter-form .research-types-select,
.research-types-panel-grid .form-input,
.research-types-panel-grid .research-types-select,
.research-types-drawer-form .form-input {
  width: 100%;
  height: 44px;
  border: 0;
  border-radius: 8px;
  background-color: #eff0f6;
  color: #171717;
  margin-bottom: 0;
  padding: 0 14px;
}

.research-types-filter-form .research-types-select {
  width: 100%;
  min-width: 0;
}

.research-types-filter-actions {
  display: flex;
  gap: 10px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.research-types-filter-actions .secondary-default-btn,
.research-types-filter-actions .primary-default-btn {
  min-height: 44px;
}

.research-types-panel-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 18px;
}

.research-types-drawer-actions {
  margin-top: 8px;
}

.research-types-panel-toggle {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid #eff0f6;
}

.research-types-field-note,
.research-types-toolbar-note,
.research-types-muted {
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.research-types-toolbar-note {
  margin: 18px 0;
}

.research-types-table-title {
  font-weight: 600;
  color: #171717;
}

.research-types-table-subtitle {
  margin-top: 4px;
  color: #767676;
  font-size: 12px;
}

.research-types-actions {
  display: flex;
  gap: 8px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.research-types-actions-column,
.research-types-actions-cell {
  text-align: right;
}

.research-types-actions-cell {
  width: 1%;
  white-space: nowrap;
}

.research-types-inline-form {
  margin: 0;
}

.research-types-inline-btn {
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

.research-types-inline-btn:hover {
  background-color: rgba(47, 73, 209, 0.16);
  color: #2f49d1;
}

.research-types-inline-btn.success {
  color: #237752;
  background-color: rgba(75, 222, 151, 0.14);
}

.research-types-inline-btn.success:hover {
  background-color: rgba(75, 222, 151, 0.2);
}

.research-types-inline-btn.warning {
  color: #a64040;
  background-color: rgba(242, 100, 100, 0.14);
}

.research-types-inline-btn.warning:hover {
  background-color: rgba(242, 100, 100, 0.22);
}

.research-types-inline-btn.danger {
  color: #fff;
  background-color: #d14343;
}

.research-types-inline-btn.danger:hover {
  background-color: #b93434;
  color: #fff;
}

.research-types-inline-btn.neutral {
  color: #5e667a;
  background-color: rgba(118, 118, 118, 0.12);
}

.research-types-inline-btn.neutral:hover {
  background-color: rgba(118, 118, 118, 0.18);
}

.research-types-empty {
  padding: 32px 24px;
  text-align: center;
  color: #767676;
}

.research-types-pagination {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  margin-top: 20px;
}

.research-types-pagination a,
.research-types-pagination span {
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

.research-types-pagination span.active {
  background-color: #2f49d1;
  color: #fff;
}

.research-types-panel-delete {
  margin-top: 16px;
  border-top-color: rgba(209, 67, 67, 0.18);
}

.research-types-delete-note {
  color: #a64040;
}

@media (max-width: 1199px) {
  .research-types-filter-form {
    grid-template-columns: minmax(0, 1fr) minmax(180px, 220px) auto;
  }
}

@media (max-width: 991px) {
  .research-types-drawer-header {
    padding: 20px 20px 0;
    flex-wrap: wrap;
  }

  .research-types-drawer-body {
    padding: 20px;
  }

  .research-types-filter-form {
    grid-template-columns: 1fr;
  }

  .research-types-filter-actions {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .research-types-actions {
    flex-wrap: wrap;
  }

  .research-types-panel-grid {
    grid-template-columns: 1fr;
  }

  .research-types-pagination {
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
    var researchTypeRecords = __RESEARCH_TYPE_RECORDS__;
    var drawerDefaults = __RESEARCH_TYPE_DRAWER_DEFAULTS__;
    var swalAlerts = __SWAL_ALERTS__;
    var drawerElement = document.getElementById('researchTypeDrawer');
    var drawerForm = drawerElement ? drawerElement.querySelector('form') : null;
    var nameInput = drawerForm ? drawerForm.querySelector('input[name="research_type"]') : null;
    var idInput = drawerForm ? drawerForm.querySelector('input[name="researchtypeid"]') : null;
    var drawerTitleElement = document.getElementById('researchTypeDrawerLabel');
    var drawerSubmitButton = drawerForm ? drawerForm.querySelector('button[type="submit"]') : null;
    var drawerInstance = null;
    var recordMap = {};
    var modalHash = window.location.hash || '';

    if (Array.isArray(researchTypeRecords)) {
      researchTypeRecords.forEach(function (record) {
        var recordId = parseInt(record && record.researchtypeid, 10);

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
      var researchTypeId = normalizedId(state.researchtypeid || state.id);
      var researchTypeName = String(state.research_type || '');

      if (idInput) {
        idInput.value = researchTypeId > 0 ? String(researchTypeId) : '';
      }

      if (nameInput) {
        nameInput.value = researchTypeName;
      }

      if (drawerTitleElement) {
        drawerTitleElement.textContent = 'Add Research Type';
      }

      if (drawerSubmitButton) {
        drawerSubmitButton.textContent = researchTypeId > 0 ? 'Save Research Type' : 'Create Type';
      }
    }

    function openDrawer() {
      if (drawerInstance) {
        drawerInstance.show();
      }
    }

    function initDeleteConfirmation() {
      $(document).on('submit', '.research-types-delete-form', function (event) {
        var form = this;

        if (form.dataset.confirmed === 'true' || typeof Swal === 'undefined') {
          return true;
        }

        event.preventDefault();

        Swal.fire({
          icon: 'warning',
          title: 'Delete research type?',
          text: 'This will permanently remove the research type if it is not assigned to any research records.',
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

    $(document).on('click', '[data-research-type-open-add]', function () {
      populateDrawer(drawerDefaults);
    });

    $(document).on('click', '[data-research-type-edit-trigger]', function (event) {
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
        clearDrawerState('#research-type-drawer');

        if (nameInput) {
          nameInput.focus();
        }
      });

      $(drawerElement).on('hidden.bs.offcanvas', function () {
        clearDrawerState('#research-type-drawer');
        populateDrawer(drawerDefaults);
      });
    }

    initDeleteConfirmation();
    showSwalAlerts(0);

    if (shouldOpenEditorDrawer) {
      openDrawer();
    } else if (modalHash === '#research-type-drawer') {
      openDrawer();
    }

    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#research-type-drawer') {
        openDrawer();
      }
    });
  });
</script>
HTML;

$extraScripts = str_replace(
    ['__OPEN_EDITOR_DRAWER__', '__RESEARCH_TYPE_RECORDS__', '__RESEARCH_TYPE_DRAWER_DEFAULTS__', '__SWAL_ALERTS__'],
    [$shouldOpenEditorDrawer ? 'true' : 'false', $researchTypeRecordsJson, $researchTypeDrawerDefaultsJson, $swalAlertsJson],
    $extraScripts
);

ob_start();
?>
<main class="main users chart-page" id="skip-target">
  <div class="container">
    <?php if ($pageError !== null): ?>
      <article class="white-block research-types-panel">
        <h3 class="white-block__title">Unable to load research types</h3>
        <p class="research-types-muted"><?= e($pageError); ?></p>
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
        <div class="research-types-toolbar">
          <div class="research-types-toolbar-actions">
            <button
              class="primary-default-btn"
              type="button"
              data-research-type-open-add
              data-bs-toggle="offcanvas"
              data-bs-target="#researchTypeDrawer"
            >
              Add Research Type
            </button>
          </div>
        </div>

        <div class="sort-bar">
          <form class="research-types-filter-form" method="get" action="<?= e(app_link('administrator/research_type.php')); ?>">
            <label class="search-wrapper">
              <i data-feather="search" aria-hidden="true"></i>
              <input class="form-input" type="text" name="q" value="<?= e($search); ?>" placeholder="Search research types">
            </label>

            <select class="research-types-select" name="status">
              <option value="">All statuses</option>
              <option value="active"<?= $statusFilter === 'active' ? ' selected' : ''; ?>>Active</option>
              <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
            </select>

            <div class="research-types-filter-actions">
              <button class="primary-default-btn" type="submit">Apply Filter</button>
              <a class="secondary-default-btn" href="<?= e(app_link('administrator/research_type.php')); ?>">Reset</a>
            </div>
          </form>
        </div>

        <div class="users-table table-wrapper">
          <table class="posts-table">
            <thead>
              <tr class="users-table-info">
                <th>No.</th>
                <th>Research Type</th>
                <th>Status</th>
                <th class="research-types-actions-column">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($researchTypes === []): ?>
                <tr>
                  <td class="research-types-empty" colspan="4">No research types matched the current filters.</td>
                </tr>
              <?php endif; ?>

              <?php $rowNumber = (($page - 1) * $perPage) + 1; ?>
              <?php foreach ($researchTypes as $record): ?>
                <?php $isActive = administrator_research_type_is_active($record); ?>
                <?php $editUrl = administrator_research_type_url(administrator_research_type_navigation_params($search, $statusFilter, $page, (int) $record['researchtypeid'])); ?>
                <tr>
                  <td><?= e((string) $rowNumber); ?></td>
                  <td>
                    <div class="research-types-table-title"><?= e((string) $record['research_type']); ?></div>
                    <div class="research-types-table-subtitle">Created <?= e(administrator_research_type_datetime_label((string) ($record['created_at'] ?? ''))); ?></div>
                  </td>
                  <td>
                    <span class="<?= e($isActive ? 'badge-active' : 'badge-disabled'); ?>">
                      <?= e(administrator_research_type_status_label($isActive)); ?>
                    </span>
                  </td>
                  <td class="research-types-actions-cell">
                    <div class="research-types-actions">
                      <a
                        class="research-types-inline-btn"
                        href="<?= e($editUrl); ?>"
                        data-research-type-edit-trigger
                        data-record-id="<?= e((string) $record['researchtypeid']); ?>"
                      >
                        Edit
                      </a>

                      <form class="research-types-inline-form" method="post" action="<?= e($pageActionUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_research_type">
                        <input type="hidden" name="researchtypeid" value="<?= e((string) $record['researchtypeid']); ?>">
                        <input type="hidden" name="target_status" value="<?= $isActive ? '0' : '1'; ?>">
                        <button class="research-types-inline-btn <?= e($isActive ? 'warning' : 'success'); ?>" type="submit">
                          <?= e($isActive ? 'Set Inactive' : 'Activate'); ?>
                        </button>
                      </form>

                      <form
                        class="research-types-inline-form research-types-delete-form"
                        method="post"
                        action="<?= e($pageActionUrl); ?>"
                      >
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_research_type">
                        <input type="hidden" name="researchtypeid" value="<?= e((string) $record['researchtypeid']); ?>">
                        <button class="research-types-inline-btn danger" type="submit">Delete</button>
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
          <div class="research-types-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= e(administrator_research_type_url(array_merge($persistedFilters, ['page' => $page - 1]))); ?>">Previous</a>
            <?php endif; ?>

            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
              <?php if ($pageNumber === $page): ?>
                <span class="active"><?= e((string) $pageNumber); ?></span>
              <?php else: ?>
                <a href="<?= e(administrator_research_type_url(array_merge($persistedFilters, ['page' => $pageNumber]))); ?>"><?= e((string) $pageNumber); ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= e(administrator_research_type_url(array_merge($persistedFilters, ['page' => $page + 1]))); ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-end research-types-drawer" tabindex="-1" id="researchTypeDrawer" aria-labelledby="researchTypeDrawerLabel">
    <div class="offcanvas-header research-types-drawer-header">
      <div class="research-types-drawer-main">
        <h3 class="research-types-drawer-title" id="researchTypeDrawerLabel"><?= e($drawerTitle); ?></h3>
        <p class="research-types-drawer-copy">Save research type names here so future research forms can load them dynamically from the database instead of hardcoding them.</p>
      </div>

      <div class="research-types-drawer-header-actions">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
    </div>

    <div class="offcanvas-body research-types-drawer-body">
      <form method="post" action="<?= e($pageActionUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_research_type">
        <input type="hidden" name="researchtypeid" value="<?= e((string) $selectedResearchTypeId); ?>">

        <div class="research-types-drawer-form">
          <label class="form-label-wrapper">
            <span class="form-label">Research Type Name</span>
            <input
              class="form-input"
              type="text"
              name="research_type"
              maxlength="100"
              value="<?= e((string) ($selectedResearchType['research_type'] ?? '')); ?>"
              placeholder="Example: Thesis"
              required
            >
            <span class="research-types-field-note">Examples: Thesis, Capstone, Copyright, Other.</span>
          </label>
        </div>

        <div class="research-types-panel-actions research-types-drawer-actions">
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
    'title' => 'Administrator | Research Type',
    'current_page' => 'research_type',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_scripts' => $extraScripts,
    'extra_styles' => $extraStyles,
]);
