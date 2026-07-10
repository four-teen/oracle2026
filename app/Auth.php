<?php

declare(strict_types=1);

final class Auth
{
    public const ROLE_ADMIN = 1;
    public const ROLE_RESEARCH_COORDINATOR = 2;
    public const ROLE_EXTENSION_COORDINATOR = 5;

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

    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrator',
            self::ROLE_RESEARCH_COORDINATOR => 'Research Coordinator',
            self::ROLE_EXTENSION_COORDINATOR => 'Extension Coordinator',
            3 => 'Student',
            4 => 'Professor',
            0 => 'User',
        ];
    }

    public static function roleLabel(int $role): string
    {
        $labels = self::roleLabels();

        return $labels[$role] ?? ('Role ' . $role);
    }

    public static function accountRoleIds(?array $account = null): array
    {
        $resolvedAccount = $account ?? self::account() ?? [];
        $roleMap = self::roleLabels();
        $rawRoles = [];

        if (isset($resolvedAccount['acc_roles'])) {
            $rawRoles = preg_split('/\s*,\s*/', trim((string) $resolvedAccount['acc_roles']));
            $rawRoles = is_array($rawRoles) ? $rawRoles : [];
        }

        if (isset($resolvedAccount['acc_type'])) {
            $rawRoles[] = (string) ((int) $resolvedAccount['acc_type']);
        }

        $roles = [];

        foreach ($rawRoles as $rawRole) {
            if (is_array($rawRole)) {
                continue;
            }

            $normalized = trim((string) $rawRole);

            if ($normalized === '' || !preg_match('/^-?\d+$/', $normalized)) {
                continue;
            }

            $roleId = (int) $normalized;

            if (array_key_exists($roleId, $roleMap)) {
                $roles[$roleId] = $roleId;
            }
        }

        if ($roles === [] && isset($resolvedAccount['acc_type'])) {
            $roleId = (int) $resolvedAccount['acc_type'];
            $roles[$roleId] = $roleId;
        }

        return array_values($roles);
    }

    public static function login(array $account, array $googleUser, ?int $selectedRole = null): void
    {
        session_regenerate_id(true);

        $roles = self::accountRoleIds($account);
        $roleSelectionRequired = count($roles) > 1 && ($selectedRole === null || !in_array($selectedRole, $roles, true));

        if ($selectedRole === null && count($roles) === 1) {
            $selectedRole = $roles[0];
        }

        $_SESSION['auth'] = [
            'account' => $account,
            'google' => $googleUser,
            'selected_role' => $selectedRole !== null && in_array($selectedRole, $roles, true) ? $selectedRole : null,
            'role_selection_required' => $roleSelectionRequired,
            'logged_in_at' => date(DATE_ATOM),
        ];
    }

    public static function isRoleSelectionRequired(): bool
    {
        return !empty($_SESSION['auth']['role_selection_required']);
    }

    public static function selectRole(int $role): void
    {
        $account = self::account();
        $roles = self::accountRoleIds($account);

        if (!in_array($role, $roles, true)) {
            throw new RuntimeException('The selected role is not assigned to this account.');
        }

        $_SESSION['auth']['selected_role'] = $role;
        $_SESSION['auth']['role_selection_required'] = false;
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

        $roles = self::accountRoleIds($freshAccount);
        $selectedRole = array_key_exists('selected_role', $_SESSION['auth'])
            ? $_SESSION['auth']['selected_role']
            : null;

        if ($roles === []) {
            self::logout();
            set_flash('auth_error', 'This account has no valid role assigned. Please contact the administrator.');
            redirect(app_link());
        }

        if ($selectedRole !== null && !in_array((int) $selectedRole, $roles, true)) {
            $_SESSION['auth']['selected_role'] = null;
            $_SESSION['auth']['role_selection_required'] = count($roles) > 1;
        } elseif ($selectedRole === null && count($roles) === 1) {
            $_SESSION['auth']['selected_role'] = $roles[0];
            $_SESSION['auth']['role_selection_required'] = false;
        } elseif ($selectedRole === null && count($roles) > 1) {
            $_SESSION['auth']['role_selection_required'] = true;
        }
    }

    public static function role(): int
    {
        if (self::isRoleSelectionRequired()) {
            return -1;
        }

        if (isset($_SESSION['auth']['selected_role'])) {
            return (int) $_SESSION['auth']['selected_role'];
        }

        $account = self::account();
        return is_array($account) && isset($account['acc_type']) ? (int) $account['acc_type'] : 0;
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), self::administratorRoles(), true);
    }

    public static function isResearchCoordinator(): bool
    {
        return self::role() === self::ROLE_RESEARCH_COORDINATOR;
    }

    public static function isExtensionCoordinator(): bool
    {
        return self::role() === self::ROLE_EXTENSION_COORDINATOR;
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

        if (self::isRoleSelectionRequired()) {
            redirect(app_link('auth/select-role.php'));
        }

        if (self::isAdmin()) {
            return;
        }

        redirect(self::defaultWorkspaceUrl());
    }

    public static function requireResearchCoordinator(): void
    {
        self::requireLogin();

        if (self::isRoleSelectionRequired()) {
            redirect(app_link('auth/select-role.php'));
        }

        if (!self::isResearchCoordinator()) {
            if (self::isAdmin()) {
                redirect(app_link('administrator/'));
            }

            if (self::isExtensionCoordinator()) {
                redirect(app_link('extension/'));
            }

            set_flash('auth_error', 'Your account is active, but no research coordinator workspace is assigned to it.');
            redirect(app_link());
        }

        if (self::campusId() < 1) {
            set_flash('auth_error', 'Your research coordinator account must be assigned to a campus first.');
            redirect(app_link());
        }
    }

    public static function requireExtensionCoordinator(): void
    {
        self::requireLogin();

        if (self::isRoleSelectionRequired()) {
            redirect(app_link('auth/select-role.php'));
        }

        if (!self::isExtensionCoordinator()) {
            if (self::isAdmin()) {
                redirect(app_link('administrator/'));
            }

            if (self::isResearchCoordinator()) {
                redirect(app_link('coordinator/'));
            }

            set_flash('auth_error', 'Your account is active, but no extension coordinator workspace is assigned to it.');
            redirect(app_link());
        }
    }

    public static function workspaceUrlForRole(int $role, ?array $account = null): string
    {
        $resolvedAccount = $account ?? self::account() ?? [];

        if ($role === self::ROLE_RESEARCH_COORDINATOR) {
            return (int) ($resolvedAccount['campus'] ?? 0) > 0
                ? app_link('coordinator/')
                : app_link();
        }

        if ($role === self::ROLE_EXTENSION_COORDINATOR) {
            return app_link('extension/');
        }

        if (in_array($role, self::administratorRoles(), true)) {
            return app_link('administrator/');
        }

        return app_link();
    }

    public static function defaultWorkspaceUrl(?array $account = null): string
    {
        $resolvedAccount = $account ?? self::account() ?? [];
        $role = self::role();

        if ($role < 0) {
            return app_link('auth/select-role.php');
        }

        if ($role === 0 && isset($resolvedAccount['acc_type'])) {
            $role = (int) $resolvedAccount['acc_type'];
        }

        return self::workspaceUrlForRole($role, $resolvedAccount);
    }

    private static function administratorRoles(): array
    {
        return [
            self::ROLE_ADMIN,
            0,
            3,
            4,
        ];
    }
}
