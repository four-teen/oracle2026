<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    return is_string($value) ? $value : (string) $value;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_https(): bool
{
    if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
        return true;
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function normalize_system_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function app_base_path(): string
{
    static $basePath;

    if ($basePath !== null) {
        return $basePath;
    }

    $appUrl = env('APP_URL');

    if ($appUrl !== null && $appUrl !== '') {
        $path = parse_url($appUrl, PHP_URL_PATH);

        if (is_string($path)) {
            $trimmed = trim($path, '/');
            $basePath = $trimmed === '' ? '' : '/' . $trimmed;

            return $basePath;
        }
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $applicationRoot = realpath(dirname(__DIR__));

    if ($documentRoot !== false && $applicationRoot !== false) {
        $documentRoot = normalize_system_path($documentRoot);
        $applicationRoot = normalize_system_path($applicationRoot);

        if (strpos(strtolower($applicationRoot), strtolower($documentRoot)) === 0) {
            $relative = trim(substr($applicationRoot, strlen($documentRoot)), '/');
            $basePath = $relative === '' ? '' : '/' . $relative;

            return $basePath;
        }
    }

    $basePath = '';

    return $basePath;
}

function app_link(string $path = ''): string
{
    $basePath = app_base_path();
    $relativePath = ltrim($path, '/');

    if ($relativePath === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return ($basePath === '' ? '' : $basePath) . '/' . $relativePath;
}

function app_url(string $path = ''): string
{
    $configuredUrl = env('APP_URL');

    if ($configuredUrl !== null && $configuredUrl !== '') {
        $baseUrl = rtrim($configuredUrl, '/');

        return $path === '' ? $baseUrl . '/' : $baseUrl . '/' . ltrim($path, '/');
    }

    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . app_link($path);
}

function redirect(string $location)
{
    header('Location: ' . $location);
    exit;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_name(env('SESSION_NAME', 'sneat_session') ?? 'sneat_session');

    $cookiePath = app_base_path() === '' ? '/' : app_base_path() . '/';
    $cookiePathWithSameSite = $cookiePath . '; samesite=Lax';

    session_set_cookie_params(
        0,
        $cookiePathWithSameSite,
        '',
        is_https(),
        true
    );

    session_start();
}

function set_flash(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $message = (string) $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $message;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool
{
    if ($token === '' || !isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function normalize_email(string $email): string
{
    $collapsed = preg_replace('/\s+/', '', trim($email));

    return mb_strtolower($collapsed ?? '', 'UTF-8');
}

function configuration_issues(): array
{
    $requiredKeys = [
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_REDIRECT_URI',
    ];

    $missing = [];

    foreach ($requiredKeys as $key) {
        $value = env($key);

        if ($value === null || trim($value) === '') {
            $missing[] = $key;
        }
    }

    return $missing;
}
