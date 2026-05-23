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
    $courseDescription = trim((string) ($row['coursedescription'] ?? ''));
    $courseMajor = trim((string) ($row['coursemajor'] ?? ''));
    $parts = [];

    if ($courseCode !== '') {
        $parts[] = $courseCode;
    }

    if ($courseDescription !== '') {
        $parts[] = $courseDescription;
    }

    $label = $parts !== [] ? implode(' - ', $parts) : ($programKey > 0 ? 'Program #' . $programKey : 'Unassigned program');

    if ($courseMajor !== '') {
        $label .= ' (' . $courseMajor . ')';
    }

    return $label;
}

function coordinator_dashboard_short_label(string $value, int $limit = 42): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    $normalized = is_string($normalized) ? $normalized : trim($value);

    if ($normalized === '' || strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, $limit - 3)) . '...';
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
$recentRecords = [];
$programBreakdown = [];
$chartRows = [];
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
                p.coursedescription,
                p.coursemajor,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblcourse p ON p.courseid = m.programid
         WHERE m.campusid = :campusid
         GROUP BY COALESCE(m.programid, 0), p.coursecode, p.coursedescription, p.coursemajor
         ORDER BY total DESC, p.coursecode ASC
         LIMIT 8"
    );
    $programStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $programStatement->execute();
    $programBreakdown = $programStatement->fetchAll();

    $chartStatement = $pdo->prepare(
        "SELECT COALESCE(m.programid, 0) AS program_key,
                p.coursecode,
                p.coursedescription,
                p.coursemajor,
                COALESCE(NULLIF(TRIM(rt.research_type), ''), 'Research record') AS research_type,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblcourse p ON p.courseid = m.programid
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         WHERE m.campusid = :campusid
         GROUP BY COALESCE(m.programid, 0), p.coursecode, p.coursedescription, p.coursemajor, COALESCE(NULLIF(TRIM(rt.research_type), ''), 'Research record')
         ORDER BY p.coursecode ASC, research_type ASC"
    );
    $chartStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $chartStatement->execute();
    $chartRows = $chartStatement->fetchAll();

    $recentStatement = $pdo->prepare(
        "SELECT m.titleid,
                m.title,
                m.status,
                m.updated_at,
                rt.research_type,
                p.coursecode,
                p.coursedescription,
                p.coursemajor
         FROM tblresearches m
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         LEFT JOIN tblcourse p ON p.courseid = m.programid
         WHERE m.campusid = :campusid
         ORDER BY COALESCE(m.updated_at, m.submitted_at) DESC, m.titleid DESC
         LIMIT 7"
    );
    $recentStatement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
    $recentStatement->execute();
    $recentRecords = $recentStatement->fetchAll();
} catch (Throwable $exception) {
    $dashboardError = $exception->getMessage();
}

$programKeys = [];
$programChartCategories = [];
$programIndexMap = [];

foreach ($programBreakdown as $index => $programRow) {
    $programKey = isset($programRow['program_key']) ? (int) $programRow['program_key'] : 0;
    $programKeys[] = $programKey;
    $programIndexMap[$programKey] = $index;
    $programChartCategories[] = coordinator_dashboard_program_label($programRow);
}

$seriesMap = [];

foreach ($chartRows as $chartRow) {
    $programKey = isset($chartRow['program_key']) ? (int) $chartRow['program_key'] : 0;

    if (!array_key_exists($programKey, $programIndexMap)) {
        continue;
    }

    $typeLabel = trim((string) ($chartRow['research_type'] ?? 'Research record'));
    $typeLabel = $typeLabel !== '' ? $typeLabel : 'Research record';

    if (!isset($seriesMap[$typeLabel])) {
        $seriesMap[$typeLabel] = array_fill(0, count($programChartCategories), 0);
    }

    $seriesMap[$typeLabel][$programIndexMap[$programKey]] = (int) ($chartRow['total'] ?? 0);
}

$programChartSeries = [];

foreach ($seriesMap as $seriesName => $seriesData) {
    $programChartSeries[] = [
        'name' => $seriesName,
        'data' => array_values($seriesData),
    ];
}

if ($programChartSeries === [] && $programBreakdown !== []) {
    $programChartSeries[] = [
        'name' => 'Total records',
        'data' => array_map(static function (array $row): int {
            return (int) ($row['total'] ?? 0);
        }, $programBreakdown),
    ];
}

$programChartData = [
    'categories' => $programChartCategories,
    'series' => $programChartSeries,
];
$programChartJson = json_encode($programChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$programChartJson = is_string($programChartJson) ? $programChartJson : '{"categories":[],"series":[]}';
$abstractCoverage = coordinator_dashboard_percentage($stats['with_abstract'], $stats['records_total']);
$authorCoverage = coordinator_dashboard_percentage($stats['with_authors'], $stats['records_total']);

$extraStyles = '
.coord-analytics-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(18rem, 0.55fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.coord-hero-panel {
  padding: 1.25rem;
  border-left: 4px solid #15803d;
}

.coord-hero-eyebrow {
  margin: 0 0 0.45rem;
  color: #15803d;
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.coord-hero-title {
  margin: 0;
  color: #111827;
  font-size: 1.35rem;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-weight: 700;
  letter-spacing: -0.04em;
}

.coord-hero-copy {
  max-width: 58rem;
  margin: 0.55rem 0 0;
  color: #4b5563;
  line-height: 1.65;
}

.coord-hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-top: 1rem;
}

.coord-scope-panel {
  padding: 1.15rem;
  display: grid;
  align-content: center;
  gap: 0.55rem;
}

.coord-scope-label {
  color: #6b7280;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
}

.coord-scope-value {
  color: #111827;
  font-size: 1.05rem;
  font-weight: 600;
}

.coord-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.coord-stat {
  min-height: 10rem;
  padding: 1.05rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.coord-stat-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.coord-stat-icon {
  width: 2.65rem;
  height: 2.65rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.8rem;
  background: #dcfce7;
  color: #15803d;
  font-size: 1.25rem;
}

.coord-stat-kicker {
  margin: 0;
  color: #6b7280;
  font-size: 0.78rem;
  font-weight: 700;
}

.coord-stat-value {
  margin: 1rem 0 0;
  color: #0f172a;
  font-size: 1.85rem;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-weight: 700;
  letter-spacing: -0.03em;
}

.coord-stat-label {
  margin: 0.25rem 0 0;
  color: #6b7280;
  font-size: 0.86rem;
  line-height: 1.45;
}

.coord-analytics-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(20rem, 0.72fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.coord-panel {
  padding: 1.15rem;
}

.coord-panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.85rem;
}

.coord-panel-title {
  margin: 0;
  color: #111827;
  font-size: 1rem;
  font-weight: 600;
  letter-spacing: 0;
}

.coord-panel-copy {
  margin: 0.25rem 0 0;
  color: #6b7280;
  line-height: 1.55;
}

.coord-chart {
  min-height: 340px;
}

.coord-chart-empty {
  min-height: 280px;
  display: grid;
  place-items: center;
  color: #6b7280;
  text-align: center;
}

.coord-breakdown-list,
.coord-activity-list {
  display: grid;
  gap: 0.65rem;
}

.coord-breakdown-row,
.coord-activity-row {
  display: grid;
  gap: 0.35rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid #edf2f7;
}

.coord-breakdown-row:last-child,
.coord-activity-row:last-child {
  border-bottom: 0;
}

.coord-breakdown-line {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.coord-breakdown-name,
.coord-activity-title {
  color: #111827;
  font-weight: 600;
  line-height: 1.4;
}

.coord-breakdown-count {
  color: #15803d;
  font-weight: 600;
}

.coord-progress-track {
  height: 0.45rem;
  overflow: hidden;
  border-radius: 999px;
  background: #ecfdf5;
}

.coord-progress-value {
  height: 100%;
  border-radius: inherit;
  background: #16a34a;
}

.coord-activity-meta {
  color: #6b7280;
  font-size: 0.84rem;
  line-height: 1.45;
}

.coord-pill-soft {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 2rem;
  padding: 0.35rem 0.7rem;
  border-radius: 999px;
  background: #f0fdf4;
  color: #166534;
  font-size: 0.78rem;
  font-weight: 600;
}

@media (max-width: 1199.98px) {
  .coord-dashboard-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .coord-analytics-hero,
  .coord-analytics-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 575.98px) {
  .coord-dashboard-grid {
    grid-template-columns: 1fr;
  }

  .coord-panel-header {
    display: block;
  }
}
';

$extraScripts = '<script src="' . e(app_link('assets/vendor/libs/apex-charts/apexcharts.js')) . '"></script>
<script>
(function () {
  const chartElement = document.getElementById("program-output-chart");
  const chartData = ' . $programChartJson . ';

  if (!chartElement) {
    return;
  }

  if (!window.ApexCharts || !chartData.series || chartData.series.length === 0 || !chartData.categories || chartData.categories.length === 0) {
    chartElement.innerHTML = "<div class=\"coord-chart-empty\">No chart data is available for this campus yet.</div>";
    return;
  }

  const shortenLabel = value => {
    const label = String(value || "");
    return label.length > 24 ? label.slice(0, 21) + "..." : label;
  };

  const chart = new ApexCharts(chartElement, {
    chart: {
      type: "line",
      height: 340,
      toolbar: { show: false },
      zoom: { enabled: false },
      fontFamily: "Public Sans, Segoe UI, Arial, sans-serif"
    },
    series: chartData.series,
    colors: ["#15803d", "#2563eb", "#f59e0b", "#7c3aed", "#dc2626", "#0891b2"],
    stroke: {
      curve: "smooth",
      width: 3
    },
    markers: {
      size: 4,
      strokeWidth: 2,
      hover: { size: 6 }
    },
    grid: {
      borderColor: "#e5e7eb",
      strokeDashArray: 4,
      padding: { left: 8, right: 8 }
    },
    xaxis: {
      categories: chartData.categories,
      labels: {
        rotate: -20,
        trim: false,
        style: { colors: "#64748b", fontSize: "12px" },
        formatter: shortenLabel
      },
      tooltip: { enabled: false }
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      decimalsInFloat: 0,
      labels: {
        style: { colors: "#64748b", fontSize: "12px" }
      },
      title: {
        text: "Number of research records",
        style: { color: "#64748b", fontWeight: 600 }
      }
    },
    legend: {
      position: "top",
      horizontalAlign: "left",
      fontWeight: 600,
      labels: { colors: "#334155" },
      markers: { radius: 12 }
    },
    tooltip: {
      shared: true,
      intersect: false,
      y: {
        formatter: value => Number(value || 0).toLocaleString() + " record(s)"
      }
    },
    dataLabels: { enabled: false }
  });

  chart.render();
})();
</script>';

ob_start();
?>
<?php if ($dashboardError !== null): ?>
  <div class="coord-alert error" data-coord-swal data-swal-icon="error" data-swal-title="Unable to Load Dashboard" data-swal-text="<?= e($dashboardError); ?>" hidden><?= e($dashboardError); ?></div>
<?php endif; ?>

<section class="coord-analytics-hero">
  <article class="coord-card coord-hero-panel">
    <p class="coord-hero-eyebrow">Campus Research Monitoring</p>
    <h2 class="coord-hero-title">Analytics dashboard for <?= e($campusLabel); ?></h2>
    <p class="coord-hero-copy">
      Monitor research output, manuscript readiness, and program-level distribution for your assigned campus.
      Use this dashboard for quick review, then update titles, authors, abstracts, SDG tags, and proposal details in Manuscript Management.
    </p>
    <div class="coord-hero-actions">
      <a class="coord-btn-primary" href="<?= e(app_link('coordinator/research.php')); ?>">
        <i class="bx bx-edit-alt"></i>
        Open Manuscript Management
      </a>
      <span class="coord-pill-soft"><i class="bx bx-time-five"></i> Latest update: <?= e(coordinator_dashboard_date_label($stats['latest_update'])); ?></span>
    </div>
  </article>

  <aside class="coord-card coord-scope-panel">
    <span class="coord-scope-label">Current data scope</span>
    <span class="coord-scope-value"><?= e($campusLabel); ?></span>
    <p class="coord-muted mb-0">All counts and trends are limited to records assigned to this campus.</p>
  </aside>
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

<section class="coord-analytics-grid">
  <article class="coord-card coord-panel">
    <div class="coord-panel-header">
      <div>
        <h2 class="coord-panel-title">Research Output by Program</h2>
        <p class="coord-panel-copy">Smooth multi-line trend showing the number of records per course/program grouped by research type.</p>
      </div>
      <span class="coord-pill-soft"><i class="bx bx-line-chart"></i> Apex analytics</span>
    </div>
    <div id="program-output-chart" class="coord-chart"></div>
  </article>

  <aside class="coord-card coord-panel">
    <div class="coord-panel-header">
      <div>
        <h2 class="coord-panel-title">Program Highlights</h2>
        <p class="coord-panel-copy">Top programs based on encoded campus records.</p>
      </div>
    </div>

    <div class="coord-breakdown-list">
      <?php if ($programBreakdown === []): ?>
        <p class="coord-muted mb-0">No program distribution is available yet.</p>
      <?php endif; ?>
      <?php foreach ($programBreakdown as $programRow): ?>
        <?php
        $programTotal = (int) ($programRow['total'] ?? 0);
        $programPercent = $stats['records_total'] > 0 ? max(4, min(100, (int) round(($programTotal / $stats['records_total']) * 100))) : 0;
        ?>
        <div class="coord-breakdown-row">
          <div class="coord-breakdown-line">
            <span class="coord-breakdown-name"><?= e(coordinator_dashboard_program_label($programRow)); ?></span>
            <span class="coord-breakdown-count"><?= e(number_format($programTotal)); ?></span>
          </div>
          <div class="coord-progress-track" aria-hidden="true">
            <span class="coord-progress-value" style="width: <?= e((string) $programPercent); ?>%;"></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>
</section>

<section class="coord-card coord-panel">
  <div class="coord-panel-header">
    <div>
      <h2 class="coord-panel-title">Recent Manuscript Activity</h2>
      <p class="coord-panel-copy">Latest campus records ready for review or updating.</p>
    </div>
    <a class="coord-btn-secondary" href="<?= e(app_link('coordinator/research.php')); ?>">
      <i class="bx bx-folder-open"></i>
      View all records
    </a>
  </div>

  <div class="coord-activity-list">
    <?php if ($recentRecords === []): ?>
      <p class="coord-muted mb-0">No manuscript activity has been recorded for this campus yet.</p>
    <?php endif; ?>
    <?php foreach ($recentRecords as $record): ?>
      <article class="coord-activity-row">
        <div class="coord-activity-title"><?= e((string) ($record['title'] ?? 'Untitled research')); ?></div>
        <div class="coord-activity-meta">
          <?= e((string) ($record['research_type'] ?? 'Research record')); ?>
          &middot; <?= e(coordinator_dashboard_short_label(coordinator_dashboard_program_label($record), 90)); ?>
          &middot; <?= e(coordinator_dashboard_date_label($record['updated_at'] ?? '')); ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php
$mainContent = (string) ob_get_clean();

CoordinatorPage::render([
    'title' => 'Campus Analytics Dashboard',
    'current_page' => 'dashboard',
    'main_content' => $mainContent,
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
    'campus_label' => $campusLabel,
]);
