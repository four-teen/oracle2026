<?php

declare(strict_types=1);

final class ResearchAiSearch
{
    public const DEFAULT_LIMIT = 5;
    private const DEFAULT_GEMINI_MODEL = 'gemini-1.5-flash';
    private const GEMINI_ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

    public static function search(PDO $pdo, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = self::normalizeText($query);
        $limit = max(1, min(8, $limit));

        if ($query === '') {
            throw new RuntimeException('Enter a research topic, title idea, or problem statement first.');
        }

        $rows = self::fetchCatalogRows($pdo);

        if ($rows === []) {
            return [
                'provider' => 'local',
                'used_gemini' => false,
                'summary' => 'No research records are available in the catalog yet.',
                'notice' => '',
                'matches' => [],
            ];
        }

        $localMatches = self::rankLocally($query, $rows, max($limit * 4, 12));
        $matches = array_slice($localMatches, 0, $limit);
        $provider = 'local';
        $usedGemini = false;
        $notice = '';
        $summary = self::defaultSummary($query, $matches, false);

        $apiKey = self::geminiApiKey();

        if ($apiKey !== '' && $localMatches !== []) {
            try {
                $geminiResult = self::rerankWithGemini($query, $localMatches, $limit, $apiKey);
                $matches = self::mergeGeminiMatches($localMatches, $geminiResult['matches'] ?? [], $limit);
                $summary = trim((string) ($geminiResult['summary'] ?? '')) ?: self::defaultSummary($query, $matches, true);
                $provider = 'gemini';
                $usedGemini = true;
            } catch (Throwable $exception) {
                $notice = 'Gemini is unavailable right now, so the page is showing the local similarity ranking instead.';
            }
        }

        if ($matches === []) {
            $summary = 'No close research match was found for that prompt in the current catalog.';
        }

        return [
            'provider' => $provider,
            'used_gemini' => $usedGemini,
            'summary' => $summary,
            'notice' => $notice,
            'matches' => array_values($matches),
        ];
    }

    private static function fetchCatalogRows(PDO $pdo): array
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
ORDER BY COALESCE(m.submitted_at, m.updated_at) DESC, m.titleid DESC
SQL
        )->fetchAll();
    }

    private static function rankLocally(string $query, array $rows, int $limit): array
    {
        $queryPrepared = self::prepareText($query);
        $rankedMatches = [];
        $fallbackMatches = [];

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
            $summary = self::summaryLabel($row, $title, $programLabel, $researchType);
            $year = self::yearValue($row);
            $dateLabel = self::dateLabel($row);
            $searchBlob = implode(
                ' ',
                array_filter([
                    $title,
                    $authorsLabel,
                    $adviserLabel,
                    $programLabel,
                    $researchType,
                    self::normalizeText($row['sdgs'] ?? ''),
                    $summary,
                ], static function ($value): bool {
                    return trim((string) $value) !== '';
                })
            );

            $titlePrepared = self::prepareText($title);
            $contextPrepared = self::prepareText($searchBlob);
            $titleScores = self::scorePrepared($queryPrepared, $titlePrepared);
            $contextScores = self::scorePrepared($queryPrepared, $contextPrepared);
            $overlapTerms = self::keywordOverlap($queryPrepared['tokens'], $contextPrepared['tokens']);
            $queryComparison = (string) ($queryPrepared['comparison'] ?? '');
            $titleComparison = (string) ($titlePrepared['comparison'] ?? '');
            $contextComparison = (string) ($contextPrepared['comparison'] ?? '');
            $phraseBonus = 0.0;

            if ($queryComparison !== '' && $titleComparison !== '' && strpos($titleComparison, $queryComparison) !== false) {
                $phraseBonus += 0.18;
            }

            if ($queryComparison !== '' && $contextComparison !== '' && strpos($contextComparison, $queryComparison) !== false) {
                $phraseBonus += 0.10;
            }

            if ($overlapTerms !== []) {
                $phraseBonus += min(0.14, count($overlapTerms) * 0.035);
            }

            $score = min(
                1.0,
                ((float) ($titleScores['hybrid'] ?? 0.0) * 0.62)
                + ((float) ($contextScores['hybrid'] ?? 0.0) * 0.28)
                + ((float) ($contextScores['cosine'] ?? 0.0) * 0.10)
                + $phraseBonus
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
                'score' => round($score, 4),
                'score_label' => self::scoreLabel($score),
                'match_reason' => self::localReason($overlapTerms, $titleScores, $contextScores, $programLabel),
                'keywords' => array_slice($overlapTerms, 0, 4),
                'rank_source' => 'local',
            ];

            $fallbackMatches[] = $match;

            if ($score >= 0.08 || $overlapTerms !== [] || $phraseBonus > 0.0) {
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

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        };

        usort($fallbackMatches, $sorter);
        usort($rankedMatches, $sorter);

        if ($rankedMatches === []) {
            return array_slice($fallbackMatches, 0, min($limit, 3));
        }

        return array_slice($rankedMatches, 0, $limit);
    }

    private static function mergeGeminiMatches(array $localMatches, array $geminiMatches, int $limit): array
    {
        $lookup = [];

        foreach ($localMatches as $match) {
            $lookup[(int) ($match['titleid'] ?? 0)] = $match;
        }

        $mergedMatches = [];

        foreach ($geminiMatches as $geminiMatch) {
            $titleId = isset($geminiMatch['titleid']) ? (int) $geminiMatch['titleid'] : 0;

            if ($titleId < 1 || !isset($lookup[$titleId])) {
                continue;
            }

            $baseMatch = $lookup[$titleId];
            $geminiScore = max(0.0, min(1.0, (float) ($geminiMatch['relevance'] ?? 0.0)));
            $baseScore = (float) ($baseMatch['score'] ?? 0.0);
            $combinedScore = max($baseScore, ($geminiScore * 0.68) + ($baseScore * 0.32));
            $baseMatch['score'] = round($combinedScore, 4);
            $baseMatch['score_label'] = self::scoreLabel($combinedScore);
            $baseMatch['match_reason'] = trim((string) ($geminiMatch['reason'] ?? '')) ?: (string) ($baseMatch['match_reason'] ?? '');
            $baseMatch['rank_source'] = 'gemini';
            $mergedMatches[] = $baseMatch;
            unset($lookup[$titleId]);
        }

        if (count($mergedMatches) < $limit && $lookup !== []) {
            foreach ($lookup as $match) {
                $mergedMatches[] = $match;

                if (count($mergedMatches) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($mergedMatches, 0, $limit);
    }

    private static function rerankWithGemini(string $query, array $localMatches, int $limit, string $apiKey): array
    {
        $model = self::geminiModel();
        $candidatePayload = [];

        foreach (array_slice($localMatches, 0, max($limit + 3, 8)) as $match) {
            $candidatePayload[] = [
                'titleid' => (int) ($match['titleid'] ?? 0),
                'title' => (string) ($match['title'] ?? ''),
                'research_type' => (string) ($match['research_type'] ?? ''),
                'program' => (string) ($match['program'] ?? ''),
                'authors' => (string) ($match['authors'] ?? ''),
                'adviser' => (string) ($match['adviser'] ?? ''),
                'summary' => (string) ($match['summary'] ?? ''),
                'local_score' => (float) ($match['score'] ?? 0.0),
            ];
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => self::geminiPrompt($query, $candidatePayload, $limit),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = self::requestJson(
            'POST',
            sprintf(self::GEMINI_ENDPOINT_TEMPLATE, rawurlencode($model), rawurlencode($apiKey)),
            ['Content-Type: application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $responseText = self::extractGeminiText($response);
        $decoded = json_decode($responseText, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response.');
        }

        $matches = [];

        foreach (($decoded['matches'] ?? []) as $match) {
            $titleId = isset($match['titleid']) ? (int) $match['titleid'] : 0;

            if ($titleId < 1) {
                continue;
            }

            $matches[] = [
                'titleid' => $titleId,
                'relevance' => max(0.0, min(1.0, (float) ($match['relevance'] ?? $match['score'] ?? 0.0))),
                'reason' => self::trimText((string) ($match['reason'] ?? ''), 220),
            ];
        }

        if ($matches === []) {
            throw new RuntimeException('Gemini did not return any ranked matches.');
        }

        return [
            'summary' => self::trimText((string) ($decoded['summary'] ?? ''), 260),
            'matches' => array_slice($matches, 0, $limit),
        ];
    }

    private static function geminiPrompt(string $query, array $candidates, int $limit): string
    {
        return "You are ranking research-catalog matches for a user query.\n"
            . "Return strict JSON with this shape only:\n"
            . "{\n"
            . '  "summary": "short summary",'
            . "\n"
            . '  "matches": [{"titleid": 0, "relevance": 0.0, "reason": "why it matches"}]'
            . "\n}\n"
            . "Rules:\n"
            . "- Use only the provided candidates.\n"
            . "- Return at most {$limit} matches.\n"
            . "- Set relevance between 0 and 1.\n"
            . "- Prefer semantic relevance to the user query, not just keyword overlap.\n"
            . "- Keep every reason under 28 words.\n"
            . "- Do not add markdown fences.\n\n"
            . 'User query: ' . $query . "\n\n"
            . 'Candidates: ' . json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function extractGeminiText(array $response): string
    {
        foreach (($response['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                $text = trim((string) ($part['text'] ?? ''));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('Gemini returned an empty response.');
    }

    private static function requestJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the Gemini request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caFile = self::resolveCertificateAuthorityFile();

        if ($caFile !== null) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $rawResponse = curl_exec($handle);

        if ($rawResponse === false) {
            $message = curl_error($handle) ?: 'Unknown cURL error.';
            curl_close($handle);

            if (stripos($message, 'certificate') !== false) {
                $message .= ' Configure SSL_CA_FILE in .env to point to a valid CA bundle.';
            }

            throw new RuntimeException('Gemini request failed: ' . $message);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unreadable response.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error']['message'] ?? 'Gemini rejected the request.';
            throw new RuntimeException('Gemini request failed: ' . $message);
        }

        return $decoded;
    }

    private static function resolveCertificateAuthorityFile(): ?string
    {
        $candidates = [];

        foreach ([
            env('SSL_CA_FILE'),
            env('CURL_CA_BUNDLE'),
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
            'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
            'C:\\VertrigoServ\\Phpmyadmin\\libraries\\certs\\cacert.pem',
        ] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function geminiModel(): string
    {
        $model = trim((string) env('GEMINI_MODEL', self::DEFAULT_GEMINI_MODEL));

        return $model !== '' ? $model : self::DEFAULT_GEMINI_MODEL;
    }

    private static function geminiApiKey(): string
    {
        $configuredKey = trim((string) env('GEMINI_API_KEY', ''));

        if ($configuredKey !== '' && in_array(self::lower($configuredKey), ['disabled', 'off', 'false'], true)) {
            return '';
        }

        if ($configuredKey !== '') {
            return $configuredKey;
        }

        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath) || !is_readable($envPath)) {
            return '';
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (strpos($line, 'GEMINI_API_KEY=') === 0) {
                $inlineKey = trim((string) substr($line, strlen('GEMINI_API_KEY=')), "\"' ");

                if ($inlineKey !== '' && in_array(self::lower($inlineKey), ['disabled', 'off', 'false'], true)) {
                    return '';
                }

                return $inlineKey;
            }

            if (strpos($line, '#') === 0) {
                $candidate = ltrim(substr($line, 1));

                if (preg_match('/^AIza[0-9A-Za-z\-_]{20,}$/', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return '';
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

        $maxLength = max(strlen($leftText), strlen($rightText));

        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = levenshtein($leftText, $rightText);
        $score = 1 - (min($distance, $maxLength) / $maxLength);

        return max(0.0, min(1.0, $score));
    }

    private static function hybrid(array $scores): float
    {
        $weights = [
            'jaccard' => 0.20,
            'cosine' => 0.30,
            'levenshtein' => 0.20,
            'dice' => 0.30,
        ];

        $hybridScore = 0.0;

        foreach ($weights as $key => $weight) {
            $hybridScore += ((float) ($scores[$key] ?? 0.0)) * $weight;
        }

        return max(0.0, min(1.0, $hybridScore));
    }

    private static function keywordOverlap(array $leftTokens, array $rightTokens, int $limit = 4): array
    {
        $overlap = array_values(array_unique(array_intersect($leftTokens, $rightTokens)));

        return array_slice($overlap, 0, $limit);
    }

    private static function normalizeTitle(string $value): string
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

    private static function localReason(array $overlapTerms, array $titleScores, array $contextScores, string $programLabel): string
    {
        if (($titleScores['hybrid'] ?? 0.0) >= 0.70) {
            return 'Strong title similarity with the research topic already in the catalog.';
        }

        if (($contextScores['hybrid'] ?? 0.0) >= 0.58 && $overlapTerms !== []) {
            return 'Related through shared keywords like ' . implode(', ', array_slice($overlapTerms, 0, 3)) . '.';
        }

        if ($overlapTerms !== []) {
            return 'Matched keyword overlap on ' . implode(', ', array_slice($overlapTerms, 0, 3)) . '.';
        }

        if ($programLabel !== 'Program not set') {
            return 'Closest available match based on the catalog title, summary, and program context.';
        }

        return 'Closest available match based on the current research catalog text.';
    }

    private static function defaultSummary(string $query, array $matches, bool $usedGemini): string
    {
        if ($matches === []) {
            return 'No close research match was found for "' . $query . '" in the current catalog.';
        }

        $leadMatch = $matches[0];
        $leadTitle = (string) ($leadMatch['title'] ?? 'the selected record');
        $leadProgram = (string) ($leadMatch['program'] ?? 'the current catalog');

        if ($usedGemini) {
            return 'Gemini ranked "' . $leadTitle . '" as the closest match for your prompt, with nearby support from related catalog records.';
        }

        return 'Local similarity scoring ranks "' . $leadTitle . '" as the closest match, especially around ' . $leadProgram . '.';
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

    private static function summaryLabel(array $row, string $title, string $programLabel, string $researchType): string
    {
        $details = self::trimText(strip_tags((string) ($row['other_details'] ?? '')), 360);

        if ($details !== '') {
            return $details;
        }

        $typeLabel = $researchType !== '' ? strtolower($researchType) : 'research';
        $programPhrase = $programLabel !== 'Program not set'
            ? 'under ' . $programLabel
            : 'within the current catalog';

        return self::trimText(
            'This record presents ' . $title . ' as a ' . $typeLabel . ' project ' . $programPhrase . '. '
            . 'It focuses on improving workflows, information access, and practical implementation for its intended users.',
            360
        );
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

        if ($value === '' || strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit - 3)) . '...';
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
