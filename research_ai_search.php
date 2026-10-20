<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/app/Env.php';
Env::load(__DIR__ . '/.env');
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/ResearchSimilarity.php';
ResearchMatchJson::protectResponse();

start_secure_session();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$query = is_string($_POST['query'] ?? null) ? trim($_POST['query']) : '';
$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : ResearchAiSearch::DEFAULT_LIMIT;

if ($query === '') {
    http_response_code(422);
    echo json_encode([
        'message' => 'Enter a research topic, title idea, or problem statement first.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        http_response_code(403);
        throw new InvalidArgumentException('Refresh the page before submitting another search.');
    }
    $requestId = is_string($_POST['request_id'] ?? null) ? $_POST['request_id'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
        throw new InvalidArgumentException('The search request is invalid. Refresh the page and try again.');
    }
    $requestKey = hash('sha256', session_id() . '|research-match|' . $requestId);
    session_write_close();
    $pdo = Database::connection();
    Database::ensureResearchMatchTables();
    $result = ResearchSimilarity::searchAndSave($pdo, $query, $limit, $requestKey);

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    if (http_response_code() !== 403) {
        http_response_code(422);
    }
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }
    echo json_encode(['message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Research Match collection: ' . $exception->getMessage());
    http_response_code(500);

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode([
        'message' => 'Unable to complete and save the search right now. Please try again.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (ob_get_level() > 0) {
    ob_end_flush();
}
