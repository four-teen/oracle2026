<?php

declare(strict_types=1);

final class Env
{
    public static function load(string $path): void
    {
        static $loaded = [];

        $cacheKey = realpath($path) ?: $path;

        if (isset($loaded[$cacheKey]) || !is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to read environment file: %s', $path));
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = substr($line, 7);
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$name, $value] = $parts;
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = self::normalizeValue(trim($value));

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        $loaded[$cacheKey] = true;
    }

    private static function normalizeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];
        $lastChar = $value[strlen($value) - 1];

        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value);
    }
}
