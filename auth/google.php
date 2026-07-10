<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (Auth::check()) {
    Auth::requireLogin();

    if (Auth::isRoleSelectionRequired()) {
        redirect(app_link('auth/select-role.php'));
    }

    redirect(Auth::defaultWorkspaceUrl());
}

$missingConfiguration = GoogleOAuth::missingConfiguration();

if ($missingConfiguration !== []) {
    set_flash('auth_error', 'Google sign-in is not configured yet. Update .env: ' . implode(', ', $missingConfiguration) . '.');
    redirect(app_link());
}

$_SESSION['oauth_state'] = bin2hex(random_bytes(32));

redirect(GoogleOAuth::authorizationUrl($_SESSION['oauth_state']));
