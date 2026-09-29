<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

Auth::requireLogin();

$account = Auth::account() ?? [];
$googleUser = Auth::googleUser() ?? [];
$roles = Auth::accountRoleIds($account);
$error = null;

if ($roles === []) {
    Auth::logout();
    set_flash('auth_error', 'This account has no valid role assigned. Please contact the administrator.');
    redirect(app_link());
}

if (count($roles) === 1 && Auth::isRoleSelectionRequired()) {
    Auth::selectRole($roles[0]);
    redirect(Auth::defaultWorkspaceUrl());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

        if (!verify_csrf_token($csrfToken)) {
            throw new RuntimeException('The request is invalid. Refresh the page and try again.');
        }

        $selectedRole = isset($_POST['role']) ? (int) $_POST['role'] : -1;
        Auth::selectRole($selectedRole);
        redirect(Auth::defaultWorkspaceUrl());
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$name = trim((string) ($googleUser['name'] ?? $account['acc_name'] ?? 'Approved User'));
$email = trim((string) ($googleUser['email'] ?? $account['email'] ?? ''));

$roleCards = [
    Auth::ROLE_ADMIN => [
        'icon' => 'bx-shield-quarter',
        'note' => 'Manage system setup, accounts, and institutional records.',
    ],
    Auth::ROLE_RESEARCH_COORDINATOR => [
        'icon' => 'bx-network-chart',
        'note' => 'Encode and monitor campus research manuscript records.',
    ],
    Auth::ROLE_EXTENSION_COORDINATOR => [
        'icon' => 'bx-network-chart',
        'note' => 'Manage extension projects, components, partners, and outputs.',
    ],
    Auth::ROLE_PROFESSOR => [
        'icon' => 'bx-book-reader',
        'note' => 'Manage your research and view your faculty assignments.',
    ],
    3 => [
        'icon' => 'bx-user-pin',
        'note' => 'Open the student workspace when it is available.',
    ],
    0 => [
        'icon' => 'bx-user',
        'note' => 'Open the default user workspace.',
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Select Workspace | Oracle</title>
  <link rel="icon" type="image/png" sizes="64x64" href="<?= e(app_link('assets/img/favicon/oracle-favicon.png')); ?>">
  <link rel="shortcut icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.ico')); ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(app_link('assets/img/favicon/apple-touch-icon.png')); ?>">
  <link rel="preload" href="<?= e(app_link('assets/img/branding/oracle-logo.png')); ?>" as="image" fetchpriority="high">
  <link rel="preload" href="<?= e(app_link('assets/vendor/fonts/boxicons/boxicons.woff2')); ?>" as="font" type="font/woff2" crossorigin>
  <style>
    @font-face {
      font-family: "boxicons";
      font-style: normal;
      font-weight: normal;
      font-display: swap;
      src: url("<?= e(app_link('assets/vendor/fonts/boxicons/boxicons.woff2')); ?>") format("woff2");
    }

    .bx {
      display: inline-block;
      font-family: "boxicons" !important;
      font-style: normal;
      font-variant: normal;
      font-weight: normal;
      line-height: 1;
      text-rendering: auto;
      text-transform: none;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    .bx-book-reader::before { content: "\ec53"; }
    .bx-grid-alt::before { content: "\ed5e"; }
    .bx-network-chart::before { content: "\ee01"; }
    .bx-shield-quarter::before { content: "\ee6b"; }
    .bx-user::before { content: "\eec6"; }
    .bx-user-pin::before { content: "\eeca"; }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      min-height: 100dvh;
      display: grid;
      align-items: start;
      justify-items: center;
      padding: 48px 24px 24px;
      background: #f6f8fb;
      color: #111827;
      font-family: "Public Sans", "Segoe UI", Arial, sans-serif;
    }

    .role-shell {
      width: min(980px, 100%);
    }

    .role-panel {
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #ffffff;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
      overflow: hidden;
    }

    .role-header {
      padding: 28px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .role-heading {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      flex: 1 1 520px;
    }

    .role-brand-logo {
      width: 64px;
      height: 64px;
      display: block;
      flex: 0 0 64px;
      border-radius: 12px;
      object-fit: cover;
      box-shadow: 0 8px 20px rgba(21, 128, 61, 0.18);
    }

    .role-kicker {
      margin: 0 0 6px;
      color: #15803d;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .role-title {
      margin: 0;
      font-size: 28px;
      line-height: 1.15;
    }

    .role-copy {
      margin: 8px 0 0;
      color: #6b7280;
      line-height: 1.55;
    }

    .role-user {
      text-align: right;
      color: #374151;
      font-weight: 700;
    }

    .role-user span {
      display: block;
      color: #6b7280;
      font-size: 13px;
      font-weight: 500;
      margin-top: 4px;
    }

    .role-alert {
      margin: 20px 28px 0;
      padding: 14px 16px;
      border-radius: 8px;
      color: #991b1b;
      background: #fee2e2;
      font-weight: 600;
    }

    .role-grid {
      padding: 28px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      grid-auto-rows: 1fr;
      gap: 14px;
    }

    .role-grid > form {
      height: 100%;
      margin: 0;
    }

    .role-card {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      height: 100%;
      min-height: 172px;
      width: 100%;
      padding: 18px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      background: #ffffff;
      color: #111827;
      text-align: left;
      cursor: pointer;
      transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
    }

    .role-card:hover,
    .role-card:focus {
      outline: none;
      border-color: #15803d;
      box-shadow: 0 10px 26px rgba(21, 128, 61, 0.12);
      transform: translateY(-1px);
    }

    .role-icon {
      width: 44px;
      height: 44px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      background: #ecfdf5;
      color: #15803d;
      font-size: 23px;
    }

    .role-name {
      display: block;
      margin-top: 14px;
      font-size: 17px;
      font-weight: 800;
    }

    .role-note {
      display: block;
      margin-top: 7px;
      color: #6b7280;
      font-size: 14px;
      line-height: 1.45;
    }

    .role-footer {
      padding: 18px 28px;
      border-top: 1px solid #e5e7eb;
      display: flex;
      justify-content: flex-end;
      background: #f9fafb;
    }

    .role-logout {
      min-height: 42px;
      padding: 0 16px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      background: #ffffff;
      color: #374151;
      font-weight: 700;
      cursor: pointer;
    }

    @media (max-width: 640px) {
      body {
        padding: 24px;
      }

      .role-header,
      .role-footer {
        padding: 22px;
      }

      .role-grid {
        padding: 22px;
      }

      .role-user {
        text-align: left;
      }

      .role-brand-logo {
        width: 54px;
        height: 54px;
        flex-basis: 54px;
      }
    }
  </style>
</head>
<body>
  <main class="role-shell">
    <section class="role-panel" aria-labelledby="role-title">
      <header class="role-header">
        <div class="role-heading">
          <img
            class="role-brand-logo"
            src="<?= e(app_link('assets/img/branding/oracle-logo.png')); ?>"
            alt="Oracle"
            width="512"
            height="512"
          >
          <div>
            <p class="role-kicker">Oracle Workspace</p>
            <h1 class="role-title" id="role-title">Select your role</h1>
            <p class="role-copy">This account has multiple roles. Choose the workspace you want to use for this session.</p>
          </div>
        </div>
        <div class="role-user">
          <?= e($name !== '' ? $name : 'Approved User'); ?>
          <?php if ($email !== ''): ?>
            <span><?= e($email); ?></span>
          <?php endif; ?>
        </div>
      </header>

      <?php if ($error !== null): ?>
        <div class="role-alert"><?= e($error); ?></div>
      <?php endif; ?>

      <div class="role-grid">
        <?php foreach ($roles as $role): ?>
          <?php $card = $roleCards[$role] ?? ['icon' => 'bx-grid-alt', 'note' => 'Open this assigned workspace.']; ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <input type="hidden" name="role" value="<?= e((string) $role); ?>">
            <button class="role-card" type="submit">
              <span class="role-icon"><i class="bx <?= e($card['icon']); ?>"></i></span>
              <span class="role-name"><?= e(Auth::roleLabel($role)); ?></span>
              <span class="role-note"><?= e($card['note']); ?></span>
            </button>
          </form>
        <?php endforeach; ?>
      </div>

      <footer class="role-footer">
        <form method="post" action="<?= e(app_link('logout.php')); ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
          <button class="role-logout" type="submit">Log Out</button>
        </form>
      </footer>
    </section>
  </main>
</body>
</html>
