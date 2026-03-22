<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/app/Env.php';
Env::load(__DIR__ . '/.env');
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/ResearchAiSearch.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$query = trim((string) ($_POST['query'] ?? ''));
$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : ResearchAiSearch::DEFAULT_LIMIT;

if ($query === '') {
    http_response_code(422);
    echo json_encode([
        'message' => 'Enter a research topic, title idea, or problem statement first.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = Database::connection();
    $result = ResearchAiSearch::search($pdo, $query, $limit);

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode([
        'message' => 'Unable to search the research catalog right now.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (ob_get_level() > 0) {
    ob_end_flush();
}
