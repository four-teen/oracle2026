<?php

declare(strict_types=1);

final class TitleSimilarity
{
    public const DEFAULT_MATCH_LIMIT = 20;

    public static function algorithmDefinitions(): array
    {
        return [
            'hybrid' => [
                'label' => 'Hybrid',
                'threshold' => 0.56,
                'weight' => 0.0,
            ],
            'jaccard' => [
                'label' => 'Jaccard',
                'threshold' => 0.34,
                'weight' => 0.20,
            ],
            'cosine' => [
                'label' => 'Cosine',
                'threshold' => 0.50,
                'weight' => 0.30,
            ],
            'levenshtein' => [
                'label' => 'Levenshtein',
                'threshold' => 0.68,
                'weight' => 0.20,
            ],
            'dice' => [
                'label' => 'Sorensen-Dice',
                'threshold' => 0.44,
                'weight' => 0.30,
            ],
        ];
    }

    public static function reviewLabelOptions(): array
    {
        return [
            'highly_related' => 'Highly Related',
            'related' => 'Related',
            'not_related' => 'Not Related',
        ];
    }

    public static function normalizeTitle(?string $title): string
    {
        $normalized = trim((string) $title);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = is_string($normalized) ? $normalized : '';
        $normalized = self::lower($normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = is_string($normalized) ? $normalized : '';
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

        return is_string($normalized) ? $normalized : '';
    }

    public static function refreshCatalog(PDO $pdo, int $limit = self::DEFAULT_MATCH_LIMIT): void
    {
        $limit = max(1, $limit);
        $records = $pdo->query(
            "SELECT titleid, title, normalized_title
             FROM tblresearches
             WHERE NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL
             ORDER BY titleid ASC"
        )->fetchAll();

        $preparedRecords = [];
        $updateNormalizedStatement = $pdo->prepare(
            'UPDATE tblresearches
             SET normalized_title = :normalized_title
             WHERE titleid = :titleid'
        );

        foreach ($records as $record) {
            $titleId = isset($record['titleid']) ? (int) $record['titleid'] : 0;
            $title = trim((string) ($record['title'] ?? ''));

            if ($titleId < 1 || $title === '') {
                continue;
            }

            $normalizedTitle = self::normalizeTitle($title);
            $storedNormalizedTitle = trim((string) ($record['normalized_title'] ?? ''));

            if ($normalizedTitle !== $storedNormalizedTitle) {
                $updateNormalizedStatement->bindValue(':normalized_title', $normalizedTitle !== '' ? $normalizedTitle : null);
                $updateNormalizedStatement->bindValue(':titleid', $titleId, PDO::PARAM_INT);
                $updateNormalizedStatement->execute();
            }

            $tokens = self::tokensFromNormalizedTitle($normalizedTitle);

            $preparedRecords[$titleId] = [
                'title' => $title,
                'normalized_title' => $normalizedTitle,
                'comparison_title' => self::comparisonString($normalizedTitle),
                'tokens' => $tokens,
                'frequencies' => self::frequencies($tokens),
            ];
        }

        $candidateMatches = [];

        foreach (array_keys($preparedRecords) as $titleId) {
            $candidateMatches[$titleId] = [];
        }

        $recordIds = array_keys($preparedRecords);
        $recordCount = count($recordIds);

        for ($outer = 0; $outer < $recordCount; $outer++) {
            $leftId = (int) $recordIds[$outer];
            $leftRecord = $preparedRecords[$leftId];

            for ($inner = $outer + 1; $inner < $recordCount; $inner++) {
                $rightId = (int) $recordIds[$inner];
                $rightRecord = $preparedRecords[$rightId];
                $scores = self::scorePreparedRecords($leftRecord, $rightRecord);

                $candidateMatches[$leftId][] = array_merge($scores, [
                    'target_titleid' => $rightId,
                ]);
                $candidateMatches[$rightId][] = array_merge($scores, [
                    'target_titleid' => $leftId,
                ]);
            }
        }

        $pdo->exec('DELETE FROM tbltitlesimilarity');

        $insertStatement = $pdo->prepare(
            'INSERT INTO tbltitlesimilarity (
                source_titleid,
                target_titleid,
                jaccard_score,
                cosine_score,
                levenshtein_score,
                dice_score,
                hybrid_score,
                score_bucket,
                rank_order
             ) VALUES (
                :source_titleid,
                :target_titleid,
                :jaccard_score,
                :cosine_score,
                :levenshtein_score,
                :dice_score,
                :hybrid_score,
                :score_bucket,
                :rank_order
             )'
        );

        foreach ($candidateMatches as $sourceTitleId => $matches) {
            usort($matches, static function (array $leftMatch, array $rightMatch): int {
                foreach (['hybrid_score', 'dice_score', 'cosine_score', 'jaccard_score', 'levenshtein_score'] as $field) {
                    $comparison = ((float) ($rightMatch[$field] ?? 0.0)) <=> ((float) ($leftMatch[$field] ?? 0.0));

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return ((int) ($leftMatch['target_titleid'] ?? 0)) <=> ((int) ($rightMatch['target_titleid'] ?? 0));
            });

            $matches = array_slice($matches, 0, $limit);

            foreach ($matches as $index => $match) {
                $insertStatement->bindValue(':source_titleid', $sourceTitleId, PDO::PARAM_INT);
                $insertStatement->bindValue(':target_titleid', (int) $match['target_titleid'], PDO::PARAM_INT);
                $insertStatement->bindValue(':jaccard_score', self::scoreStorageValue((float) $match['jaccard_score']));
                $insertStatement->bindValue(':cosine_score', self::scoreStorageValue((float) $match['cosine_score']));
                $insertStatement->bindValue(':levenshtein_score', self::scoreStorageValue((float) $match['levenshtein_score']));
                $insertStatement->bindValue(':dice_score', self::scoreStorageValue((float) $match['dice_score']));
                $insertStatement->bindValue(':hybrid_score', self::scoreStorageValue((float) $match['hybrid_score']));
                $insertStatement->bindValue(':score_bucket', (string) $match['score_bucket']);
                $insertStatement->bindValue(':rank_order', $index + 1, PDO::PARAM_INT);
                $insertStatement->execute();
            }
        }

        $pdo->exec(
            "UPDATE tblresearches
             SET similarity_refreshed_at = CASE
                 WHEN NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL THEN CURRENT_TIMESTAMP
                 ELSE NULL
             END"
        );
    }

    public static function saveReview(
        PDO $pdo,
        int $sourceTitleId,
        int $targetTitleId,
        int $reviewerAccountId,
        string $expertLabel,
        string $reviewNote = ''
    ): void {
        if ($reviewerAccountId < 1) {
            throw new RuntimeException('A valid reviewer account is required.');
        }

        if (!isset(self::reviewLabelOptions()[$expertLabel])) {
            throw new RuntimeException('Select a valid expert review label.');
        }

        [$leftId, $rightId] = self::canonicalPair($sourceTitleId, $targetTitleId);

        if ($leftId < 1 || $rightId < 1 || $leftId === $rightId) {
            throw new RuntimeException('Select two different title records for review.');
        }

        $reviewNote = trim($reviewNote);
        $statement = $pdo->prepare(
            'INSERT INTO tbltitlesimilarity_review (
                source_titleid,
                target_titleid,
                reviewer_accountid,
                expert_label,
                review_note
             ) VALUES (
                :source_titleid,
                :target_titleid,
                :reviewer_accountid,
                :expert_label,
                :review_note
             )
             ON DUPLICATE KEY UPDATE
                expert_label = VALUES(expert_label),
                review_note = VALUES(review_note),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->bindValue(':source_titleid', $leftId, PDO::PARAM_INT);
        $statement->bindValue(':target_titleid', $rightId, PDO::PARAM_INT);
        $statement->bindValue(':reviewer_accountid', $reviewerAccountId, PDO::PARAM_INT);
        $statement->bindValue(':expert_label', $expertLabel);
        $statement->bindValue(':review_note', $reviewNote !== '' ? $reviewNote : null);
        $statement->execute();
    }

    public static function fetchStoredPairs(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT s.source_titleid,
                    s.target_titleid,
                    s.jaccard_score,
                    s.cosine_score,
                    s.levenshtein_score,
                    s.dice_score,
                    s.hybrid_score,
                    s.score_bucket,
                    s.rank_order,
                    source.title AS source_title,
                    target.title AS target_title
             FROM tbltitlesimilarity s
             INNER JOIN tblresearches source ON source.titleid = s.source_titleid
             INNER JOIN tblresearches target ON target.titleid = s.target_titleid
             ORDER BY s.hybrid_score DESC, s.rank_order ASC, s.source_titleid ASC, s.target_titleid ASC"
        )->fetchAll();

        $pairs = [];

        foreach ($rows as $row) {
            $sourceTitleId = isset($row['source_titleid']) ? (int) $row['source_titleid'] : 0;
            $targetTitleId = isset($row['target_titleid']) ? (int) $row['target_titleid'] : 0;

            if ($sourceTitleId < 1 || $targetTitleId < 1 || $sourceTitleId === $targetTitleId) {
                continue;
            }

            [$leftId, $rightId] = self::canonicalPair($sourceTitleId, $targetTitleId);
            $pairKey = self::pairKey($leftId, $rightId);

            if (!isset($pairs[$pairKey])) {
                $pairs[$pairKey] = [
                    'source_titleid' => $leftId,
                    'target_titleid' => $rightId,
                    'source_title' => $sourceTitleId === $leftId ? (string) ($row['source_title'] ?? '') : (string) ($row['target_title'] ?? ''),
                    'target_title' => $sourceTitleId === $leftId ? (string) ($row['target_title'] ?? '') : (string) ($row['source_title'] ?? ''),
                    'jaccard_score' => (float) ($row['jaccard_score'] ?? 0.0),
                    'cosine_score' => (float) ($row['cosine_score'] ?? 0.0),
                    'levenshtein_score' => (float) ($row['levenshtein_score'] ?? 0.0),
                    'dice_score' => (float) ($row['dice_score'] ?? 0.0),
                    'hybrid_score' => (float) ($row['hybrid_score'] ?? 0.0),
                    'score_bucket' => (string) ($row['score_bucket'] ?? self::bucketForScore((float) ($row['hybrid_score'] ?? 0.0))),
                    'best_rank' => isset($row['rank_order']) ? (int) $row['rank_order'] : 0,
                    'appears_count' => 1,
                ];
                continue;
            }

            $pairs[$pairKey]['jaccard_score'] = max($pairs[$pairKey]['jaccard_score'], (float) ($row['jaccard_score'] ?? 0.0));
            $pairs[$pairKey]['cosine_score'] = max($pairs[$pairKey]['cosine_score'], (float) ($row['cosine_score'] ?? 0.0));
            $pairs[$pairKey]['levenshtein_score'] = max($pairs[$pairKey]['levenshtein_score'], (float) ($row['levenshtein_score'] ?? 0.0));
            $pairs[$pairKey]['dice_score'] = max($pairs[$pairKey]['dice_score'], (float) ($row['dice_score'] ?? 0.0));
            $pairs[$pairKey]['hybrid_score'] = max($pairs[$pairKey]['hybrid_score'], (float) ($row['hybrid_score'] ?? 0.0));
            $pairs[$pairKey]['best_rank'] = $pairs[$pairKey]['best_rank'] > 0
                ? min($pairs[$pairKey]['best_rank'], (int) ($row['rank_order'] ?? 0))
                : (int) ($row['rank_order'] ?? 0);
            $pairs[$pairKey]['score_bucket'] = self::bucketForScore($pairs[$pairKey]['hybrid_score']);
            $pairs[$pairKey]['appears_count']++;
        }

        usort($pairs, static function (array $leftPair, array $rightPair): int {
            $comparison = ((float) ($rightPair['hybrid_score'] ?? 0.0)) <=> ((float) ($leftPair['hybrid_score'] ?? 0.0));

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ((int) ($leftPair['best_rank'] ?? 0)) <=> ((int) ($rightPair['best_rank'] ?? 0));

            if ($comparison !== 0) {
                return $comparison;
            }

            return strcasecmp((string) ($leftPair['source_title'] ?? ''), (string) ($rightPair['source_title'] ?? ''));
        });

        return array_values($pairs);
    }

    public static function reviewSummaries(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT source_titleid,
                    target_titleid,
                    expert_label,
                    COUNT(*) AS label_count,
                    MAX(updated_at) AS latest_reviewed_at
             FROM tbltitlesimilarity_review
             GROUP BY source_titleid, target_titleid, expert_label"
        )->fetchAll();

        $summaries = [];

        foreach ($rows as $row) {
            $leftId = isset($row['source_titleid']) ? (int) $row['source_titleid'] : 0;
            $rightId = isset($row['target_titleid']) ? (int) $row['target_titleid'] : 0;
            $label = trim((string) ($row['expert_label'] ?? ''));
            $count = isset($row['label_count']) ? (int) $row['label_count'] : 0;
            $pairKey = self::pairKey($leftId, $rightId);

            if ($leftId < 1 || $rightId < 1 || $leftId === $rightId || $label === '' || $count < 1) {
                continue;
            }

            if (!isset($summaries[$pairKey])) {
                $summaries[$pairKey] = [
                    'source_titleid' => $leftId,
                    'target_titleid' => $rightId,
                    'counts' => array_fill_keys(array_keys(self::reviewLabelOptions()), 0),
                    'total_reviews' => 0,
                    'latest_reviewed_at' => '',
                    'consensus_label' => '',
                    'consensus_binary' => '',
                ];
            }

            if (!isset($summaries[$pairKey]['counts'][$label])) {
                $summaries[$pairKey]['counts'][$label] = 0;
            }

            $summaries[$pairKey]['counts'][$label] += $count;
            $summaries[$pairKey]['total_reviews'] += $count;

            $latestReviewedAt = trim((string) ($row['latest_reviewed_at'] ?? ''));

            if ($latestReviewedAt !== '' && strcmp($latestReviewedAt, (string) $summaries[$pairKey]['latest_reviewed_at']) > 0) {
                $summaries[$pairKey]['latest_reviewed_at'] = $latestReviewedAt;
            }
        }

        foreach ($summaries as $pairKey => $summary) {
            $positiveCount = (int) (($summary['counts']['highly_related'] ?? 0) + ($summary['counts']['related'] ?? 0));
            $negativeCount = (int) ($summary['counts']['not_related'] ?? 0);
            $consensusLabel = '';
            $consensusBinary = '';

            if ($positiveCount > $negativeCount) {
                $consensusBinary = 'positive';
                $consensusLabel = ($summary['counts']['highly_related'] ?? 0) >= ($summary['counts']['related'] ?? 0)
                    ? 'highly_related'
                    : 'related';
            } elseif ($negativeCount > $positiveCount) {
                $consensusBinary = 'negative';
                $consensusLabel = 'not_related';
            } elseif ($positiveCount > 0 || $negativeCount > 0) {
                $consensusLabel = 'mixed';
                $consensusBinary = 'mixed';
            }

            $summaries[$pairKey]['consensus_label'] = $consensusLabel;
            $summaries[$pairKey]['consensus_binary'] = $consensusBinary;
        }

        return $summaries;
    }

    public static function evaluationMetrics(PDO $pdo): array
    {
        $definitions = self::algorithmDefinitions();
        $pairScores = [];
        $scoreRows = $pdo->query(
            "SELECT LEAST(source_titleid, target_titleid) AS left_titleid,
                    GREATEST(source_titleid, target_titleid) AS right_titleid,
                    MAX(jaccard_score) AS jaccard_score,
                    MAX(cosine_score) AS cosine_score,
                    MAX(levenshtein_score) AS levenshtein_score,
                    MAX(dice_score) AS dice_score,
                    MAX(hybrid_score) AS hybrid_score
             FROM tbltitlesimilarity
             GROUP BY LEAST(source_titleid, target_titleid), GREATEST(source_titleid, target_titleid)"
        )->fetchAll();

        foreach ($scoreRows as $scoreRow) {
            $leftId = isset($scoreRow['left_titleid']) ? (int) $scoreRow['left_titleid'] : 0;
            $rightId = isset($scoreRow['right_titleid']) ? (int) $scoreRow['right_titleid'] : 0;
            $pairScores[self::pairKey($leftId, $rightId)] = [
                'jaccard' => (float) ($scoreRow['jaccard_score'] ?? 0.0),
                'cosine' => (float) ($scoreRow['cosine_score'] ?? 0.0),
                'levenshtein' => (float) ($scoreRow['levenshtein_score'] ?? 0.0),
                'dice' => (float) ($scoreRow['dice_score'] ?? 0.0),
                'hybrid' => (float) ($scoreRow['hybrid_score'] ?? 0.0),
            ];
        }

        $metrics = [];

        foreach ($definitions as $algorithmKey => $definition) {
            $metrics[$algorithmKey] = [
                'label' => (string) ($definition['label'] ?? ucfirst($algorithmKey)),
                'threshold' => (float) ($definition['threshold'] ?? 0.0),
                'tp' => 0,
                'fp' => 0,
                'tn' => 0,
                'fn' => 0,
                'reviewed_pairs' => 0,
                'accuracy' => 0.0,
                'precision' => 0.0,
                'recall' => 0.0,
                'f1' => 0.0,
            ];
        }

        foreach (self::reviewSummaries($pdo) as $pairKey => $summary) {
            if (($summary['consensus_binary'] ?? '') === 'mixed') {
                continue;
            }

            if (!isset($pairScores[$pairKey])) {
                continue;
            }

            $isPositive = ($summary['consensus_binary'] ?? '') === 'positive';

            foreach ($definitions as $algorithmKey => $definition) {
                $predictedPositive = ((float) ($pairScores[$pairKey][$algorithmKey] ?? 0.0)) >= (float) ($definition['threshold'] ?? 0.0);
                $metrics[$algorithmKey]['reviewed_pairs']++;

                if ($predictedPositive && $isPositive) {
                    $metrics[$algorithmKey]['tp']++;
                    continue;
                }

                if ($predictedPositive) {
                    $metrics[$algorithmKey]['fp']++;
                    continue;
                }

                if ($isPositive) {
                    $metrics[$algorithmKey]['fn']++;
                    continue;
                }

                $metrics[$algorithmKey]['tn']++;
            }
        }

        foreach ($metrics as $algorithmKey => $metric) {
            $total = (int) $metric['reviewed_pairs'];
            $tp = (int) $metric['tp'];
            $fp = (int) $metric['fp'];
            $tn = (int) $metric['tn'];
            $fn = (int) $metric['fn'];
            $precisionDenominator = $tp + $fp;
            $recallDenominator = $tp + $fn;
            $accuracy = $total > 0 ? ($tp + $tn) / $total : 0.0;
            $precision = $precisionDenominator > 0 ? $tp / $precisionDenominator : 0.0;
            $recall = $recallDenominator > 0 ? $tp / $recallDenominator : 0.0;
            $f1 = ($precision + $recall) > 0.0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;

            $metrics[$algorithmKey]['accuracy'] = $accuracy;
            $metrics[$algorithmKey]['precision'] = $precision;
            $metrics[$algorithmKey]['recall'] = $recall;
            $metrics[$algorithmKey]['f1'] = $f1;
        }

        uasort($metrics, static function (array $leftMetric, array $rightMetric): int {
            $comparison = ((float) ($rightMetric['f1'] ?? 0.0)) <=> ((float) ($leftMetric['f1'] ?? 0.0));

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ((float) ($rightMetric['accuracy'] ?? 0.0)) <=> ((float) ($leftMetric['accuracy'] ?? 0.0));

            if ($comparison !== 0) {
                return $comparison;
            }

            return strcasecmp((string) ($leftMetric['label'] ?? ''), (string) ($rightMetric['label'] ?? ''));
        });

        return $metrics;
    }

    public static function scoreLabel(float $score): string
    {
        return number_format(max(0.0, min(1.0, $score)) * 100, 1) . '%';
    }

    public static function bucketLabel(string $bucket): string
    {
        $labels = [
            'high' => 'High confidence',
            'medium' => 'Moderate confidence',
            'low' => 'Emerging match',
        ];

        return $labels[$bucket] ?? 'Scored match';
    }

    public static function canonicalPair(int $leftId, int $rightId): array
    {
        if ($leftId <= $rightId) {
            return [$leftId, $rightId];
        }

        return [$rightId, $leftId];
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private static function comparisonString(string $normalizedTitle): string
    {
        $comparison = $normalizedTitle;

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizedTitle);

            if (is_string($converted) && trim($converted) !== '') {
                $comparison = self::lower(trim($converted));
            }
        }

        return $comparison;
    }

    private static function tokensFromNormalizedTitle(string $normalizedTitle): array
    {
        static $stopWords = [
            'a' => true,
            'an' => true,
            'and' => true,
            'at' => true,
            'based' => true,
            'by' => true,
            'for' => true,
            'from' => true,
            'in' => true,
            'of' => true,
            'on' => true,
            'or' => true,
            'the' => true,
            'to' => true,
            'using' => true,
            'with' => true,
        ];

        if ($normalizedTitle === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $normalizedTitle) ?: [];
        $filteredTokens = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);

            if ($token === '' || strlen($token) <= 1 || isset($stopWords[$token])) {
                continue;
            }

            $filteredTokens[] = $token;
        }

        return $filteredTokens;
    }

    private static function frequencies(array $tokens): array
    {
        $frequencies = [];

        foreach ($tokens as $token) {
            if (!isset($frequencies[$token])) {
                $frequencies[$token] = 0;
            }

            $frequencies[$token]++;
        }

        return $frequencies;
    }

    private static function scorePreparedRecords(array $leftRecord, array $rightRecord): array
    {
        $scores = [
            'jaccard_score' => self::jaccard($leftRecord['tokens'], $rightRecord['tokens']),
            'cosine_score' => self::cosine($leftRecord['frequencies'], $rightRecord['frequencies']),
            'levenshtein_score' => self::levenshteinSimilarity(
                (string) ($leftRecord['comparison_title'] ?? ''),
                (string) ($rightRecord['comparison_title'] ?? '')
            ),
            'dice_score' => self::dice($leftRecord['tokens'], $rightRecord['tokens']),
        ];

        $scores['hybrid_score'] = self::hybrid($scores);
        $scores['score_bucket'] = self::bucketForScore((float) $scores['hybrid_score']);

        return $scores;
    }

    private static function jaccard(array $leftTokens, array $rightTokens): float
    {
        $leftSet = array_values(array_unique($leftTokens));
        $rightSet = array_values(array_unique($rightTokens));
        $union = array_unique(array_merge($leftSet, $rightSet));
        $unionCount = count($union);

        if ($unionCount === 0) {
            return 0.0;
        }

        return count(array_intersect($leftSet, $rightSet)) / $unionCount;
    }

    private static function cosine(array $leftFrequencies, array $rightFrequencies): float
    {
        if ($leftFrequencies === [] || $rightFrequencies === []) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeLeft = 0.0;
        $magnitudeRight = 0.0;

        foreach ($leftFrequencies as $token => $count) {
            $magnitudeLeft += $count * $count;

            if (isset($rightFrequencies[$token])) {
                $dotProduct += $count * $rightFrequencies[$token];
            }
        }

        foreach ($rightFrequencies as $count) {
            $magnitudeRight += $count * $count;
        }

        $denominator = sqrt($magnitudeLeft) * sqrt($magnitudeRight);

        if ($denominator <= 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    private static function dice(array $leftTokens, array $rightTokens): float
    {
        $leftSet = array_values(array_unique($leftTokens));
        $rightSet = array_values(array_unique($rightTokens));
        $setTotal = count($leftSet) + count($rightSet);

        if ($setTotal === 0) {
            return 0.0;
        }

        return (2 * count(array_intersect($leftSet, $rightSet))) / $setTotal;
    }

    private static function levenshteinSimilarity(string $leftTitle, string $rightTitle): float
    {
        if ($leftTitle === '' || $rightTitle === '') {
            return 0.0;
        }

        $maxLength = max(strlen($leftTitle), strlen($rightTitle));

        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = levenshtein($leftTitle, $rightTitle);
        $score = 1 - (min($distance, $maxLength) / $maxLength);

        return max(0.0, min(1.0, $score));
    }

    private static function hybrid(array $scores): float
    {
        $definitions = self::algorithmDefinitions();
        $hybridScore = 0.0;

        foreach ($definitions as $algorithmKey => $definition) {
            $weight = (float) ($definition['weight'] ?? 0.0);

            if ($weight <= 0.0) {
                continue;
            }

            $scoreField = $algorithmKey . '_score';
            $hybridScore += ((float) ($scores[$scoreField] ?? 0.0)) * $weight;
        }

        return max(0.0, min(1.0, $hybridScore));
    }

    private static function scoreStorageValue(float $score): string
    {
        return number_format(max(0.0, min(1.0, $score)), 5, '.', '');
    }

    private static function bucketForScore(float $score): string
    {
        if ($score >= 0.72) {
            return 'high';
        }

        if ($score >= 0.48) {
            return 'medium';
        }

        return 'low';
    }

    private static function pairKey(int $leftId, int $rightId): string
    {
        return $leftId . ':' . $rightId;
    }
}
