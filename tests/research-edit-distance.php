<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/ResearchEditDistance.php';
require dirname(__DIR__) . '/app/ResearchMatchJson.php';

function referenceDistance(string $left, string $right): int
{
    $previous = range(0, strlen($right));
    for ($row = 1, $rows = strlen($left); $row <= $rows; $row++) {
        $current = [$row];
        for ($column = 1, $columns = strlen($right); $column <= $columns; $column++) {
            $current[$column] = min($current[$column - 1] + 1, $previous[$column] + 1,
                $previous[$column - 1] + ($left[$row - 1] === $right[$column - 1] ? 0 : 1));
        }
        $previous = $current;
    }
    return $previous[strlen($right)];
}

$portable = new ReflectionMethod(ResearchEditDistance::class, 'portableDistance');
$portable->setAccessible(true);
$cases = [['', ''], ['', 'abc'], ['kitten', 'sitting'], ['abc', 'abc'], ['abc', 'xyz'], ['ba', 'a'], ['a', 'ba'],
    [str_repeat('a', 300) . 'cccc', str_repeat('a', 300) . 'bbbb'],
    [str_repeat('ab', 180), str_repeat('ba', 175)], [str_repeat('a', 300), str_repeat('b', 600)]];
mt_srand(41071);
foreach ([1, 14, 15, 16, 29, 30, 31, 59, 60, 61, 62, 63, 64, 123, 124, 125, 254, 255, 256, 257, 299, 300, 301, 510, 511, 512] as $length) {
    for ($sample = 0; $sample < 3; $sample++) {
        $left = $right = '';
        $rightLength = max(0, $length + mt_rand(-20, 20));
        for ($index = 0; $index < $length; $index++) $left .= 'abcde '[mt_rand(0, 5)];
        for ($index = 0; $index < $rightLength; $index++) $right .= 'abcde '[mt_rand(0, 5)];
        $cases[] = [$left, $right];
    }
}
foreach ($cases as $index => $case) {
    [$left, $right] = $case;
    $expected = PHP_VERSION_ID >= 80000 ? levenshtein($left, $right) : referenceDistance($left, $right);
    $actual = $portable->invoke(null, $left, $right);
    if ($actual !== $expected || ResearchEditDistance::distance($left, $right) !== $expected || $portable->invoke(null, $right, $left) !== $expected) {
        throw new RuntimeException('Edit distance mismatch in case ' . $index . ': ' . $actual . ' / ' . $expected);
    }
}
$json = ResearchMatchJson::encode(['title' => 'Solar energy', 'score' => .72]);
if (ResearchMatchJson::decode($json)['score'] !== .72) throw new RuntimeException('JSON round trip failed');
foreach (['{broken', 'null'] as $invalid) {
    try { ResearchMatchJson::decode($invalid); throw new LogicException('Invalid JSON accepted'); }
    catch (RuntimeException $expected) {}
}
try { ResearchMatchJson::encode(['bad' => "\xB1\x31"]); throw new LogicException('Invalid UTF-8 accepted'); }
catch (RuntimeException $expected) {}
echo 'PASS: ' . count($cases) . ' independent edit-distance cases, block boundaries, full strings, symmetry, and checked JSON on PHP ' . PHP_VERSION . PHP_EOL;
