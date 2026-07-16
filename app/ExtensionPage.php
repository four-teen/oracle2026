<?php

declare(strict_types=1);

final class ExtensionPage
{
    public static function render(array $options = []): void
    {
        Auth::requireExtensionCoordinator();

        $title = isset($options['title']) ? (string) $options['title'] : 'Extension Coordinator';
        $currentPage = isset($options['current_page']) ? (string) $options['current_page'] : 'projects';
        $mainContent = isset($options['main_content']) ? (string) $options['main_content'] : '';
        $extraStyles = isset($options['extra_styles']) ? trim((string) $options['extra_styles']) : '';
        $extraScripts = isset($options['extra_scripts']) ? trim((string) $options['extra_scripts']) : '';
        $viewer = self::viewerIdentity();
        $pageContext = self::pageContext($currentPage, $title);

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title); ?></title>
  <meta name="description" content="Oracle extension coordinator workspace">
  <link rel="icon" type="image/png" sizes="64x64" href="<?= e(app_link('assets/img/favicon/oracle-favicon.png')); ?>">
  <link rel="shortcut icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.ico')); ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(app_link('assets/img/favicon/apple-touch-icon.png')); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700&family=Space+Grotesk:wght@500;700&display=swap"
    rel="stylesheet"
  >
  <link rel="stylesheet" href="<?= e(app_link('assets/vendor/fonts/boxicons.css')); ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: #f6f8fb;
      color: #283027;
      font-family: "Public Sans", "Segoe UI", Arial, sans-serif;
    }

    a {
      color: inherit;
    }

    .extension-shell {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 276px minmax(0, 1fr);
    }

    .extension-sidebar {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 20px 16px;
      border-right: 1px solid #dbe4ef;
      background: #ffffff;
      overflow-y: auto;
    }

    .extension-brand {
      display: flex;
      gap: 12px;
      align-items: center;
      padding: 12px;
      border-radius: 8px;
      text-decoration: none;
    }

    .extension-brand-mark {
      width: 44px;
      height: 44px;
      display: block;
      flex: 0 0 44px;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 5px 12px rgba(15, 118, 110, 0.18);
    }

    .extension-brand-mark img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: cover;
    }

    .extension-brand-title {
      display: block;
      color: #111827;
      font-size: 18px;
      font-family: "Space Grotesk", "Public Sans", sans-serif;
      font-weight: 700;
      line-height: 1.1;
      letter-spacing: 0;
    }

    .extension-brand-note {
      display: block;
      margin-top: 3px;
      color: #64748b;
      font-size: 12px;
    }

    .extension-menu {
      display: grid;
      gap: 10px;
      margin-top: 22px;
    }

    .extension-menu-link {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 13px;
      border: 1px solid #dbe4ef;
      border-radius: 8px;
      background: #fff;
      text-decoration: none;
    }

    .extension-menu-link.active,
    .extension-menu-link:hover {
      border-color: #99f6e4;
      background: #f0fdfa;
      color: #0f766e;
    }

    .extension-menu-icon {
      width: 36px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 36px;
      border-radius: 8px;
      background: #ccfbf1;
      color: #0f766e;
      font-size: 19px;
    }

    .extension-menu-title {
      display: block;
      font-weight: 800;
      line-height: 1.25;
    }

    .extension-menu-note {
      display: block;
      margin-top: 3px;
      color: #64748b;
      font-size: 12px;
      line-height: 1.4;
    }

    .extension-logout-form {
      margin: 20px 0 0;
    }

    .extension-logout-btn {
      width: 100%;
      min-height: 42px;
      border: 1px solid #dbe4ef;
      border-radius: 8px;
      background: #ffffff;
      color: #334155;
      font-weight: 800;
      cursor: pointer;
    }

    .extension-main {
      min-width: 0;
    }

    .extension-topbar {
      min-height: 82px;
      position: sticky;
      top: 0;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 26px;
      border-bottom: 1px solid #dbe4ef;
      background: rgba(247, 250, 252, 0.94);
      backdrop-filter: blur(14px);
    }

    .extension-kicker {
      margin: 0 0 4px;
      color: #0f766e;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .extension-page-title {
      margin: 0;
      color: #111827;
      font-size: 22px;
      font-family: "Space Grotesk", "Public Sans", sans-serif;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: 0;
    }

    .extension-user {
      display: flex;
      align-items: center;
      gap: 12px;
      text-align: right;
    }

    .extension-user-name {
      display: block;
      color: #111827;
      font-weight: 800;
    }

    .extension-user-role {
      display: block;
      color: #64748b;
      font-size: 13px;
    }

    .extension-avatar {
      width: 42px;
      height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      background: #ccfbf1;
      color: #0f766e;
      font-weight: 800;
    }

    .extension-content {
      padding: 26px;
    }

    .extension-footer {
      padding: 0 26px 24px;
      color: #64748b;
      font-size: 13px;
    }

    .extension-card {
      border: 1px solid #dbe4ef;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04);
    }

    .extension-muted {
      color: #64748b;
    }

    .extension-btn,
    .extension-btn-secondary,
    .extension-btn-danger {
      min-height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      padding: 0 15px;
      border-radius: 8px;
      border: 1px solid transparent;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
    }

    .extension-btn {
      color: #ffffff;
      background: #0f766e;
    }

    .extension-btn-secondary {
      color: #0f766e;
      background: #f0fdfa;
      border-color: #99f6e4;
    }

    .extension-btn-danger {
      color: #991b1b;
      background: #fef2f2;
      border-color: #fecaca;
    }

    .extension-alert {
      padding: 14px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-weight: 700;
      line-height: 1.5;
    }

    .extension-alert.success {
      color: #0f5132;
      background: #d1fae5;
    }

    .extension-alert.error {
      color: #991b1b;
      background: #fee2e2;
    }

    .swal2-popup {
      border-radius: 8px !important;
    }

    .swal2-container {
      z-index: 20000 !important;
    }

    @media (max-width: 991.98px) {
      .extension-shell {
        display: block;
      }

      .extension-sidebar {
        position: static;
        height: auto;
      }

      .extension-menu {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .extension-topbar,
      .extension-user {
        align-items: flex-start;
        flex-direction: column;
      }

      .extension-user {
        text-align: left;
      }

      .extension-menu {
        grid-template-columns: 1fr;
      }
    }

<?= $extraStyles !== '' ? $extraStyles . "\n" : ''; ?>  </style>
</head>
<body>
  <div class="extension-shell">
    <aside class="extension-sidebar">
      <a class="extension-brand" href="<?= e(app_link('extension/')); ?>">
        <span class="extension-brand-mark">
          <img
            src="<?= e(app_link('assets/img/branding/oracle-logo.png')); ?>"
            alt=""
            width="512"
            height="512"
            aria-hidden="true"
          >
        </span>
        <span>
          <span class="extension-brand-title">Oracle</span>
          <span class="extension-brand-note">Extension Coordinator</span>
        </span>
      </a>

      <nav class="extension-menu" aria-label="Extension navigation">
        <?= self::menuLink('dashboard', $currentPage, 'bx-network-chart', 'Analytics Dashboard', 'Monitor project status, partners, funds, and SDGs.', 'extension/'); ?>
        <?= self::menuLink('projects', $currentPage, 'bx-network-chart', 'Project Registry', 'Manage proposals, partners, budgets, and outputs.', 'extension/projects.php'); ?>
      </nav>

      <form class="extension-logout-form" method="post" action="<?= e(app_link('logout.php')); ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
        <button class="extension-logout-btn" type="submit">
          <i class="bx bx-power-off"></i>
          Log Out
        </button>
      </form>
    </aside>

    <main class="extension-main">
      <header class="extension-topbar">
        <div>
          <p class="extension-kicker"><?= e($pageContext['section']); ?></p>
          <h1 class="extension-page-title"><?= e($pageContext['title']); ?></h1>
        </div>
        <div class="extension-user">
          <span>
            <span class="extension-user-name"><?= e($viewer['name']); ?></span>
            <span class="extension-user-role">Extension Coordinator</span>
          </span>
          <span class="extension-avatar"><?= e($viewer['initial']); ?></span>
        </div>
      </header>

      <div class="extension-content">
<?= $mainContent !== '' ? $mainContent . "\n" : ''; ?>
      </div>

      <footer class="extension-footer">
        Oracle extension coordinator workspace for project proposal recording and monitoring.
      </footer>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script>
    document.addEventListener("submit", function (event) {
      var form = event.target;

      if (!form || !form.matches || !form.matches("[data-extension-confirm]")) {
        return;
      }

      if (form.dataset.confirmed === "1") {
        return;
      }

      event.preventDefault();

      if (!window.Swal) {
        form.dataset.confirmed = "1";
        form.submit();
        return;
      }

      window.Swal.fire({
        icon: form.dataset.confirmIcon || "warning",
        title: form.dataset.confirmTitle || "Are you sure?",
        text: form.dataset.confirmText || "This action cannot be undone.",
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton || "Yes, continue",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#dc2626",
        cancelButtonColor: "#64748b",
        reverseButtons: true,
        focusCancel: true
      }).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        form.dataset.confirmed = "1";
        window.Swal.fire({
          title: "Processing...",
          text: "Please wait.",
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: function () {
            window.Swal.showLoading();
          }
        });
        window.setTimeout(function () {
          form.submit();
        }, 0);
      });
    });
  </script>
<?= $extraScripts !== '' ? $extraScripts . "\n" : ''; ?>
</body>
</html>
<?php
    }

    private static function menuLink(
        string $key,
        string $currentPage,
        string $icon,
        string $title,
        string $note,
        string $path
    ): string {
        $active = $key === $currentPage ? ' active' : '';

        return '<a class="extension-menu-link' . e($active) . '" href="' . e(app_link($path)) . '">'
            . '<span class="extension-menu-icon"><i class="bx ' . e($icon) . '"></i></span>'
            . '<span><span class="extension-menu-title">' . e($title) . '</span>'
            . '<span class="extension-menu-note">' . e($note) . '</span></span>'
            . '</a>';
    }

    private static function viewerIdentity(): array
    {
        $account = Auth::account();
        $googleUser = Auth::googleUser();
        $name = trim((string) ($googleUser['name'] ?? $account['acc_name'] ?? 'Extension Coordinator'));
        $email = trim((string) ($googleUser['email'] ?? $account['email'] ?? ''));

        return [
            'name' => $name !== '' ? $name : ($email !== '' ? $email : 'Extension Coordinator'),
            'initial' => self::initial($name !== '' ? $name : 'E'),
        ];
    }

    private static function pageContext(string $currentPage, string $title): array
    {
        if ($currentPage === 'projects') {
            return [
                'title' => 'Extension Project Registry',
                'section' => 'Extension Projects',
            ];
        }

        return [
            'title' => $title !== '' ? $title : 'Extension Analytics Dashboard',
            'section' => 'Extension Analytics',
        ];
    }

    private static function initial(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'E';
        }

        if (function_exists('mb_substr')) {
            return strtoupper((string) mb_substr($trimmed, 0, 1, 'UTF-8'));
        }

        return strtoupper(substr($trimmed, 0, 1));
    }
}
