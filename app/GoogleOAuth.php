<?php

declare(strict_types=1);

final class GoogleOAuth
{
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function missingConfiguration(): array
    {
        $requiredKeys = [
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

    public static function authorizationUrl(string $state): string
    {
        $clientId = env('GOOGLE_CLIENT_ID');

        if ($clientId === null || trim($clientId) === '') {
            throw new RuntimeException('Google sign-in is not configured yet.');
        }

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ];

        $hostedDomain = env('GOOGLE_HOSTED_DOMAIN');

        if ($hostedDomain !== null && trim($hostedDomain) !== '') {
            $query['hd'] = trim($hostedDomain);
        }

        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function exchangeCodeForToken(string $code): array
    {
        $payload = [
            'code' => $code,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ];

        return self::requestJson(
            'POST',
            self::TOKEN_ENDPOINT,
            [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
        );
    }

    public static function fetchUserInfo(string $accessToken): array
    {
        return self::requestJson(
            'GET',
            self::USERINFO_ENDPOINT,
            [
                'Authorization: Bearer ' . $accessToken,
            ]
        );
    }

    public static function verifiedEmail(array $profile): string
    {
        $email = isset($profile['email']) ? trim((string) $profile['email']) : '';
        $emailVerified = $profile['email_verified'] ?? false;
        $isVerified = $emailVerified === true || $emailVerified === 1 || $emailVerified === '1' || $emailVerified === 'true';

        if (!$isVerified || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google did not return a verified email address.');
        }

        return $email;
    }

    public static function redirectUri(): string
    {
        $configuredUri = env('GOOGLE_REDIRECT_URI');

        if ($configuredUri !== null && trim($configuredUri) !== '') {
            return trim($configuredUri);
        }

        return app_url('auth/google-callback.php');
    }

    private static function requestJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the Google sign-in request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => 20,
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
                $message .= ' Configure SSL_CA_FILE in .env to point to a valid cacert.pem or ca-bundle.crt file.';
            }

            throw new RuntimeException('Google sign-in request failed: ' . $message);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Google returned an unexpected response.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error_description'] ?? $decoded['error'] ?? 'Google rejected the sign-in request.';
            throw new RuntimeException('Google sign-in failed: ' . $message);
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
}
