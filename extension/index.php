<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireExtensionCoordinator();

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

function extension_date_label(?string $value, string $emptyLabel = 'Not set'): string
{
    $normalized = trim((string) $value);

    if ($normalized === '') {
        return $emptyLabel;
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('M j, Y', $timestamp) : $normalized;
}

function extension_money_label($value, string $emptyLabel = 'PHP 0.00'): string
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

function extension_short_text(string $value, int $limit = 72): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    $normalized = is_string($normalized) ? $normalized : trim($value);

    if ($normalized === '' || strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, $limit - 3)) . '...';
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

    return 'neutral';
}

function extension_status_badge(string $status): string
{
    return '<span class="extension-status extension-status--' . e(extension_status_tone($status)) . '">' . e($status) . '</span>';
}

$pdo = Database::connection();
Database::ensureExtensionProjectTables();

$metrics = [
    'total' => 0,
    'active' => 0,
    'pending' => 0,
    'completed' => 0,
    'archived' => 0,
    'budget_total' => 0.0,
    'with_budget' => 0,
    'with_agency' => 0,
    'with_sdgs' => 0,
    'with_dates' => 0,
    'with_components' => 0,
    'components' => 0,
    'overdue_active' => 0,
    'upcoming_30' => 0,
    'stale_active' => 0,
    'avg_duration' => null,
    'latest_update' => null,
];
$statusBreakdown = [];
$partnerBreakdown = [];
$topSdgItems = [];
$recentProjects = [];
$upcomingProjects = [];
$pageError = null;

try {
    $summary = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ('Approved', 'Ongoing') THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status IN ('Draft', 'Submitted') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) AS archived,
                COALESCE(SUM(total_budget), 0) AS budget_total,
                SUM(CASE WHEN total_budget IS NOT NULL AND total_budget > 0 THEN 1 ELSE 0 END) AS with_budget,
                SUM(CASE WHEN TRIM(COALESCE(cooperating_agency, '')) <> '' THEN 1 ELSE 0 END) AS with_agency,
                SUM(CASE WHEN TRIM(COALESCE(sdgs, '')) <> '' THEN 1 ELSE 0 END) AS with_sdgs,
                SUM(CASE WHEN start_date IS NOT NULL AND end_date IS NOT NULL THEN 1 ELSE 0 END) AS with_dates,
                SUM(CASE WHEN status IN ('Approved', 'Ongoing') AND end_date IS NOT NULL AND end_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_active,
                SUM(CASE WHEN status IN ('Approved', 'Ongoing') AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS upcoming_30,
                SUM(CASE WHEN status NOT IN ('Completed', 'Archived') AND updated_at < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS stale_active,
                AVG(CASE WHEN duration_months IS NOT NULL AND duration_months > 0 THEN duration_months END) AS avg_duration,
                MAX(updated_at) AS latest_update
         FROM tblextension_projects"
    )->fetch();

    if (is_array($summary)) {
        foreach (['total', 'active', 'pending', 'completed', 'archived', 'with_budget', 'with_agency', 'with_sdgs', 'with_dates', 'overdue_active', 'upcoming_30', 'stale_active'] as $metricKey) {
            $metrics[$metricKey] = (int) ($summary[$metricKey] ?? 0);
        }

        $metrics['budget_total'] = (float) ($summary['budget_total'] ?? 0);
        $metrics['avg_duration'] = $summary['avg_duration'] !== null ? (float) $summary['avg_duration'] : null;
        $metrics['latest_update'] = isset($summary['latest_update']) ? (string) $summary['latest_update'] : null;
    }

    $metrics['components'] = (int) $pdo->query('SELECT COUNT(*) FROM tblextension_project_components')->fetchColumn();
    $metrics['with_components'] = (int) $pdo->query('SELECT COUNT(DISTINCT extension_projectid) FROM tblextension_project_components')->fetchColumn();

    $statusRows = $pdo->query(
        'SELECT status,
                COUNT(*) AS total,
                COALESCE(SUM(total_budget), 0) AS budget_total
         FROM tblextension_projects
         GROUP BY status'
    )->fetchAll();
    $statusMap = [];

    foreach ($statusRows as $statusRow) {
        $statusName = trim((string) ($statusRow['status'] ?? 'Draft'));
        $statusMap[$statusName] = [
            'status' => $statusName,
            'total' => (int) ($statusRow['total'] ?? 0),
            'budget_total' => (float) ($statusRow['budget_total'] ?? 0),
        ];
    }

    foreach (extension_status_options() as $statusOption) {
        $statusBreakdown[] = $statusMap[$statusOption] ?? [
            'status' => $statusOption,
            'total' => 0,
            'budget_total' => 0.0,
        ];
    }

    $partnerBreakdown = $pdo->query(
        "SELECT cooperating_agency AS label,
                COUNT(*) AS total,
                COALESCE(SUM(total_budget), 0) AS budget_total
         FROM tblextension_projects
         WHERE TRIM(COALESCE(cooperating_agency, '')) <> ''
         GROUP BY cooperating_agency
         ORDER BY total DESC, budget_total DESC, cooperating_agency ASC
         LIMIT 6"
    )->fetchAll();

    $recentProjects = $pdo->query(
        'SELECT extension_projectid,
                project_title,
                status,
                project_leader_name,
                updated_at
         FROM tblextension_projects
         ORDER BY updated_at DESC, extension_projectid DESC
         LIMIT 6'
    )->fetchAll();

    $upcomingProjects = $pdo->query(
        "SELECT extension_projectid,
                project_title,
                status,
                end_date,
                project_leader_name
         FROM tblextension_projects
         WHERE status IN ('Approved', 'Ongoing')
           AND end_date IS NOT NULL
         ORDER BY CASE WHEN end_date < CURDATE() THEN 0 ELSE 1 END,
                  end_date ASC,
                  extension_projectid DESC
         LIMIT 6"
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

    foreach (array_slice($sdgCounts, 0, 6, true) as $sdgCode => $sdgCount) {
        $topSdgItems[] = [
            'code' => (int) $sdgCode,
            'label' => extension_sdg_options()[(int) $sdgCode] ?? 'SDG ' . (string) $sdgCode,
            'total' => (int) $sdgCount,
        ];
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$completionRate = extension_percent_label($metrics['completed'], $metrics['total']);
$budgetCoverage = extension_percent_label($metrics['with_budget'], $metrics['total']);
$partnerCoverage = extension_percent_label($metrics['with_agency'], $metrics['total']);
$sdgCoverage = extension_percent_label($metrics['with_sdgs'], $metrics['total']);
$dateCoverage = extension_percent_label($metrics['with_dates'], $metrics['total']);
$componentCoverage = extension_percent_label($metrics['with_components'], $metrics['total']);
$averageDurationLabel = $metrics['avg_duration'] !== null ? number_format((float) $metrics['avg_duration'], 1) . ' months' : 'No duration data';
$pipelinePriority = $metrics['overdue_active'] > 0
    ? 'Overdue active projects need immediate follow-up.'
    : ($metrics['stale_active'] > 0 ? 'Some open records have not been updated in 60 days.' : 'Pipeline data is current based on saved dates.');

$summaryCards = [
    [
        'label' => 'Total Projects',
        'value' => number_format($metrics['total']),
        'copy' => number_format($metrics['active']) . ' active, ' . number_format($metrics['pending']) . ' pending movement.',
        'icon' => 'bx-network-chart',
    ],
    [
        'label' => 'Completion Rate',
        'value' => $completionRate,
        'copy' => number_format($metrics['completed']) . ' completed project(s) in the registry.',
        'icon' => 'bx-check-shield',
    ],
    [
        'label' => 'Encoded Budget',
        'value' => extension_money_label($metrics['budget_total']),
        'copy' => $budgetCoverage . ' of records include funding values.',
        'icon' => 'bx-wallet',
    ],
    [
        'label' => 'Milestone Risk',
        'value' => number_format($metrics['overdue_active']),
        'copy' => number_format($metrics['upcoming_30']) . ' active project(s) end within 30 days.',
        'icon' => 'bx-calendar-exclamation',
    ],
];

$completenessItems = [
    ['label' => 'Budget encoded', 'count' => $metrics['with_budget'], 'percent' => $budgetCoverage, 'tone' => 'primary'],
    ['label' => 'Partner agency', 'count' => $metrics['with_agency'], 'percent' => $partnerCoverage, 'tone' => 'info'],
    ['label' => 'SDG alignment', 'count' => $metrics['with_sdgs'], 'percent' => $sdgCoverage, 'tone' => 'success'],
    ['label' => 'Start and end dates', 'count' => $metrics['with_dates'], 'percent' => $dateCoverage, 'tone' => 'warning'],
    ['label' => 'Component lines', 'count' => $metrics['with_components'], 'percent' => $componentCoverage, 'tone' => 'primary'],
];

$statusChartData = [
    'labels' => array_map(static function (array $row): string {
        return (string) ($row['status'] ?? '');
    }, $statusBreakdown),
    'counts' => array_map(static function (array $row): int {
        return (int) ($row['total'] ?? 0);
    }, $statusBreakdown),
    'budget' => array_map(static function (array $row): float {
        return (float) ($row['budget_total'] ?? 0);
    }, $statusBreakdown),
];
$statusChartJson = json_encode($statusChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$statusChartJson = is_string($statusChartJson) ? $statusChartJson : '{"labels":[],"counts":[],"budget":[]}';
$completenessChartData = [
    'labels' => array_map(static function (array $item): string {
        return $item['label'];
    }, $completenessItems),
    'counts' => array_map(static function (array $item): int {
        return (int) $item['count'];
    }, $completenessItems),
];
$completenessChartJson = json_encode($completenessChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$completenessChartJson = is_string($completenessChartJson) ? $completenessChartJson : '{"labels":[],"counts":[]}';

$extraStyles = <<<'CSS'
.extension-dashboard-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(20rem, 0.7fr);
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
  max-width: 54rem;
}

.extension-eyebrow {
  margin: 0 0 0.45rem;
  color: #0f766e;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.extension-hero-title,
.extension-panel-title,
.extension-stat-value,
.extension-signal-value {
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-weight: 700;
  letter-spacing: 0;
}

.extension-hero-title {
  margin: 0;
  font-size: 1.58rem;
  line-height: 1.22;
}

.extension-hero-copy,
.extension-panel-copy,
.extension-stat-copy,
.extension-row-meta,
.extension-muted-copy {
  color: #6b7280;
  line-height: 1.58;
}

.extension-hero-copy {
  max-width: 48rem;
  margin: 0.7rem 0 0;
}

.extension-hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-top: 1.1rem;
}

.extension-hero-watermark {
  position: absolute;
  right: 1rem;
  bottom: 0.75rem;
  color: rgba(15, 118, 110, 0.08);
  font-size: 8rem;
  line-height: 1;
}

.extension-focus-panel {
  padding: 1.15rem;
  display: grid;
  align-content: start;
  gap: 0.8rem;
}

.extension-focus-main {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.extension-focus-icon,
.extension-stat-icon {
  width: 2.8rem;
  height: 2.8rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  border-radius: 8px;
  background: #ccfbf1;
  color: #0f766e;
  font-size: 1.35rem;
}

.extension-focus-label,
.extension-stat-label,
.extension-tiny-label {
  display: block;
  color: #6b7280;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.extension-focus-value {
  display: block;
  margin-top: 0.2rem;
  color: #111827;
  font-family: "Space Grotesk", "Public Sans", sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
}

.extension-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-stat-card {
  min-height: 10rem;
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

.extension-stat-value {
  display: block;
  margin-top: 1rem;
  font-size: 1.6rem;
  line-height: 1.15;
}

.extension-stat-copy {
  margin: 0.3rem 0 0;
  font-size: 0.86rem;
}

.extension-analytics-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(20rem, 0.8fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-overview-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-panel {
  padding: 1.15rem;
}

.extension-panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 0.95rem;
}

.extension-panel-title {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.25;
}

.extension-panel-copy {
  margin: 0.25rem 0 0;
  font-size: 0.88rem;
}

.extension-status-chart,
.extension-completeness-chart {
  min-height: 330px;
}

.extension-chart-empty {
  min-height: 12rem;
  display: grid;
  place-items: center;
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
  font-size: 1.18rem;
}

.extension-signal-copy {
  margin: 0.35rem 0 0;
  color: #6b7280;
  font-size: 0.82rem;
  line-height: 1.5;
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
  background: #f0fdfa;
  color: #0f766e;
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

.extension-status--neutral {
  background: #f1f5f9;
  color: #64748b;
}

.extension-empty {
  padding: 1rem 0;
  color: #6b7280;
}

@media (max-width: 1199.98px) {
  .extension-dashboard-grid,
  .extension-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .extension-dashboard-hero,
  .extension-analytics-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 767.98px) {
  .extension-dashboard-grid,
  .extension-overview-grid,
  .extension-signal-grid {
    grid-template-columns: 1fr;
  }

  .extension-panel-header,
  .extension-row-main {
    display: block;
  }

  .extension-hero-watermark {
    display: none;
  }
}
CSS;

$extraScripts = '<script src="' . e(app_link('assets/vendor/libs/apex-charts/apexcharts.js')) . '"></script>
<script>
(function () {
  var statusElement = document.getElementById("extension-status-chart");
  var completenessElement = document.getElementById("extension-completeness-chart");
  var statusData = ' . $statusChartJson . ';
  var completenessData = ' . $completenessChartJson . ';

  function hasValues(values) {
    return Array.isArray(values) && values.some(function (value) {
      return Number(value || 0) > 0;
    });
  }

  if (statusElement) {
    if (!window.ApexCharts || !hasValues(statusData.counts)) {
      statusElement.innerHTML = "<div class=\"extension-chart-empty\">No project status data is available yet.</div>";
    } else {
      new ApexCharts(statusElement, {
        chart: {
          type: "bar",
          height: 330,
          toolbar: { show: false },
          fontFamily: "Public Sans, Segoe UI, Arial, sans-serif"
        },
        series: [
          { name: "Projects", data: statusData.counts },
          { name: "Budget", data: statusData.budget }
        ],
        colors: ["#0f766e", "#2563eb"],
        plotOptions: {
          bar: { borderRadius: 6, columnWidth: "42%" }
        },
        dataLabels: { enabled: false },
        grid: { borderColor: "#e5e7eb", strokeDashArray: 4 },
        xaxis: {
          categories: statusData.labels,
          labels: { style: { colors: "#64748b", fontSize: "12px", fontWeight: 600 } },
          axisBorder: { show: false },
          axisTicks: { show: false }
        },
        yaxis: [
          {
            min: 0,
            forceNiceScale: true,
            decimalsInFloat: 0,
            labels: { style: { colors: "#64748b", fontSize: "12px" } }
          },
          {
            opposite: true,
            min: 0,
            labels: {
              style: { colors: "#64748b", fontSize: "12px" },
              formatter: function (value) {
                return "PHP " + Number(value || 0).toLocaleString();
              }
            }
          }
        ],
        tooltip: {
          shared: true,
          y: [
            {
              formatter: function (value) {
                var count = Number(value || 0);
                return count.toLocaleString() + (count === 1 ? " project" : " projects");
              }
            },
            {
              formatter: function (value) {
                return "PHP " + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              }
            }
          ]
        }
      }).render();
    }
  }

  if (completenessElement) {
    if (!window.ApexCharts || !hasValues(completenessData.counts)) {
      completenessElement.innerHTML = "<div class=\"extension-chart-empty\">Completeness analytics will appear after records are encoded.</div>";
    } else {
      new ApexCharts(completenessElement, {
        chart: {
          type: "radar",
          height: 330,
          toolbar: { show: false },
          fontFamily: "Public Sans, Segoe UI, Arial, sans-serif"
        },
        series: [{ name: "Records", data: completenessData.counts }],
        labels: completenessData.labels,
        colors: ["#0f766e"],
        markers: { size: 4 },
        fill: { opacity: 0.18 },
        yaxis: {
          min: 0,
          decimalsInFloat: 0
        },
        tooltip: {
          y: {
            formatter: function (value) {
              var count = Number(value || 0);
              return count.toLocaleString() + (count === 1 ? " record" : " records");
            }
          }
        }
      }).render();
    }
  }
})();
</script>';

ob_start();
?>
<?php if ($pageError !== null): ?>
  <div class="extension-alert error"><?= e($pageError); ?></div>
<?php endif; ?>

<section class="extension-dashboard-hero" aria-label="Extension analytics overview">
  <article class="extension-card extension-hero-panel">
    <div class="extension-hero-content">
      <p class="extension-eyebrow">Extension Analytics</p>
      <h2 class="extension-hero-title">Dashboard for portfolio health, implementation risk, and registry completeness</h2>
      <p class="extension-hero-copy">
        The dashboard uses project registry fields to show which proposals are moving, which records need cleanup, where funding and partners are concentrated, and which active projects need date-based follow-up.
      </p>
      <div class="extension-hero-actions">
        <a class="extension-btn" href="<?= e(app_link('extension/projects.php')); ?>">
          <i class="bx bx-list-ul"></i>
          Open Project Registry
        </a>
        <a class="extension-btn-secondary" href="<?= e(app_link('extension/projects.php?new=1#extension-editor')); ?>">
          <i class="bx bx-plus"></i>
          Encode Project
        </a>
      </div>
    </div>
    <i class="bx bx-network-chart extension-hero-watermark" aria-hidden="true"></i>
  </article>

  <aside class="extension-card extension-focus-panel">
    <div class="extension-focus-main">
      <span class="extension-focus-icon"><i class="bx bx-bell"></i></span>
      <span>
        <span class="extension-focus-label">Priority Signal</span>
        <span class="extension-focus-value"><?= e($pipelinePriority); ?></span>
      </span>
    </div>
    <p class="extension-muted-copy">
      Latest registry update: <?= e(extension_date_label($metrics['latest_update'], 'No project updates yet')); ?>.
      Average encoded duration: <?= e($averageDurationLabel); ?>.
    </p>
  </aside>
</section>

<section class="extension-dashboard-grid" aria-label="Extension analytics summary">
  <?php foreach ($summaryCards as $summaryCard): ?>
    <article class="extension-card extension-stat-card">
      <div class="extension-stat-top">
        <span class="extension-stat-label"><?= e($summaryCard['label']); ?></span>
        <span class="extension-stat-icon"><i class="bx <?= e($summaryCard['icon']); ?>"></i></span>
      </div>
      <div>
        <span class="extension-stat-value"><?= e($summaryCard['value']); ?></span>
        <p class="extension-stat-copy"><?= e($summaryCard['copy']); ?></p>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<section class="extension-analytics-grid">
  <article class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">Pipeline and Funding</p>
        <h2 class="extension-panel-title">Project Status Distribution</h2>
        <p class="extension-panel-copy">Compare project counts with encoded budget by workflow status.</p>
      </div>
      <span class="extension-chip"><i class="bx bx-bar-chart-alt-2"></i> Status + budget</span>
    </div>
    <div id="extension-status-chart" class="extension-status-chart"></div>
  </article>

  <aside class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">Registry Quality</p>
        <h2 class="extension-panel-title">Completeness Signals</h2>
        <p class="extension-panel-copy">These are the best quality checks for the current registry structure.</p>
      </div>
    </div>
    <div class="extension-signal-grid">
      <?php foreach ($completenessItems as $item): ?>
        <div class="extension-signal">
          <span class="extension-tiny-label"><?= e($item['label']); ?></span>
          <span class="extension-signal-value"><?= e($item['percent']); ?></span>
          <p class="extension-signal-copy"><?= e(number_format((int) $item['count'])); ?> of <?= e(number_format($metrics['total'])); ?> record(s).</p>
        </div>
      <?php endforeach; ?>
      <div class="extension-signal">
        <span class="extension-tiny-label">Open records stale</span>
        <span class="extension-signal-value"><?= e(number_format($metrics['stale_active'])); ?></span>
        <p class="extension-signal-copy">Not completed or archived, and untouched for 60 days.</p>
      </div>
    </div>
  </aside>
</section>

<section class="extension-analytics-grid">
  <article class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">Data Completeness</p>
        <h2 class="extension-panel-title">Readiness Radar</h2>
        <p class="extension-panel-copy">A compact view of how complete the registry is across budget, partners, SDGs, dates, and components.</p>
      </div>
    </div>
    <div id="extension-completeness-chart" class="extension-completeness-chart"></div>
  </article>

  <aside class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">Partner Concentration</p>
        <h2 class="extension-panel-title">Top Cooperating Agencies</h2>
      </div>
      <span class="extension-chip"><i class="bx bx-buildings"></i> <?= e($partnerCoverage); ?> partnered</span>
    </div>
    <div class="extension-row-list">
      <?php if ($partnerBreakdown === []): ?>
        <p class="extension-empty">No cooperating agency data has been encoded yet.</p>
      <?php endif; ?>
      <?php foreach ($partnerBreakdown as $partnerRow): ?>
        <?php
        $partnerTotal = (int) ($partnerRow['total'] ?? 0);
        $partnerPercent = $metrics['total'] > 0 ? max(4, min(100, (int) round(($partnerTotal / $metrics['total']) * 100))) : 0;
        ?>
        <div class="extension-row">
          <div class="extension-row-main">
            <span class="extension-row-title"><?= e(extension_short_text((string) ($partnerRow['label'] ?? 'Unnamed partner'), 74)); ?></span>
            <span class="extension-row-meta"><?= e(number_format($partnerTotal)); ?> project(s)</span>
          </div>
          <span class="extension-row-meta"><?= e(extension_money_label($partnerRow['budget_total'] ?? 0)); ?> encoded budget</span>
          <div class="extension-progress-track" aria-hidden="true">
            <span class="extension-progress-value" style="width: <?= e((string) $partnerPercent); ?>%;"></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>
</section>

<section class="extension-overview-grid">
  <article class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">SDG Alignment</p>
        <h2 class="extension-panel-title">Most Used SDG Tags</h2>
      </div>
      <span class="extension-chip"><i class="bx bx-target-lock"></i> <?= e($sdgCoverage); ?> tagged</span>
    </div>
    <div class="extension-row-list">
      <?php if ($topSdgItems === []): ?>
        <p class="extension-empty">No SDG tags have been encoded yet.</p>
      <?php endif; ?>
      <?php foreach ($topSdgItems as $sdgItem): ?>
        <?php
        $sdgTotal = (int) ($sdgItem['total'] ?? 0);
        $sdgPercent = $metrics['total'] > 0 ? max(4, min(100, (int) round(($sdgTotal / $metrics['total']) * 100))) : 0;
        ?>
        <div class="extension-row">
          <div class="extension-row-main">
            <span class="extension-row-title"><?= e('SDG ' . (string) $sdgItem['code']); ?></span>
            <span class="extension-row-meta"><?= e(number_format($sdgTotal)); ?> project(s)</span>
          </div>
          <span class="extension-row-meta"><?= e((string) $sdgItem['label']); ?></span>
          <div class="extension-progress-track" aria-hidden="true">
            <span class="extension-progress-value" style="width: <?= e((string) $sdgPercent); ?>%;"></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </article>

  <article class="extension-card extension-panel">
    <div class="extension-panel-header">
      <div>
        <p class="extension-eyebrow">Milestone Monitoring</p>
        <h2 class="extension-panel-title">Active Project Date Risks</h2>
      </div>
      <span class="extension-chip"><i class="bx bx-calendar-event"></i> <?= e(number_format($metrics['upcoming_30'])); ?> ending soon</span>
    </div>
    <div class="extension-row-list">
      <?php if ($upcomingProjects === []): ?>
        <p class="extension-empty">No active project end dates are available yet.</p>
      <?php endif; ?>
      <?php foreach ($upcomingProjects as $project): ?>
        <?php
        $endDate = trim((string) ($project['end_date'] ?? ''));
        $isOverdue = $endDate !== '' && strtotime($endDate) !== false && strtotime($endDate) < strtotime(date('Y-m-d'));
        ?>
        <div class="extension-row">
          <div class="extension-row-main">
            <span class="extension-row-title"><?= e(extension_short_text((string) ($project['project_title'] ?? 'Untitled project'), 74)); ?></span>
            <?= extension_status_badge((string) ($project['status'] ?? 'Ongoing')); ?>
          </div>
          <span class="extension-row-meta">
            <?= $isOverdue ? 'Overdue since ' : 'Ends '; ?><?= e(extension_date_label($project['end_date'] ?? null)); ?>
            | <?= e((string) ($project['project_leader_name'] ?: 'Leader not assigned')); ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </article>
</section>

<section class="extension-card extension-panel">
  <div class="extension-panel-header">
    <div>
      <p class="extension-eyebrow">Recent Registry Updates</p>
      <h2 class="extension-panel-title">Latest Project Activity</h2>
      <p class="extension-panel-copy">Use this as the handoff point from analytics into the registry records.</p>
    </div>
    <a class="extension-btn-secondary" href="<?= e(app_link('extension/projects.php')); ?>">
      <i class="bx bx-list-ul"></i>
      View Registry
    </a>
  </div>
  <div class="extension-row-list">
    <?php if ($recentProjects === []): ?>
      <p class="extension-empty">No extension project records have been encoded yet.</p>
    <?php endif; ?>
    <?php foreach ($recentProjects as $project): ?>
      <div class="extension-row">
        <div class="extension-row-main">
          <a class="extension-row-title" href="<?= e(app_link('extension/projects.php?edit=' . (int) $project['extension_projectid'] . '#extension-editor')); ?>">
            <?= e(extension_short_text((string) ($project['project_title'] ?? 'Untitled project'), 90)); ?>
          </a>
          <?= extension_status_badge((string) ($project['status'] ?? 'Draft')); ?>
        </div>
        <span class="extension-row-meta">
          Updated <?= e(extension_date_label($project['updated_at'] ?? null, 'No update date')); ?>
          | <?= e((string) ($project['project_leader_name'] ?: 'Leader not assigned')); ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php
$mainContent = (string) ob_get_clean();

ExtensionPage::render([
    'title' => 'Extension Analytics Dashboard',
    'current_page' => 'dashboard',
    'main_content' => $mainContent,
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
]);
