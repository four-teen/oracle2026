<?php

declare(strict_types=1);

/** Checked JSON handling for PHP 7.1, where JSON_THROW_ON_ERROR is unavailable. */
final class ResearchMatchJson
{
    public static function encode(array $value, int $flags = 0): string
    {
        $json = json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    public static function decode(string $json): array
    {
        $value = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) {
            throw new RuntimeException('Stored calculation JSON is invalid: ' . json_last_error_msg());
        }
        return $value;
    }

    /** JSON endpoints own their response buffers; PHP notices must never become response content. */
    public static function respond(array $value, int $status = 200): void
    {
        $json = self::encode($value);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $json;
    }

    public static function protectResponse(): void
    {
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log('Research Match fatal error: ' . $error['message']);
                self::respond(['message' => 'The server could not finish the request. Saved progress is retained. Check the server error log before resuming.'], 500);
            }
        });
    }
}
