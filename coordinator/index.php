<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireResearchCoordinator();
require_once dirname(__DIR__) . '/app/CoordinatorStudentResearch.php';

function coordinator_dashboard_int(array $row, string $key): int
{
    return isset($row[$key]) ? (int) $row[$key] : 0;
}

function coordinator_dashboard_date_label(?string $value): string
{
    $normalized = trim((string) $value);

    if ($normalized === '') {
        return 'No updates yet';
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('M j, Y', $timestamp) : $normalized;
}

function coordinator_dashboard_program_label(array $row): string
{
    $programKey = isset($row['program_key']) ? (int) $row['program_key'] : (int) ($row['programid'] ?? 0);
    $courseCode = trim((string) ($row['coursecode'] ?? ''));

    return $courseCode !== '' ? $courseCode : ($programKey > 0 ? 'Program #' . $programKey : 'Unassigned program');
}

function coordinator_dashboard_percentage(int $part, int $total): string
{
    if ($total < 1) {
        return '0%';
    }

    return number_format(($part / $total) * 100, 1) . '%';
}

$campusId = Auth::campusId();
$campusLabel = 'Campus #' . $campusId;
$stats = [
    'records_total' => 0,
    'with_abstract' => 0,
    'with_authors' => 0,
    'types_used' => 0,
    'programs_used' => 0,
    'latest_update' => '',
];
$programBreakdown = [];
$sdgOptions = coordinator_student_sdg_options();
$sdgCounts = array_fill_keys(array_keys($sdgOptions), 0);
$sdgAlignedTotal = 0;
$sdgAvailable = false;
$dashboardError = null;
$contributorRoles = [
    'advisers' => ['label' => 'Advisers', 'title' => 'Top 20 advisers', 'icon' => 'bx-user-voice', 'people' => [], 'recorded_total' => 0],
    'panelists' => ['label' => 'Panelists', 'title' => 'Top 20 panelists', 'icon' => 'bx-group', 'people' => [], 'recorded_total' => 0],
    'statisticians' => ['label' => 'Statisticians', 'title' => 'Top 20 statisticians', 'icon' => 'bx-line-chart', 'people' => [], 'recorded_total' => 0],
    'english-critics' => ['label' => 'English critics', 'title' => 'Top 20 English critics', 'icon' => 'bx-edit-alt', 'people' => [], 'recorded_total' => 0],
];
$contributorsAvailable = false;

try {
    $pdo = Database::connection();
    Database::ensureAccountCoordinatorColumns();
    Database::ensureManuscriptTable();

    $campusStatement = $pdo->prepare(
        'SELECT campusname
         FROM tblcampus
         WHERE campusid = :campusid
         LIMIT 1'
    );
    $campusStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $campusStatement->execute();
    $resolvedCampusLabel = trim((string) $campusStatement->fetchColumn());

    if ($resolvedCampusLabel !== '') {
        $campusLabel = $resolvedCampusLabel;
    }

    $summaryStatement = $pdo->prepare(
        "SELECT COUNT(*) AS records_total,
                SUM(CASE WHEN TRIM(COALESCE(other_details, '')) <> '' OR TRIM(COALESCE(abstract_file_path, '')) <> '' THEN 1 ELSE 0 END) AS with_abstract,
                SUM(CASE WHEN TRIM(COALESCE(authors, '')) <> '' THEN 1 ELSE 0 END) AS with_authors,
                COUNT(DISTINCT CASE WHEN COALESCE(typeid, 0) > 0 THEN typeid END) AS types_used,
                COUNT(DISTINCT CASE WHEN COALESCE(programid, 0) > 0 THEN programid END) AS programs_used,
                MAX(updated_at) AS latest_update
         FROM tblresearches
         WHERE campusid = :campusid"
    );
    $summaryStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $summaryStatement->execute();
    $summary = $summaryStatement->fetch();

    if (is_array($summary)) {
        $stats['records_total'] = coordinator_dashboard_int($summary, 'records_total');
        $stats['with_abstract'] = coordinator_dashboard_int($summary, 'with_abstract');
        $stats['with_authors'] = coordinator_dashboard_int($summary, 'with_authors');
        $stats['types_used'] = coordinator_dashboard_int($summary, 'types_used');
        $stats['programs_used'] = coordinator_dashboard_int($summary, 'programs_used');
        $stats['latest_update'] = isset($summary['latest_update']) ? (string) $summary['latest_update'] : '';
    }

    $programStatement = $pdo->prepare(
        "SELECT COALESCE(m.programid, 0) AS program_key,
                p.coursecode,
                c.collegeid,
                c.collegename,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblcourse p ON p.courseid = m.programid
         LEFT JOIN tblcollege c
           ON c.collegeid = CAST(NULLIF(TRIM(COALESCE(p.coursecollege, '')), '') AS UNSIGNED)
          AND CAST(NULLIF(TRIM(COALESCE(c.collegecampus, '')), '') AS UNSIGNED) = m.campusid
         WHERE m.campusid = :campusid
         GROUP BY COALESCE(m.programid, 0), p.coursecode, c.collegeid, c.collegename
         ORDER BY program_key ASC"
    );
    $programStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $programStatement->execute();
    $programBreakdown = $programStatement->fetchAll();

    $sdgStatement = $pdo->prepare(
        'SELECT sdgs, COUNT(*) AS total
         FROM tblresearches
         WHERE campusid = :campusid
         GROUP BY sdgs'
    );
    $sdgStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $sdgStatement->execute();

    foreach ($sdgStatement->fetchAll() as $sdgRow) {
        // Each manuscript counts once per valid goal, even if its stored codes repeat.
        $goals = coordinator_student_integer_selection($sdgRow['sdgs'] ?? '', $sdgOptions);
        $total = (int) $sdgRow['total'];

        if ($goals !== []) {
            $sdgAlignedTotal += $total;
        }

        foreach ($goals as $goal) {
            $sdgCounts[$goal] += $total;
        }
    }

    $sdgAvailable = true;
} catch (Throwable $exception) {
    $dashboardError = $exception->getMessage();
}

if ($dashboardError === null) {
    try {
        // Account IDs keep people with the same name separate. Panelist links are
        // counted once per manuscript, regardless of duplicate assignment rows.
        $contributorStatement = $pdo->prepare(
            "SELECT 'advisers' AS role_key, a.accountid, a.acc_name, COUNT(DISTINCT m.titleid) AS total
             FROM tblresearches m
             INNER JOIN tblaccount a ON a.accountid = m.adviser_accountid
             WHERE m.campusid = :adviser_campus
             GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'panelists' AS role_key, a.accountid, a.acc_name, COUNT(DISTINCT m.titleid) AS total
             FROM tblresearches m
             INNER JOIN tblmanuscript_panelists mp ON mp.manuscriptid = m.titleid
             INNER JOIN tblaccount a ON a.accountid = mp.accountid
             WHERE m.campusid = :panelist_campus
             GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'statisticians' AS role_key, a.accountid, a.acc_name, COUNT(DISTINCT m.titleid) AS total
             FROM tblresearches m
             INNER JOIN tblaccount a ON a.accountid = m.statistician_accountid
             WHERE m.campusid = :statistician_campus
             GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'english-critics' AS role_key, a.accountid, a.acc_name, COUNT(DISTINCT m.titleid) AS total
             FROM tblresearches m
             INNER JOIN tblaccount a ON a.accountid = m.english_critic_accountid
             WHERE m.campusid = :critic_campus
             GROUP BY a.accountid, a.acc_name
             ORDER BY role_key ASC, total DESC, acc_name ASC, accountid ASC"
        );
        foreach (['adviser_campus', 'panelist_campus', 'statistician_campus', 'critic_campus'] as $parameter) {
            $contributorStatement->bindValue(':' . $parameter, $campusId, PDO::PARAM_INT);
        }
        $contributorStatement->execute();

        foreach ($contributorStatement->fetchAll() as $contributorRow) {
            $roleKey = (string) $contributorRow['role_key'];
            $name = trim((string) $contributorRow['acc_name']);
            $contributorRoles[$roleKey]['people'][] = [
                'name' => $name !== '' ? (preg_replace('/\s+/u', ' ', $name) ?? $name) : 'Account #' . (int) $contributorRow['accountid'],
                'total' => (int) $contributorRow['total'],
            ];
            $contributorRoles[$roleKey]['recorded_total'] += (int) $contributorRow['total'];
        }

        // Several panelists may be attached to one manuscript, so their summed
        // individual totals must not be used as manuscript coverage.
        $panelistCoverageStatement = $pdo->prepare(
            'SELECT COUNT(DISTINCT m.titleid)
             FROM tblresearches m
             INNER JOIN tblmanuscript_panelists mp ON mp.manuscriptid = m.titleid
             INNER JOIN tblaccount a ON a.accountid = mp.accountid
             WHERE m.campusid = :campusid'
        );
        $panelistCoverageStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
        $panelistCoverageStatement->execute();
        $contributorRoles['panelists']['recorded_total'] = (int) $panelistCoverageStatement->fetchColumn();
        $contributorsAvailable = true;
    } catch (Throwable $exception) {
        error_log('Coordinator contributor statistics: ' . $exception->getMessage());
    }
}

$programPalette = ['#2563eb', '#c2410c', '#7c3aed', '#047857', '#be185d', '#0e7490', '#a16207', '#4338ca', '#b91c1c', '#4d7c0f', '#a21caf', '#0369a1'];
$collegeGroups = [];
$colorIndex = 0;

foreach ($programBreakdown as $programRow) {
    $collegeId = (int) ($programRow['collegeid'] ?? 0);
    $collegeName = trim((string) ($programRow['collegename'] ?? ''));
    $programTotal = (int) ($programRow['total'] ?? 0);

    if (!isset($collegeGroups[$collegeId])) {
        $collegeGroups[$collegeId] = [
            'id' => $collegeId,
            'name' => $collegeId > 0 ? ($collegeName !== '' ? $collegeName : 'College #' . $collegeId) : 'College not assigned',
            'total' => 0,
            'programs' => [],
        ];
    }

    $color = (int) ($programRow['program_key'] ?? 0) === 0 ? '#94a3b8' : '#64748b';

    if ($collegeId > 0) {
        $color = $programPalette[$colorIndex] ?? ('hsl(' . (($colorIndex * 137) % 360) . ', 65%, 35%)');
        $colorIndex++;
    }

    $collegeGroups[$collegeId]['total'] += $programTotal;
    $collegeGroups[$collegeId]['programs'][] = [
        'code' => coordinator_dashboard_program_label($programRow),
        'total' => $programTotal,
        'color' => $color,
    ];
}

foreach ($collegeGroups as &$collegeGroup) {
    usort($collegeGroup['programs'], static function (array $left, array $right): int {
        return ($right['total'] <=> $left['total']) ?: strnatcasecmp($left['code'], $right['code']);
    });
}
unset($collegeGroup);

uasort($collegeGroups, static function (array $left, array $right): int {
    return (($left['id'] === 0) <=> ($right['id'] === 0))
        ?: ($right['total'] <=> $left['total'])
        ?: strnatcasecmp($left['name'], $right['name']);
});

$activeCollegeCount = count(array_filter($collegeGroups, static function (array $college): bool {
    return $college['id'] > 0;
}));
$largestCollegeTotal = $collegeGroups !== [] ? max(array_column($collegeGroups, 'total')) : 0;
$scaleMagnitude = pow(10, floor(log10(max(1, $largestCollegeTotal / 4))));
$scaleStep = max(1, (int) (ceil($largestCollegeTotal / 4 / $scaleMagnitude) * $scaleMagnitude));
$chartScale = $scaleStep * 4;
$abstractCoverage = coordinator_dashboard_percentage($stats['with_abstract'], $stats['records_total']);
$authorCoverage = coordinator_dashboard_percentage($stats['with_authors'], $stats['records_total']);
$sdgCoverage = coordinator_dashboard_percentage($sdgAlignedTotal, $stats['records_total']);
$sdgUnassignedTotal = max(0, $stats['records_total'] - $sdgAlignedTotal);
$representedGoalCount = count(array_filter($sdgCounts));
$rankedGoals = array_keys($sdgOptions);
usort($rankedGoals, static function (int $left, int $right) use ($sdgCounts): int {
    return ($sdgCounts[$right] <=> $sdgCounts[$left]) ?: ($left <=> $right);
});
$sdgColors = [
    1 => '#e5243b', 2 => '#dda63a', 3 => '#4c9f38', 4 => '#c5192d',
    5 => '#ff3a21', 6 => '#26bde2', 7 => '#fcc30b', 8 => '#a21942',
    9 => '#fd6925', 10 => '#dd1367', 11 => '#fd9d24', 12 => '#bf8b2e',
    13 => '#3f7e44', 14 => '#0a97d9', 15 => '#56c02b', 16 => '#00689d', 17 => '#19486a',
];

$extraHead = '<link rel="stylesheet" href="' . e(app_link('assets/css/coordinator-dashboard.css')) . '?v=' . filemtime(dirname(__DIR__) . '/assets/css/coordinator-dashboard.css') . '">';
$extraScripts = '<script src="' . e(app_link('assets/js/coordinator-dashboard.js')) . '?v=' . filemtime(dirname(__DIR__) . '/assets/js/coordinator-dashboard.js') . '"></script>';

ob_start();
?>
<?php if ($dashboardError !== null): ?>
  <div class="coord-alert error" data-coord-swal data-swal-icon="error" data-swal-title="Unable to Load Dashboard" data-swal-text="<?= e($dashboardError); ?>" hidden><?= e($dashboardError); ?></div>
<?php endif; ?>

<section class="coord-card coord-analytics-hero">
  <div>
    <p class="coord-hero-eyebrow"><?= e($campusLabel); ?> &middot; Campus research</p>
    <h2 class="coord-hero-title">Research production at a glance</h2>
    <p class="coord-hero-copy">Explore college output, research contributors, and your campus alignment with the Sustainable Development Goals.</p>
  </div>
  <div class="coord-hero-actions">
    <a class="coord-btn-primary" href="<?= e(app_link('coordinator/research.php')); ?>">
      <i class="bx bx-edit-alt"></i>
      Manage manuscripts
    </a>
    <span class="coord-update">Latest update: <?= e(coordinator_dashboard_date_label($stats['latest_update'])); ?></span>
  </div>
</section>

<section class="coord-dashboard-grid" aria-label="Campus research analytics summary">
  <article class="coord-card coord-stat">
    <div class="coord-stat-top">
      <p class="coord-stat-kicker">Total Manuscripts</p>
      <span class="coord-stat-icon"><i class="bx bx-collection"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= e(number_format($stats['records_total'])); ?></p>
      <p class="coord-stat-label">Research, capstone, proposal, and related campus records.</p>
    </div>
  </article>

  <article class="coord-card coord-stat">
    <div class="coord-stat-top">
      <p class="coord-stat-kicker">SDG Coverage</p>
      <span class="coord-stat-icon"><i class="bx bx-globe" aria-hidden="true"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= $sdgAvailable ? e($sdgCoverage) : '&mdash;'; ?></p>
      <p class="coord-stat-label"><?= $sdgAvailable ? e(number_format($sdgAlignedTotal)) . ' manuscripts have at least one SDG tag.' : 'SDG statistics are currently unavailable.'; ?></p>
    </div>
  </article>

  <article class="coord-card coord-stat">
    <div class="coord-stat-top">
      <p class="coord-stat-kicker">Goals Represented</p>
      <span class="coord-stat-icon"><i class="bx bx-target-lock" aria-hidden="true"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= $sdgAvailable ? e((string) $representedGoalCount) . '<span class="coord-stat-denominator"> / 17</span>' : '&mdash;'; ?></p>
      <p class="coord-stat-label">Sustainable Development Goals represented in campus manuscripts.</p>
    </div>
  </article>

  <article class="coord-card coord-stat">
    <div class="coord-stat-top">
      <p class="coord-stat-kicker">Active Programs</p>
      <span class="coord-stat-icon"><i class="bx bx-network-chart"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= e(number_format($stats['programs_used'])); ?></p>
      <p class="coord-stat-label"><?= e(number_format($stats['types_used'])); ?> research type(s) represented in the campus catalog.</p>
    </div>
  </article>
</section>

<section id="college-output-chart" class="coord-card coord-production" data-view="share" aria-labelledby="college-output-title">
  <div class="coord-panel-header">
    <div>
      <h2 id="college-output-title" class="coord-panel-title">Research output by college</h2>
      <p class="coord-panel-copy">Ranked by output. Expand a college to see its programs.</p>
    </div>
    <?php if ($collegeGroups !== []): ?>
      <div class="coord-view-switch" role="group" aria-label="Chart comparison">
        <button type="button" data-chart-view="volume" aria-pressed="false">By volume</button>
        <button type="button" data-chart-view="share" aria-pressed="true">By share</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($collegeGroups === []): ?>
    <div class="coord-chart-empty">
      <i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i>
      <strong><?= $dashboardError !== null ? 'Research output is unavailable' : 'No campus manuscripts yet'; ?></strong>
      <p><?= $dashboardError !== null ? 'Please try loading the dashboard again.' : 'College and program contributions will appear once manuscripts are added.'; ?></p>
    </div>
  <?php else: ?>
    <div class="coord-production-meta">
      <span class="coord-meta-chip"><strong><?= e(number_format($activeCollegeCount)); ?></strong> <?= $activeCollegeCount === 1 ? 'college' : 'colleges'; ?> with output</span>
      <span class="coord-meta-chip"><strong><?= e(number_format($stats['programs_used'])); ?></strong> active programs</span>
    </div>
    <p class="coord-chart-hint" data-chart-hint aria-live="polite">Each bar is 100% of its college's output. Colors show program shares.</p>

    <div class="coord-chart-axis" aria-hidden="true">
      <?php for ($tick = 0; $tick <= 4; $tick++): ?>
        <span data-axis-count="<?= e(number_format($scaleStep * $tick)); ?>" data-axis-share="<?= e((string) ($tick * 25)); ?>%">
          <?= e((string) ($tick * 25)); ?>%
        </span>
      <?php endfor; ?>
    </div>

    <?php foreach ($collegeGroups as $college): ?>
      <?php $collegeWidth = number_format(($college['total'] / $chartScale) * 100, 6, '.', ''); ?>
      <article class="coord-college" aria-labelledby="college-name-<?= e((string) $college['id']); ?>">
        <div class="coord-college-heading">
          <h3 id="college-name-<?= e((string) $college['id']); ?>" class="coord-college-name"><?= e($college['name']); ?></h3>
          <div class="coord-college-total">
            <span><strong><?= e(number_format($college['total'])); ?></strong><span class="visually-hidden"> manuscripts</span></span>
            <span><?= e(coordinator_dashboard_percentage($college['total'], $stats['records_total'])); ?> of campus</span>
          </div>
        </div>
        <div class="coord-college-track" role="img" aria-label="<?= e($college['name'] . ': ' . number_format($college['total']) . ' manuscripts. Open the program breakdown for details.'); ?>">
          <div class="coord-college-stack" style="--college-width: <?= e($collegeWidth); ?>%;" aria-hidden="true">
            <?php foreach ($college['programs'] as $program): ?>
              <?php $programShare = number_format(($program['total'] / $college['total']) * 100, 6, '.', ''); ?>
              <div
                class="coord-program-segment"
                style="--program-color: <?= e($program['color']); ?>; --segment-share: <?= e($programShare); ?>%;"
                title="<?= e($program['code'] . ': ' . number_format($program['total']) . ' manuscripts (' . coordinator_dashboard_percentage($program['total'], $college['total']) . ' of this group)'); ?>"
              >
                <span class="coord-program-label"><?= e($program['code']); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <details class="coord-program-details">
          <summary>
            <span><?= e((string) count($college['programs'])); ?> <?= count($college['programs']) === 1 ? 'program' : 'programs'; ?></span>
            <span class="coord-program-disclosure"><span class="coord-program-show">View breakdown</span><span class="coord-program-hide">Hide breakdown</span><i class="bx bx-chevron-down" aria-hidden="true"></i></span>
          </summary>
          <ul class="coord-program-keys" aria-label="<?= e('Program contributions for ' . $college['name']); ?>">
          <?php foreach ($college['programs'] as $program): ?>
            <li class="coord-program-key" style="--program-color: <?= e($program['color']); ?>;">
              <span class="coord-program-dot" aria-hidden="true"></span>
              <span><?= e($program['code']); ?></span>
              <strong><?= e(number_format($program['total'])); ?><span class="visually-hidden"> manuscripts</span></strong>
              <small>(<?= e(coordinator_dashboard_percentage($program['total'], $college['total'])); ?>)</small>
            </li>
          <?php endforeach; ?>
          </ul>
        </details>
        <?php if ($college['id'] === 0): ?>
          <p class="coord-college-note">These campus records do not have a program linked to a college on this campus.</p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($dashboardError === null && $stats['records_total'] > 0): ?>
    <div class="coord-completeness" aria-label="Catalog completeness">
      <p class="coord-completeness-title">Catalog completeness</p>
      <dl>
        <div><dt><i class="bx bx-file" aria-hidden="true"></i> With abstracts</dt><dd><?= e($abstractCoverage); ?><small><?= e(number_format($stats['with_abstract'])); ?> manuscripts</small></dd></div>
        <div><dt><i class="bx bx-user-check" aria-hidden="true"></i> With authors</dt><dd><?= e($authorCoverage); ?><small><?= e(number_format($stats['with_authors'])); ?> manuscripts</small></dd></div>
      </dl>
    </div>
  <?php endif; ?>
</section>

<div class="coord-analytics-grid">
<section class="coord-card coord-contributors" aria-labelledby="contributor-title">
  <div class="coord-panel-header">
    <div>
      <p class="coord-section-eyebrow">Research support</p>
      <h2 id="contributor-title" class="coord-panel-title">Leading research contributors</h2>
      <p class="coord-panel-copy">Top 20 people in each role, ranked by campus manuscript count.</p>
    </div>
    <span class="coord-panel-symbol" aria-hidden="true"><i class="bx bx-group"></i></span>
  </div>

  <?php if (!$contributorsAvailable): ?>
    <div class="coord-chart-empty">
      <i class="bx bx-group" aria-hidden="true"></i>
      <strong>Contributor statistics are unavailable</strong>
      <p>Please try loading the dashboard again.</p>
    </div>
  <?php else: ?>
    <div class="coord-contributor-tabs" role="tablist" aria-label="Research contributor roles" data-contributor-tabs hidden>
      <?php foreach ($contributorRoles as $roleKey => $role): ?>
        <button type="button" id="contributor-tab-<?= e($roleKey); ?>" class="coord-contributor-tab" role="tab" aria-controls="contributor-panel-<?= e($roleKey); ?>" aria-selected="<?= $roleKey === 'advisers' ? 'true' : 'false'; ?>" tabindex="<?= $roleKey === 'advisers' ? '0' : '-1'; ?>" data-contributor-tab="<?= e($roleKey); ?>">
          <i class="bx <?= e($role['icon']); ?>" aria-hidden="true"></i><span><?= e($role['label']); ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($contributorRoles as $roleKey => $role): ?>
      <?php
      $peopleTotal = count($role['people']);
      $topPeople = array_slice($role['people'], 0, 20);
      $leadingTotal = (int) ($topPeople[0]['total'] ?? 0);
      $roleCoverage = coordinator_dashboard_percentage($role['recorded_total'], $stats['records_total']);
      ?>
      <div id="contributor-panel-<?= e($roleKey); ?>" class="coord-contributor-panel" role="tabpanel" aria-labelledby="contributor-tab-<?= e($roleKey); ?>" tabindex="0" data-contributor-panel="<?= e($roleKey); ?>">
        <div class="coord-contributor-overview">
          <div><span>Contributors</span><strong><?= e(number_format($peopleTotal)); ?></strong><small>With recorded assignments</small></div>
          <div><span>Manuscripts covered</span><strong><?= e(number_format($role['recorded_total'])); ?><small> / <?= e(number_format($stats['records_total'])); ?></small></strong><small><?= e($roleCoverage); ?> of campus manuscripts</small></div>
        </div>
        <div class="coord-ranking-heading">
          <h3><?= e($role['title']); ?></h3>
          <span><?= $peopleTotal > 0 ? 'Showing ' . e((string) count($topPeople)) . ' of ' . e(number_format($peopleTotal)) : 'No assignments'; ?></span>
        </div>

        <?php if ($topPeople === []): ?>
          <div class="coord-contributor-empty">
            <i class="bx <?= e($role['icon']); ?>" aria-hidden="true"></i>
            <strong>No <?= e(strtolower($role['label'])); ?> recorded yet</strong>
            <p>Assign this role to campus manuscripts to see contributor statistics.</p>
          </div>
        <?php else: ?>
          <div class="coord-ranking-columns" aria-hidden="true"><span>Rank</span><span>Name</span><span>Manuscripts</span></div>
          <ol class="coord-contributor-ranking" aria-label="<?= e($role['title'] . ' by manuscript count'); ?>">
            <?php $previousTotal = null; $rank = 0; ?>
            <?php foreach ($topPeople as $personIndex => $person): ?>
              <?php
              if ($person['total'] !== $previousTotal) {
                  $rank = $personIndex + 1;
              }
              $previousTotal = $person['total'];
              $relativeWidth = number_format(($person['total'] / max(1, $leadingTotal)) * 100, 2, '.', '');
              ?>
              <li class="coord-contributor-row">
                <span class="coord-contributor-rank<?= $rank <= 3 ? ' coord-rank-leading' : ''; ?>"><span class="visually-hidden">Rank </span><?= e((string) $rank); ?></span>
                <div class="coord-contributor-person"><span class="coord-contributor-name"><?= e($person['name']); ?></span><span class="coord-contributor-meter" aria-hidden="true"><span style="width: <?= e($relativeWidth); ?>%;"></span></span></div>
                <strong class="coord-contributor-count"><?= e(number_format($person['total'])); ?><span class="visually-hidden"> <?= $person['total'] === 1 ? 'manuscript' : 'manuscripts'; ?></span></strong>
              </li>
            <?php endforeach; ?>
          </ol>
          <p class="coord-contributor-ties">Equal totals share a rank. Tied names are listed alphabetically.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="coord-contributor-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Each manuscript counts once per person in a role. A manuscript can have several panelists and appear in more than one role.</p></div>
  <?php endif; ?>
</section>

<section class="coord-card coord-sdg-panel" aria-labelledby="sdg-alignment-title">
  <div class="coord-panel-header">
    <div>
      <p class="coord-section-eyebrow">Sustainable development</p>
      <h2 id="sdg-alignment-title" class="coord-panel-title">Research by SDG</h2>
      <p class="coord-panel-copy">Most represented goals in your campus catalog.</p>
    </div>
    <span class="coord-panel-symbol" aria-hidden="true"><i class="bx bx-globe"></i></span>
  </div>

  <?php if (!$sdgAvailable || $stats['records_total'] === 0 || $sdgAlignedTotal === 0): ?>
    <div class="coord-chart-empty">
      <i class="bx bx-globe" aria-hidden="true"></i>
      <strong><?= !$sdgAvailable ? 'SDG statistics are unavailable' : ($stats['records_total'] === 0 ? 'No SDG statistics yet' : 'No SDG tags assigned'); ?></strong>
      <p><?= !$sdgAvailable ? 'Please try loading the dashboard again.' : ($stats['records_total'] === 0 ? 'Goal counts will appear as campus manuscripts are added and tagged.' : 'Add SDG tags in manuscript management to start tracking goal alignment.'); ?></p>
    </div>
  <?php else: ?>
    <div class="coord-sdg-coverage">
      <div class="coord-sdg-coverage-heading"><span>Manuscripts with SDG tags</span><strong><?= e($sdgCoverage); ?></strong></div>
      <div class="coord-sdg-coverage-track" role="img" aria-label="<?= e(number_format($sdgAlignedTotal) . ' of ' . number_format($stats['records_total']) . ' manuscripts have at least one valid SDG tag (' . $sdgCoverage . ').'); ?>"><span style="width: <?= e($sdgCoverage); ?>;"></span></div>
      <p><strong><?= e(number_format($sdgAlignedTotal)); ?></strong> of <?= e(number_format($stats['records_total'])); ?> manuscripts <span aria-hidden="true">&middot;</span> <?= e((string) $representedGoalCount); ?> goals represented</p>
    </div>

    <div class="coord-sdg-list-head" aria-hidden="true"><span>Goal</span><span>Manuscripts / campus share</span></div>
    <ol id="coord-sdg-goals" class="coord-sdg-ranking" aria-label="Sustainable Development Goals ranked by manuscript count">
      <?php foreach ($rankedGoals as $goalIndex => $goalCode): ?>
        <?php
        $goalTotal = $sdgCounts[$goalCode];
        $goalShare = coordinator_dashboard_percentage($goalTotal, $stats['records_total']);
        $goalImage = 'assets/img/sdgs/sdg-' . str_pad((string) $goalCode, 2, '0', STR_PAD_LEFT) . '.png';
        ?>
        <li class="coord-sdg-row<?= $goalTotal === 0 ? ' coord-sdg-zero' : ''; ?>"<?= $goalIndex >= 6 ? ' data-sdg-extra' : ''; ?> style="--sdg-color: <?= e($sdgColors[$goalCode]); ?>;">
          <img class="coord-sdg-icon" src="<?= e(app_link($goalImage)); ?>" width="42" height="42" alt="" loading="lazy">
          <div class="coord-sdg-goal">
            <span class="coord-sdg-code">SDG <?= e((string) $goalCode); ?></span>
            <span class="coord-sdg-name"><?= e($sdgOptions[$goalCode]); ?></span>
            <span class="coord-sdg-meter" aria-hidden="true"><span style="width: <?= e($goalShare); ?>;"></span></span>
          </div>
          <div class="coord-sdg-amount"><strong><?= e(number_format($goalTotal)); ?><span class="visually-hidden"> <?= $goalTotal === 1 ? 'manuscript' : 'manuscripts'; ?></span></strong><span><?= e($goalShare); ?><span class="visually-hidden"> of all campus manuscripts</span></span></div>
        </li>
      <?php endforeach; ?>
    </ol>
    <button class="coord-sdg-expand" type="button" data-sdg-expand aria-expanded="true" aria-controls="coord-sdg-goals" hidden>
      <span data-sdg-expand-label>Show fewer goals</span><i class="bx bx-chevron-up" aria-hidden="true"></i>
    </button>

    <div class="coord-sdg-note">
      <i class="bx <?= $sdgUnassignedTotal > 0 ? 'bx-info-circle' : 'bx-check-circle'; ?>" aria-hidden="true"></i>
      <p><?= $sdgUnassignedTotal > 0 ? '<strong>' . e(number_format($sdgUnassignedTotal)) . '</strong> ' . ($sdgUnassignedTotal === 1 ? 'manuscript has' : 'manuscripts have') . ' no SDG tags yet.' : 'Every manuscript has at least one SDG tag.'; ?></p>
    </div>
    <p class="coord-sdg-counting-note">Percentages use all campus manuscripts. A manuscript can appear under more than one goal.</p>
  <?php endif; ?>
</section>
</div>
<?php
$mainContent = (string) ob_get_clean();

CoordinatorPage::render([
    'title' => 'Campus Analytics Dashboard',
    'current_page' => 'dashboard',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_scripts' => $extraScripts,
    'campus_label' => $campusLabel,
]);
