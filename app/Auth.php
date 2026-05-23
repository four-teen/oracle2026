<?php

declare(strict_types=1);

final class Auth
{
    public const ROLE_ADMIN = 1;
    public const ROLE_RESEARCH_COORDINATOR = 2;

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

        Database::ensureAccountCoordinatorColumns();

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

    public static function role(): int
    {
        $account = self::account();

        return is_array($account) && isset($account['acc_type']) ? (int) $account['acc_type'] : 0;
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    public static function isResearchCoordinator(): bool
    {
        return self::role() === self::ROLE_RESEARCH_COORDINATOR;
    }

    public static function campusId(): int
    {
        $account = self::account();

        return is_array($account) && isset($account['campus']) ? (int) $account['campus'] : 0;
    }

    public static function programId(): int
    {
        $account = self::account();

        return is_array($account) && isset($account['programid']) ? (int) $account['programid'] : 0;
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isResearchCoordinator()) {
            return;
        }

        if (self::campusId() > 0) {
            redirect(app_link('coordinator/'));
        }

        set_flash('auth_error', 'Your research coordinator account must be assigned to a campus first.');
        redirect(app_link());
    }

    public static function requireResearchCoordinator(): void
    {
        self::requireLogin();

        if (!self::isResearchCoordinator()) {
            if (self::isAdmin()) {
                redirect(app_link('administrator/'));
            }

            set_flash('auth_error', 'Your account is active, but no research coordinator workspace is assigned to it.');
            redirect(app_link());
        }

        if (self::campusId() < 1) {
            set_flash('auth_error', 'Your research coordinator account must be assigned to a campus first.');
            redirect(app_link());
        }
    }

    public static function defaultWorkspaceUrl(?array $account = null): string
    {
        $resolvedAccount = $account ?? self::account() ?? [];
        $role = isset($resolvedAccount['acc_type']) ? (int) $resolvedAccount['acc_type'] : 0;

        if ($role === self::ROLE_RESEARCH_COORDINATOR) {
            return (int) ($resolvedAccount['campus'] ?? 0) > 0
                ? app_link('coordinator/')
                : app_link();
        }

        if (isset($resolvedAccount['accountid']) || isset($resolvedAccount['email'])) {
            return app_link('administrator/');
        }

        return app_link();
    }
}
