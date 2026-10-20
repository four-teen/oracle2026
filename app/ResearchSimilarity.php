<?php

declare(strict_types=1);

require_once __DIR__ . '/ResearchAiSearch.php';
require_once __DIR__ . '/TitleSimilarity.php';
require_once __DIR__ . '/ResearchMatchJson.php';

/** Durable collection for public searches and resumable catalog experiments. */
final class ResearchSimilarity
{
    public const SOURCES = ['user_submitted' => 'User-submitted search', 'system_generated' => 'System-generated evaluation'];
    public const MODES = ['query_to_record' => 'Search text to title / abstract', 'record_to_record' => 'Title to title / abstract to abstract'];

    private static function json(array $value): string
    {
        return ResearchMatchJson::encode($value);
    }

    private static function one(PDO $pdo, string $sql, array $parameters): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private static function snapshot(PDO $pdo, array $record): int
    {
        $title = (string) $record['title'];
        $abstract = ResearchAiSearch::plainText((string) ($record['other_details'] ?? ''));
        $hash = hash('sha256', self::json([(int) $record['titleid'], $title, $abstract]));
        $statement = $pdo->prepare('INSERT INTO tblresearchmatch_document (content_hash, titleid, title, abstract_text)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE document_id = LAST_INSERT_ID(document_id)');
        $statement->execute([$hash, (int) $record['titleid'], $title, $abstract]);
        return (int) $pdo->lastInsertId();
    }

    private static function insertResult(PDO $pdo, int $runId, int $documentId, ?int $rank, array $calculation, ?int $reusedFrom = null): void
    {
        $statement = $pdo->prepare('INSERT INTO tblresearchmatch_result
            (run_id, target_document_id, rank_order, basis, title_score, abstract_score, overall_score, calculation_json, reused_from_result_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$runId, $documentId, $rank, $calculation['basis'], $calculation['title_score'],
            $calculation['abstract_score'], $calculation['overall_score'], self::json($calculation), $reusedFrom]);
    }

    private static function publicResponse(array $run): array
    {
        $response = ResearchMatchJson::decode((string) $run['response_json']);
        $response['collection'] = ['run_id' => (int) $run['run_id'], 'source_kind' => 'user_submitted', 'saved' => true];
        return $response;
    }

    /** The request key is scoped to the browser session; retries return the same completed run. */
    public static function searchAndSave(PDO $pdo, string $query, int $limit, string $requestKey): array
    {
        $originalQuery = trim($query);
        $query = ResearchAiSearch::validateQuery($query);
        $normalized = ResearchAiSearch::normalizeTitle($query);
        $limit = max(1, min(8, $limit));
        $existing = self::one($pdo, 'SELECT * FROM tblresearchmatch_run WHERE request_key = ?', [$requestKey]);
        if ($existing !== null) {
            if ($existing['query_text'] !== $originalQuery || (int) $existing['result_limit'] !== $limit) {
                throw new InvalidArgumentException('Submit a new request for a different search.');
            }
            return self::publicResponse($existing);
        }

        $rows = ResearchAiSearch::fetchCatalogRows($pdo);
        $catalogHash = hash('sha256', self::json($rows));
        // Cache computations briefly, but still record each distinct successful search event.
        $cached = self::one($pdo, "SELECT * FROM tblresearchmatch_run
            WHERE source_kind = 'user_submitted' AND status = 'completed' AND catalog_hash = ?
              AND algorithm_version = ? AND query_hash = ? AND normalized_query = ? AND result_limit = ?
              AND completed_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ORDER BY run_id DESC LIMIT 1", [$catalogHash, ResearchAiSearch::ALGORITHM_VERSION, hash('sha256', $normalized), $normalized, $limit]);
        $response = $cached !== null ? ResearchMatchJson::decode($cached['response_json'])
            : ResearchAiSearch::searchRows($query, $rows, $limit);
        if ($response['matches'] === [] && $rows !== []) {
            $response['summary'] = 'No close research match was found for "' . $query . '" in the current catalog.';
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("INSERT INTO tblresearchmatch_run
                (request_key, source_kind, comparison_mode, status, query_text, normalized_query, query_hash,
                 algorithm_version, catalog_hash, catalog_count, result_count, result_limit,
                 response_json, cached_from_run_id, completed_at)
                VALUES (?, 'user_submitted', 'query_to_record', 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $statement->execute([$requestKey, $originalQuery, $normalized, hash('sha256', $normalized), ResearchAiSearch::ALGORITHM_VERSION,
                $catalogHash, count($rows), count($response['matches']), $limit, self::json($response), $cached['run_id'] ?? null]);
            $runId = (int) $pdo->lastInsertId();
            if ($cached !== null) {
                $copy = $pdo->prepare('INSERT INTO tblresearchmatch_result
                    (run_id, target_document_id, rank_order, basis, title_score, abstract_score, overall_score, calculation_json, computed_at, reused_from_result_id)
                    SELECT ?, target_document_id, rank_order, basis, title_score, abstract_score, overall_score, calculation_json, computed_at, result_id
                    FROM tblresearchmatch_result WHERE run_id = ?');
                $copy->execute([$runId, $cached['run_id']]);
            } else {
                $byId = array_column($rows, null, 'titleid');
                foreach ($response['matches'] as $index => $match) {
                    $documentId = self::snapshot($pdo, $byId[$match['titleid']]);
                    self::insertResult($pdo, $runId, $documentId, $index + 1, $match['score_breakdown']);
                }
            }
            self::saveSummary($pdo, $runId, array_column($response['matches'], 'score_breakdown'));
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // A simultaneous retry may have committed this request while it was being calculated.
            $existing = self::one($pdo, 'SELECT * FROM tblresearchmatch_run WHERE request_key = ?', [$requestKey]);
            if ($existing !== null && $existing['query_text'] === $originalQuery && (int) $existing['result_limit'] === $limit) {
                return self::publicResponse($existing);
            }
            throw $exception;
        }
        $response['collection'] = ['run_id' => $runId, 'source_kind' => 'user_submitted', 'saved' => true];
        return $response;
    }

    public static function startBatch(PDO $pdo, int $accountId, string $requestKey): array
    {
        if ($accountId < 1) {
            throw new InvalidArgumentException('The administrator account could not be resolved.');
        }
        $existing = self::one($pdo, 'SELECT * FROM tblresearchmatch_batch WHERE request_key = ?', [$requestKey]);
        if ($existing !== null) {
            return $existing;
        }
        $rows = ResearchAiSearch::fetchCatalogRows($pdo, true);
        usort($rows, static function (array $a, array $b): int { return (int) $a['titleid'] <=> (int) $b['titleid']; });
        $count = count($rows);
        if ($count < 2) {
            throw new InvalidArgumentException('At least two manuscripts with titles are needed for a catalog evaluation.');
        }
        $hash = hash('sha256', self::json($rows));
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO tblresearchmatch_batch
                (request_key, algorithm_version, catalog_hash, catalog_count, expected_comparisons, created_by)
                VALUES (?, ?, ?, ?, ?, ?)');
            $statement->execute([$requestKey, ResearchAiSearch::ALGORITHM_VERSION, $hash, $count, $count * ($count - 1), $accountId]);
            $batchId = (int) $pdo->lastInsertId();
            $link = $pdo->prepare('INSERT INTO tblresearchmatch_batch_document (batch_id, position, document_id) VALUES (?, ?, ?)');
            $run = $pdo->prepare("INSERT INTO tblresearchmatch_run
                (source_kind, comparison_mode, status, source_document_id, batch_id, source_position,
                 algorithm_version, catalog_hash, catalog_count)
                VALUES ('system_generated', 'record_to_record', 'queued', ?, ?, ?, ?, ?, ?)");
            foreach ($rows as $position => $row) {
                $documentId = self::snapshot($pdo, $row);
                $link->execute([$batchId, $position, $documentId]);
                $run->execute([$documentId, $batchId, $position, ResearchAiSearch::ALGORITHM_VERSION, $hash, $count]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $existing = self::one($pdo, 'SELECT * FROM tblresearchmatch_batch WHERE request_key = ?', [$requestKey]);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
        return self::batch($pdo, $batchId);
    }

    public static function batch(PDO $pdo, int $batchId): array
    {
        $batch = self::one($pdo, 'SELECT * FROM tblresearchmatch_batch WHERE batch_id = ?', [$batchId]);
        if ($batch === null) {
            throw new InvalidArgumentException('Evaluation batch not found.');
        }
        $counts = self::one($pdo, "SELECT COUNT(*) AS total_runs, SUM(status = 'completed') AS completed_runs
            FROM tblresearchmatch_run WHERE batch_id = ?", [$batchId]);
        return array_merge($batch, $counts ?? []);
    }

    public static function setBatchStatus(PDO $pdo, int $batchId, string $status): array
    {
        if (!in_array($status, ['paused', 'running'], true)) {
            throw new InvalidArgumentException('Invalid batch status.');
        }
        $batch = self::batch($pdo, $batchId);
        // The PHP 7.1 response failure left a frozen catalog but no calculations. Reuse that safe snapshot.
        if ($status === 'running' && $batch['algorithm_version'] === 'research-match-1.0'
            && ResearchAiSearch::ALGORITHM_VERSION === 'research-match-1.1' && (int) $batch['processed_comparisons'] === 0) {
            $pdo->beginTransaction();
            try {
                $locked = self::one($pdo, 'SELECT * FROM tblresearchmatch_batch WHERE batch_id = ? FOR UPDATE', [$batchId]);
                $existing = self::one($pdo, 'SELECT COUNT(*) AS total FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id WHERE r.batch_id = ?', [$batchId]);
                $started = self::one($pdo, "SELECT COUNT(*) AS total FROM tblresearchmatch_run WHERE batch_id = ? AND status <> 'queued'", [$batchId]);
                if ($locked['algorithm_version'] === 'research-match-1.0' && (int) $locked['processed_comparisons'] === 0
                    && (int) $existing['total'] === 0 && (int) $started['total'] === 0) {
                    $pdo->prepare('UPDATE tblresearchmatch_run SET algorithm_version = ? WHERE batch_id = ?')->execute([ResearchAiSearch::ALGORITHM_VERSION, $batchId]);
                    $pdo->prepare('UPDATE tblresearchmatch_batch SET algorithm_version = ? WHERE batch_id = ?')->execute([ResearchAiSearch::ALGORITHM_VERSION, $batchId]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            $batch = self::batch($pdo, $batchId);
        }
        if ($batch['algorithm_version'] !== ResearchAiSearch::ALGORITHM_VERSION) {
            throw new InvalidArgumentException('This batch uses a previous algorithm version. Start a new evaluation to use the current version.');
        }
        $statement = $pdo->prepare("UPDATE tblresearchmatch_batch SET status = ? WHERE batch_id = ? AND status <> 'completed'");
        $statement->execute([$status, $batchId]);
        return self::batch($pdo, $batchId);
    }

    /** A short, locked transaction commits results and the cursor together. Retrying cannot skip a target. */
    public static function processBatch(PDO $pdo, int $batchId, int $chunkSize = 80): array
    {
        $pdo->beginTransaction();
        try {
            $batch = self::one($pdo, 'SELECT * FROM tblresearchmatch_batch WHERE batch_id = ? FOR UPDATE', [$batchId]);
            if ($batch === null) {
                throw new InvalidArgumentException('Evaluation batch not found.');
            }
            if ($batch['status'] !== 'running') {
                $pdo->commit();
                return self::batch($pdo, $batchId);
            }
            if ($batch['algorithm_version'] !== ResearchAiSearch::ALGORITHM_VERSION) {
                throw new InvalidArgumentException('Algorithm version changed. Start a new evaluation; the previous calculations remain available.');
            }
            $run = self::one($pdo, "SELECT r.*, d.title, d.abstract_text FROM tblresearchmatch_run r
                JOIN tblresearchmatch_document d ON d.document_id = r.source_document_id
                WHERE r.batch_id = ? AND r.status IN ('queued', 'running') ORDER BY r.source_position LIMIT 1", [$batchId]);
            if ($run === null) {
                $pdo->prepare("UPDATE tblresearchmatch_batch SET status = 'completed', completed_at = NOW() WHERE batch_id = ?")->execute([$batchId]);
                $pdo->commit();
                return self::batch($pdo, $batchId);
            }
            $limit = max(1, min(200, $chunkSize));
            $statement = $pdo->prepare('SELECT bd.position, d.*, reverse_result.result_id AS reused_result_id,
                reverse_result.calculation_json AS reused_calculation_json FROM tblresearchmatch_batch_document bd
                JOIN tblresearchmatch_document d ON d.document_id = bd.document_id
                LEFT JOIN tblresearchmatch_run reverse_run ON reverse_run.batch_id = bd.batch_id
                    AND reverse_run.source_position = bd.position AND reverse_run.algorithm_version = ?
                LEFT JOIN tblresearchmatch_result reverse_result ON reverse_result.run_id = reverse_run.run_id
                    AND reverse_result.target_document_id = ?
                WHERE bd.batch_id = ? AND bd.position >= ? ORDER BY bd.position LIMIT ' . $limit);
            $statement->execute([$run['algorithm_version'], $run['source_document_id'], $batchId, $run['next_target_position']]);
            $targets = $statement->fetchAll();
            $source = ResearchAiSearch::prepareRecord($run['title'], $run['abstract_text']);
            $cursor = (int) $run['next_target_position'];
            $added = 0;
            $started = microtime(true);
            foreach ($targets as $target) {
                if ((int) $target['document_id'] !== (int) $run['source_document_id']) {
                    // All four corresponding-field scores are symmetric for these same frozen inputs.
                    $reusedFrom = $target['reused_result_id'] !== null ? (int) $target['reused_result_id'] : null;
                    $calculation = $reusedFrom !== null ? ResearchMatchJson::decode($target['reused_calculation_json'])
                        : ResearchAiSearch::compareRecords($source, ResearchAiSearch::prepareRecord($target['title'], $target['abstract_text']));
                    self::insertResult($pdo, (int) $run['run_id'], (int) $target['document_id'], null, $calculation, $reusedFrom);
                    $added++;
                }
                $cursor = (int) $target['position'] + 1;
                if (microtime(true) - $started >= 3.0) {
                    break;
                }
            }
            $complete = $cursor >= (int) $batch['catalog_count'];
            if ($complete) {
                $ranked = $pdo->prepare('SELECT result_id, calculation_json FROM tblresearchmatch_result WHERE run_id = ? ORDER BY overall_score DESC, target_document_id');
                $ranked->execute([$run['run_id']]);
                $rankRows = $ranked->fetchAll();
                $rankIds = array_column($rankRows, 'result_id');
                foreach (array_chunk($rankIds, 200, true) as $rankChunk) {
                    $cases = [];
                    $values = [];
                    foreach ($rankChunk as $index => $resultId) {
                        $cases[] = 'WHEN ? THEN ?';
                        $values[] = $resultId;
                        $values[] = $index + 1;
                    }
                    $placeholders = implode(',', array_fill(0, count($rankChunk), '?'));
                    $updateRank = $pdo->prepare('UPDATE tblresearchmatch_result SET rank_order = CASE result_id '
                        . implode(' ', $cases) . ' END WHERE run_id = ? AND result_id IN (' . $placeholders . ')');
                    $updateRank->execute(array_merge($values, [$run['run_id']], array_values($rankChunk)));
                }
                self::saveSummary($pdo, (int) $run['run_id'], array_map(static function (array $row): array {
                    return ResearchMatchJson::decode($row['calculation_json']);
                }, $rankRows));
            }
            $update = $pdo->prepare('UPDATE tblresearchmatch_run SET next_target_position = ?, result_count = result_count + ?,
                status = ?, completed_at = ' . ($complete ? 'NOW()' : 'NULL') . ' WHERE run_id = ?');
            $update->execute([$cursor, $added, $complete ? 'completed' : 'running', $run['run_id']]);
            $update = $pdo->prepare('UPDATE tblresearchmatch_batch SET processed_comparisons = processed_comparisons + ? WHERE batch_id = ?');
            $update->execute([$added, $batchId]);
            if ($complete && (int) $run['source_position'] === (int) $batch['catalog_count'] - 1) {
                $pdo->prepare("UPDATE tblresearchmatch_batch SET status = 'completed', completed_at = NOW() WHERE batch_id = ?")->execute([$batchId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return self::batch($pdo, $batchId);
    }

    public static function saveReview(PDO $pdo, int $resultId, int $accountId, string $name, string $label, string $note): void
    {
        if ($accountId < 1 || !isset(TitleSimilarity::reviewLabelOptions()[$label]) || mb_strlen($note, 'UTF-8') > 2000) {
            throw new InvalidArgumentException('Choose a valid review label and keep the note within 2,000 characters.');
        }
        $result = self::one($pdo, "SELECT x.result_id FROM tblresearchmatch_result x
            JOIN tblresearchmatch_run r ON r.run_id = x.run_id WHERE x.result_id = ? AND r.status = 'completed'", [$resultId]);
        if ($result === null) {
            throw new InvalidArgumentException('Only completed search results can be reviewed.');
        }
        $statement = $pdo->prepare('INSERT INTO tblresearchmatch_review
            (result_id, reviewer_accountid, reviewer_name, expert_label, review_note) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE expert_label = VALUES(expert_label), review_note = VALUES(review_note),
                reviewer_name = VALUES(reviewer_name), updated_at = CURRENT_TIMESTAMP');
        $statement->execute([$resultId, $accountId, mb_substr($name, 0, 255, 'UTF-8'), $label, trim($note)]);
    }

    /** All filter values are validated centrally and every text/ID value is bound. */
    public static function filter(array $input): array
    {
        $source = is_string($input['source'] ?? null) ? $input['source'] : '';
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : '';
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        $review = is_string($input['review'] ?? null) ? $input['review'] : '';
        return ['source' => isset(self::SOURCES[$source]) ? $source : '', 'mode' => isset(self::MODES[$mode]) ? $mode : '',
            'status' => in_array($status, ['queued', 'running', 'completed'], true) ? $status : '',
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 1000, 'UTF-8') : '',
            'batch' => self::id($input['batch'] ?? 0), 'run' => self::id($input['run'] ?? 0),
            'review' => in_array($review, ['pending', 'reviewed'], true) ? $review : ''];
    }

    public static function id($value): int
    {
        return is_scalar($value) && ctype_digit((string) $value) ? max(0, (int) $value) : 0;
    }

    public static function runWhere(array $filter): array
    {
        $where = ['1=1'];
        $parameters = [];
        foreach (['source' => 'source_kind', 'mode' => 'comparison_mode', 'status' => 'status', 'batch' => 'batch_id', 'run' => 'run_id'] as $key => $field) {
            if (!empty($filter[$key])) {
                $where[] = 'r.' . $field . ' = ?';
                $parameters[] = $filter[$key];
            }
        }
        if ($filter['q'] !== '') {
            $where[] = '(r.query_text LIKE ? OR s.title LIKE ?)';
            $term = '%' . addcslashes($filter['q'], '\\%_') . '%';
            $parameters[] = $term;
            $parameters[] = $term;
        }
        return [implode(' AND ', $where), $parameters];
    }

    public static function runList(PDO $pdo, array $filter, int $page): array
    {
        [$where, $parameters] = self::runWhere($filter);
        $from = ' FROM tblresearchmatch_run r LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id WHERE ' . $where;
        $count = self::one($pdo, 'SELECT COUNT(*) AS total' . $from, $parameters);
        $total = (int) $count['total'];
        $page = min(max(1, $page), max(1, (int) ceil($total / 20)));
        $statement = $pdo->prepare('SELECT r.*, s.title AS source_title' . $from . ' ORDER BY r.run_id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $statement->execute($parameters);
        return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    public static function run(PDO $pdo, int $runId): ?array
    {
        return self::one($pdo, 'SELECT r.*, s.title AS source_title, s.abstract_text AS source_abstract, s.titleid AS source_titleid
            FROM tblresearchmatch_run r LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id
            WHERE r.run_id = ?', [$runId]);
    }

    public static function results(PDO $pdo, int $runId, string $review, int $page): array
    {
        $where = 'x.run_id = ?';
        if ($review !== '') {
            $where .= ($review === 'pending' ? ' AND NOT EXISTS' : ' AND EXISTS') .
                ' (SELECT 1 FROM tblresearchmatch_review v WHERE v.result_id = x.result_id)';
        }
        $count = self::one($pdo, 'SELECT COUNT(*) AS total FROM tblresearchmatch_result x WHERE ' . $where, [$runId]);
        $total = (int) $count['total'];
        $page = min(max(1, $page), max(1, (int) ceil($total / 20)));
        $statement = $pdo->prepare('SELECT x.*, t.title AS target_title, t.titleid AS target_titleid,
            (SELECT COUNT(*) FROM tblresearchmatch_review v WHERE v.result_id = x.result_id) AS reviews
            FROM tblresearchmatch_result x JOIN tblresearchmatch_document t ON t.document_id = x.target_document_id
            WHERE ' . $where . ' ORDER BY COALESCE(x.rank_order, 4294967295), x.overall_score DESC, x.target_document_id LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $statement->execute([$runId]);
        return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    public static function detail(PDO $pdo, int $resultId): ?array
    {
        return self::one($pdo, 'SELECT x.*, r.source_kind, r.comparison_mode, r.status AS run_status, r.query_text,
            r.algorithm_version, r.batch_id, r.catalog_hash, r.created_at AS run_created_at, r.completed_at AS run_completed_at,
            r.normalized_query, r.cached_from_run_id, r.catalog_count, r.result_limit,
            s.title AS source_title, s.abstract_text AS source_abstract, s.titleid AS source_titleid,
            t.title AS target_title, t.abstract_text AS target_abstract, t.titleid AS target_titleid
            FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id
            LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id
            JOIN tblresearchmatch_document t ON t.document_id = x.target_document_id WHERE x.result_id = ?', [$resultId]);
    }

    public static function reviews(PDO $pdo, int $resultId): array
    {
        $statement = $pdo->prepare('SELECT * FROM tblresearchmatch_review WHERE result_id = ? ORDER BY updated_at DESC');
        $statement->execute([$resultId]);
        return $statement->fetchAll();
    }

    private static function saveSummary(PDO $pdo, int $runId, array $calculations): void
    {
        $summary = ['comparisons' => count($calculations), 'high' => 0, 'medium' => 0, 'low' => 0, 'title_only' => 0, 'algorithms' => []];
        foreach (TitleSimilarity::algorithmDefinitions() as $key => $definition) {
            $summary['algorithms'][$key] = ['label' => $definition['label'], 'threshold' => $definition['threshold'], 'score_sum' => 0.0, 'predicted_related' => 0];
        }
        foreach ($calculations as $calculation) {
            $assessment = $calculation['system_assessment'] ?? ResearchAiSearch::assessment((float) $calculation['overall_score']);
            $summary[$assessment['bucket']]++;
            $summary['title_only'] += $calculation['basis'] === 'title_only' ? 1 : 0;
            $scores = array_column($calculation['algorithms'], 'combined_score', 'key');
            $scores['hybrid'] = $calculation['overall_score'];
            foreach ($scores as $key => $score) {
                $summary['algorithms'][$key]['score_sum'] += $score;
                $summary['algorithms'][$key]['predicted_related'] += $score >= $summary['algorithms'][$key]['threshold'] ? 1 : 0;
            }
        }
        $pdo->prepare('UPDATE tblresearchmatch_run SET summary_json = ? WHERE run_id = ?')->execute([self::json($summary), $runId]);
    }

    /** Summaries are produced automatically during scoring; optional expert labels are not required. */
    public static function automatedSummary(PDO $pdo, array $filter): array
    {
        [$where, $parameters] = self::runWhere($filter);
        $statement = $pdo->prepare("SELECT r.source_kind, r.comparison_mode, r.algorithm_version, r.summary_json
            FROM tblresearchmatch_run r LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id
            WHERE r.status = 'completed' AND r.summary_json IS NOT NULL AND " . $where);
        $statement->execute($parameters);
        $groups = [];
        while ($row = $statement->fetch()) {
            $key = $row['source_kind'] . '|' . $row['comparison_mode'] . '|' . $row['algorithm_version'];
            $summary = ResearchMatchJson::decode($row['summary_json']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['source' => $row['source_kind'], 'mode' => $row['comparison_mode'], 'version' => $row['algorithm_version'],
                    'runs' => 0, 'comparisons' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'title_only' => 0, 'algorithms' => []];
            }
            $groups[$key]['runs']++;
            foreach (['comparisons', 'high', 'medium', 'low', 'title_only'] as $field) $groups[$key][$field] += $summary[$field];
            foreach ($summary['algorithms'] as $algorithm => $metric) {
                if (!isset($groups[$key]['algorithms'][$algorithm])) {
                    $groups[$key]['algorithms'][$algorithm] = ['label' => $metric['label'], 'threshold' => $metric['threshold'], 'score_sum' => 0.0, 'predicted_related' => 0];
                }
                $groups[$key]['algorithms'][$algorithm]['score_sum'] += $metric['score_sum'];
                $groups[$key]['algorithms'][$algorithm]['predicted_related'] += $metric['predicted_related'];
            }
        }
        return $groups;
    }

    /** Metrics are separated by input mode and algorithm version; tied reviewer votes are excluded. */
    public static function metrics(PDO $pdo, array $filter): array
    {
        [$where, $parameters] = self::runWhere($filter);
        $statement = $pdo->prepare("SELECT x.result_id, x.calculation_json, r.comparison_mode, r.algorithm_version,
            SUM(v.expert_label IN ('highly_related', 'related')) AS positive, SUM(v.expert_label = 'not_related') AS negative
            FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id
            LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id
            JOIN tblresearchmatch_review v ON v.result_id = x.result_id
            WHERE r.status = 'completed' AND " . $where . '
            GROUP BY x.result_id, x.calculation_json, r.comparison_mode, r.algorithm_version');
        $statement->execute($parameters);
        $groups = [];
        while ($row = $statement->fetch()) {
            if ((int) $row['positive'] === (int) $row['negative']) {
                continue;
            }
            $group = $row['comparison_mode'] . '|' . $row['algorithm_version'];
            $scores = ResearchMatchJson::decode($row['calculation_json']);
            $algorithms = array_column($scores['algorithms'], 'combined_score', 'key');
            $algorithms['hybrid'] = $scores['overall_score'];
            foreach (TitleSimilarity::algorithmDefinitions() as $key => $definition) {
                if (!isset($groups[$group]['metrics'][$key])) {
                    $groups[$group]['mode'] = $row['comparison_mode'];
                    $groups[$group]['version'] = $row['algorithm_version'];
                    $groups[$group]['metrics'][$key] = $definition + ['tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0];
                }
                $actual = (int) $row['positive'] > (int) $row['negative'];
                $predicted = $algorithms[$key] >= $definition['threshold'];
                $bucket = $predicted ? ($actual ? 'tp' : 'fp') : ($actual ? 'fn' : 'tn');
                $groups[$group]['metrics'][$key][$bucket]++;
            }
        }
        foreach ($groups as &$group) {
            foreach ($group['metrics'] as &$metric) {
                $total = $metric['tp'] + $metric['fp'] + $metric['tn'] + $metric['fn'];
                $metric['count'] = $total;
                $metric['accuracy'] = ($metric['tp'] + $metric['tn']) / max(1, $total);
                $metric['precision'] = $metric['tp'] / max(1, $metric['tp'] + $metric['fp']);
                $metric['recall'] = $metric['tp'] / max(1, $metric['tp'] + $metric['fn']);
                $metric['f1'] = 2 * $metric['tp'] / max(1, 2 * $metric['tp'] + $metric['fp'] + $metric['fn']);
            }
            unset($metric);
        }
        unset($group);
        return $groups;
    }

    /** Export in bounded pages, with scores expanded into columns and snapshots retained. */
    public static function export(PDO $pdo, array $filter, $stream): void
    {
        $columns = ['run_id', 'result_id', 'source_kind', 'comparison_mode', 'algorithm_version', 'batch_id',
            'catalog_hash', 'catalog_count', 'result_limit', 'cached_from_run_id', 'reused_from_result_id', 'run_status', 'query_text', 'normalized_query', 'source_titleid', 'source_title', 'source_abstract',
            'target_titleid', 'target_title', 'target_abstract', 'rank_order', 'basis', 'title_weight', 'abstract_weight',
            'title_score', 'abstract_score', 'overall_score', 'system_bucket', 'system_label', 'high_similarity_threshold', 'moderate_similarity_threshold'];
        foreach (['jaccard', 'cosine', 'levenshtein', 'dice'] as $algorithm) {
            foreach (['weight', 'title_score', 'abstract_score', 'combined_score', 'contribution'] as $field) {
                $columns[] = $algorithm . '_' . $field;
            }
        }
        $columns = array_merge($columns, ['computed_at', 'run_created_at', 'run_completed_at', 'reviews_json']);
        fputcsv($stream, $columns);
        [$where, $parameters] = self::runWhere($filter);
        $cursor = 0;
        $high = self::one($pdo, 'SELECT COALESCE(MAX(result_id), 0) AS id FROM tblresearchmatch_result', []);
        do {
            $sql = 'SELECT x.*, r.source_kind, r.comparison_mode, r.algorithm_version, r.batch_id, r.catalog_hash,
                r.status AS run_status, r.query_text, r.created_at AS run_created_at, r.completed_at AS run_completed_at,
                r.normalized_query, r.cached_from_run_id, r.catalog_count, r.result_limit,
                s.title AS source_title, s.abstract_text AS source_abstract, s.titleid AS source_titleid,
                t.title AS target_title, t.abstract_text AS target_abstract, t.titleid AS target_titleid
                FROM tblresearchmatch_result x JOIN tblresearchmatch_run r ON r.run_id = x.run_id
                LEFT JOIN tblresearchmatch_document s ON s.document_id = r.source_document_id
                JOIN tblresearchmatch_document t ON t.document_id = x.target_document_id
                WHERE ' . $where . ' AND x.result_id > ? AND x.result_id <= ?';
            if ($filter['review'] !== '') {
                $sql .= ($filter['review'] === 'pending' ? ' AND NOT EXISTS' : ' AND EXISTS') .
                    ' (SELECT 1 FROM tblresearchmatch_review v WHERE v.result_id = x.result_id)';
            }
            $statement = $pdo->prepare($sql . ' ORDER BY x.result_id LIMIT 250');
            $statement->execute(array_merge($parameters, [$cursor, $high['id']]));
            $rows = $statement->fetchAll();
            $reviewMap = [];
            if ($rows !== []) {
                $ids = array_column($rows, 'result_id');
                $reviews = $pdo->prepare('SELECT * FROM tblresearchmatch_review WHERE result_id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY review_id');
                $reviews->execute($ids);
                foreach ($reviews->fetchAll() as $review) {
                    $reviewMap[$review['result_id']][] = $review;
                }
            }
            foreach ($rows as $row) {
                $calculation = ResearchMatchJson::decode($row['calculation_json']);
                $row = array_merge($row, $calculation);
                $assessment = $calculation['system_assessment'] ?? ResearchAiSearch::assessment((float) $calculation['overall_score']);
                $row['system_bucket'] = $assessment['bucket'];
                $row['system_label'] = $assessment['label'];
                $row['high_similarity_threshold'] = $assessment['high_threshold'];
                $row['moderate_similarity_threshold'] = $assessment['medium_threshold'];
                foreach ($calculation['algorithms'] as $algorithm) {
                    foreach (['weight', 'title_score', 'abstract_score', 'combined_score', 'contribution'] as $field) {
                        $row[$algorithm['key'] . '_' . $field] = $algorithm[$field];
                    }
                }
                $row['reviews_json'] = self::json($reviewMap[$row['result_id']] ?? []);
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_float($value)) {
                        $value = sprintf('%.17g', $value);
                    }
                    // Spreadsheet programs must treat submitted titles and notes as literal text.
                    if (is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value)) {
                        $value = "'" . $value;
                    }
                    $values[] = $value;
                }
                fputcsv($stream, $values);
                $cursor = (int) $row['result_id'];
            }
        } while (count($rows) === 250);
    }
}
