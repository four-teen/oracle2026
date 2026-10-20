<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/ResearchSimilarity.php';
Auth::requireAdmin();

function research_match_url(array $parameters = []): string
{
    $parameters = array_filter($parameters, static function ($value): bool { return $value !== '' && $value !== 0 && $value !== null; });
    return app_link('administrator/title_similarity.php') . ($parameters !== [] ? '?' . http_build_query($parameters) : '');
}

function research_match_percent($value): string
{
    return $value === null ? 'N/A' : number_format((float) $value * 100, 2) . '%';
}

$filter = ResearchSimilarity::filter($_GET);
$resultId = ResearchSimilarity::id($_GET['result'] ?? 0);
$account = Auth::account() ?? [];
$accountId = (int) ($account['accountid'] ?? 0);
$error = get_flash('research_match_error');
$success = get_flash('research_match_success');
$runs = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$results = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$batches = [];
$batch = null;
$run = null;
$detail = null;
$reviews = [];
$metrics = [];
$automated = [];
$eligible = 0;
$totals = ['user_runs' => 0, 'system_runs' => 0, 'calculations' => 0];

try {
    $pdo = Database::connection();
    Database::ensureResearchMatchTables();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            throw new InvalidArgumentException('Refresh the page before saving your review.');
        }
        if (($_POST['action'] ?? '') !== 'review') {
            throw new InvalidArgumentException('Unsupported action.');
        }
        $postedResult = ResearchSimilarity::id($_POST['result_id'] ?? 0);
        ResearchSimilarity::saveReview($pdo, $postedResult, $accountId,
            (string) ($account['acc_name'] ?? $account['email'] ?? 'Reviewer'),
            is_string($_POST['expert_label'] ?? null) ? $_POST['expert_label'] : '',
            is_string($_POST['review_note'] ?? null) ? $_POST['review_note'] : '');
        set_flash('research_match_success', 'Your relevance review was saved.');
        redirect(research_match_url(array_merge($filter, ['result' => $postedResult])) . '#calculation');
    }

    if (($_GET['export'] ?? '') === 'csv') {
        session_write_close();
        set_time_limit(0);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="research-match-collection.csv"');
        header('Cache-Control: no-store');
        $stream = fopen('php://output', 'wb');
        // UTF-8 signature allows spreadsheet applications to display names correctly.
        fwrite($stream, "\xEF\xBB\xBF");
        ResearchSimilarity::export($pdo, $filter, $stream);
        fclose($stream);
        exit;
    }

    $eligible = (int) $pdo->query("SELECT COUNT(*) FROM tblresearches WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL")->fetchColumn();
    $totals = $pdo->query("SELECT COALESCE(SUM(source_kind = 'user_submitted'), 0) AS user_runs,
        COALESCE(SUM(source_kind = 'system_generated'), 0) AS system_runs,
        COALESCE(SUM(result_count), 0) AS calculations FROM tblresearchmatch_run")->fetch();
    $batches = $pdo->query('SELECT * FROM tblresearchmatch_batch ORDER BY batch_id DESC LIMIT 20')->fetchAll();
    if ($filter['batch'] > 0) {
        $batch = ResearchSimilarity::batch($pdo, $filter['batch']);
    } elseif ($batches !== []) {
        $batch = ResearchSimilarity::batch($pdo, (int) $batches[0]['batch_id']);
    }
    $runs = ResearchSimilarity::runList($pdo, $filter, max(1, ResearchSimilarity::id($_GET['page'] ?? 1)));
    if ($filter['run'] > 0) {
        $run = ResearchSimilarity::run($pdo, $filter['run']);
        if ($run === null) {
            throw new InvalidArgumentException('The selected search run was not found.');
        }
        $results = ResearchSimilarity::results($pdo, (int) $run['run_id'], $filter['review'], max(1, ResearchSimilarity::id($_GET['result_page'] ?? 1)));
    }
    if ($resultId > 0) {
        $detail = ResearchSimilarity::detail($pdo, $resultId);
        if ($detail === null) {
            throw new InvalidArgumentException('The selected calculation was not found.');
        }
        $reviews = ResearchSimilarity::reviews($pdo, $resultId);
    }
    $metrics = ResearchSimilarity::metrics($pdo, $filter);
    $automated = ResearchSimilarity::automatedSummary($pdo, $filter);
} catch (Throwable $exception) {
    error_log('Research Match administrator: ' . $exception->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        set_flash('research_match_error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The review could not be saved. Please try again.');
        redirect(research_match_url(array_merge($filter, ['result' => $resultId])) . '#calculation');
    }
    // A download failure must not append an HTML page to a partial CSV.
    if (($_GET['export'] ?? '') === 'csv') {
        if (!headers_sent()) {
            header_remove('Content-Disposition');
            header('Content-Type: text/plain; charset=UTF-8');
            http_response_code(500);
            echo 'The calculation export could not be completed. Please try again.';
        }
        exit;
    }
    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The research collection could not be loaded. Please try again.';
}

$cssFile = dirname(__DIR__) . '/assets/css/research-similarity.css';
$jsFile = dirname(__DIR__) . '/assets/js/research-similarity.js';
$extraHead = '<link rel="stylesheet" href="' . e(app_link('assets/css/research-similarity.css')) . '?v=' . filemtime($cssFile) . '">';
$config = ['endpoint' => app_link('administrator/research_similarity_action.php'), 'csrf' => csrf_token(), 'batch' => $batch, 'canStart' => $eligible >= 2,
    'collectionUrl' => app_link('administrator/title_similarity.php')];
$extraScripts = '<script type="application/json" id="research-match-config">' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
    . '<script src="' . e(app_link('assets/js/research-similarity.js')) . '?v=' . filemtime($jsFile) . '"></script>';

ob_start();
?>
<div class="rm-shell">
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success); ?></div><?php endif; ?>

  <section class="rm-panel rm-hero">
    <div><p class="rm-eyebrow">Research Match collection</p><h2>Research Similarity Evaluation</h2>
      <p>Collect real search submissions and automated catalog comparisons, inspect their calculations, and build an expert-reviewed research dataset.</p></div>
    <a class="rm-button secondary" href="<?= e(app_link('administrator/title_similarity_legacy.php')); ?>">Previous title-only evaluation <i class="bx bx-link-external" aria-hidden="true"></i></a>
  </section>

  <section class="rm-stats" aria-label="Collection totals">
    <article><span>User-submitted searches</span><strong><?= e(number_format((int) $totals['user_runs'])); ?></strong><small>Successful submissions from the Research Match modal</small></article>
    <article><span>System-generated runs</span><strong><?= e(number_format((int) $totals['system_runs'])); ?></strong><small>One run per source manuscript, including queued runs</small></article>
    <article><span>Saved calculations</span><strong><?= e(number_format((int) $totals['calculations'])); ?></strong><small>Shared collection for both sources</small></article>
  </section>

  <section class="rm-panel" aria-labelledby="rm-generate-title">
    <div class="rm-panel-heading"><div><p class="rm-eyebrow">Automated collection</p><h3 id="rm-generate-title">Run catalog evaluation</h3>
      <p>Compare each manuscript with every other manuscript. Self-matches are excluded; every other score is saved, including low and zero scores.</p></div>
      <button type="button" class="rm-button primary" id="rm-start" <?= $eligible < 2 ? 'disabled' : ''; ?>><i class="bx bx-play" aria-hidden="true"></i> Start new evaluation</button>
    </div>
    <div class="rm-method"><div><strong><?= e(number_format($eligible)); ?> manuscripts</strong><span><?= e(number_format($eligible * max(0, $eligible - 1))); ?> source-to-target calculations in a new run</span></div>
      <div><strong>70% title + 30% abstract</strong><span>Title to title; abstract to abstract. Missing either abstract uses 100% title.</span></div>
      <div><strong>Four algorithms</strong><span>Jaccard 20% · Cosine 30% · Levenshtein 20% · Dice 30%</span></div></div>
    <p class="rm-note">Catalog evaluations include all titled manuscripts, including unpublished records available to administrators. Public searches use the public catalog. Saved text snapshots preserve each experiment's inputs.</p>
    <div class="rm-job" id="rm-job" <?= $batch === null ? 'hidden' : ''; ?>>
      <div class="rm-job-heading"><strong id="rm-job-title"><?= $batch ? e('Evaluation #' . $batch['batch_id'] . ' · ' . ucfirst($batch['status'])) : ''; ?></strong>
        <span id="rm-job-count"><?= $batch ? e(number_format((int) $batch['processed_comparisons']) . ' / ' . number_format((int) $batch['expected_comparisons']) . ' calculations') : ''; ?></span></div>
      <progress id="rm-progress" max="<?= $batch ? e((string) max(1, (int) $batch['expected_comparisons'])) : '1'; ?>" value="<?= $batch ? e((string) $batch['processed_comparisons']) : '0'; ?>">Evaluation progress</progress>
      <p id="rm-job-message" class="rm-note" role="status" aria-live="polite"><?= $batch && $batch['status'] === 'completed' ? 'Evaluation completed. All calculations are saved. Open saved runs to review or export them.' : 'Select Resume to continue saved progress. Closing this page stops further batches of work; completed work is retained.'; ?></p>
      <div class="rm-actions"><button type="button" class="rm-button primary" id="rm-resume" <?= !$batch || $batch['status'] === 'completed' ? 'hidden' : ''; ?>>Resume</button>
        <button type="button" class="rm-button secondary" id="rm-pause" hidden>Pause</button>
        <a class="rm-button secondary" id="rm-job-open" target="_blank" rel="noopener" href="<?= e(research_match_url(['batch' => (int) ($batch['batch_id'] ?? 0)])); ?>">View saved runs <span class="visually-hidden">in a new tab</span></a></div>
    </div>
    <p class="rm-note" id="rm-action-message" role="status" aria-live="polite"></p>
    <?php if ($batches !== []): ?><details class="rm-history"><summary>Evaluation history</summary><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Evaluation</th><th>Started</th><th>Status</th><th>Manuscripts</th><th>Saved / expected</th><th></th></tr></thead><tbody>
      <?php foreach ($batches as $item): ?><tr><td>#<?= e((string) $item['batch_id']); ?></td><td><?= e($item['created_at']); ?></td><td><span class="rm-badge"><?= e(ucfirst($item['status'])); ?></span></td><td><?= e(number_format((int) $item['catalog_count'])); ?></td><td><?= e(number_format((int) $item['processed_comparisons']) . ' / ' . number_format((int) $item['expected_comparisons'])); ?></td><td><a href="<?= e(research_match_url(['batch' => (int) $item['batch_id']])); ?>">Open</a></td></tr><?php endforeach; ?>
    </tbody></table></div></details><?php endif; ?>
  </section>

  <section class="rm-panel" id="collection" aria-labelledby="rm-collection-title">
    <div class="rm-panel-heading"><div><p class="rm-eyebrow">Shared database collection</p><h3 id="rm-collection-title">Search runs</h3><p>User-submitted records preserve the returned matches. Catalog runs preserve every comparison. The source identifies how a run was created; it does not verify a person's identity.</p></div>
      <a class="rm-button secondary" href="<?= e(research_match_url(array_merge($filter, ['export' => 'csv']))); ?>">Export calculations CSV</a></div>
    <form class="rm-filters" method="get" action="<?= e(app_link('administrator/title_similarity.php')); ?>">
      <label class="rm-search">Search input or source title<input type="search" name="q" value="<?= e($filter['q']); ?>" placeholder="Find a search or manuscript"></label>
      <label>Source<select name="source"><option value="">Both sources</option><?php foreach (ResearchSimilarity::SOURCES as $key => $label): ?><option value="<?= e($key); ?>" <?= $filter['source'] === $key ? 'selected' : ''; ?>><?= e($label); ?></option><?php endforeach; ?></select></label>
      <label>Calculation mode<select name="mode"><option value="">Both modes</option><?php foreach (ResearchSimilarity::MODES as $key => $label): ?><option value="<?= e($key); ?>" <?= $filter['mode'] === $key ? 'selected' : ''; ?>><?= e($label); ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><option value="">Any status</option><?php foreach (['queued', 'running', 'completed'] as $status): ?><option <?= $filter['status'] === $status ? 'selected' : ''; ?> value="<?= e($status); ?>"><?= e(ucfirst($status)); ?></option><?php endforeach; ?></select></label>
      <?php if ($filter['batch']): ?><input type="hidden" name="batch" value="<?= e((string) $filter['batch']); ?>"><?php endif; ?>
      <div class="rm-actions"><button class="rm-button primary" type="submit">Apply filters</button><a class="rm-button secondary" href="<?= e(research_match_url()); ?>">Reset</a></div>
    </form>
    <p class="rm-note"><?= e(number_format($runs['total'])); ?> <?= $runs['total'] === 1 ? 'run' : 'runs'; ?><?= $filter['batch'] ? ' in evaluation #' . e((string) $filter['batch']) : ''; ?>. All timestamps use the database server's time.</p>
    <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Run / source</th><th>Search input or source manuscript</th><th>Status / calculations</th><th>Created</th><th></th></tr></thead><tbody>
      <?php if ($runs['rows'] === []): ?><tr><td colspan="5" class="rm-empty">No saved runs yet. Submit a Research Match search or start a catalog evaluation.</td></tr><?php endif; ?>
      <?php foreach ($runs['rows'] as $row): ?><tr><td><strong>#<?= e((string) $row['run_id']); ?></strong><span class="rm-badge <?= $row['source_kind'] === 'user_submitted' ? 'human' : 'system'; ?>"><?= e(ResearchSimilarity::SOURCES[$row['source_kind']]); ?></span></td>
        <td class="rm-input"><strong><?= e($row['query_text'] ?? $row['source_title'] ?? 'Unavailable'); ?></strong><small><?= e(ResearchSimilarity::MODES[$row['comparison_mode']]); ?> · <?= e($row['algorithm_version']); ?><?= $row['batch_id'] ? ' · Evaluation #' . e((string) $row['batch_id']) : ''; ?></small></td>
        <td><?= e(ucfirst($row['status'])); ?><small><?= e(number_format((int) $row['result_count'])); ?> saved</small></td><td><?= e($row['created_at']); ?></td>
        <td><a class="rm-button secondary" href="<?= e(research_match_url(array_merge($filter, ['run' => (int) $row['run_id']]))); ?>#results">Open run</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <nav class="rm-pagination" aria-label="Search run pages"><?php if ($runs['page'] > 1): ?><a href="<?= e(research_match_url(array_merge($filter, ['page' => $runs['page'] - 1]))); ?>#collection">Previous</a><?php endif; ?><span>Page <?= e((string) $runs['page']); ?> of <?= e((string) $runs['pages']); ?></span><?php if ($runs['page'] < $runs['pages']): ?><a href="<?= e(research_match_url(array_merge($filter, ['page' => $runs['page'] + 1]))); ?>#collection">Next</a><?php endif; ?></nav>
  </section>

  <?php if ($run !== null): ?>
  <section class="rm-panel" id="results" aria-labelledby="rm-results-title">
    <div class="rm-panel-heading"><div><p class="rm-eyebrow"><?= e(ResearchSimilarity::SOURCES[$run['source_kind']]); ?></p><h3 id="rm-results-title">Run #<?= e((string) $run['run_id']); ?> results</h3><p><?= e($run['query_text'] ?? $run['source_title']); ?></p></div>
      <a class="rm-button secondary" href="<?= e(research_match_url(['run' => (int) $run['run_id'], 'export' => 'csv'])); ?>">Export this run</a></div>
    <p class="rm-note"><?= e(number_format((int) $run['result_count'])); ?> saved calculations · <?= e(ucfirst($run['status'])); ?>. Scores, rankings, and system assessments are generated automatically. Your review is optional and can be added afterward.</p>
    <div class="rm-actions rm-review-filters"><?php foreach (['' => 'All results', 'pending' => 'Pending review', 'reviewed' => 'Reviewed'] as $value => $label): ?><a class="rm-button <?= $filter['review'] === $value ? 'primary' : 'secondary'; ?>" href="<?= e(research_match_url(array_merge($filter, ['review' => $value]))); ?>#results"><?= e($label); ?></a><?php endforeach; ?></div>
    <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Rank</th><th>Matched manuscript</th><th>Basis</th><th>System assessment</th><th>Expert reviews</th><th></th></tr></thead><tbody>
      <?php if ($results['rows'] === []): ?><tr><td colspan="6" class="rm-empty">No results in this selection. Queued runs receive calculations when the evaluation resumes.</td></tr><?php endif; ?>
      <?php foreach ($results['rows'] as $row): $assessment = ResearchAiSearch::assessment((float) $row['overall_score']); ?><tr><td><?= $row['rank_order'] !== null ? e((string) $row['rank_order']) : 'Pending'; ?></td><td class="rm-input"><strong><?= e($row['target_title']); ?></strong><small>Manuscript #<?= e((string) $row['target_titleid']); ?></small></td><td><?= $row['basis'] === 'title_only' ? 'Title only' : 'Title + abstract'; ?></td><td><span class="rm-badge system"><?= e($assessment['label']); ?></span><small><?= e(research_match_percent($row['overall_score'])); ?></small></td><td><?= e((string) $row['reviews']); ?></td><td><a class="rm-button secondary" href="<?= e(research_match_url(array_merge($filter, ['result' => (int) $row['result_id']]))); ?>#calculation">Review / calculation</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <nav class="rm-pagination" aria-label="Result pages"><?php if ($results['page'] > 1): ?><a href="<?= e(research_match_url(array_merge($filter, ['result_page' => $results['page'] - 1]))); ?>#results">Previous</a><?php endif; ?><span><?= e(number_format($results['total'])); ?> results · Page <?= e((string) $results['page']); ?> of <?= e((string) $results['pages']); ?></span><?php if ($results['page'] < $results['pages']): ?><a href="<?= e(research_match_url(array_merge($filter, ['result_page' => $results['page'] + 1]))); ?>#results">Next</a><?php endif; ?></nav>
  </section>
  <?php endif; ?>

  <?php if ($detail !== null): $calculation = ResearchMatchJson::decode($detail['calculation_json']); $ownReview = []; foreach ($reviews as $review) { if ((int) $review['reviewer_accountid'] === $accountId) { $ownReview = $review; } } ?>
  <section class="rm-panel" id="calculation" aria-labelledby="rm-detail-title">
    <p class="rm-eyebrow">Run #<?= e((string) $detail['run_id']); ?> · <?= e(ResearchSimilarity::SOURCES[$detail['source_kind']]); ?></p>
    <h3 id="rm-detail-title">Review saved comparison #<?= e((string) $detail['result_id']); ?></h3>
    <p class="rm-note"><?= e(ResearchSimilarity::MODES[$detail['comparison_mode']]); ?> · <?= e($detail['algorithm_version']); ?> · Calculated <?= e($detail['computed_at']); ?></p>
    <?php $assessment = $calculation['system_assessment'] ?? ResearchAiSearch::assessment((float) $detail['overall_score']); ?>
    <p><span class="rm-badge system">System assessment: <?= e($assessment['label']); ?></span> <strong><?= e(research_match_percent($detail['overall_score'])); ?></strong></p>
    <div class="rm-snapshots"><article><h4><?= $detail['comparison_mode'] === 'query_to_record' ? 'Submitted search text' : 'Source manuscript'; ?></h4><p><?= e($detail['query_text'] ?? $detail['source_title']); ?></p>
      <?php if ($detail['source_title'] !== null): ?><details><summary>Source abstract snapshot</summary><p><?= e($detail['source_abstract'] !== '' ? $detail['source_abstract'] : 'Abstract unavailable.'); ?></p></details><?php endif; ?></article>
      <article><h4>Matched manuscript #<?= e((string) $detail['target_titleid']); ?></h4><p><?= e($detail['target_title']); ?></p><details><summary>Matched abstract snapshot</summary><p><?= e($detail['target_abstract'] !== '' ? $detail['target_abstract'] : 'Abstract unavailable.'); ?></p></details></article></div>
    <?php if ($detail['run_status'] === 'completed'): ?>
    <form method="post" action="<?= e(research_match_url(array_merge($filter, ['result' => $resultId]))); ?>#calculation" class="rm-review-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="result_id" value="<?= e((string) $detail['result_id']); ?>">
      <h4>Your optional relevance review</h4><p class="rm-note">Review the automated result whenever needed. Your judgment is saved separately and does not overwrite the system score or assessment.</p>
      <div class="rm-fields"><label>Relevance label<select name="expert_label" required><option value="">Choose a label</option><?php foreach (TitleSimilarity::reviewLabelOptions() as $value => $label): ?><option value="<?= e($value); ?>" <?= ($ownReview['expert_label'] ?? '') === $value ? 'selected' : ''; ?>><?= e($label); ?></option><?php endforeach; ?></select></label>
        <label class="rm-grow">Review note<textarea name="review_note" maxlength="2000" rows="2"><?= e($ownReview['review_note'] ?? ''); ?></textarea></label></div>
      <button class="rm-button primary" type="submit">Save relevance review</button><p class="rm-note">Saving again updates your own review. Other reviewers' labels are preserved.</p>
    </form><?php else: ?><p class="rm-note">This run is still processing. Reviews become available when its rankings are complete.</p><?php endif; ?>
    <details class="rm-calculation"><summary>View calculation</summary>
      <p><?= $calculation['basis'] === 'title_only' ? 'Abstract evidence is unavailable. Each algorithm uses its title score only.' : 'Each algorithm combines title similarity × 70% + abstract similarity × 30%.'; ?> The overall score is the sum of the weighted contributions.</p>
      <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Algorithm</th><th>Weight</th><th>Title</th><th>Abstract</th><th>Combined</th><th>Contribution</th></tr></thead><tbody>
        <?php foreach ($calculation['algorithms'] as $algorithm): ?><tr><td><?= e($algorithm['label']); ?></td><td><?= e(research_match_percent($algorithm['weight'])); ?></td><td><?= e(research_match_percent($algorithm['title_score'])); ?></td><td><?= e(research_match_percent($algorithm['abstract_score'])); ?></td><td><?= e(research_match_percent($algorithm['combined_score'])); ?></td><td><?= e(number_format($algorithm['contribution'] * 100, 4)); ?> pts</td></tr><?php endforeach; ?>
      </tbody><tfoot><tr><th colspan="5">Overall similarity</th><td><strong><?= e(research_match_percent($calculation['overall_score'])); ?></strong></td></tr></tfoot></table></div>
      <p class="rm-note">Title weight <?= e(research_match_percent($calculation['title_weight'])); ?> · Abstract weight <?= e(research_match_percent($calculation['abstract_weight'])); ?>. CSV exports retain the underlying numeric values; displayed values are rounded.</p>
    </details>
    <?php if ($reviews !== []): ?><details class="rm-history"><summary>Review history (<?= e((string) count($reviews)); ?>)</summary><?php foreach ($reviews as $review): ?><article class="rm-review-history"><strong><?= e($review['reviewer_name']); ?> · <?= e(TitleSimilarity::reviewLabelOptions()[$review['expert_label']]); ?></strong><small><?= e($review['updated_at']); ?></small><p><?= e($review['review_note'] ?? ''); ?></p></article><?php endforeach; ?></details><?php endif; ?>
  </section><?php endif; ?>

  <section class="rm-panel" aria-labelledby="rm-auto-title"><h3 id="rm-auto-title">Automated evaluation</h3>
    <p class="rm-note" id="rm-auto-live-status" role="status" aria-live="polite"></p>
    <p class="rm-note">Reports are generated as each source run finishes, without requiring a review. High similarity means at least 72%; moderate means 48% to below 72%; lower scores are low similarity. These are system assessment thresholds, not verified human relevance labels.</p>
    <?php if ($automated === []): ?><p class="rm-empty" id="rm-auto-empty">Automated reports appear when the first source run completes. Resume the catalog evaluation to generate them.</p><?php endif; ?>
    <?php foreach ($automated as $group): ?>
      <h4><?= e(ResearchSimilarity::SOURCES[$group['source']] . ' · ' . $group['version']); ?></h4>
      <p class="rm-note"><?= e(number_format($group['runs'])); ?> completed runs · <?= e(number_format($group['comparisons'])); ?> comparisons · <?= e(number_format($group['title_only'])); ?> title-only comparisons.</p>
      <div class="rm-method"><div><strong><?= e(number_format($group['high'])); ?> high similarity</strong><span>Overall score ≥ 72%</span></div><div><strong><?= e(number_format($group['medium'])); ?> moderate similarity</strong><span>Overall score ≥ 48% and &lt; 72%</span></div><div><strong><?= e(number_format($group['low'])); ?> low similarity</strong><span>Overall score &lt; 48%</span></div></div>
      <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Algorithm</th><th>Average similarity</th><th>Reference threshold</th><th>At / above threshold</th><th>Share of comparisons</th></tr></thead><tbody>
      <?php foreach ($group['algorithms'] as $metric): ?><tr><td><?= e($metric['label']); ?></td><td><?= $group['comparisons'] > 0 ? e(research_match_percent($metric['score_sum'] / $group['comparisons'])) : 'N/A'; ?></td><td><?= e(research_match_percent($metric['threshold'])); ?></td><td><?= e(number_format($metric['predicted_related'])); ?></td><td><?= $group['comparisons'] > 0 ? e(research_match_percent($metric['predicted_related'] / $group['comparisons'])) : 'N/A'; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endforeach; ?>
  </section>

  <section class="rm-panel" aria-labelledby="rm-metrics-title"><h3 id="rm-metrics-title">Optional review validation</h3>
    <p class="rm-note">Metrics use the selected run, source, mode, and evaluation filters above. Modes and algorithm versions are reported separately. Highly Related and Related count as positive; tied positive/negative reviewer votes are excluded. Reference thresholds are inherited from the previous evaluation and require validation for this method.</p>
    <?php if ($metrics === []): ?><p class="rm-empty">All automated scores and reports are available without reviews. Accuracy, precision, recall, and F1 become available when you add independent relevance reviews afterward.</p><?php endif; ?>
    <?php foreach ($metrics as $group): ?><h4><?= e(ResearchSimilarity::MODES[$group['mode']] . ' · ' . $group['version']); ?></h4><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Algorithm</th><th>Threshold</th><th>Reviewed</th><th>TP / FP / TN / FN</th><th>Accuracy</th><th>Precision</th><th>Recall</th><th>F1</th></tr></thead><tbody>
      <?php foreach ($group['metrics'] as $metric): ?><tr><td><?= e($metric['label']); ?></td><td><?= e(research_match_percent($metric['threshold'])); ?></td><td><?= e((string) $metric['count']); ?></td><td><?= e($metric['tp'] . ' / ' . $metric['fp'] . ' / ' . $metric['tn'] . ' / ' . $metric['fn']); ?></td><td><?= e(research_match_percent($metric['accuracy'])); ?></td><td><?= e(research_match_percent($metric['precision'])); ?></td><td><?= e(research_match_percent($metric['recall'])); ?></td><td><?= e(research_match_percent($metric['f1'])); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endforeach; ?>
  </section>
</div>
<?php
$mainContent = (string) ob_get_clean();
AdminPage::render(['title' => 'Administrator | Research Similarity Evaluation', 'current_page' => 'title_similarity',
    'main_content' => $mainContent, 'extra_head' => $extraHead, 'extra_scripts' => $extraScripts]);
