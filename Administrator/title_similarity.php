<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function administrator_title_similarity_url(array $parameters = []): string
{
    $query = http_build_query($parameters, '', '&');

    if ($query === '') {
        return app_link('administrator/title_similarity.php');
    }

    return app_link('administrator/title_similarity.php') . '?' . $query;
}

function administrator_title_similarity_positive_int($value): int
{
    if (is_array($value)) {
        return 0;
    }

    $normalized = trim((string) $value);

    if ($normalized === '' || !ctype_digit($normalized)) {
        return 0;
    }

    return (int) $normalized;
}

function administrator_title_similarity_datetime_label(?string $value): string
{
    $normalized = trim((string) $value);

    if ($normalized === '') {
        return 'Not reviewed yet';
    }

    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('M j, Y g:i A', $timestamp) : $normalized;
}

function administrator_title_similarity_review_label(string $value): string
{
    $options = TitleSimilarity::reviewLabelOptions();

    if (isset($options[$value])) {
        return $options[$value];
    }

    if ($value === 'mixed') {
        return 'Mixed Review';
    }

    if ($value === 'pending') {
        return 'Pending Review';
    }

    return 'Unknown';
}

function administrator_title_similarity_review_badge_class(string $value): string
{
    if ($value === 'highly_related' || $value === 'related' || $value === 'positive') {
        return 'success';
    }

    if ($value === 'not_related' || $value === 'negative') {
        return 'danger';
    }

    if ($value === 'mixed') {
        return 'warning';
    }

    return 'neutral';
}

function administrator_title_similarity_bucket_badge_class(string $value): string
{
    if ($value === 'high') {
        return 'success';
    }

    if ($value === 'medium') {
        return 'warning';
    }

    return 'neutral';
}

function administrator_title_similarity_navigation_params(
    string $search,
    string $reviewFilter,
    string $bucketFilter,
    int $page = 1,
    int $sourceTitleId = 0,
    int $targetTitleId = 0
): array {
    $parameters = [];

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    if ($reviewFilter !== '') {
        $parameters['review'] = $reviewFilter;
    }

    if ($bucketFilter !== '') {
        $parameters['bucket'] = $bucketFilter;
    }

    if ($page > 1) {
        $parameters['page'] = $page;
    }

    if ($sourceTitleId > 0 && $targetTitleId > 0) {
        $parameters['source'] = $sourceTitleId;
        $parameters['target'] = $targetTitleId;
    }

    return $parameters;
}

$search = trim((string) ($_GET['q'] ?? ''));
$reviewFilter = trim((string) ($_GET['review'] ?? ''));
$bucketFilter = trim((string) ($_GET['bucket'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$requestedSourceTitleId = administrator_title_similarity_positive_int($_GET['source'] ?? 0);
$requestedTargetTitleId = administrator_title_similarity_positive_int($_GET['target'] ?? 0);

if (!in_array($reviewFilter, ['pending', 'reviewed', 'positive', 'negative', 'mixed'], true)) {
    $reviewFilter = '';
}

if (!in_array($bucketFilter, ['high', 'medium', 'low'], true)) {
    $bucketFilter = '';
}

if ($requestedSourceTitleId > 0 && $requestedTargetTitleId > 0) {
    [$requestedSourceTitleId, $requestedTargetTitleId] = TitleSimilarity::canonicalPair($requestedSourceTitleId, $requestedTargetTitleId);
}

$actionSuccess = get_flash('title_similarity_success');
$actionError = get_flash('title_similarity_error');
$pageError = null;
$selectedPair = null;
$selectedPairReviews = [];
$pairRecords = [];
$metrics = [];
$reviewSummaries = [];
$persistedFilters = administrator_title_similarity_navigation_params($search, $reviewFilter, $bucketFilter);
$persistedFiltersWithPage = administrator_title_similarity_navigation_params($search, $reviewFilter, $bucketFilter, $page);
$currentAccount = Auth::account() ?? [];
$currentAccountId = isset($currentAccount['accountid']) ? (int) $currentAccount['accountid'] : 0;
$currentReviewerName = trim((string) ($currentAccount['acc_name'] ?? $currentAccount['email'] ?? 'Reviewer'));
$totalTitles = 0;
$totalStoredMatches = 0;
$reviewedPairCount = 0;
$pendingPairCount = 0;
$latestComputedAt = '';
$bestAlgorithm = null;
$perPage = 20;
$totalPages = 1;
$filteredPairs = [];
$currentUserReviewMap = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string) ($_POST['action'] ?? ''));

    try {
        $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $pdo = Database::connection();
        Database::ensureManuscriptTable();
        Database::ensureAccountStatusColumn();

        if ($postedAction === 'recompute_title_similarity') {
            $pdo->beginTransaction();

            try {
                TitleSimilarity::refreshCatalog($pdo);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            set_flash('title_similarity_success', 'Title similarity scores were refreshed for the full catalog.');
            redirect(administrator_title_similarity_url($persistedFiltersWithPage));
        }

        if ($postedAction === 'save_similarity_review') {
            $sourceTitleId = administrator_title_similarity_positive_int($_POST['source_titleid'] ?? 0);
            $targetTitleId = administrator_title_similarity_positive_int($_POST['target_titleid'] ?? 0);
            $expertLabel = trim((string) ($_POST['expert_label'] ?? ''));
            $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

            if ($currentAccountId < 1) {
                throw new RuntimeException('The current reviewer account could not be resolved.');
            }

            if (strlen($reviewNote) > 2000) {
                throw new RuntimeException('Reviewer notes must be 2000 characters or fewer.');
            }

            $pdo->beginTransaction();

            try {
                TitleSimilarity::saveReview($pdo, $sourceTitleId, $targetTitleId, $currentAccountId, $expertLabel, $reviewNote);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }

            [$sourceTitleId, $targetTitleId] = TitleSimilarity::canonicalPair($sourceTitleId, $targetTitleId);
            set_flash('title_similarity_success', 'Similarity review saved for this title pair.');
            redirect(administrator_title_similarity_url(administrator_title_similarity_navigation_params(
                $search,
                $reviewFilter,
                $bucketFilter,
                $page,
                $sourceTitleId,
                $targetTitleId
            )));
        }

        throw new RuntimeException('The requested action is not supported.');
    } catch (Throwable $exception) {
        set_flash('title_similarity_error', $exception->getMessage());
        redirect(administrator_title_similarity_url($persistedFiltersWithPage));
    }
}

try {
    $pdo = Database::connection();
    Database::ensureManuscriptTable();
    Database::ensureAccountStatusColumn();

    $totalTitles = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM tblresearches
         WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL"
    )->fetchColumn();
    $totalStoredMatches = (int) $pdo->query('SELECT COUNT(*) FROM tbltitlesimilarity')->fetchColumn();
    $latestComputedAt = (string) ($pdo->query('SELECT MAX(computed_at) FROM tbltitlesimilarity')->fetchColumn() ?: '');
    $reviewSummaries = TitleSimilarity::reviewSummaries($pdo);
    $metrics = TitleSimilarity::evaluationMetrics($pdo);

    foreach ($metrics as $metric) {
        if ((int) ($metric['reviewed_pairs'] ?? 0) < 1) {
            continue;
        }

        $bestAlgorithm = $metric;
        break;
    }

    if ($currentAccountId > 0) {
        $currentUserReviews = $pdo->prepare(
            'SELECT source_titleid, target_titleid, expert_label, review_note
             FROM tbltitlesimilarity_review
             WHERE reviewer_accountid = :reviewer_accountid'
        );
        $currentUserReviews->bindValue(':reviewer_accountid', $currentAccountId, PDO::PARAM_INT);
        $currentUserReviews->execute();

        foreach ($currentUserReviews->fetchAll() as $reviewRow) {
            $sourceTitleId = isset($reviewRow['source_titleid']) ? (int) $reviewRow['source_titleid'] : 0;
            $targetTitleId = isset($reviewRow['target_titleid']) ? (int) $reviewRow['target_titleid'] : 0;
            $currentUserReviewMap[$sourceTitleId . ':' . $targetTitleId] = [
                'expert_label' => trim((string) ($reviewRow['expert_label'] ?? '')),
                'review_note' => trim((string) ($reviewRow['review_note'] ?? '')),
            ];
        }
    }

    foreach (TitleSimilarity::fetchStoredPairs($pdo) as $pairRecord) {
        $pairKey = (int) $pairRecord['source_titleid'] . ':' . (int) $pairRecord['target_titleid'];
        $reviewSummary = $reviewSummaries[$pairKey] ?? [
            'counts' => [],
            'total_reviews' => 0,
            'latest_reviewed_at' => '',
            'consensus_label' => '',
            'consensus_binary' => '',
        ];
        $currentUserReview = $currentUserReviewMap[$pairKey] ?? [
            'expert_label' => '',
            'review_note' => '',
        ];

        $pairRecord['review_summary'] = $reviewSummary;
        $pairRecord['current_user_review'] = $currentUserReview;
        $pairRecord['review_status'] = (int) ($reviewSummary['total_reviews'] ?? 0) > 0 ? 'reviewed' : 'pending';
        $pairRecord['consensus_binary'] = (string) ($reviewSummary['consensus_binary'] ?? '');
        $pairRecord['consensus_label'] = (string) ($reviewSummary['consensus_label'] ?? '');
        $pairRecords[$pairKey] = $pairRecord;
    }

    foreach ($pairRecords as $pairRecord) {
        if ((int) ($pairRecord['review_summary']['total_reviews'] ?? 0) > 0) {
            $reviewedPairCount++;
        } else {
            $pendingPairCount++;
        }

        if ($requestedSourceTitleId > 0
            && $requestedTargetTitleId > 0
            && (int) $pairRecord['source_titleid'] === $requestedSourceTitleId
            && (int) $pairRecord['target_titleid'] === $requestedTargetTitleId
        ) {
            $selectedPair = $pairRecord;
        }

        if ($search !== '') {
            $haystack = strtolower(trim((string) $pairRecord['source_title'] . ' ' . (string) $pairRecord['target_title']));

            if (strpos($haystack, strtolower($search)) === false) {
                continue;
            }
        }

        if ($reviewFilter === 'pending' && $pairRecord['review_status'] !== 'pending') {
            continue;
        }

        if ($reviewFilter === 'reviewed' && $pairRecord['review_status'] !== 'reviewed') {
            continue;
        }

        if ($reviewFilter === 'positive' && $pairRecord['consensus_binary'] !== 'positive') {
            continue;
        }

        if ($reviewFilter === 'negative' && $pairRecord['consensus_binary'] !== 'negative') {
            continue;
        }

        if ($reviewFilter === 'mixed' && $pairRecord['consensus_binary'] !== 'mixed') {
            continue;
        }

        if ($bucketFilter !== '' && (string) $pairRecord['score_bucket'] !== $bucketFilter) {
            continue;
        }

        $filteredPairs[] = $pairRecord;
    }

    $filteredCount = count($filteredPairs);
    $totalPages = max(1, (int) ceil($filteredCount / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $filteredPairs = array_slice($filteredPairs, ($page - 1) * $perPage, $perPage);

    if ($selectedPair !== null) {
        $reviewHistoryStatement = $pdo->prepare(
            "SELECT tr.expert_label,
                    tr.review_note,
                    tr.updated_at,
                    COALESCE(NULLIF(TRIM(a.acc_name), ''), NULLIF(TRIM(a.email), ''), CONCAT('Account #', tr.reviewer_accountid)) AS reviewer_name
             FROM tbltitlesimilarity_review tr
             LEFT JOIN tblaccount a ON a.accountid = tr.reviewer_accountid
             WHERE tr.source_titleid = :source_titleid
               AND tr.target_titleid = :target_titleid
             ORDER BY tr.updated_at DESC, tr.similarity_reviewid DESC"
        );
        $reviewHistoryStatement->bindValue(':source_titleid', (int) $selectedPair['source_titleid'], PDO::PARAM_INT);
        $reviewHistoryStatement->bindValue(':target_titleid', (int) $selectedPair['target_titleid'], PDO::PARAM_INT);
        $reviewHistoryStatement->execute();
        $selectedPairReviews = $reviewHistoryStatement->fetchAll();
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}

$swalAlerts = [];

if ($actionSuccess !== null && trim($actionSuccess) !== '') {
    $swalAlerts[] = [
        'icon' => 'success',
        'title' => 'Saved',
        'text' => $actionSuccess,
        'toast' => true,
    ];
}

if ($actionError !== null && trim($actionError) !== '') {
    $swalAlerts[] = [
        'icon' => 'error',
        'title' => 'Action failed',
        'text' => $actionError,
        'toast' => false,
    ];
}

if ($pageError !== null && trim($pageError) !== '') {
    $swalAlerts[] = [
        'icon' => 'error',
        'title' => 'Page unavailable',
        'text' => $pageError,
        'toast' => false,
    ];
}

$swalAlertsJson = json_encode($swalAlerts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!is_string($swalAlertsJson)) {
    $swalAlertsJson = '[]';
}

$shouldOpenReviewDrawer = $selectedPair !== null;

$extraHead = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
HTML;

$extraScripts = str_replace(
    ['__OPEN_DRAWER__', '__SWAL_ALERTS__', '__BASE_URL__'],
    [$shouldOpenReviewDrawer ? 'true' : 'false', $swalAlertsJson, addslashes(administrator_title_similarity_url($persistedFiltersWithPage))],
    <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
  (function () {
    var shouldOpenDrawer = __OPEN_DRAWER__;
    var swalAlerts = __SWAL_ALERTS__;
    var baseUrl = '__BASE_URL__';
    var scrollStorageKey = 'titleSimilarityScrollTop:' + window.location.pathname;
    var reviewDrawerElement = document.getElementById('titleSimilarityReviewDrawer');
    var recomputeForms = Array.prototype.slice.call(document.querySelectorAll('.title-similarity-recompute-form'));
    var reviewForms = Array.prototype.slice.call(document.querySelectorAll('.title-similarity-review-form'));
    var reviewLinks = Array.prototype.slice.call(document.querySelectorAll('.title-similarity-review-link'));

    function persistScrollPosition() {
      try {
        window.sessionStorage.setItem(scrollStorageKey, String(Math.max(window.pageYOffset || 0, 0)));
      } catch (error) {
        // Ignore storage access issues and continue with the submit.
      }
    }

    function restoreScrollPosition() {
      var storedScrollTop = null;

      try {
        storedScrollTop = window.sessionStorage.getItem(scrollStorageKey);
      } catch (error) {
        storedScrollTop = null;
      }

      if (storedScrollTop === null) {
        return;
      }

      try {
        window.sessionStorage.removeItem(scrollStorageKey);
      } catch (error) {
        // Ignore storage cleanup issues.
      }

      var parsedScrollTop = parseInt(storedScrollTop, 10);

      if (isNaN(parsedScrollTop) || parsedScrollTop < 1) {
        return;
      }

      var applyScrollPosition = function () {
        window.scrollTo(0, parsedScrollTop);
      };

      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(function () {
          applyScrollPosition();
          window.requestAnimationFrame(applyScrollPosition);
        });

        return;
      }

      applyScrollPosition();
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
        confirmButtonColor: '#696cff'
      };

      if (alert.toast) {
        options.toast = true;
        options.position = 'top-end';
        options.showConfirmButton = false;
        options.timer = 2800;
        options.timerProgressBar = true;
      }

      Swal.fire(options).then(function () {
        showSwalAlerts(index + 1);
      });
    }

    recomputeForms.forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (form.dataset.confirmed === 'true' || typeof Swal === 'undefined') {
          return;
        }

        event.preventDefault();

        Swal.fire({
          icon: 'question',
          title: 'Refresh similarity scores?',
          text: 'This will rebuild normalized titles and the stored top related-title matches for the current catalog.',
          showCancelButton: true,
          confirmButtonText: 'Refresh Now',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#696cff',
          cancelButtonColor: '#8592a3'
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }

          form.dataset.confirmed = 'true';
          form.submit();
        });
      });
    });

    reviewLinks.forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
          return;
        }

        persistScrollPosition();
      });
    });

    reviewForms.forEach(function (form) {
      form.addEventListener('submit', function () {
        persistScrollPosition();
      });
    });

    restoreScrollPosition();

    if (reviewDrawerElement && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
      var reviewDrawer = bootstrap.Offcanvas.getOrCreateInstance(reviewDrawerElement);

      if (shouldOpenDrawer) {
        reviewDrawer.show();
      }

      reviewDrawerElement.addEventListener('hidden.bs.offcanvas', function () {
        if (window.location.search.indexOf('source=') === -1 && window.location.search.indexOf('target=') === -1) {
          return;
        }

        window.history.replaceState({}, document.title, baseUrl);
      });
    }

    showSwalAlerts(0);
  })();
</script>
HTML
);

$extraStyles = <<<'CSS'
.title-similarity-shell { display: grid; gap: 24px; }
.title-similarity-hero { display: grid; gap: 20px; }
.title-similarity-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.title-similarity-card,
.title-similarity-panel,
.title-similarity-metric-table,
.title-similarity-record-table { background: #fff; border: 1px solid rgba(105, 108, 255, 0.12); border-radius: 22px; box-shadow: 0 18px 48px rgba(67, 89, 113, 0.08); }
.title-similarity-card { padding: 22px; }
.title-similarity-card-label { margin: 0 0 8px; font-size: 13px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #8592a3; }
.title-similarity-card-value { margin: 0; font-size: 34px; line-height: 1.05; font-weight: 800; color: #233255; }
.title-similarity-card-note { margin: 10px 0 0; color: #697a8d; font-size: 14px; line-height: 1.6; }
.title-similarity-panel { padding: 24px; }
.title-similarity-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
.title-similarity-panel-title { margin: 0; font-size: 22px; font-weight: 800; color: #233255; }
.title-similarity-panel-copy { margin: 8px 0 0; max-width: 820px; color: #697a8d; font-size: 14px; line-height: 1.65; }
.title-similarity-toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
.title-similarity-toolbar form { margin: 0; }
.title-similarity-filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; width: 100%; }
.title-similarity-search-shell { flex: 1 1 280px; min-height: 50px; display: flex; align-items: center; gap: 12px; padding: 0 18px; border: 1px solid rgba(105, 108, 255, 0.18); border-radius: 999px; background: #fff; }
.title-similarity-search-shell i { font-size: 18px; color: #8592a3; }
.title-similarity-search-shell input { flex: 1 1 auto; border: 0; outline: 0; background: transparent; min-width: 0; font-size: 14px; color: #233255; }
.title-similarity-filter-form select { min-height: 50px; border: 1px solid rgba(105, 108, 255, 0.18); border-radius: 16px; padding: 0 16px; background: #fff; color: #233255; font-size: 14px; }
.title-similarity-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
.title-similarity-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 18px; border-radius: 16px; border: 0; text-decoration: none; font-size: 14px; font-weight: 700; transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease; }
.title-similarity-btn:hover { transform: translateY(-1px); }
.title-similarity-btn.primary { background: linear-gradient(135deg, #696cff, #5f61e6); color: #fff; box-shadow: 0 14px 28px rgba(105, 108, 255, 0.28); }
.title-similarity-btn.secondary { background: rgba(105, 108, 255, 0.12); color: #4f58d8; }
.title-similarity-btn.outline { background: #fff; color: #566a7f; border: 1px solid rgba(105, 108, 255, 0.18); }
.title-similarity-metric-table,
.title-similarity-record-table { overflow: hidden; }
.title-similarity-table { width: 100%; margin: 0; border-collapse: collapse; }
.title-similarity-table thead th { padding: 16px 18px; background: #f7f9fc; color: #8592a3; font-size: 12px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.title-similarity-table tbody td { padding: 16px 18px; border-top: 1px solid #edf1f7; vertical-align: top; color: #566a7f; font-size: 14px; }
.title-similarity-table tbody tr:hover { background: rgba(105, 108, 255, 0.04); }
.title-similarity-pair-title { margin: 0; font-size: 15px; line-height: 1.55; color: #233255; font-weight: 700; }
.title-similarity-pair-title span { color: #8592a3; font-weight: 600; }
.title-similarity-pair-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.title-similarity-chip,
.title-similarity-badge { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.2; white-space: nowrap; }
.title-similarity-chip { background: rgba(105, 108, 255, 0.08); color: #4f58d8; }
.title-similarity-badge.success { background: rgba(40, 199, 111, 0.16); color: #146c43; }
.title-similarity-badge.warning { background: rgba(255, 171, 0, 0.16); color: #9b6a00; }
.title-similarity-badge.danger { background: rgba(255, 62, 29, 0.14); color: #b42318; }
.title-similarity-badge.neutral { background: rgba(105, 108, 255, 0.1); color: #566a7f; }
.title-similarity-score-stack { display: grid; gap: 6px; min-width: 110px; }
.title-similarity-score-main { font-size: 18px; font-weight: 800; color: #233255; }
.title-similarity-score-note { font-size: 12px; color: #8592a3; }
.title-similarity-actions-cell { text-align: right; }
.title-similarity-empty { padding: 44px 24px; text-align: center; color: #8592a3; font-size: 15px; }
.title-similarity-pagination { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
.title-similarity-pagination a,
.title-similarity-pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 42px; min-height: 42px; padding: 0 12px; border-radius: 14px; background: #fff; border: 1px solid rgba(105, 108, 255, 0.14); color: #566a7f; text-decoration: none; font-size: 14px; font-weight: 700; }
.title-similarity-pagination span.active { background: #696cff; border-color: #696cff; color: #fff; }
.title-similarity-guides { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.title-similarity-guide-card { padding: 18px; border-radius: 18px; background: linear-gradient(180deg, #f7f9ff 0%, #fff 100%); border: 1px solid rgba(105, 108, 255, 0.12); }
.title-similarity-guide-card h4 { margin: 0 0 8px; font-size: 16px; font-weight: 800; color: #233255; }
.title-similarity-guide-card p { margin: 0; font-size: 13px; line-height: 1.65; color: #697a8d; }
.title-similarity-drawer { width: min(560px, 100vw); }
.title-similarity-drawer-header { align-items: flex-start; gap: 14px; padding: 24px 24px 12px; border-bottom: 1px solid #edf1f7; }
.title-similarity-drawer-title { margin: 0; font-size: 24px; font-weight: 800; color: #233255; }
.title-similarity-drawer-copy { margin: 8px 0 0; color: #697a8d; font-size: 14px; line-height: 1.65; }
.title-similarity-drawer-body { padding: 20px 24px 24px; display: grid; gap: 18px; }
.title-similarity-drawer-section { display: grid; gap: 12px; padding: 18px; border: 1px solid #edf1f7; border-radius: 18px; background: #fff; }
.title-similarity-drawer-section h5 { margin: 0; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #8592a3; }
.title-similarity-drawer-pair { display: grid; gap: 10px; }
.title-similarity-drawer-pair p { margin: 0; font-size: 14px; line-height: 1.65; color: #233255; }
.title-similarity-score-grid { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.title-similarity-score-card { padding: 14px; border-radius: 16px; background: #f7f9ff; border: 1px solid rgba(105, 108, 255, 0.1); }
.title-similarity-score-card strong { display: block; color: #233255; font-size: 16px; font-weight: 800; }
.title-similarity-score-card span { display: block; margin-top: 6px; color: #697a8d; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
.title-similarity-review-form { display: grid; gap: 14px; }
.title-similarity-review-form select,
.title-similarity-review-form textarea { width: 100%; border: 1px solid rgba(105, 108, 255, 0.16); border-radius: 16px; padding: 14px 16px; color: #233255; font-size: 14px; background: #fff; outline: 0; }
.title-similarity-review-form textarea { min-height: 110px; resize: vertical; }
.title-similarity-review-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.title-similarity-review-history { display: grid; gap: 10px; }
.title-similarity-history-item { padding: 14px; border-radius: 16px; background: #f9fafc; border: 1px solid #edf1f7; }
.title-similarity-history-item strong { color: #233255; }
.title-similarity-history-item p { margin: 8px 0 0; color: #697a8d; font-size: 13px; line-height: 1.6; }
.title-similarity-inline-note { margin: 0; color: #697a8d; font-size: 13px; line-height: 1.65; }
.swal2-popup { border-radius: 18px; }
.swal2-toast { box-shadow: 0 0.9rem 2.4rem rgba(67, 89, 113, 0.18); }
@media (max-width: 991.98px) {
  .title-similarity-actions-cell { text-align: left; }
}
@media (max-width: 767.98px) {
  .title-similarity-toolbar,
  .title-similarity-filter-form,
  .title-similarity-actions,
  .title-similarity-review-actions { align-items: stretch; }
  .title-similarity-filter-form select,
  .title-similarity-btn,
  .title-similarity-search-shell { width: 100%; }
  .title-similarity-score-grid { grid-template-columns: 1fr; }
}
CSS;

ob_start();
?>
<main class="container-fluid flex-grow-1 container-p-y">
  <div class="title-similarity-shell">
    <section class="title-similarity-hero">
      <div class="title-similarity-grid">
        <article class="title-similarity-card">
          <p class="title-similarity-card-label">Indexed Titles</p>
          <p class="title-similarity-card-value"><?= e(number_format($totalTitles)); ?></p>
          <p class="title-similarity-card-note">Live manuscript titles available for normalization, matching, and algorithm evaluation.</p>
        </article>

        <article class="title-similarity-card">
          <p class="title-similarity-card-label">Stored Top Matches</p>
          <p class="title-similarity-card-value"><?= e(number_format($totalStoredMatches)); ?></p>
          <p class="title-similarity-card-note">Directional top related-title records saved from the current catalog recomputation cycle.</p>
        </article>

        <article class="title-similarity-card">
          <p class="title-similarity-card-label">Reviewed Pairs</p>
          <p class="title-similarity-card-value"><?= e(number_format($reviewedPairCount)); ?></p>
          <p class="title-similarity-card-note">Unique title pairs already labeled by reviewers as related, highly related, or not related.</p>
        </article>

        <article class="title-similarity-card">
          <p class="title-similarity-card-label">Pending Review</p>
          <p class="title-similarity-card-value"><?= e(number_format($pendingPairCount)); ?></p>
          <p class="title-similarity-card-note">Recommended candidate pairs still waiting for human judgment before final evaluation.</p>
        </article>
      </div>

      <div class="title-similarity-panel">
        <div class="title-similarity-panel-head">
          <div>
            <h3 class="title-similarity-panel-title">Collection and Validation Workflow</h3>
            <p class="title-similarity-panel-copy">Use this workspace before deployment: encode or import the actual titles into manuscript records, rebuild normalized titles and stored matches, then collect expert labels on the suggested title pairs so ORACLE can compare Jaccard, Cosine, Levenshtein, Sorensen-Dice, and the weighted hybrid score against real human review.</p>
          </div>

          <div class="title-similarity-actions">
            <form class="title-similarity-recompute-form" method="post" action="<?= e(administrator_title_similarity_url($persistedFiltersWithPage)); ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
              <input type="hidden" name="action" value="recompute_title_similarity">
              <button class="title-similarity-btn primary" type="submit"><i class="bx bx-refresh"></i> Refresh Catalog Scores</button>
            </form>
            <a class="title-similarity-btn outline" href="<?= e(app_link('administrator/manuscripts.php')); ?>"><i class="bx bx-book-open"></i> Open Manuscripts</a>
          </div>
        </div>

        <div class="title-similarity-guides">
          <article class="title-similarity-guide-card">
            <h4>1. Collect Actual Titles</h4>
            <p>Use the manuscripts drawer as the official live intake, and import any legacy titles into the same `tblresearches` catalog so the evaluation dataset comes from the real repository.</p>
          </article>
          <article class="title-similarity-guide-card">
            <h4>2. Recompute Matches</h4>
            <p>The refresh action lowercases, trims, removes punctuation, stores `normalized_title`, and saves only the top related-title matches per source record for review.</p>
          </article>
          <article class="title-similarity-guide-card">
            <h4>3. Collect Labels</h4>
            <p>Open a pair, mark it as highly related, related, or not related, and keep building a human-reviewed sample until your metrics table has enough coverage for comparison.</p>
          </article>
          <article class="title-similarity-guide-card">
            <h4>4. Select For Deployment</h4>
            <p>Use the live accuracy, precision, recall, and F1 values below to decide whether a single algorithm or the hybrid score should drive the production related-title view.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="title-similarity-panel">
      <div class="title-similarity-panel-head">
        <div>
          <h3 class="title-similarity-panel-title">Algorithm Evaluation</h3>
          <p class="title-similarity-panel-copy">
            <?= $bestAlgorithm !== null
                ? e($bestAlgorithm['label']) . ' is currently leading on F1-score using the reviewed pairs.'
                : 'Label a few title pairs first, then this table will show which algorithm performs best on your actual catalog.'; ?>
          </p>
        </div>
        <p class="title-similarity-inline-note">Latest stored recompute: <?= e($latestComputedAt !== '' ? administrator_title_similarity_datetime_label($latestComputedAt) : 'Not available yet'); ?></p>
      </div>

      <div class="title-similarity-metric-table">
        <table class="title-similarity-table">
          <thead>
            <tr>
              <th>Algorithm</th>
              <th>Threshold</th>
              <th>Reviewed Pairs</th>
              <th>Accuracy</th>
              <th>Precision</th>
              <th>Recall</th>
              <th>F1-Score</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($metrics === []): ?>
              <tr>
                <td colspan="7" class="title-similarity-empty">Evaluation metrics will appear after the first full similarity recompute.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($metrics as $metric): ?>
                <tr>
                  <td>
                    <strong><?= e((string) ($metric['label'] ?? 'Algorithm')); ?></strong>
                    <?php if ($bestAlgorithm !== null && (string) ($bestAlgorithm['label'] ?? '') === (string) ($metric['label'] ?? '')): ?>
                      <div class="title-similarity-pair-meta">
                        <span class="title-similarity-badge success">Current Best</span>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td><?= e(TitleSimilarity::scoreLabel((float) ($metric['threshold'] ?? 0.0))); ?></td>
                  <td><?= e(number_format((int) ($metric['reviewed_pairs'] ?? 0))); ?></td>
                  <td><?= e(TitleSimilarity::scoreLabel((float) ($metric['accuracy'] ?? 0.0))); ?></td>
                  <td><?= e(TitleSimilarity::scoreLabel((float) ($metric['precision'] ?? 0.0))); ?></td>
                  <td><?= e(TitleSimilarity::scoreLabel((float) ($metric['recall'] ?? 0.0))); ?></td>
                  <td><strong><?= e(TitleSimilarity::scoreLabel((float) ($metric['f1'] ?? 0.0))); ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="title-similarity-panel">
      <div class="title-similarity-panel-head">
        <div>
          <h3 class="title-similarity-panel-title">Review Candidate Pairs</h3>
          <p class="title-similarity-panel-copy">These pairs come from the stored top related-title matches. Review them to build the human-labeled benchmark set for your research and your deployment decision.</p>
        </div>
      </div>

      <div class="title-similarity-toolbar">
        <form class="title-similarity-filter-form" method="get" action="<?= e(administrator_title_similarity_url()); ?>">
          <label class="title-similarity-search-shell">
            <i class="bx bx-search"></i>
            <input type="search" name="q" value="<?= e($search); ?>" placeholder="Search by either title in the pair">
          </label>

          <select name="review">
            <option value="">All review states</option>
            <option value="pending"<?= $reviewFilter === 'pending' ? ' selected' : ''; ?>>Pending Review</option>
            <option value="reviewed"<?= $reviewFilter === 'reviewed' ? ' selected' : ''; ?>>Reviewed</option>
            <option value="positive"<?= $reviewFilter === 'positive' ? ' selected' : ''; ?>>Positive Consensus</option>
            <option value="negative"<?= $reviewFilter === 'negative' ? ' selected' : ''; ?>>Negative Consensus</option>
            <option value="mixed"<?= $reviewFilter === 'mixed' ? ' selected' : ''; ?>>Mixed Review</option>
          </select>

          <select name="bucket">
            <option value="">All confidence levels</option>
            <option value="high"<?= $bucketFilter === 'high' ? ' selected' : ''; ?>>High confidence</option>
            <option value="medium"<?= $bucketFilter === 'medium' ? ' selected' : ''; ?>>Moderate confidence</option>
            <option value="low"<?= $bucketFilter === 'low' ? ' selected' : ''; ?>>Emerging match</option>
          </select>

          <div class="title-similarity-actions">
            <button class="title-similarity-btn secondary" type="submit">Apply Filter</button>
            <a class="title-similarity-btn outline" href="<?= e(administrator_title_similarity_url()); ?>">Reset</a>
          </div>
        </form>
      </div>

      <div class="title-similarity-record-table">
        <table class="title-similarity-table">
          <thead>
            <tr>
              <th>No.</th>
              <th>Title Pair</th>
              <th>Hybrid</th>
              <th>Consensus</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($filteredPairs === []): ?>
              <tr>
                <td colspan="5" class="title-similarity-empty">No title pairs matched the current search and filters.</td>
              </tr>
            <?php else: ?>
              <?php $rowNumber = (($page - 1) * $perPage) + 1; ?>
              <?php foreach ($filteredPairs as $pairRecord): ?>
                <?php
                $consensusLabel = (string) ($pairRecord['consensus_label'] ?? '');
                $reviewStatusLabel = $consensusLabel !== ''
                    ? administrator_title_similarity_review_label($consensusLabel)
                    : 'Pending Review';
                $reviewStatusClass = $consensusLabel !== ''
                    ? administrator_title_similarity_review_badge_class($consensusLabel)
                    : 'neutral';
                $pairUrl = administrator_title_similarity_url(
                    administrator_title_similarity_navigation_params(
                        $search,
                        $reviewFilter,
                        $bucketFilter,
                        $page,
                        (int) $pairRecord['source_titleid'],
                        (int) $pairRecord['target_titleid']
                    )
                );
                ?>
                <tr>
                  <td><?= e((string) $rowNumber); ?></td>
                  <td>
                    <p class="title-similarity-pair-title">
                      <span>Source:</span> <?= e((string) $pairRecord['source_title']); ?><br>
                      <span>Target:</span> <?= e((string) $pairRecord['target_title']); ?>
                    </p>
                    <div class="title-similarity-pair-meta">
                      <span class="title-similarity-chip">Jaccard <?= e(TitleSimilarity::scoreLabel((float) $pairRecord['jaccard_score'])); ?></span>
                      <span class="title-similarity-chip">Cosine <?= e(TitleSimilarity::scoreLabel((float) $pairRecord['cosine_score'])); ?></span>
                      <span class="title-similarity-chip">Levenshtein <?= e(TitleSimilarity::scoreLabel((float) $pairRecord['levenshtein_score'])); ?></span>
                      <span class="title-similarity-chip">Dice <?= e(TitleSimilarity::scoreLabel((float) $pairRecord['dice_score'])); ?></span>
                    </div>
                  </td>
                  <td>
                    <div class="title-similarity-score-stack">
                      <span class="title-similarity-score-main"><?= e(TitleSimilarity::scoreLabel((float) $pairRecord['hybrid_score'])); ?></span>
                      <span class="title-similarity-badge <?= e(administrator_title_similarity_bucket_badge_class((string) $pairRecord['score_bucket'])); ?>">
                        <?= e(TitleSimilarity::bucketLabel((string) $pairRecord['score_bucket'])); ?>
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="title-similarity-score-stack">
                      <span class="title-similarity-badge <?= e($reviewStatusClass); ?>"><?= e($reviewStatusLabel); ?></span>
                      <span class="title-similarity-score-note">
                        <?= e((int) ($pairRecord['review_summary']['total_reviews'] ?? 0) > 0
                            ? number_format((int) ($pairRecord['review_summary']['total_reviews'] ?? 0)) . ' review(s)'
                            : 'No labels yet'); ?>
                      </span>
                    </div>
                  </td>
                  <td class="title-similarity-actions-cell">
                    <a class="title-similarity-btn secondary title-similarity-review-link" href="<?= e($pairUrl); ?>">Review Pair</a>
                  </td>
                </tr>
                <?php $rowNumber++; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="title-similarity-pagination">
          <?php if ($page > 1): ?>
            <a href="<?= e(administrator_title_similarity_url(array_merge($persistedFilters, ['page' => $page - 1]))); ?>">Previous</a>
          <?php endif; ?>

          <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
            <?php if ($pageNumber === $page): ?>
              <span class="active"><?= e((string) $pageNumber); ?></span>
            <?php else: ?>
              <a href="<?= e(administrator_title_similarity_url(array_merge($persistedFilters, ['page' => $pageNumber]))); ?>"><?= e((string) $pageNumber); ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="<?= e(administrator_title_similarity_url(array_merge($persistedFilters, ['page' => $page + 1]))); ?>">Next</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="offcanvas offcanvas-end title-similarity-drawer" tabindex="-1" id="titleSimilarityReviewDrawer" aria-labelledby="titleSimilarityReviewDrawerLabel">
    <div class="offcanvas-header title-similarity-drawer-header">
      <div>
        <h3 class="title-similarity-drawer-title" id="titleSimilarityReviewDrawerLabel">Title Pair Review</h3>
        <p class="title-similarity-drawer-copy">Review the suggested title pair, inspect each algorithm score, then save your expert label to support the evaluation metrics above.</p>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body title-similarity-drawer-body">
      <?php if ($selectedPair !== null): ?>
        <?php
        $selectedCurrentReview = $selectedPair['current_user_review'] ?? ['expert_label' => '', 'review_note' => ''];
        $selectedConsensusLabel = (string) ($selectedPair['consensus_label'] ?? '');
        ?>
        <section class="title-similarity-drawer-section">
          <h5>Title Pair</h5>
          <div class="title-similarity-drawer-pair">
            <p><strong>Source Title</strong><br><?= e((string) $selectedPair['source_title']); ?></p>
            <p><strong>Target Title</strong><br><?= e((string) $selectedPair['target_title']); ?></p>
          </div>
          <div class="title-similarity-pair-meta">
            <span class="title-similarity-badge <?= e(administrator_title_similarity_bucket_badge_class((string) $selectedPair['score_bucket'])); ?>">
              <?= e(TitleSimilarity::bucketLabel((string) $selectedPair['score_bucket'])); ?>
            </span>
            <span class="title-similarity-badge <?= e($selectedConsensusLabel !== '' ? administrator_title_similarity_review_badge_class($selectedConsensusLabel) : 'neutral'); ?>">
              <?= e($selectedConsensusLabel !== '' ? administrator_title_similarity_review_label($selectedConsensusLabel) : 'Pending Review'); ?>
            </span>
            <span class="title-similarity-badge neutral"><?= e(number_format((int) ($selectedPair['review_summary']['total_reviews'] ?? 0))); ?> review(s)</span>
          </div>
        </section>

        <section class="title-similarity-drawer-section">
          <h5>Algorithm Scores</h5>
          <div class="title-similarity-score-grid">
            <div class="title-similarity-score-card">
              <strong><?= e(TitleSimilarity::scoreLabel((float) $selectedPair['hybrid_score'])); ?></strong>
              <span>Hybrid</span>
            </div>
            <div class="title-similarity-score-card">
              <strong><?= e(TitleSimilarity::scoreLabel((float) $selectedPair['jaccard_score'])); ?></strong>
              <span>Jaccard</span>
            </div>
            <div class="title-similarity-score-card">
              <strong><?= e(TitleSimilarity::scoreLabel((float) $selectedPair['cosine_score'])); ?></strong>
              <span>Cosine</span>
            </div>
            <div class="title-similarity-score-card">
              <strong><?= e(TitleSimilarity::scoreLabel((float) $selectedPair['levenshtein_score'])); ?></strong>
              <span>Levenshtein</span>
            </div>
            <div class="title-similarity-score-card">
              <strong><?= e(TitleSimilarity::scoreLabel((float) $selectedPair['dice_score'])); ?></strong>
              <span>Sorensen-Dice</span>
            </div>
            <div class="title-similarity-score-card">
              <strong><?= e(number_format((int) $selectedPair['best_rank'])); ?></strong>
              <span>Best Rank Position</span>
            </div>
          </div>
        </section>

        <section class="title-similarity-drawer-section">
          <h5>Your Review</h5>
          <form class="title-similarity-review-form" method="post" action="<?= e(administrator_title_similarity_url(administrator_title_similarity_navigation_params(
              $search,
              $reviewFilter,
              $bucketFilter,
              $page,
              (int) $selectedPair['source_titleid'],
              (int) $selectedPair['target_titleid']
          ))); ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_similarity_review">
            <input type="hidden" name="source_titleid" value="<?= e((string) $selectedPair['source_titleid']); ?>">
            <input type="hidden" name="target_titleid" value="<?= e((string) $selectedPair['target_titleid']); ?>">

            <select name="expert_label" required>
              <option value="">Select your label</option>
              <?php foreach (TitleSimilarity::reviewLabelOptions() as $labelValue => $labelText): ?>
                <option value="<?= e($labelValue); ?>"<?= (string) ($selectedCurrentReview['expert_label'] ?? '') === $labelValue ? ' selected' : ''; ?>><?= e($labelText); ?></option>
              <?php endforeach; ?>
            </select>

            <textarea name="review_note" placeholder="Optional note: explain why these titles are related or not related."><?= e((string) ($selectedCurrentReview['review_note'] ?? '')); ?></textarea>

            <p class="title-similarity-inline-note">Signed in as <?= e($currentReviewerName !== '' ? $currentReviewerName : 'Reviewer'); ?>. Saving again will update your own label for this pair.</p>

            <div class="title-similarity-review-actions">
              <button class="title-similarity-btn primary" type="submit">Save Review</button>
              <button class="title-similarity-btn outline" type="button" data-bs-dismiss="offcanvas">Close</button>
            </div>
          </form>
        </section>

        <section class="title-similarity-drawer-section">
          <h5>Review History</h5>
          <div class="title-similarity-review-history">
            <?php if ($selectedPairReviews === []): ?>
              <div class="title-similarity-history-item">
                <strong>No review history yet.</strong>
                <p>This title pair is ready for the first reviewer label.</p>
              </div>
            <?php else: ?>
              <?php foreach ($selectedPairReviews as $reviewRow): ?>
                <?php $historyLabel = trim((string) ($reviewRow['expert_label'] ?? '')); ?>
                <div class="title-similarity-history-item">
                  <strong><?= e((string) ($reviewRow['reviewer_name'] ?? 'Reviewer')); ?></strong>
                  <span class="title-similarity-badge <?= e(administrator_title_similarity_review_badge_class($historyLabel)); ?>"><?= e(administrator_title_similarity_review_label($historyLabel)); ?></span>
                  <p><?= e(administrator_title_similarity_datetime_label($reviewRow['updated_at'] ?? '')); ?></p>
                  <?php if (trim((string) ($reviewRow['review_note'] ?? '')) !== ''): ?>
                    <p><?= e((string) $reviewRow['review_note']); ?></p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php else: ?>
        <section class="title-similarity-drawer-section">
          <h5>Ready To Review</h5>
          <p class="title-similarity-inline-note">Choose any title pair from the table to open the review drawer and save an expert label.</p>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php
$mainContent = (string) ob_get_clean();

AdminPage::render([
    'title' => 'Administrator | Title Similarity',
    'current_page' => 'title_similarity',
    'main_content' => $mainContent,
    'extra_head' => $extraHead,
    'extra_scripts' => $extraScripts,
    'extra_styles' => $extraStyles,
]);
