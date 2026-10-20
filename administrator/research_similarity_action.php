<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/ResearchSimilarity.php';
ResearchMatchJson::protectResponse();

try {
    if (!Auth::check()) {
        ResearchMatchJson::respond(['message' => 'Your session has expired. Sign in again before resuming the evaluation.'], 401);
        exit;
    }
    Auth::requireLogin();
    if (!Auth::isAdmin()) {
        ResearchMatchJson::respond(['message' => 'Administrator access is required for catalog evaluations.'], 403);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('Use POST for evaluation actions.');
    }
    if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        http_response_code(403);
        throw new InvalidArgumentException('Refresh the page and try again.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $accountId = (int) (Auth::account()['accountid'] ?? 0);
    $batchId = ResearchSimilarity::id($_POST['batch_id'] ?? 0);
    $requestId = is_string($_POST['request_id'] ?? null) ? $_POST['request_id'] : '';
    session_write_close();
    $pdo = Database::connection();
    Database::ensureResearchMatchTables();

    switch ($action) {
        case 'start':
            if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
                throw new InvalidArgumentException('The evaluation request is invalid.');
            }
            $batch = ResearchSimilarity::startBatch($pdo, $accountId, hash('sha256', 'catalog|' . $accountId . '|' . $requestId));
            break;
        case 'process':
            $batch = ResearchSimilarity::processBatch($pdo, $batchId);
            break;
        case 'pause':
        case 'resume':
            $batch = ResearchSimilarity::setBatchStatus($pdo, $batchId, $action === 'pause' ? 'paused' : 'running');
            break;
        case 'status':
            $batch = ResearchSimilarity::batch($pdo, $batchId);
            break;
        default:
            throw new InvalidArgumentException('Unsupported evaluation action.');
    }
    ResearchMatchJson::respond(['batch' => $batch]);
} catch (InvalidArgumentException $exception) {
    if (http_response_code() === 200) {
        http_response_code(422);
    }
    ResearchMatchJson::respond(['message' => $exception->getMessage()], http_response_code() ?: 422);
} catch (Throwable $exception) {
    error_log('Research Match batch: ' . $exception->getMessage());
    http_response_code(500);
    ResearchMatchJson::respond(['message' => 'The evaluation could not continue. Saved progress is retained; resume the batch to retry.'], 500);
}
