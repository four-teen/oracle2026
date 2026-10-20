<?php

declare(strict_types=1);

require_once __DIR__ . '/ResearchEditDistance.php';

final class ResearchAiSearch
{
    public const DEFAULT_LIMIT = 5;
    public const MAX_QUERY_LENGTH = 1000;
    public const ALGORITHM_VERSION = 'research-match-1.1';
    private const TITLE_WEIGHT = 0.70;
    private const ABSTRACT_WEIGHT = 0.30;
    private const ALGORITHMS = [
        'jaccard' => ['label' => 'Jaccard', 'weight' => 0.20],
        'cosine' => ['label' => 'Cosine', 'weight' => 0.30],
        'levenshtein' => ['label' => 'Levenshtein', 'weight' => 0.20],
        'dice' => ['label' => 'Sørensen–Dice', 'weight' => 0.30],
    ];

    public static function search(PDO $pdo, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = self::validateQuery($query);
        $limit = max(1, min(8, $limit));

        $rows = self::fetchCatalogRows($pdo);

        return self::searchRows($query, $rows, $limit);
    }

    public static function searchRows(string $query, array $rows, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = self::validateQuery($query);
        $limit = max(1, min(8, $limit));

        if ($rows === []) {
            return [
                'provider' => 'local',
                'used_gemini' => false,
                'summary' => 'No research records are available in the catalog yet.',
                'notice' => '',
                'matches' => [],
            ];
        }

        // The displayed percentage and ordering come exclusively from the four algorithms.
        $matches = self::rankLocally($query, $rows, $limit);

        return [
            'provider' => 'local',
            'used_gemini' => false,
            'summary' => self::defaultSummary($query, $matches),
            'notice' => '',
            'matches' => array_values($matches),
        ];
    }

    public static function fetchCatalogRows(PDO $pdo, bool $includePrivate = false): array
    {
        return $pdo->query(
            <<<'SQL'
SELECT m.titleid,
       m.title,
       m.authors,
       m.other_details,
       m.sdgs,
       m.status,
       COALESCE(
           NULLIF(TRIM(m.adviser_name), ''),
           NULLIF(TRIM(a.acc_name), ''),
           NULLIF(TRIM(a.email), '')
       ) AS adviser_display,
       p.coursecode,
       p.coursedescription,
       p.coursemajor,
       rt.research_type,
       m.submitted_at,
       m.updated_at
FROM tblresearches m
LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
LEFT JOIN tblcourse p ON p.courseid = m.programid
LEFT JOIN tblresearchtype rt ON rt.researchtypeid = m.typeid
WHERE NULLIF(TRIM(COALESCE(m.title, '')), '') IS NOT NULL
SQL
            . ($includePrivate ? '' : ' AND (COALESCE(m.owner_accountid, 0) = 0 OR m.is_published = 1)') . <<<'SQL'

ORDER BY COALESCE(m.submitted_at, m.updated_at) DESC, m.titleid DESC
SQL
        )->fetchAll();
    }

    private static function rankLocally(string $query, array $rows, int $limit): array
    {
        $queryPrepared = self::prepareText($query);
        $rankedMatches = [];

        foreach ($rows as $row) {
            $titleId = isset($row['titleid']) ? (int) $row['titleid'] : 0;
            $title = self::normalizeText($row['title'] ?? '');

            if ($titleId < 1 || $title === '') {
                continue;
            }

            $programLabel = self::programLabel($row);
            $authorsLabel = self::authorsLabel($row['authors'] ?? '');
            $adviserLabel = self::adviserLabel($row);
            $researchType = self::normalizeText($row['research_type'] ?? '');
            $abstract = self::plainText((string) ($row['other_details'] ?? ''));
            $summary = $abstract !== '' ? self::trimText($abstract, 300) : 'Abstract unavailable for this record.';
            $year = self::yearValue($row);
            $dateLabel = self::dateLabel($row);
            $titlePrepared = self::prepareText($title);
            $abstractPrepared = self::prepareText($abstract);
            $breakdown = self::comparePrepared($queryPrepared, $titlePrepared, $abstractPrepared);
            $score = $breakdown['overall_score'];
            $overlapTerms = self::keywordOverlap(
                $queryPrepared['tokens'],
                array_merge($titlePrepared['tokens'], $abstractPrepared['tokens'])
            );

            $match = [
                'titleid' => $titleId,
                'title' => $title,
                'authors' => $authorsLabel,
                'adviser' => $adviserLabel,
                'program' => $programLabel,
                'research_type' => $researchType !== '' ? $researchType : 'Research record',
                'year' => $year,
                'date_label' => $dateLabel,
                'summary' => self::trimText($summary, 300),
                'score' => $score,
                'score_label' => self::scoreLabel($score),
                'score_breakdown' => $breakdown,
                'match_reason' => self::localReason($overlapTerms, $breakdown),
                'keywords' => array_slice($overlapTerms, 0, 4),
                'rank_source' => 'local',
            ];

            if ($score >= 0.08 || $overlapTerms !== []) {
                $rankedMatches[] = $match;
            }
        }

        $sorter = static function (array $left, array $right): int {
            $comparison = ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0));

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ((int) ($right['year'] ?? 0)) <=> ((int) ($left['year'] ?? 0));

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            return $comparison !== 0 ? $comparison : $left['titleid'] <=> $right['titleid'];
        };

        usort($rankedMatches, $sorter);

        return array_slice($rankedMatches, 0, $limit);
    }

    /** Compare one record using the same calculation as the public search. Scores are in [0, 1]. */
    public static function scoreResearch(string $query, string $title, ?string $abstract = null): array
    {
        return self::comparePrepared(
            self::prepareText(self::validateQuery($query)),
            self::prepareText($title),
            self::prepareText(self::plainText($abstract ?? ''))
        );
    }

    private static function comparePrepared(array $query, array $title, array $abstract): array
    {
        $titleScores = self::scorePrepared($query, $title);
        $hasAbstract = $abstract['normalized'] !== '';
        $abstractScores = $hasAbstract ? self::scorePrepared($query, $abstract) : null;
        return self::combineScores($titleScores, $abstractScores);
    }

    /** Prepare once, then reuse for every catalog comparison. No title/query length limit applies here. */
    public static function prepareRecord(string $title, ?string $abstract): array
    {
        return ['title' => self::prepareText($title), 'abstract' => self::prepareText(self::plainText($abstract ?? ''))];
    }

    /** Catalog mode compares corresponding fields. An absent abstract on either side uses title only. */
    public static function compareRecords(array $source, array $target): array
    {
        $hasAbstracts = $source['abstract']['normalized'] !== '' && $target['abstract']['normalized'] !== '';
        return self::combineScores(
            self::scorePrepared($source['title'], $target['title']),
            $hasAbstracts ? self::scorePrepared($source['abstract'], $target['abstract']) : null
        );
    }

    private static function combineScores(array $titleScores, ?array $abstractScores): array
    {
        $hasAbstract = $abstractScores !== null;
        $titleWeight = $hasAbstract ? self::TITLE_WEIGHT : 1.0;
        $abstractWeight = $hasAbstract ? self::ABSTRACT_WEIGHT : 0.0;
        $algorithms = [];
        $overallScore = 0.0;

        foreach (self::ALGORITHMS as $key => $algorithm) {
            $combined = ($titleScores[$key] * $titleWeight)
                + (($abstractScores[$key] ?? 0.0) * $abstractWeight);
            $contribution = $combined * $algorithm['weight'];
            $overallScore += $contribution;
            $algorithms[] = [
                'key' => $key,
                'label' => $algorithm['label'],
                'weight' => $algorithm['weight'],
                'title_score' => $titleScores[$key],
                'abstract_score' => $abstractScores[$key] ?? null,
                'combined_score' => $combined,
                'contribution' => $contribution,
            ];
        }

        return [
            'basis' => $hasAbstract ? 'title_abstract' : 'title_only',
            'title_weight' => $titleWeight,
            'abstract_weight' => $abstractWeight,
            'title_score' => $titleScores['hybrid'],
            'abstract_score' => $abstractScores['hybrid'] ?? null,
            'overall_score' => max(0.0, min(1.0, $overallScore)),
            'algorithms' => $algorithms,
            'system_assessment' => self::assessment($overallScore),
        ];
    }

    public static function assessment(float $score): array
    {
        $bucket = $score >= 0.72 ? 'high' : ($score >= 0.48 ? 'medium' : 'low');
        $labels = ['high' => 'High similarity', 'medium' => 'Moderate similarity', 'low' => 'Low similarity'];
        return ['bucket' => $bucket, 'label' => $labels[$bucket], 'high_threshold' => 0.72, 'medium_threshold' => 0.48, 'source' => 'system_generated'];
    }

    public static function validateQuery(string $query): string
    {
        $query = self::normalizeText($query);
        $length = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);

        if ($length > self::MAX_QUERY_LENGTH) {
            throw new InvalidArgumentException('Keep the search title within ' . self::MAX_QUERY_LENGTH . ' characters.');
        }

        if (self::prepareText($query)['tokens'] === []) {
            throw new InvalidArgumentException('Enter a research title or some meaningful topic keywords.');
        }

        return $query;
    }

    public static function plainText(string $value): string
    {
        $value = (string) preg_replace('/<[^>]*>/', ' ', $value);
        return self::normalizeText(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function prepareText(string $value): array
    {
        $normalized = self::normalizeTitle($value);
        $comparison = self::comparisonString($normalized);
        $tokens = self::tokensFromNormalized($normalized);

        return [
            'normalized' => $normalized,
            'comparison' => $comparison,
            'tokens' => $tokens,
            'frequencies' => self::frequencies($tokens),
        ];
    }

    private static function scorePrepared(array $left, array $right): array
    {
        $scores = [
            'jaccard' => self::jaccard($left['tokens'] ?? [], $right['tokens'] ?? []),
            'cosine' => self::cosine($left['frequencies'] ?? [], $right['frequencies'] ?? []),
            'levenshtein' => self::levenshteinSimilarity(
                (string) ($left['comparison'] ?? ''),
                (string) ($right['comparison'] ?? '')
            ),
            'dice' => self::dice($left['tokens'] ?? [], $right['tokens'] ?? []),
        ];

        $scores['hybrid'] = self::hybrid($scores);

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
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        foreach ($leftFrequencies as $token => $count) {
            $leftMagnitude += $count * $count;

            if (isset($rightFrequencies[$token])) {
                $dotProduct += $count * $rightFrequencies[$token];
            }
        }

        foreach ($rightFrequencies as $count) {
            $rightMagnitude += $count * $count;
        }

        $denominator = sqrt($leftMagnitude) * sqrt($rightMagnitude);

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

    private static function levenshteinSimilarity(string $leftText, string $rightText): float
    {
        if ($leftText === '' || $rightText === '') {
            return 0.0;
        }

        // The compatibility implementation also compares full strings on PHP 7.1.
        $maxLength = max(strlen($leftText), strlen($rightText));

        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = ResearchEditDistance::distance($leftText, $rightText);
        $score = 1 - (min($distance, $maxLength) / $maxLength);

        return max(0.0, min(1.0, $score));
    }

    private static function hybrid(array $scores): float
    {
        $hybridScore = 0.0;

        foreach (self::ALGORITHMS as $key => $algorithm) {
            $hybridScore += ((float) ($scores[$key] ?? 0.0)) * $algorithm['weight'];
        }

        return max(0.0, min(1.0, $hybridScore));
    }

    private static function keywordOverlap(array $leftTokens, array $rightTokens, int $limit = 4): array
    {
        $overlap = array_values(array_unique(array_intersect($leftTokens, $rightTokens)));

        return array_slice($overlap, 0, $limit);
    }

    public static function normalizeTitle(string $value): string
    {
        $normalized = self::normalizeText($value);
        $normalized = self::lower($normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = is_string($normalized) ? $normalized : '';
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

        return is_string($normalized) ? $normalized : '';
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

    private static function tokensFromNormalized(string $normalizedTitle): array
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

    private static function localReason(array $overlapTerms, array $breakdown): string
    {
        $basis = $breakdown['basis'] === 'title_only'
            ? 'Title only; abstract unavailable.'
            : 'Compared with the title and full abstract.';

        return $basis . ($overlapTerms !== []
            ? ' Shared terms: ' . implode(', ', $overlapTerms) . '.'
            : 'Similarity is based on wording and character overlap.');
    }

    private static function defaultSummary(string $query, array $matches): string
    {
        if ($matches === []) {
            return 'No close research match was found for "' . $query . '" in the current catalog.';
        }

        return 'Results are ranked by the weighted similarity of four algorithms using titles and available abstracts.';
    }

    private static function programLabel(array $row): string
    {
        $courseCode = self::normalizeText($row['coursecode'] ?? '');
        $courseDescription = self::normalizeText($row['coursedescription'] ?? '');
        $courseMajor = self::normalizeText($row['coursemajor'] ?? '');
        $parts = [];

        if ($courseCode !== '') {
            $parts[] = $courseCode;
        }

        if ($courseDescription !== '') {
            $parts[] = $courseDescription;
        }

        $label = $parts !== [] ? implode(' - ', $parts) : '';

        if ($courseMajor !== '') {
            $label = $label !== '' ? $label . ' (' . $courseMajor . ')' : $courseMajor;
        }

        return $label !== '' ? $label : 'Program not set';
    }

    private static function authorsLabel(?string $authors): string
    {
        $normalized = self::normalizeText($authors);

        if ($normalized === '') {
            return 'Author information unavailable';
        }

        $parts = preg_split('/\s*;\s*/', $normalized) ?: [];
        $parts = array_values(array_filter(array_map([self::class, 'normalizeText'], $parts), static function ($part): bool {
            return $part !== '';
        }));

        return $parts !== [] ? implode(', ', $parts) : $normalized;
    }

    private static function adviserLabel(array $row): string
    {
        $adviser = self::normalizeText($row['adviser_display'] ?? '');

        return $adviser !== '' ? $adviser : 'Adviser not assigned';
    }

    private static function yearValue(array $row): int
    {
        foreach (['submitted_at', 'updated_at'] as $field) {
            $value = self::normalizeText($row[$field] ?? '');

            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return (int) date('Y', $timestamp);
            }
        }

        return (int) date('Y');
    }

    private static function dateLabel(array $row): string
    {
        foreach ([
            'submitted_at' => 'Submitted ',
            'updated_at' => 'Updated ',
        ] as $field => $prefix) {
            $value = self::normalizeText($row[$field] ?? '');

            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return $prefix . date('M j, Y', $timestamp);
            }
        }

        return 'Date unavailable';
    }

    private static function scoreLabel(float $score): string
    {
        return number_format(max(0.0, min(1.0, $score)) * 100, 1) . '%';
    }

    private static function normalizeText(?string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return is_string($normalized) ? $normalized : '';
    }

    private static function trimText(string $value, int $limit = 220): string
    {
        $value = self::normalizeText($value);

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length <= $limit) {
            return $value;
        }

        $excerpt = function_exists('mb_substr') ? mb_substr($value, 0, $limit - 3, 'UTF-8') : substr($value, 0, $limit - 3);
        return rtrim($excerpt) . '...';
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
