<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireResearchCoordinator();

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
$dashboardError = null;

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
} catch (Throwable $exception) {
    $dashboardError = $exception->getMessage();
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
    <p class="coord-hero-copy">Explore your campus output by college and see how each program contributes.</p>
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
      <p class="coord-stat-kicker">Abstract Coverage</p>
      <span class="coord-stat-icon"><i class="bx bx-file"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= e($abstractCoverage); ?></p>
      <p class="coord-stat-label"><?= e(number_format($stats['with_abstract'])); ?> record(s) have abstract text or an uploaded file.</p>
    </div>
  </article>

  <article class="coord-card coord-stat">
    <div class="coord-stat-top">
      <p class="coord-stat-kicker">Author Readiness</p>
      <span class="coord-stat-icon"><i class="bx bx-user-check"></i></span>
    </div>
    <div>
      <p class="coord-stat-value"><?= e($authorCoverage); ?></p>
      <p class="coord-stat-label"><?= e(number_format($stats['with_authors'])); ?> record(s) include encoded authors.</p>
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

<section id="college-output-chart" class="coord-card coord-production" data-view="volume" aria-labelledby="college-output-title">
  <div class="coord-panel-header">
    <div>
      <h2 id="college-output-title" class="coord-panel-title">Research output by college</h2>
      <p class="coord-panel-copy">Compare college totals and each program's share of research production.</p>
    </div>
    <?php if ($collegeGroups !== []): ?>
      <div class="coord-view-switch" role="group" aria-label="Chart comparison">
        <button type="button" data-chart-view="volume" aria-pressed="true">By volume</button>
        <button type="button" data-chart-view="share" aria-pressed="false">By share</button>
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
      <span class="coord-meta-chip"><strong><?= e(number_format($stats['records_total'])); ?></strong> manuscripts</span>
      <span class="coord-meta-chip"><strong><?= e(number_format($activeCollegeCount)); ?></strong> <?= $activeCollegeCount === 1 ? 'college' : 'colleges'; ?> with output</span>
      <p class="coord-chart-hint" data-chart-hint aria-live="polite">Bar length compares manuscript totals. Colors show programs.</p>
    </div>

    <div class="coord-chart-axis" aria-hidden="true">
      <?php for ($tick = 0; $tick <= 4; $tick++): ?>
        <span data-axis-count="<?= e(number_format($scaleStep * $tick)); ?>" data-axis-share="<?= e((string) ($tick * 25)); ?>%">
          <?= e(number_format($scaleStep * $tick)); ?>
        </span>
      <?php endfor; ?>
    </div>

    <?php foreach ($collegeGroups as $college): ?>
      <?php $collegeWidth = number_format(($college['total'] / $chartScale) * 100, 6, '.', ''); ?>
      <article class="coord-college" aria-labelledby="college-name-<?= e((string) $college['id']); ?>">
        <div class="coord-college-heading">
          <h3 id="college-name-<?= e((string) $college['id']); ?>" class="coord-college-name"><?= e($college['name']); ?></h3>
          <div class="coord-college-total">
            <span><strong><?= e(number_format($college['total'])); ?></strong> manuscripts</span>
            <span><?= e(coordinator_dashboard_percentage($college['total'], $stats['records_total'])); ?> of campus</span>
          </div>
        </div>
        <div class="coord-college-track" role="img" aria-label="<?= e($college['name'] . ': ' . number_format($college['total']) . ' manuscripts. Program contributions are listed below.'); ?>">
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
        <?php if ($college['id'] === 0): ?>
          <p class="coord-college-note">These campus records do not have a program linked to a college on this campus.</p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
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
