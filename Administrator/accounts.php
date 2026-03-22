<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function administrator_account_role_map(): array
{
    return [
        1 => 'Administrator',
        2 => 'Staff',
        3 => 'Student',
        4 => 'Professor',
        0 => 'User',
    ];
}

function administrator_account_role_label($value): string
{
    $normalized = (int) $value;
    $roles = administrator_account_role_map();

    return $roles[$normalized] ?? ('Role ' . $normalized);
}

function administrator_account_role_badge_class($value): string
{
    switch ((int) $value) {
        case 1:
            return 'accounts-role-admin';
        case 2:
            return 'accounts-role-staff';
        case 3:
            return 'accounts-role-student';
        case 4:
            return 'accounts-role-professor';
        case 0:
            return 'accounts-role-user';
        default:
            return 'accounts-role-unknown';
    }
}

function administrator_account_status_label(bool $isEnabled): string
{
    return $isEnabled ? 'Enabled' : 'Disabled';
}

function administrator_current_account_id(): int
{
    $account = Auth::account();

    return is_array($account) && isset($account['accountid']) ? (int) $account['accountid'] : 0;
}

function administrator_current_login_email(): string
{
    $googleUser = Auth::googleUser();

    if (is_array($googleUser) && isset($googleUser['email'])) {
        return normalize_email((string) $googleUser['email']);
    }

    $account = Auth::account();

    return is_array($account) && isset($account['email']) ? normalize_email((string) $account['email']) : '';
}

function administrator_account_is_current(array $account, int $currentAccountId): bool
{
    return $currentAccountId > 0
        && isset($account['accountid'])
        && (int) $account['accountid'] === $currentAccountId;
}

function administrator_accounts_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    if ($query === '') {
        return app_link('administrator/accounts.php');
    }

    return app_link('administrator/accounts.php') . '?' . $query;
}

function administrator_account_navigation_params(
    string $search,
    string $roleFilter,
    string $statusFilter,
    int $page = 1,
    ?int $editAccountId = null
): array {
    $parameters = [];

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    if ($roleFilter !== '') {
        $parameters['role'] = $roleFilter;
    }

    if ($statusFilter !== '') {
        $parameters['status'] = $statusFilter;
    }

    if ($page > 1) {
        $parameters['page'] = $page;
    }

    if ($editAccountId !== null && $editAccountId > 0) {
        $parameters['edit'] = $editAccountId;
    }

    return $parameters;
}

function administrator_account_is_enabled(array $account): bool
{
    return !array_key_exists('is_enabled', $account) || (int) $account['is_enabled'] === 1;
}

function administrator_account_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function administrator_account_initial(string $value): string
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return '?';
    }

    if (function_exists('mb_substr')) {
        return strtoupper((string) mb_substr($trimmed, 0, 1, 'UTF-8'));
    }

    return strtoupper(substr($trimmed, 0, 1));
}

$roleMap = administrator_account_role_map();
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? trim((string) $_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$editAccountId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($page < 1) {
    $page = 1;
}

if (!in_array($statusFilter, ['enabled', 'disabled'], true)) {
    $statusFilter = '';
}

if ($roleFilter !== '' && !array_key_exists((int) $roleFilter, $roleMap)) {
    $roleFilter = '';
}

if ($editAccountId < 1) {
    $editAccountId = 0;
}

$accounts = [];
$roleCounts = array_fill_keys(array_map('strval', array_keys($roleMap)), 0);
$totalAccounts = 0;
$enabledAccounts = 0;
$disabledAccounts = 0;
$filteredAccounts = 0;
$pageError = null;
$editorError = null;
$selectedAccount = null;
$actionSuccess = get_flash('accounts_success');
$actionError = get_flash('accounts_error');
$totalPages = 1;
$perPage = 20;
$currentAccountId = administrator_current_account_id();
$currentLoginEmail = administrator_current_login_email();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string) ($_POST['action'] ?? ''));
    $currentAccount = null;

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $pdo = Database::connection();
        Database::ensureAccountStatusColumn();

        if ($postedAction === 'save_account') {
            $accountId = isset($_POST['accountid']) ? (int) $_POST['accountid'] : 0;
            $currentAccount = Database::findAccountById($accountId);

            if ($currentAccount === null) {
                throw new RuntimeException('The selected account was not found.');
            }

            $name = trim((string) ($_POST['acc_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $roleInput = trim((string) ($_POST['acc_type'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Account name is required.');
            }

            if (administrator_account_string_length($name) > 50) {
                throw new RuntimeException('Account name must be 50 characters or fewer.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }

            if (administrator_account_string_length($email) > 50) {
                throw new RuntimeException('Email must be 50 characters or fewer.');
            }

            if ($roleInput === '' || !array_key_exists((int) $roleInput, $roleMap)) {
                throw new RuntimeException('Select a valid role.');
            }

            $normalizedEmail = normalize_email($email);

            if (
                $accountId === $currentAccountId
                && $currentLoginEmail !== ''
                && $normalizedEmail !== $currentLoginEmail
            ) {
                throw new RuntimeException(
                    'You cannot change the login email for the account you are currently signed in with.'
                );
            }

            $duplicateStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM tblaccount
                 WHERE REPLACE(LOWER(email), " ", "") = :email
                   AND accountid <> :accountid'
            );
            $duplicateStatement->bindValue(':email', $normalizedEmail);
            $duplicateStatement->bindValue(':accountid', $accountId, PDO::PARAM_INT);
            $duplicateStatement->execute();

            if ((int) $duplicateStatement->fetchColumn() > 0) {
                throw new RuntimeException('That email address is already assigned to another account.');
            }

            $updateStatement = $pdo->prepare(
                'UPDATE tblaccount
                 SET acc_name = :acc_name,
                     email = :email,
                     acc_type = :acc_type
                 WHERE accountid = :accountid'
            );
            $updateStatement->bindValue(':acc_name', $name);
            $updateStatement->bindValue(':email', $email);
            $updateStatement->bindValue(':acc_type', (int) $roleInput, PDO::PARAM_INT);
            $updateStatement->bindValue(':accountid', $accountId, PDO::PARAM_INT);
            $updateStatement->execute();

            set_flash('accounts_success', 'Account details were updated.');
            redirect(administrator_accounts_url(administrator_account_navigation_params(
                $search,
                $roleFilter,
                $statusFilter,
                $page,
                $accountId
            )));
        }

        if ($postedAction === 'toggle_account') {
            $accountId = isset($_POST['accountid']) ? (int) $_POST['accountid'] : 0;
            $targetStatus = isset($_POST['target_status']) ? (int) $_POST['target_status'] : -1;

            if ($accountId < 1) {
                throw new RuntimeException('The selected account is invalid.');
            }

            if (!in_array($targetStatus, [0, 1], true)) {
                throw new RuntimeException('The selected status is invalid.');
            }

            if ($accountId === $currentAccountId && $targetStatus === 0) {
                throw new RuntimeException('You cannot disable the account you are currently signed in with.');
            }

            $updateStatement = $pdo->prepare(
                'UPDATE tblaccount
                 SET is_enabled = :is_enabled
                 WHERE accountid = :accountid'
            );
            $updateStatement->bindValue(':is_enabled', $targetStatus, PDO::PARAM_INT);
            $updateStatement->bindValue(':accountid', $accountId, PDO::PARAM_INT);
            $updateStatement->execute();

            set_flash(
                'accounts_success',
                $targetStatus === 1 ? 'Account access was enabled.' : 'Account access was disabled.'
            );
            redirect(administrator_accounts_url(administrator_account_navigation_params(
                $search,
                $roleFilter,
                $statusFilter,
                $page,
                $editAccountId > 0 ? $editAccountId : null
            )));
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        if ($postedAction === 'save_account') {
            $editorError = $exception->getMessage();
            $selectedAccount = [
                'accountid' => isset($_POST['accountid']) ? (int) $_POST['accountid'] : 0,
                'acc_name' => trim((string) ($_POST['acc_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'acc_type' => isset($_POST['acc_type']) ? (int) $_POST['acc_type'] : 0,
                'is_enabled' => isset($currentAccount['is_enabled']) ? (int) $currentAccount['is_enabled'] : 1,
            ];
            $editAccountId = (int) $selectedAccount['accountid'];
        } else {
            set_flash('accounts_error', $exception->getMessage());
            redirect(administrator_accounts_url(administrator_account_navigation_params(
                $search,
                $roleFilter,
                $statusFilter,
                $page,
                $editAccountId > 0 ? $editAccountId : null
            )));
        }
    }
}

try {
    $pdo = Database::connection();
    Database::ensureAccountStatusColumn();

    foreach ($pdo->query('SELECT acc_type, COUNT(*) AS total FROM tblaccount GROUP BY acc_type') as $roleOption) {
        $roleKey = (string) $roleOption['acc_type'];

        if (array_key_exists($roleKey, $roleCounts)) {
            $roleCounts[$roleKey] = (int) $roleOption['total'];
        }
    }

    $totalAccounts = (int) $pdo->query('SELECT COUNT(*) FROM tblaccount')->fetchColumn();
    $enabledAccounts = (int) $pdo->query('SELECT COUNT(*) FROM tblaccount WHERE is_enabled = 1')->fetchColumn();
    $disabledAccounts = (int) $pdo->query('SELECT COUNT(*) FROM tblaccount WHERE is_enabled = 0')->fetchColumn();

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = '(CAST(accountid AS CHAR) LIKE :search_id OR acc_name LIKE :search_name OR email LIKE :search_email)';
        $params['search_id'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
        $params['search_email'] = '%' . $search . '%';
    }

    if ($roleFilter !== '') {
        $conditions[] = 'acc_type = :acc_type';
        $params['acc_type'] = (int) $roleFilter;
    }

    if ($statusFilter === 'enabled') {
        $conditions[] = 'is_enabled = 1';
    } elseif ($statusFilter === 'disabled') {
        $conditions[] = 'is_enabled = 0';
    }

    $whereClause = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM tblaccount' . $whereClause);

    foreach ($params as $key => $value) {
        $parameterType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $countStatement->bindValue(':' . $key, $value, $parameterType);
    }

    $countStatement->execute();
    $filteredAccounts = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredAccounts / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStatement = $pdo->prepare(
        'SELECT accountid, acc_name, email, acc_type, is_enabled
         FROM tblaccount'
        . $whereClause .
        ' ORDER BY accountid ASC
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $parameterType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $listStatement->bindValue(':' . $key, $value, $parameterType);
    }

    $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();
    $accounts = $listStatement->fetchAll();

    if ($selectedAccount === null && $editAccountId > 0) {
        $selectedAccount = Database::findAccountById($editAccountId);

        if ($selectedAccount === null) {
            $actionError = $actionError ?? 'The selected account could not be loaded.';
            $editAccountId = 0;
        }
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$summaryItems = [
    [
        'icon' => 'users',
        'color' => 'primary',
        'value' => number_format($totalAccounts),
        'title' => 'Total Accounts',
        'meta' => 'All records inside tblaccount',
    ],
    [
        'icon' => 'user-check',
        'color' => 'success',
        'value' => number_format($enabledAccounts),
        'title' => 'Enabled Accounts',
        'meta' => 'Accounts allowed to sign in',
    ],
    [
        'icon' => 'user-x',
        'color' => 'warning',
        'value' => number_format($disabledAccounts),
        'title' => 'Disabled Accounts',
        'meta' => 'Accounts blocked from sign-in',
    ],
    [
        'icon' => 'filter',
        'color' => 'purple',
        'value' => number_format($filteredAccounts),
        'title' => 'Filtered Results',
        'meta' => 'Current search, role, and status',
    ],
];

$persistedFilters = administrator_account_navigation_params($search, $roleFilter, $statusFilter);
$pageFiltersWithoutEditor = administrator_account_navigation_params($search, $roleFilter, $statusFilter, $page);
$pageFiltersWithEditor = administrator_account_navigation_params(
    $search,
    $roleFilter,
    $statusFilter,
    $page,
    $editAccountId > 0 ? $editAccountId : null
);

$extraStyles = '
.accounts-alert {
  padding: 16px 18px;
  border-radius: 10px;
  margin-bottom: 20px;
  font-size: 14px;
  line-height: 1.6;
}

.accounts-alert.success {
  background-color: rgba(75, 222, 151, 0.14);
  color: #237752;
}

.accounts-alert.error {
  background-color: rgba(242, 100, 100, 0.14);
  color: #a64040;
}

.accounts-filter-form {
  display: grid;
  grid-template-columns: minmax(320px, 1.8fr) minmax(180px, 0.8fr) minmax(180px, 0.8fr) auto;
  gap: 14px;
  align-items: center;
  width: 100%;
}

.accounts-filter-form > * {
  min-width: 0;
}

.accounts-filter-form .search-wrapper {
  min-width: 0;
}

.accounts-filter-form .search-wrapper .form-input {
  width: 100%;
  margin-bottom: 0;
}

.accounts-filter-form .form-input,
.accounts-filter-form .accounts-select,
.accounts-editor-grid .form-input,
.accounts-editor-grid .accounts-select {
  height: 44px;
  border: 0;
  border-radius: 8px;
  background-color: #eff0f6;
  color: #171717;
}

.accounts-filter-form .accounts-select {
  width: 100%;
  min-width: 0;
  padding: 0 14px;
}

.accounts-filter-actions {
  display: flex;
  gap: 10px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.accounts-filter-actions .secondary-default-btn,
.accounts-filter-actions .primary-default-btn {
  min-height: 44px;
}

.accounts-toolbar-note {
  margin: 18px 0;
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.accounts-editor {
  padding: 24px;
}

.accounts-editor-heading {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  align-items: flex-start;
  margin-bottom: 18px;
}

.accounts-editor-copy {
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.accounts-editor-status {
  display: flex;
  align-items: center;
}

.accounts-editor-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.accounts-editor-grid .form-label-wrapper {
  width: 100%;
}

.accounts-editor-grid .form-input,
.accounts-editor-grid .accounts-select {
  width: 100%;
  margin-bottom: 0;
  padding: 0 14px;
}

.accounts-editor-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 18px;
}

.accounts-editor-toggle {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid #eff0f6;
}

.accounts-field-note {
  color: #767676;
  font-size: 13px;
  line-height: 1.6;
}

.accounts-table-name {
  display: flex;
  align-items: center;
  gap: 12px;
  color: #171717;
  font-weight: 600;
}

.accounts-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(47, 73, 209, 0.12);
  color: #2f49d1;
  font-weight: 700;
  flex-shrink: 0;
}

.accounts-email {
  color: #767676;
  word-break: break-word;
}

.accounts-muted {
  color: #767676;
}

.accounts-role-badge {
  min-width: 110px;
  padding: 7px 12px;
  border-radius: 100px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  text-align: center;
}

.accounts-role-admin {
  color: #2f49d1;
  background-color: rgba(47, 73, 209, 0.12);
}

.accounts-role-staff {
  color: #237752;
  background-color: rgba(75, 222, 151, 0.14);
}

.accounts-role-student {
  color: #c58316;
  background-color: rgba(255, 182, 72, 0.18);
}

.accounts-role-professor {
  color: #6a3bd1;
  background-color: rgba(95, 46, 234, 0.14);
}

.accounts-role-user {
  color: #5e667a;
  background-color: rgba(118, 118, 118, 0.14);
}

.accounts-role-unknown {
  color: #a64040;
  background-color: rgba(242, 100, 100, 0.14);
}

.accounts-table-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.accounts-inline-form {
  margin: 0;
}

.accounts-inline-btn {
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

.accounts-inline-btn[disabled] {
  opacity: 0.55;
  cursor: not-allowed;
  pointer-events: none;
}

.accounts-inline-btn:hover {
  background-color: rgba(47, 73, 209, 0.16);
  color: #2f49d1;
}

.accounts-inline-btn.success {
  color: #237752;
  background-color: rgba(75, 222, 151, 0.14);
}

.accounts-inline-btn.success:hover {
  background-color: rgba(75, 222, 151, 0.2);
}

.accounts-inline-btn.danger {
  color: #a64040;
  background-color: rgba(242, 100, 100, 0.14);
}

.accounts-inline-btn.danger:hover {
  background-color: rgba(242, 100, 100, 0.22);
}

.accounts-inline-btn.neutral {
  color: #5e667a;
  background-color: rgba(118, 118, 118, 0.12);
}

.accounts-inline-btn.neutral:hover {
  background-color: rgba(118, 118, 118, 0.18);
}

.accounts-inline-note {
  min-height: 36px;
  padding: 8px 14px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  color: #5e667a;
  background-color: rgba(118, 118, 118, 0.12);
}

.accounts-pagination {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  margin-top: 20px;
}

.accounts-pagination a,
.accounts-pagination span {
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

.accounts-pagination span.active {
  background-color: #2f49d1;
  color: #fff;
}

.accounts-empty {
  padding: 32px 24px;
  text-align: center;
  color: #767676;
}

@media (max-width: 1199px) {
  .accounts-filter-form {
    grid-template-columns: minmax(0, 1fr) minmax(180px, 220px) minmax(180px, 220px);
  }

  .accounts-filter-actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }
}

@media (max-width: 991px) {
  .accounts-filter-form {
    grid-template-columns: 1fr;
  }

  .accounts-filter-actions {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .accounts-editor-grid {
    grid-template-columns: 1fr;
  }

  .accounts-pagination {
    justify-content: flex-start;
  }
}
';

ob_start();
?>
<main class="main users chart-page" id="skip-target">
  <div class="container">
    <?php if ($actionSuccess !== null): ?>
      <div class="accounts-alert success"><?= e($actionSuccess); ?></div>
    <?php endif; ?>

    <?php if ($actionError !== null): ?>
      <div class="accounts-alert error"><?= e($actionError); ?></div>
    <?php endif; ?>

    <?php if ($pageError !== null): ?>
      <article class="white-block" style="padding: 24px; margin-bottom: 24px;">
        <h3 class="white-block__title">Unable to load accounts</h3>
        <p class="accounts-muted"><?= e($pageError); ?></p>
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
        <?php if ($selectedAccount !== null): ?>
          <?php $selectedAccountEnabled = administrator_account_is_enabled($selectedAccount); ?>
          <?php $selectedAccountIsCurrent = administrator_account_is_current($selectedAccount, $currentAccountId); ?>
          <article class="white-block accounts-editor">
            <div class="accounts-editor-heading">
              <div>
                <h3 class="white-block__title">Edit Account #<?= e((string) $selectedAccount['accountid']); ?></h3>
                <p class="accounts-editor-copy">Update the account name, email, and role. Use enable or disable to control sign-in access.</p>
              </div>
              <div class="accounts-editor-status">
                <span class="<?= e($selectedAccountEnabled ? 'badge-active' : 'badge-disabled'); ?>">
                  <?= e(administrator_account_status_label($selectedAccountEnabled)); ?>
                </span>
              </div>
            </div>

            <?php if ($editorError !== null): ?>
              <div class="accounts-alert error"><?= e($editorError); ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e(administrator_accounts_url($pageFiltersWithEditor)); ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
              <input type="hidden" name="action" value="save_account">
              <input type="hidden" name="accountid" value="<?= e((string) $selectedAccount['accountid']); ?>">

              <div class="accounts-editor-grid">
                <label class="form-label-wrapper">
                  <span class="form-label">Account Name</span>
                  <input
                    class="form-input"
                    type="text"
                    name="acc_name"
                    maxlength="50"
                    value="<?= e((string) $selectedAccount['acc_name']); ?>"
                    required
                  >
                </label>

                <label class="form-label-wrapper">
                  <span class="form-label">Email Address</span>
                  <input
                    class="form-input"
                    type="email"
                    name="email"
                    maxlength="50"
                    value="<?= e((string) $selectedAccount['email']); ?>"
                    required
                  >
                  <?php if ($selectedAccountIsCurrent): ?>
                    <span class="accounts-field-note">This email must stay matched to the Google account in your current session.</span>
                  <?php endif; ?>
                </label>

                <label class="form-label-wrapper">
                  <span class="form-label">Role</span>
                  <select class="accounts-select" name="acc_type" required>
                    <?php foreach ($roleMap as $roleCode => $roleLabel): ?>
                      <option value="<?= e((string) $roleCode); ?>"<?= (int) $selectedAccount['acc_type'] === $roleCode ? ' selected' : ''; ?>>
                        <?= e($roleLabel); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>

              <div class="accounts-editor-actions">
                <button class="primary-default-btn" type="submit">Save Changes</button>
                <a class="secondary-default-btn" href="<?= e(administrator_accounts_url($pageFiltersWithoutEditor)); ?>">Close Editor</a>
              </div>
            </form>

            <?php if ($selectedAccountIsCurrent): ?>
              <div class="accounts-editor-toggle">
                <span class="accounts-inline-note">Current Session</span>
                <span class="accounts-field-note">The account in your current session cannot be disabled here.</span>
              </div>
            <?php else: ?>
              <form class="accounts-editor-toggle" method="post" action="<?= e(administrator_accounts_url($pageFiltersWithEditor)); ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                <input type="hidden" name="action" value="toggle_account">
                <input type="hidden" name="accountid" value="<?= e((string) $selectedAccount['accountid']); ?>">
                <input type="hidden" name="target_status" value="<?= $selectedAccountEnabled ? '0' : '1'; ?>">
                <button class="accounts-inline-btn <?= e($selectedAccountEnabled ? 'danger' : 'success'); ?>" type="submit">
                  <?= e($selectedAccountEnabled ? 'Disable Account' : 'Enable Account'); ?>
                </button>
                <span class="accounts-field-note">Disabling blocks future Google sign-ins for this account until it is enabled again.</span>
              </form>
            <?php endif; ?>
          </article>
        <?php endif; ?>

        <div class="sort-bar">
          <form class="accounts-filter-form" method="get" action="<?= e(app_link('administrator/accounts.php')); ?>">
            <label class="search-wrapper">
              <i data-feather="search" aria-hidden="true"></i>
              <input class="form-input" type="text" name="q" value="<?= e($search); ?>" placeholder="Search by id, name, or email">
            </label>

            <select class="accounts-select" name="role">
              <option value="">All roles</option>
              <?php foreach ($roleMap as $roleCode => $roleLabel): ?>
                <?php $roleCount = $roleCounts[(string) $roleCode] ?? 0; ?>
                <option value="<?= e((string) $roleCode); ?>"<?= $roleFilter === (string) $roleCode ? ' selected' : ''; ?>>
                  <?= e($roleLabel); ?> (<?= e((string) $roleCount); ?>)
                </option>
              <?php endforeach; ?>
            </select>

            <select class="accounts-select" name="status">
              <option value="">All statuses</option>
              <option value="enabled"<?= $statusFilter === 'enabled' ? ' selected' : ''; ?>>Enabled</option>
              <option value="disabled"<?= $statusFilter === 'disabled' ? ' selected' : ''; ?>>Disabled</option>
            </select>

            <div class="accounts-filter-actions">
              <button class="primary-default-btn" type="submit">Apply Filter</button>
              <a class="secondary-default-btn" href="<?= e(app_link('administrator/accounts.php')); ?>">Reset</a>
            </div>
          </form>
        </div>

        <p class="accounts-toolbar-note">Manage the live <code>tblaccount</code> records here. Editing updates the account information directly, and disabling an account blocks future sign-ins.</p>

        <div class="users-table table-wrapper">
          <table class="posts-table">
            <thead>
              <tr class="users-table-info">
                <th>ID</th>
                <th>Account Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($accounts === []): ?>
                <tr>
                  <td class="accounts-empty" colspan="6">No accounts matched the current filters.</td>
                </tr>
              <?php endif; ?>

              <?php foreach ($accounts as $record): ?>
                <?php
                $name = trim((string) $record['acc_name']);
                $email = trim((string) $record['email']);
                $roleCode = (int) $record['acc_type'];
                $initial = administrator_account_initial($name);
                $isEnabled = administrator_account_is_enabled($record);
                $isCurrentAccount = administrator_account_is_current($record, $currentAccountId);
                $editUrl = administrator_accounts_url(administrator_account_navigation_params(
                    $search,
                    $roleFilter,
                    $statusFilter,
                    $page,
                    (int) $record['accountid']
                ));
                ?>
                <tr>
                  <td><?= e((string) $record['accountid']); ?></td>
                  <td>
                    <div class="accounts-table-name">
                      <span class="accounts-avatar"><?= e($initial); ?></span>
                      <span><?= e($name); ?></span>
                    </div>
                  </td>
                  <td class="accounts-email"><?= e($email); ?></td>
                  <td>
                    <span class="accounts-role-badge <?= e(administrator_account_role_badge_class($roleCode)); ?>">
                      <?= e(administrator_account_role_label($roleCode)); ?>
                    </span>
                  </td>
                  <td>
                    <span class="<?= e($isEnabled ? 'badge-active' : 'badge-disabled'); ?>">
                      <?= e(administrator_account_status_label($isEnabled)); ?>
                    </span>
                  </td>
                  <td>
                    <div class="accounts-table-actions">
                      <a class="accounts-inline-btn" href="<?= e($editUrl); ?>">Edit</a>

                      <?php if ($isCurrentAccount): ?>
                        <span class="accounts-inline-note">Current Session</span>
                      <?php else: ?>
                        <form class="accounts-inline-form" method="post" action="<?= e(administrator_accounts_url($pageFiltersWithEditor)); ?>">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="toggle_account">
                          <input type="hidden" name="accountid" value="<?= e((string) $record['accountid']); ?>">
                          <input type="hidden" name="target_status" value="<?= $isEnabled ? '0' : '1'; ?>">
                          <button class="accounts-inline-btn <?= e($isEnabled ? 'danger' : 'success'); ?>" type="submit">
                            <?= e($isEnabled ? 'Disable' : 'Enable'); ?>
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if (filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
                        <a class="accounts-inline-btn neutral" href="mailto:<?= e($email); ?>">Email</a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
          <div class="accounts-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= e(administrator_accounts_url(array_merge($persistedFilters, ['page' => $page - 1]))); ?>">Previous</a>
            <?php endif; ?>

            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
              <?php if ($pageNumber === $page): ?>
                <span class="active"><?= e((string) $pageNumber); ?></span>
              <?php elseif ($pageNumber === 1 || $pageNumber === $totalPages || abs($pageNumber - $page) <= 1): ?>
                <a href="<?= e(administrator_accounts_url(array_merge($persistedFilters, ['page' => $pageNumber]))); ?>"><?= e((string) $pageNumber); ?></a>
              <?php elseif ($pageNumber === 2 && $page > 4): ?>
                <span>...</span>
              <?php elseif ($pageNumber === $totalPages - 1 && $page < $totalPages - 3): ?>
                <span>...</span>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= e(administrator_accounts_url(array_merge($persistedFilters, ['page' => $page + 1]))); ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php
$mainContent = (string) ob_get_clean();

AdminPage::render([
    'title' => 'Administrator | Accounts',
    'current_page' => 'accounts',
    'main_content' => $mainContent,
    'extra_styles' => $extraStyles,
]);
