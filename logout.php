<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect(app_link());
}

$token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

if (!verify_csrf_token($token)) {
    set_flash('auth_error', 'The sign-out request is invalid. Please try again.');
    redirect(app_link());
}

Auth::logout();

redirect(app_link());
