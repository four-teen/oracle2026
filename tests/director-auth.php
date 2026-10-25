<?php

declare(strict_types=1);

final class Database
{
    public static $account;
    public static $lookups = 0;
    public static $readChecks = 0;
    public static $maintenance = 0;
    public static function ensureAccountReadSchema(): void { self::$readChecks++; }
    public static function ensureAccountCoordinatorColumns(): void { self::$maintenance++; }
    public static function findAccountById(int $id): ?array { self::$lookups++; return self::$account; }
    public static function findAccountByEmail(string $email): ?array { self::$lookups++; return self::$account; }
}
final class DirectorAuthRedirect extends RuntimeException {}
function app_link(string $path = ''): string { return '/oracle/' . $path; }
function set_flash(string $key, string $value): void { $_SESSION['_flash'][$key] = $value; }
function redirect(string $url) { throw new DirectorAuthRedirect($url); }
require dirname(__DIR__) . '/app/Auth.php';
session_start(['save_path' => sys_get_temp_dir()]);
$account = ['accountid' => 1, 'acc_name' => 'Director fixture', 'email' => 'fixture@example.test',
    'acc_type' => 6, 'acc_roles' => '6', 'is_enabled' => 1, 'campus' => 2];
$reset = static function () use ($account): void {
    Database::$account = $account;
    $_SESSION['auth'] = ['account' => $account, 'selected_role' => 6, 'role_selection_required' => false];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$denied = static function (array $changes) use ($reset, $account): void {
    $reset();
    Database::$account = array_replace($account, $changes);
    try { Auth::requireDirector(); }
    catch (DirectorAuthRedirect $expected) { return; }
    throw new RuntimeException('Director access should have been denied.');
};

$reset();
Auth::requireDirector();
Auth::requireDirector();
$assert(Database::$lookups === 2, 'Account status was not refreshed on each authorization check.');
$assert(Database::$readChecks === 2 && Database::$maintenance === 0, 'Director authorization ran database maintenance.');
$denied(['is_enabled' => 0]);
$denied(['acc_type' => 4, 'acc_roles' => '4']);
$denied(['campus' => 0]);
$reset();
Database::$account = null;
try { Auth::requireDirector(); throw new RuntimeException('A removed account was allowed.'); }
catch (DirectorAuthRedirect $expected) {}
$reset();
Auth::requireLogin();
$assert(Database::$maintenance === 1, 'Other workspace authentication changed unexpectedly.');
session_destroy();
echo "PASS: director uses read checks, refreshes the account, and denies disabled, removed, wrong-role, and unassigned accounts\n";
echo "PASS: existing authentication setup for other workspaces is preserved\n";
