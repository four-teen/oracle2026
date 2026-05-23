<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (isset($_GET['error'])) {
    set_flash('auth_error', 'Google sign-in was cancelled or denied.');
    redirect(app_link());
}

$expectedState = isset($_SESSION['oauth_state']) && is_string($_SESSION['oauth_state']) ? $_SESSION['oauth_state'] : '';
$receivedState = isset($_GET['state']) ? (string) $_GET['state'] : '';

unset($_SESSION['oauth_state']);

if ($expectedState === '' || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
    set_flash('auth_error', 'The Google sign-in state is invalid. Please try again.');
    redirect(app_link());
}

$authorizationCode = isset($_GET['code']) ? trim((string) $_GET['code']) : '';

if ($authorizationCode === '') {
    set_flash('auth_error', 'Google did not return an authorization code.');
    redirect(app_link());
}

try {
    $token = GoogleOAuth::exchangeCodeForToken($authorizationCode);
    $accessToken = isset($token['access_token']) ? trim((string) $token['access_token']) : '';

    if ($accessToken === '') {
        throw new RuntimeException('Google did not return an access token.');
    }

    $profile = GoogleOAuth::fetchUserInfo($accessToken);
    $email = GoogleOAuth::verifiedEmail($profile);
    Database::ensureAccountCoordinatorColumns();
    $account = Database::findAccountByEmail($email);

    if ($account === null) {
        set_flash('auth_error', 'This Google account is not listed in tblaccount.email.');
        redirect(app_link());
    }

    if (array_key_exists('is_enabled', $account) && (int) $account['is_enabled'] !== 1) {
        set_flash('auth_error', 'This account has been disabled. Please contact the administrator.');
        redirect(app_link());
    }

    Auth::login($account, [
        'id' => isset($profile['sub']) ? (string) $profile['sub'] : '',
        'email' => $email,
        'name' => isset($profile['name']) ? (string) $profile['name'] : (string) ($account['acc_name'] ?? ''),
        'picture' => isset($profile['picture']) ? (string) $profile['picture'] : '',
    ]);

    redirect(Auth::defaultWorkspaceUrl($account));
} catch (PDOException $exception) {
    set_flash('auth_error', 'Database query failed. Verify your tblaccount structure and database settings.');
    redirect(app_link());
} catch (Throwable $exception) {
    set_flash('auth_error', $exception->getMessage());
    redirect(app_link());
}
