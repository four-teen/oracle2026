<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function admin_dashboard_int(array $row, string $key): int
{
    return isset($row[$key]) ? (int) $row[$key] : 0;
}

function admin_dashboard_percent_label(int $value, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    $percentage = ($value / $total) * 100;

    return ($percentage >= 10 ? number_format($percentage, 0) : number_format($percentage, 1)) . '%';
}

function admin_dashboard_month_label(string $monthKey): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $monthKey);

    return $date instanceof DateTimeImmutable ? $date->format('M Y') : $monthKey;
}

function admin_dashboard_date_label(?string $dateValue, string $format = 'M j, Y'): string
{
    $normalized = trim((string) $dateValue);

    if ($normalized === '') {
        return 'No updates yet';
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date($format, $timestamp) : $normalized;
}

function admin_dashboard_short_program_label(string $value): string
{
    $normalized = trim($value);

    if ($normalized === '') {
        return 'Unassigned program';
    }

    $parts = explode(' - ', $normalized, 2);
    $shortLabel = trim($parts[0]);

    return $shortLabel !== '' ? $shortLabel : $normalized;
}

$stats = [
    'accounts_total' => 0,
    'accounts_enabled' => 0,
    'accounts_admin' => 0,
    'research_records_total' => 0,
    'review_team_assigned' => 0,
    'ready_for_review' => 0,
    'with_adviser' => 0,
    'with_panel' => 0,
    'with_statistician' => 0,
    'with_critic' => 0,
    'with_authors' => 0,
    'with_abstract' => 0,
    'programs_used' => 0,
    'academic_years_used' => 0,
    'latest_update' => null,
];
$programBreakdown = [];
$academicYearBreakdown = [];
$campusBreakdown = [];
$researchTypeBreakdown = [];
$chartData = [
    'labels' => [],
    'records' => [],
    'review_team' => [],
    'abstracts' => [],
    'ready' => [],
];
$activeArchiveMonths = 0;
$chartInsight = 'No archive activity has been logged yet.';
$dashboardError = null;

try {
    $pdo = Database::connection();
    Database::ensureAccountStatusColumn();
    Database::ensureResearchTypeTable();
    Database::ensureManuscriptTable();

    $panelAssignedSql = "(
        EXISTS (
            SELECT 1
            FROM tblmanuscript_panelists mp
            WHERE mp.manuscriptid = m.titleid
        )
        OR TRIM(COALESCE(m.panelist_accountids, '')) <> ''
    )";
    $reviewTeamAssignedSql = "COALESCE(m.adviser_accountid, 0) > 0
        AND {$panelAssignedSql}
        AND COALESCE(m.statistician_accountid, 0) > 0
        AND COALESCE(m.english_critic_accountid, 0) > 0";
    $readyForReviewSql = "{$reviewTeamAssignedSql}
        AND TRIM(COALESCE(m.authors, '')) <> ''
        AND TRIM(COALESCE(m.abstract_file_path, '')) <> ''";

    $accountSummary = $pdo->query(
        "SELECT COUNT(*) AS accounts_total,
                SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS accounts_enabled,
                SUM(CASE WHEN acc_type = 1 THEN 1 ELSE 0 END) AS accounts_admin
         FROM tblaccount"
    )->fetch();

    $researchSummary = $pdo->query(
        "SELECT COUNT(*) AS research_records_total,
                SUM(CASE WHEN {$reviewTeamAssignedSql} THEN 1 ELSE 0 END) AS review_team_assigned,
                SUM(CASE WHEN {$readyForReviewSql} THEN 1 ELSE 0 END) AS ready_for_review,
                SUM(CASE WHEN COALESCE(m.adviser_accountid, 0) > 0 THEN 1 ELSE 0 END) AS with_adviser,
                SUM(CASE WHEN {$panelAssignedSql} THEN 1 ELSE 0 END) AS with_panel,
                SUM(CASE WHEN COALESCE(m.statistician_accountid, 0) > 0 THEN 1 ELSE 0 END) AS with_statistician,
                SUM(CASE WHEN COALESCE(m.english_critic_accountid, 0) > 0 THEN 1 ELSE 0 END) AS with_critic,
                SUM(CASE WHEN TRIM(COALESCE(m.authors, '')) <> '' THEN 1 ELSE 0 END) AS with_authors,
                SUM(CASE WHEN TRIM(COALESCE(m.abstract_file_path, '')) <> '' THEN 1 ELSE 0 END) AS with_abstract,
                COUNT(DISTINCT CASE WHEN COALESCE(m.programid, 0) > 0 THEN m.programid END) AS programs_used,
                COUNT(DISTINCT CASE WHEN COALESCE(m.ayid, 0) > 0 THEN m.ayid END) AS academic_years_used,
                MAX(m.updated_at) AS latest_update
         FROM tblresearches m"
    )->fetch();

    $programBreakdown = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' - ', c.coursecode, c.coursedescription)), ''), 'Unassigned program') AS label,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblcourse c ON c.courseid = m.programid
         GROUP BY COALESCE(NULLIF(TRIM(CONCAT_WS(' - ', c.coursecode, c.coursedescription)), ''), 'Unassigned program')
         ORDER BY total DESC, label ASC
         LIMIT 4"
    )->fetchAll();

    $academicYearBreakdown = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT(ay.ay_from, '-', ay.ay_to)), ''), 'Unassigned academic year') AS label,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblacademic_year ay ON ay.ayid = m.ayid
         GROUP BY COALESCE(NULLIF(TRIM(CONCAT(ay.ay_from, '-', ay.ay_to)), ''), 'Unassigned academic year')
         ORDER BY total DESC, label ASC
         LIMIT 4"
    )->fetchAll();

    $campusBreakdown = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(c.campusname), ''), 'Unassigned campus') AS label,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblcampus c ON c.campusid = m.campusid
         GROUP BY COALESCE(NULLIF(TRIM(c.campusname), ''), 'Unassigned campus')
         ORDER BY total DESC, label ASC
         LIMIT 4"
    )->fetchAll();

    $researchTypeBreakdown = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(rt.research_type), ''), 'Unspecified type') AS label,
                COUNT(*) AS total
         FROM tblresearches m
         LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
         GROUP BY COALESCE(NULLIF(TRIM(rt.research_type), ''), 'Unspecified type')
         ORDER BY total DESC, label ASC
         LIMIT 4"
    )->fetchAll();

    $monthlyRows = $pdo->query(
        "SELECT DATE_FORMAT(COALESCE(m.updated_at, m.submitted_at), '%Y-%m') AS month_key,
                COUNT(*) AS records_total,
                SUM(CASE WHEN {$reviewTeamAssignedSql} THEN 1 ELSE 0 END) AS review_team_assigned,
                SUM(CASE WHEN TRIM(COALESCE(m.abstract_file_path, '')) <> '' THEN 1 ELSE 0 END) AS with_abstract,
                SUM(CASE WHEN {$readyForReviewSql} THEN 1 ELSE 0 END) AS ready_for_review
         FROM tblresearches m
         WHERE COALESCE(m.updated_at, m.submitted_at) IS NOT NULL
         GROUP BY month_key
         ORDER BY month_key ASC"
    )->fetchAll();

    $stats['accounts_total'] = admin_dashboard_int((array) $accountSummary, 'accounts_total');
    $stats['accounts_enabled'] = admin_dashboard_int((array) $accountSummary, 'accounts_enabled');
    $stats['accounts_admin'] = admin_dashboard_int((array) $accountSummary, 'accounts_admin');
    $stats['research_records_total'] = admin_dashboard_int((array) $researchSummary, 'research_records_total');
    $stats['review_team_assigned'] = admin_dashboard_int((array) $researchSummary, 'review_team_assigned');
    $stats['ready_for_review'] = admin_dashboard_int((array) $researchSummary, 'ready_for_review');
    $stats['with_adviser'] = admin_dashboard_int((array) $researchSummary, 'with_adviser');
    $stats['with_panel'] = admin_dashboard_int((array) $researchSummary, 'with_panel');
    $stats['with_statistician'] = admin_dashboard_int((array) $researchSummary, 'with_statistician');
    $stats['with_critic'] = admin_dashboard_int((array) $researchSummary, 'with_critic');
    $stats['with_authors'] = admin_dashboard_int((array) $researchSummary, 'with_authors');
    $stats['with_abstract'] = admin_dashboard_int((array) $researchSummary, 'with_abstract');
    $stats['programs_used'] = admin_dashboard_int((array) $researchSummary, 'programs_used');
    $stats['academic_years_used'] = admin_dashboard_int((array) $researchSummary, 'academic_years_used');
    $stats['latest_update'] = isset($researchSummary['latest_update']) ? (string) $researchSummary['latest_update'] : null;

    $monthlyMap = [];

    foreach ($monthlyRows as $monthlyRow) {
        $monthKey = trim((string) ($monthlyRow['month_key'] ?? ''));

        if ($monthKey === '') {
            continue;
        }

        $monthlyMap[$monthKey] = [
            'records_total' => (int) $monthlyRow['records_total'],
            'review_team_assigned' => (int) $monthlyRow['review_team_assigned'],
            'with_abstract' => (int) $monthlyRow['with_abstract'],
            'ready_for_review' => (int) $monthlyRow['ready_for_review'],
        ];
    }

    $anchorMonth = trim((string) $stats['latest_update']) !== ''
        ? new DateTimeImmutable((string) $stats['latest_update'])
        : new DateTimeImmutable('now');
    $anchorMonth = $anchorMonth->modify('first day of this month');

    for ($offset = 7; $offset >= 0; $offset--) {
        $monthKey = $anchorMonth->modify('-' . $offset . ' months')->format('Y-m');
        $monthValues = $monthlyMap[$monthKey] ?? [
            'records_total' => 0,
            'review_team_assigned' => 0,
            'with_abstract' => 0,
            'ready_for_review' => 0,
        ];

        $chartData['labels'][] = admin_dashboard_month_label($monthKey);
        $chartData['records'][] = (int) $monthValues['records_total'];
        $chartData['review_team'][] = (int) $monthValues['review_team_assigned'];
        $chartData['abstracts'][] = (int) $monthValues['with_abstract'];
        $chartData['ready'][] = (int) $monthValues['ready_for_review'];
    }

    $activeArchiveMonths = count(array_filter($monthlyRows, static function ($monthlyRow) {
        return (int) ($monthlyRow['records_total'] ?? 0) > 0;
    }));

    $peakRecords = 0;
    $peakLabel = null;
    $latestReadyLabel = null;

    foreach ($chartData['records'] as $index => $recordCount) {
        if ($recordCount > $peakRecords) {
            $peakRecords = $recordCount;
            $peakLabel = $chartData['labels'][$index] ?? null;
        }
    }

    for ($index = count($chartData['ready']) - 1; $index >= 0; $index--) {
        if (($chartData['ready'][$index] ?? 0) > 0) {
            $latestReadyLabel = $chartData['labels'][$index] ?? null;
            break;
        }
    }

    if ($peakRecords > 0 && $peakLabel !== null) {
        $chartInsight = 'Peak archive activity reached ' . number_format($peakRecords) . ' records in ' . $peakLabel . '.';
        $chartInsight .= $latestReadyLabel !== null
            ? ' Review-ready records appear in ' . $latestReadyLabel . '.'
            : ' No review-ready month is recorded yet.';
    }
} catch (Throwable $exception) {
    $dashboardError = $exception->getMessage();
}

$topProgram = $programBreakdown[0] ?? ['label' => 'No program data', 'total' => 0];
$topCampus = $campusBreakdown[0] ?? ['label' => 'No campus data', 'total' => 0];
$topResearchType = $researchTypeBreakdown[0] ?? ['label' => 'No research type data', 'total' => 0];
$recordsTotal = max(0, $stats['research_records_total']);
$accountsMeta = number_format($stats['accounts_total']) . ' total accounts, ' . number_format($stats['accounts_admin']) . ' administrator';
$recordsMeta = number_format($stats['programs_used']) . ' programs across ' . number_format($stats['academic_years_used']) . ' academic years';
$reviewTeamMeta = admin_dashboard_percent_label($stats['review_team_assigned'], $recordsTotal) . ' have adviser, panel, statistician, and critic';
$readyMeta = admin_dashboard_percent_label($stats['ready_for_review'], $recordsTotal) . ' include the abstract and full reviewer setup';

$summaryItems = [
    [
        'label' => 'Enabled Accounts',
        'value' => number_format($stats['accounts_enabled']),
        'meta' => $accountsMeta,
        'icon' => 'bx-user-check',
        'icon_class' => 'bg-label-primary',
    ],
    [
        'label' => 'Research Records',
        'value' => number_format($recordsTotal),
        'meta' => $recordsMeta,
        'icon' => 'bx-book-content',
        'icon_class' => 'bg-label-info',
    ],
    [
        'label' => 'Review Team Assigned',
        'value' => number_format($stats['review_team_assigned']),
        'meta' => $reviewTeamMeta,
        'icon' => 'bx-group',
        'icon_class' => 'bg-label-warning',
    ],
    [
        'label' => 'Ready for Review',
        'value' => number_format($stats['ready_for_review']),
        'meta' => $readyMeta,
        'icon' => 'bx-check-shield',
        'icon_class' => 'bg-label-success',
    ],
];

$signalItems = [
    [
        'label' => 'Abstract Backlog',
        'value' => number_format(max(0, $recordsTotal - $stats['with_abstract'])),
        'copy' => 'records still need an abstract upload before final review.',
        'tone' => 'warning',
    ],
    [
        'label' => 'Reviewer Backlog',
        'value' => number_format(max(0, $recordsTotal - $stats['review_team_assigned'])),
        'copy' => 'records still need a full adviser and panel lineup.',
        'tone' => 'danger',
    ],
    [
        'label' => 'Leading Program',
        'value' => admin_dashboard_short_program_label((string) ($topProgram['label'] ?? 'No program data')),
        'copy' => number_format((int) ($topProgram['total'] ?? 0)) . ' records or '
            . admin_dashboard_percent_label((int) ($topProgram['total'] ?? 0), $recordsTotal) . ' of the archive.',
        'tone' => 'primary',
    ],
    [
        'label' => 'Latest Update',
        'value' => admin_dashboard_date_label($stats['latest_update']),
        'copy' => 'Most recent research activity saved in the archive.',
        'tone' => 'info',
    ],
];

$coverageItems = [];

foreach ([
    ['label' => 'Authors listed', 'count' => $stats['with_authors'], 'tone' => 'success'],
    ['label' => 'Adviser assigned', 'count' => $stats['with_adviser'], 'tone' => 'primary'],
    ['label' => 'Review panel assigned', 'count' => $stats['with_panel'], 'tone' => 'info'],
    ['label' => 'Statistician assigned', 'count' => $stats['with_statistician'], 'tone' => 'warning'],
    ['label' => 'English critic assigned', 'count' => $stats['with_critic'], 'tone' => 'warning'],
    ['label' => 'Abstract uploaded', 'count' => $stats['with_abstract'], 'tone' => 'warning'],
    ['label' => 'Ready for review', 'count' => $stats['ready_for_review'], 'tone' => 'success'],
] as $coverageItem) {
    $count = (int) $coverageItem['count'];
    $percentage = $recordsTotal > 0 ? ($count / $recordsTotal) * 100 : 0.0;

    $coverageItems[] = [
        'label' => $coverageItem['label'],
        'count' => $count,
        'percent_label' => admin_dashboard_percent_label($count, $recordsTotal),
        'value_label' => number_format($count) . ' of ' . number_format($recordsTotal),
        'tone' => $coverageItem['tone'],
        'width_css' => $percentage > 0 ? 'max(' . number_format($percentage, 2, '.', '') . '%, 0.55rem)' : '0',
    ];
}

$programMixItems = [];

foreach ($programBreakdown as $programRow) {
    $count = (int) ($programRow['total'] ?? 0);
    $percentage = $recordsTotal > 0 ? ($count / $recordsTotal) * 100 : 0.0;
    $fullLabel = (string) ($programRow['label'] ?? 'Unassigned program');
    $shortLabel = admin_dashboard_short_program_label($fullLabel);

    $programMixItems[] = [
        'label' => $shortLabel,
        'description' => $shortLabel !== $fullLabel ? $fullLabel : '',
        'count_label' => number_format($count),
        'percent_label' => admin_dashboard_percent_label($count, $recordsTotal),
        'width_css' => $percentage > 0 ? 'max(' . number_format($percentage, 2, '.', '') . '%, 0.55rem)' : '0',
    ];
}

$academicYearMixItems = [];

foreach ($academicYearBreakdown as $academicYearRow) {
    $count = (int) ($academicYearRow['total'] ?? 0);
    $percentage = $recordsTotal > 0 ? ($count / $recordsTotal) * 100 : 0.0;

    $academicYearMixItems[] = [
        'label' => (string) ($academicYearRow['label'] ?? 'Unassigned academic year'),
        'description' => '',
        'count_label' => number_format($count),
        'percent_label' => admin_dashboard_percent_label($count, $recordsTotal),
        'width_css' => $percentage > 0 ? 'max(' . number_format($percentage, 2, '.', '') . '%, 0.55rem)' : '0',
    ];
}

$chartPayload = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$apexChartsUrl = e(app_link('assets/vendor/libs/apex-charts/apexcharts.js'));

$extraStyles = <<<'CSS'
.dashboard-section-copy {
  margin: 0;
  color: #6b7280;
  font-size: 0.84rem;
  line-height: 1.6;
}

.dashboard-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2rem;
  padding: 0.35rem 0.8rem;
  border-radius: 999px;
  background: rgba(105, 108, 255, 0.1);
  color: #5a5ee5;
  font-size: 0.76rem;
  font-weight: 700;
}

.dashboard-trend-chart {
  min-height: 330px;
}

.dashboard-chart-footnote {
  margin-top: 0.75rem;
  color: #6b7280;
  font-size: 0.84rem;
  line-height: 1.6;
}

.dashboard-signal-list,
.dashboard-coverage-list,
.dashboard-mix-list {
  display: grid;
  gap: 0.95rem;
}

.dashboard-signal-item {
  padding: 1rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.95rem;
  background: #fcfcff;
}

.dashboard-signal-item--primary {
  background: rgba(105, 108, 255, 0.06);
}

.dashboard-signal-item--info {
  background: rgba(3, 195, 236, 0.07);
}

.dashboard-signal-item--warning {
  background: rgba(255, 171, 0, 0.08);
}

.dashboard-signal-item--danger {
  background: rgba(255, 62, 29, 0.07);
}

.dashboard-signal-label {
  display: inline-block;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #8592a3;
}

.dashboard-signal-value {
  margin-top: 0.35rem;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}

.dashboard-signal-copy {
  margin: 0.45rem 0 0;
  color: #6b7280;
  font-size: 0.82rem;
  line-height: 1.6;
}

.dashboard-progress-top,
.dashboard-mix-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.45rem;
}

.dashboard-progress-label,
.dashboard-mix-label {
  font-weight: 600;
  color: #111827;
  line-height: 1.4;
}

.dashboard-progress-meta,
.dashboard-mix-copy {
  display: block;
  margin-top: 0.15rem;
  color: #6b7280;
  font-size: 0.78rem;
  line-height: 1.5;
}

.dashboard-progress-value,
.dashboard-mix-value {
  color: #6b7280;
  font-size: 0.8rem;
  font-weight: 700;
  white-space: nowrap;
}

.dashboard-progress-track {
  width: 100%;
  height: 0.6rem;
  border-radius: 999px;
  background: #eef2f7;
  overflow: hidden;
}

.dashboard-progress-track--thin {
  height: 0.5rem;
}

.dashboard-progress-fill {
  height: 100%;
  border-radius: inherit;
}

.dashboard-progress-fill--primary {
  background: linear-gradient(90deg, #696cff 0%, #9ca2ff 100%);
}

.dashboard-progress-fill--info {
  background: linear-gradient(90deg, #03c3ec 0%, #72dbf4 100%);
}

.dashboard-progress-fill--warning {
  background: linear-gradient(90deg, #ffab00 0%, #ffc85a 100%);
}

.dashboard-progress-fill--success {
  background: linear-gradient(90deg, #71dd37 0%, #9bec73 100%);
}

.dashboard-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-bottom: 1rem;
}

.dashboard-meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2rem;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  border: 1px solid #e5e7eb;
  background: #f9fafb;
  color: #4b5563;
  font-size: 0.78rem;
}

.dashboard-meta-chip strong {
  color: #111827;
  font-weight: 700;
}

.dashboard-subsection {
  margin-top: 1.25rem;
}

.dashboard-subsection:first-of-type {
  margin-top: 0;
}

.dashboard-subsection-title {
  margin: 0 0 0.85rem;
  font-size: 0.86rem;
  font-weight: 700;
  color: #111827;
}

@media (max-width: 575.98px) {
  .dashboard-progress-top,
  .dashboard-mix-top {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.35rem;
  }

  .dashboard-chip-row {
    flex-direction: column;
    align-items: stretch;
  }
}
CSS;

$extraScripts = <<<HTML
<script src="{$apexChartsUrl}"></script>
<script>
  (function () {
    const chartElement = document.getElementById('dashboard-trend-chart');
    const chartData = {$chartPayload};

    if (!chartElement || typeof ApexCharts === 'undefined' || !chartData) {
      return;
    }

    const options = {
      chart: {
        type: 'line',
        height: 330,
        toolbar: {
          show: false
        },
        zoom: {
          enabled: false
        },
        animations: {
          enabled: true,
          easing: 'easeinout',
          speed: 650
        }
      },
      series: [
        { name: 'Records updated', data: chartData.records },
        { name: 'Review team assigned', data: chartData.review_team },
        { name: 'Abstract uploaded', data: chartData.abstracts },
        { name: 'Ready for review', data: chartData.ready }
      ],
      colors: ['#696cff', '#03c3ec', '#ffab00', '#71dd37'],
      stroke: {
        curve: 'smooth',
        width: 3
      },
      markers: {
        size: 4,
        strokeWidth: 0,
        hover: {
          sizeOffset: 2
        }
      },
      grid: {
        borderColor: '#edf0f2',
        strokeDashArray: 4,
        padding: {
          left: 8,
          right: 12
        }
      },
      dataLabels: {
        enabled: false
      },
      legend: {
        position: 'top',
        horizontalAlign: 'left',
        fontSize: '13px',
        labels: {
          colors: '#6b7280'
        }
      },
      xaxis: {
        categories: chartData.labels,
        axisBorder: {
          show: false
        },
        axisTicks: {
          show: false
        },
        labels: {
          style: {
            colors: '#8592a3',
            fontSize: '12px'
          }
        }
      },
      yaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
          style: {
            colors: '#8592a3',
            fontSize: '12px'
          }
        }
      },
      tooltip: {
        shared: true,
        intersect: false,
        y: {
          formatter: function (value) {
            const count = Number(value || 0);
            return count + (count === 1 ? ' record' : ' records');
          }
        }
      },
      responsive: [
        {
          breakpoint: 768,
          options: {
            chart: {
              height: 300
            },
            legend: {
              position: 'bottom'
            }
          }
        }
      ]
    };

    const chart = new ApexCharts(chartElement, options);
    chart.render();
  })();
</script>
HTML;

ob_start();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
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
                <div class="col-xl-8">
                  <div class="card surface-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-start gap-3 flex-wrap">
                      <div>
                        <h5 class="mb-1">Archive activity trend</h5>
                        <p class="dashboard-section-copy">
                          Counts are grouped by each record's latest saved month so you can compare archive activity with review completion.
                        </p>
                      </div>
                      <span class="dashboard-chip">Last 8 archive months</span>
                    </div>
                    <div class="card-body">
                      <div id="dashboard-trend-chart" class="dashboard-trend-chart"></div>
                      <p class="dashboard-chart-footnote mb-0"><?= e($chartInsight); ?></p>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card surface-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">Data signals</h5>
                      <p class="dashboard-section-copy">
                        The current archive is strongest on author data and weakest on reviewer assignment and abstract uploads.
                      </p>
                    </div>
                    <div class="card-body">
                      <div class="dashboard-signal-list">
<?php foreach ($signalItems as $signalItem): ?>
                        <div class="dashboard-signal-item dashboard-signal-item--<?= e($signalItem['tone']); ?>">
                          <span class="dashboard-signal-label"><?= e($signalItem['label']); ?></span>
                          <div class="dashboard-signal-value"><?= e($signalItem['value']); ?></div>
                          <p class="dashboard-signal-copy"><?= e($signalItem['copy']); ?></p>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-xl-6">
                  <div class="card surface-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">Completion coverage</h5>
                      <p class="dashboard-section-copy">
                        This shows how much of the review checklist is already filled across the <?= e(number_format($recordsTotal)); ?> archived records.
                      </p>
                    </div>
                    <div class="card-body">
<?php if ($coverageItems === []): ?>
                      <p class="mb-0 muted-copy">No completion data is available yet.</p>
<?php else: ?>
                      <div class="dashboard-coverage-list">
<?php foreach ($coverageItems as $coverageItem): ?>
                        <div>
                          <div class="dashboard-progress-top">
                            <div>
                              <span class="dashboard-progress-label"><?= e($coverageItem['label']); ?></span>
                              <span class="dashboard-progress-meta"><?= e($coverageItem['value_label']); ?> records</span>
                            </div>
                            <span class="dashboard-progress-value"><?= e($coverageItem['percent_label']); ?></span>
                          </div>
                          <div class="dashboard-progress-track">
                            <div
                              class="dashboard-progress-fill dashboard-progress-fill--<?= e($coverageItem['tone']); ?>"
                              style="width: <?= e($coverageItem['width_css']); ?>;"
                            ></div>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-xl-6">
                  <div class="card surface-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">Archive mix</h5>
                      <p class="dashboard-section-copy">
                        Program coverage, academic-year spread, and the profile of the current research archive.
                      </p>
                    </div>
                    <div class="card-body">
                      <div class="dashboard-chip-row">
                        <span class="dashboard-meta-chip">
                          <strong>Campus</strong>
                          <span><?= e((string) ($topCampus['label'] ?? 'No campus data')); ?></span>
                        </span>
                        <span class="dashboard-meta-chip">
                          <strong>Type</strong>
                          <span><?= e((string) ($topResearchType['label'] ?? 'No research type data')); ?></span>
                        </span>
                        <span class="dashboard-meta-chip">
                          <strong>Active Months</strong>
                          <span><?= e(number_format($activeArchiveMonths)); ?></span>
                        </span>
                      </div>

                      <div class="dashboard-subsection">
                        <h6 class="dashboard-subsection-title">Programs</h6>
<?php if ($programMixItems === []): ?>
                        <p class="mb-0 muted-copy">No program distribution has been captured yet.</p>
<?php else: ?>
                        <div class="dashboard-mix-list">
<?php foreach ($programMixItems as $programMixItem): ?>
                          <div>
                            <div class="dashboard-mix-top">
                              <div>
                                <span class="dashboard-mix-label"><?= e($programMixItem['label']); ?></span>
<?php if ($programMixItem['description'] !== ''): ?>
                                <span class="dashboard-mix-copy"><?= e($programMixItem['description']); ?></span>
<?php endif; ?>
                              </div>
                              <span class="dashboard-mix-value"><?= e($programMixItem['count_label']); ?> | <?= e($programMixItem['percent_label']); ?></span>
                            </div>
                            <div class="dashboard-progress-track dashboard-progress-track--thin">
                              <div
                                class="dashboard-progress-fill dashboard-progress-fill--primary"
                                style="width: <?= e($programMixItem['width_css']); ?>;"
                              ></div>
                            </div>
                          </div>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                      </div>

                      <div class="dashboard-subsection">
                        <h6 class="dashboard-subsection-title">Academic years</h6>
<?php if ($academicYearMixItems === []): ?>
                        <p class="mb-0 muted-copy">No academic-year distribution has been captured yet.</p>
<?php else: ?>
                        <div class="dashboard-mix-list">
<?php foreach ($academicYearMixItems as $academicYearMixItem): ?>
                          <div>
                            <div class="dashboard-mix-top">
                              <div>
                                <span class="dashboard-mix-label"><?= e($academicYearMixItem['label']); ?></span>
                              </div>
                              <span class="dashboard-mix-value"><?= e($academicYearMixItem['count_label']); ?> | <?= e($academicYearMixItem['percent_label']); ?></span>
                            </div>
                            <div class="dashboard-progress-track dashboard-progress-track--thin">
                              <div
                                class="dashboard-progress-fill dashboard-progress-fill--info"
                                style="width: <?= e($academicYearMixItem['width_css']); ?>;"
                              ></div>
                            </div>
                          </div>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                      </div>
                    </div>
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
    'extra_styles' => $extraStyles,
    'extra_scripts' => $extraScripts,
]);
