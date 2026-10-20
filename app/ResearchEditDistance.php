<?php

declare(strict_types=1);

/** Exact byte edit distance, matching PHP's unit-cost Levenshtein semantics. */
final class ResearchEditDistance
{
    public static function distance(string $left, string $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if (PHP_VERSION_ID >= 80000 || max(strlen($left), strlen($right)) <= 255) {
            return levenshtein($left, $right);
        }
        return self::portableDistance($left, $right);
    }

    /**
     * Myers bit vectors in bounded integer words avoid PHP 7's native 255-byte limit.
     * Block recurrence reference: https://github.com/Martinsos/edlib/blob/master/edlib/src/edlib.cpp
     * Uses actual last-block width, so no padding or approximate/truncated comparisons are needed.
     */
    private static function portableDistance(string $left, string $right): int
    {
        $leftLength = strlen($left);
        $rightLength = strlen($right);
        $prefix = 0;
        $shared = min($leftLength, $rightLength);
        while ($prefix < $shared && $left[$prefix] === $right[$prefix]) {
            $prefix++;
        }
        $left = substr($left, $prefix);
        $right = substr($right, $prefix);
        $leftLength -= $prefix;
        $rightLength -= $prefix;
        while ($leftLength > 0 && $rightLength > 0 && $left[$leftLength - 1] === $right[$rightLength - 1]) {
            $leftLength--;
            $rightLength--;
        }
        $left = substr($left, 0, $leftLength);
        $right = substr($right, 0, $rightLength);
        if ($leftLength === 0 || $rightLength === 0) {
            return max($leftLength, $rightLength);
        }
        if ($leftLength > $rightLength) {
            [$left, $right, $leftLength, $rightLength] = [$right, $left, $rightLength, $leftLength];
        }
        // Leave one addition bit and the sign bit unused; intermediate sums stay integer-valued.
        $wordSize = PHP_INT_SIZE >= 8 ? 62 : 30;
        $blocks = (int) ceil($leftLength / $wordSize);
        $masks = $tops = $positive = $negative = $profiles = [];
        for ($block = 0; $block < $blocks; $block++) {
            $width = min($wordSize, $leftLength - $block * $wordSize);
            $masks[$block] = (1 << $width) - 1;
            $tops[$block] = 1 << ($width - 1);
            $positive[$block] = $masks[$block];
            $negative[$block] = 0;
        }
        for ($index = 0; $index < $leftLength; $index++) {
            $character = $left[$index];
            $block = intdiv($index, $wordSize);
            $profiles[$character][$block] = ($profiles[$character][$block] ?? 0) | (1 << ($index % $wordSize));
        }
        $score = $leftLength;
        for ($index = 0; $index < $rightLength; $index++) {
            $profile = $profiles[$right[$index]] ?? [];
            $incoming = 1;
            for ($block = 0; $block < $blocks; $block++) {
                $equal = $profile[$block] ?? 0;
                $pv = $positive[$block];
                $mv = $negative[$block];
                $vertical = $equal | $mv;
                $equal |= $incoming < 0 ? 1 : 0;
                $horizontal = ((($equal & $pv) + $pv) ^ $pv) | $equal;
                $up = $mv | ~($horizontal | $pv);
                $down = $pv & $horizontal;
                $outgoing = (($up & $tops[$block]) !== 0 ? 1 : 0) - (($down & $tops[$block]) !== 0 ? 1 : 0);
                $up = ($up << 1) | ($incoming > 0 ? 1 : 0);
                $down = ($down << 1) | ($incoming < 0 ? 1 : 0);
                $positive[$block] = ($down | ~($vertical | $up)) & $masks[$block];
                $negative[$block] = ($up & $vertical) & $masks[$block];
                $incoming = $outgoing;
            }
            $score += $incoming;
        }
        return $score;
    }
}
