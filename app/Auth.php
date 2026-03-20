<?php

declare(strict_types=1);

final class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['auth']['account']) && is_array($_SESSION['auth']['account']);
    }

    public static function account(): ?array
    {
        return self::check() ? $_SESSION['auth']['account'] : null;
    }

    public static function googleUser(): ?array
    {
        return isset($_SESSION['auth']['google']) && is_array($_SESSION['auth']['google'])
            ? $_SESSION['auth']['google']
            : null;
    }

    public static function login(array $account, array $googleUser): void
    {
        session_regenerate_id(true);

        $_SESSION['auth'] = [
            'account' => $account,
            'google' => $googleUser,
            'logged_in_at' => date(DATE_ATOM),
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            set_flash('auth_error', 'Please sign in with your approved Google account.');
            redirect(app_link());
        }

        $account = self::account() ?? [];
        $freshAccount = null;

        if (isset($account['accountid'])) {
            $freshAccount = Database::findAccountById((int) $account['accountid']);
        }

        if ($freshAccount === null && isset($account['email'])) {
            $freshAccount = Database::findAccountByEmail((string) $account['email']);
        }

        if ($freshAccount === null) {
            self::logout();
            set_flash('auth_error', 'This account is no longer available. Please sign in again.');
            redirect(app_link());
        }

        if (array_key_exists('is_enabled', $freshAccount) && (int) $freshAccount['is_enabled'] !== 1) {
            self::logout();
            set_flash('auth_error', 'This account has been disabled. Please contact the administrator.');
            redirect(app_link());
        }

        $_SESSION['auth']['account'] = $freshAccount;
    }
}
