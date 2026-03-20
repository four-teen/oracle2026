<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$stats = [
    'accounts_total' => 0,
    'accounts_enabled' => 0,
    'research_types_total' => 0,
    'manuscripts_total' => 0,
    'manuscripts_ready' => 0,
];
$recentManuscripts = [];
$dashboardError = null;

try {
    $pdo = Database::connection();
    Database::ensureAccountStatusColumn();
    Database::ensureResearchTypeTable();
    Database::ensureManuscriptTable();

    $stats['accounts_total'] = (int) $pdo->query('SELECT COUNT(*) FROM tblaccount')->fetchColumn();
    $stats['accounts_enabled'] = (int) $pdo->query('SELECT COUNT(*) FROM tblaccount WHERE is_enabled = 1')->fetchColumn();
    $stats['research_types_total'] = (int) $pdo->query('SELECT COUNT(*) FROM tblresearchtype')->fetchColumn();
    $stats['manuscripts_total'] = (int) $pdo->query('SELECT COUNT(*) FROM tblresearches')->fetchColumn();
    $stats['manuscripts_ready'] = (int) $pdo->query(
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

    $recentStatement = $pdo->query(
        'SELECT m.titleid AS manuscriptid,
                m.title AS manuscript_title,
                m.sdgs AS sdg_code,
                m.updated_at,
                rt.research_type,
                c.campusname AS campus_name,
                m.status
         FROM tblresearches m
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         LEFT JOIN tblcampus c ON c.campusid = m.campusid
         ORDER BY m.updated_at DESC, m.titleid DESC
         LIMIT 5'
    );
    $recentManuscripts = $recentStatement->fetchAll();
} catch (Throwable $exception) {
    $dashboardError = $exception->getMessage();
}

$summaryItems = [
    [
        'label' => 'Accounts',
        'value' => number_format($stats['accounts_total']),
        'meta' => number_format($stats['accounts_enabled']) . ' enabled for sign-in',
        'icon' => 'bx-user',
        'icon_class' => 'bg-label-primary',
    ],
    [
        'label' => 'Research Types',
        'value' => number_format($stats['research_types_total']),
        'meta' => 'Dynamic options loaded from the database',
        'icon' => 'bx-category-alt',
        'icon_class' => 'bg-label-info',
    ],
    [
        'label' => 'Research Records',
        'value' => number_format($stats['manuscripts_total']),
        'meta' => 'Legacy tblresearches with detail management',
        'icon' => 'bx-book-open',
        'icon_class' => 'bg-label-warning',
    ],
    [
        'label' => 'Ready for Review',
        'value' => number_format($stats['manuscripts_ready']),
        'meta' => 'Detailed information and abstract complete',
        'icon' => 'bx-check-shield',
        'icon_class' => 'bg-label-success',
    ],
];

$workflowItems = [
    'Accounts in tblaccount control who can sign in with Google.',
    'Research Type management keeps research choices dynamic.',
    'Base research data saves first: type, campus, SDGs, title, status, and legacy references.',
    'Detailed management follows: select adviser, panelists, statistician, English critic, authors, and abstract upload.',
];

ob_start();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="app-page-header">
                <div>
                  <span class="app-page-eyebrow"><i class="bx bx-grid-alt"></i> Dashboard</span>
                  <h4 class="fw-bold py-3 mb-2">Oracle administrator dashboard</h4>
                  <p class="app-page-copy">
                    Overview of accounts, research setup, and recently updated records.
                  </p>
                </div>
              </div>

<?php if ($dashboardError !== null): ?>
              <div class="page-alert error"><?= e($dashboardError); ?></div>
<?php endif; ?>

              <div class="row g-4 mb-4">
<?php foreach ($summaryItems as $summaryItem): ?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card metric-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                          <span class="metric-label d-block mb-1"><?= e($summaryItem['label']); ?></span>
                          <h3 class="mb-0"><?= e($summaryItem['value']); ?></h3>
                        </div>
                        <span class="metric-icon <?= e($summaryItem['icon_class']); ?>">
                          <i class="bx <?= e($summaryItem['icon']); ?>"></i>
                        </span>
                      </div>
                      <p class="mb-0 muted-copy"><?= e($summaryItem['meta']); ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="row g-4">
                <div class="col-lg-7">
                  <div class="card surface-card h-100">
                    <div class="card-header">
                      <h5 class="mb-0">Current workflow</h5>
                    </div>
                    <div class="card-body">
<?php foreach ($workflowItems as $index => $workflowItem): ?>
                      <div class="d-flex gap-3<?= $index < count($workflowItems) - 1 ? ' mb-3' : ''; ?>">
                        <span class="badge bg-label-primary rounded-pill" style="height: fit-content;"><?= e((string) ($index + 1)); ?></span>
                        <div>
                          <h6 class="mb-1">Step <?= e((string) ($index + 1)); ?></h6>
                          <p class="mb-0 muted-copy"><?= e($workflowItem); ?></p>
                        </div>
                      </div>
<?php endforeach; ?>
                    </div>
                  </div>
                </div>

                <div class="col-lg-5">
                  <div class="card surface-card h-100">
                    <div class="card-header">
                      <h5 class="mb-0">Quick actions</h5>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                      <a href="<?= e(app_link('administrator/accounts.php')); ?>" class="btn btn-outline-primary">
                        Open Accounts Management
                      </a>
                      <a href="<?= e(app_link('administrator/research_type.php')); ?>" class="btn btn-outline-info">
                        Open Research Type Management
                      </a>
                      <a href="<?= e(app_link('administrator/manuscripts.php')); ?>" class="btn btn-outline-warning">
                        Open Research Management
                      </a>
                      <p class="mb-0 helper-copy">
                        Use these pages to manage accounts, research types, and manuscript records from one place.
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card surface-card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Recently updated research records</h5>
                  <a href="<?= e(app_link('administrator/manuscripts.php')); ?>" class="btn btn-sm btn-primary">Manage research</a>
                </div>
                <div class="card-body">
                  <div class="app-table-responsive">
                    <table class="table app-table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Title</th>
                          <th>Research Type</th>
                          <th>Campus</th>
                          <th>Status</th>
                          <th>SDGs</th>
                          <th>Updated</th>
                        </tr>
                      </thead>
                      <tbody>
<?php if ($recentManuscripts === []): ?>
                        <tr>
                          <td colspan="7" class="text-center py-4 muted-copy">No research records have been saved yet.</td>
                        </tr>
<?php endif; ?>
<?php foreach ($recentManuscripts as $record): ?>
                        <tr>
                          <td><?= e((string) $record['manuscriptid']); ?></td>
                          <td><?= e((string) $record['manuscript_title']); ?></td>
                          <td><?= e((string) ($record['research_type'] ?? 'Unknown Type')); ?></td>
                          <td><?= e((string) ($record['campus_name'] ?? '')); ?></td>
                          <td><?= e(trim((string) ($record['status'] ?? '')) !== '' ? (string) $record['status'] : 'Pending'); ?></td>
                          <td><?= e((string) $record['sdg_code']); ?></td>
                          <td><?= e((string) $record['updated_at']); ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
<?php
$mainContent = (string) ob_get_clean();

AdminPage::render([
    'title' => 'Administrator | Dashboard',
    'current_page' => 'dashboard',
    'main_content' => $mainContent,
]);
