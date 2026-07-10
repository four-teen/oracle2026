<?php

declare(strict_types=1);

final class CoordinatorPage
{
    public static function render(array $options = []): void
    {
        Auth::requireResearchCoordinator();

        $title = isset($options['title']) ? (string) $options['title'] : 'Research Coordinator';
        $currentPage = isset($options['current_page']) ? (string) $options['current_page'] : 'dashboard';
        $mainContent = isset($options['main_content']) ? (string) $options['main_content'] : '';
        $extraHead = isset($options['extra_head']) ? trim((string) $options['extra_head']) : '';
        $extraStyles = isset($options['extra_styles']) ? trim((string) $options['extra_styles']) : '';
        $extraScripts = isset($options['extra_scripts']) ? trim((string) $options['extra_scripts']) : '';
        $campusLabel = isset($options['campus_label']) ? trim((string) $options['campus_label']) : self::campusLabel();
        $viewer = self::viewerIdentity();
        $pageContext = self::pageContext($currentPage, $title);
        $select2CssPath = dirname(__DIR__) . '/assets/vendor/libs/select2/select2.min.css';
        $select2JsPath = dirname(__DIR__) . '/assets/vendor/libs/select2/select2.min.js';
        $select2CssVersion = is_file($select2CssPath) ? (string) filemtime($select2CssPath) : '1';
        $select2JsVersion = is_file($select2JsPath) ? (string) filemtime($select2JsPath) : '1';

        ?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="<?= e(app_link('assets/')); ?>"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title><?= e($title); ?></title>
    <meta name="description" content="Oracle research coordinator workspace" />
    <link rel="icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.png')); ?>" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700&family=Space+Grotesk:wght@500;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/fonts/boxicons.css')); ?>" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/libs/select2/select2.min.css') . '?v=' . $select2CssVersion); ?>" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/core.css')); ?>" class="template-customizer-core-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/theme-default.css')); ?>" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/css/demo.css')); ?>" />
<?php if ($extraHead !== ''): ?>
<?= $extraHead . "\n"; ?>
<?php endif; ?>
    <script src="<?= e(app_link('assets/vendor/js/helpers.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/config.js')); ?>"></script>
    <style>
      body {
        background: #f6f8fb;
        color: #283027;
        font-family: "Public Sans", "Segoe UI", Arial, sans-serif;
      }

      .coordinator-shell {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 17.5rem minmax(0, 1fr);
      }

      .coordinator-sidebar {
        position: sticky;
        top: 0;
        height: 100vh;
        padding: 1.25rem 1rem;
        background: #ffffff;
        border-right: 1px solid #e5e7eb;
        overflow-y: auto;
      }

      .coordinator-brand {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.85rem;
        border-radius: 0.9rem;
        color: #111827;
        text-decoration: none;
      }

      .coordinator-brand-mark {
        width: 2.75rem;
        height: 2.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.85rem;
        background: #15803d;
        color: #ffffff;
        font-size: 1.35rem;
      }

      .coordinator-brand-title {
        display: block;
        font-size: 1.05rem;
        font-family: "Space Grotesk", "Public Sans", sans-serif;
        font-weight: 700;
        letter-spacing: -0.03em;
      }

      .coordinator-brand-subtitle {
        display: block;
        color: #6b7280;
        font-size: 0.76rem;
      }

      .coordinator-menu {
        display: grid;
        gap: 0.7rem;
        margin-top: 1.25rem;
      }

      .coordinator-menu-link {
        display: flex;
        align-items: flex-start;
        gap: 0.8rem;
        padding: 0.85rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.85rem;
        background: #ffffff;
        color: #374151;
        text-decoration: none;
      }

      .coordinator-menu-link:hover,
      .coordinator-menu-link.active {
        color: #14532d;
        border-color: #bbf7d0;
        background: #f0fdf4;
      }

      .coordinator-menu-icon {
        width: 2.2rem;
        height: 2.2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 2.2rem;
        border-radius: 0.75rem;
        background: #ecfdf5;
        color: #15803d;
        font-size: 1.1rem;
      }

      .coordinator-menu-title {
        display: block;
        font-weight: 600;
        line-height: 1.25;
      }

      .coordinator-menu-note {
        display: block;
        margin-top: 0.18rem;
        color: #6b7280;
        font-size: 0.78rem;
        line-height: 1.35;
      }

      .coordinator-main {
        min-width: 0;
      }

      .coordinator-topbar {
        position: sticky;
        top: 0;
        z-index: 20;
        min-height: 5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.5rem;
        background: rgba(246, 248, 251, 0.94);
        backdrop-filter: blur(14px);
        border-bottom: 1px solid #e5e7eb;
      }

      .coordinator-page-kicker {
        margin: 0 0 0.15rem;
        color: #15803d;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .coordinator-page-title {
        margin: 0;
        color: #111827;
        font-size: 1.28rem;
        font-family: "Space Grotesk", "Public Sans", sans-serif;
        font-weight: 700;
        letter-spacing: -0.035em;
      }

      .coordinator-user {
        display: flex;
        align-items: center;
        gap: 0.85rem;
      }

      .coordinator-user-copy {
        text-align: right;
      }

      .coordinator-user-name {
        display: block;
        color: #111827;
        font-weight: 600;
      }

      .coordinator-user-campus {
        display: block;
        color: #6b7280;
        font-size: 0.82rem;
      }

      .coordinator-avatar {
        width: 2.65rem;
        height: 2.65rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #dcfce7;
        color: #15803d;
        font-weight: 600;
      }

      .coordinator-content {
        padding: 1.5rem;
      }

      .coordinator-footer {
        padding: 0 1.5rem 1.5rem;
        color: #6b7280;
        font-size: 0.86rem;
      }

      .coordinator-logout-form {
        margin: 1rem 0 0;
      }

      .coordinator-logout-btn {
        width: 100%;
        min-height: 2.65rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.8rem;
        background: #ffffff;
        color: #374151;
        font-weight: 600;
      }

      .coordinator-logout-btn:hover {
        background: #f9fafb;
      }

      .coord-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.85rem;
        background: #ffffff;
        box-shadow: 0 0.25rem 1rem rgba(15, 23, 42, 0.05);
      }

      .coord-muted {
        color: #6b7280;
      }

      .coord-btn-primary,
      .coord-btn-secondary,
      .coord-btn-danger {
        min-height: 2.55rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.55rem 0.9rem;
        border-radius: 0.65rem;
        border: 1px solid transparent;
        font-weight: 600;
        text-decoration: none;
      }

      .coord-btn-primary {
        color: #ffffff;
        background: #15803d;
      }

      .coord-btn-primary:hover {
        color: #ffffff;
        background: #166534;
      }

      .coord-btn-secondary {
        color: #14532d;
        background: #f0fdf4;
        border-color: #bbf7d0;
      }

      .coord-btn-danger {
        color: #991b1b;
        background: #fef2f2;
        border-color: #fecaca;
      }

      .coord-alert {
        padding: 0.9rem 1rem;
        border-radius: 0.85rem;
        margin-bottom: 1rem;
        font-weight: 600;
      }

      .coord-alert.success {
        color: #166534;
        background: #dcfce7;
      }

      .coord-alert.error {
        color: #991b1b;
        background: #fee2e2;
      }

      .swal2-container {
        z-index: 20000 !important;
      }

      .swal2-popup {
        border-radius: 0.9rem !important;
      }

      @media (max-width: 991.98px) {
        .coordinator-shell {
          display: block;
        }

        .coordinator-sidebar {
          position: static;
          height: auto;
        }

        .coordinator-menu {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 575.98px) {
        .coordinator-topbar,
        .coordinator-user {
          align-items: flex-start;
          flex-direction: column;
        }

        .coordinator-user-copy {
          text-align: left;
        }

        .coordinator-menu {
          grid-template-columns: 1fr;
        }
      }

<?= $extraStyles !== '' ? $extraStyles . "\n" : ''; ?>    </style>
  </head>
  <body>
    <div class="coordinator-shell">
      <aside class="coordinator-sidebar">
        <a href="<?= e(app_link('coordinator/')); ?>" class="coordinator-brand">
          <span class="coordinator-brand-mark"><i class="bx bx-network-chart"></i></span>
          <span>
            <span class="coordinator-brand-title">Oracle</span>
            <span class="coordinator-brand-subtitle">Research Coordinator</span>
          </span>
        </a>

        <nav class="coordinator-menu" aria-label="Research coordinator navigation">
          <?= self::menuLink('dashboard', $currentPage, 'bx-line-chart', 'Analytics Dashboard', 'Campus output and program trends', 'coordinator/'); ?>
          <?= self::menuLink('research', $currentPage, 'bx-edit-alt', 'Manuscript Management', 'Encode and update campus records', 'coordinator/research.php'); ?>
        </nav>

        <form class="coordinator-logout-form" method="post" action="<?= e(app_link('logout.php')); ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
          <button type="submit" class="coordinator-logout-btn">
            <i class="bx bx-power-off"></i>
            Log Out
          </button>
        </form>
      </aside>

      <main class="coordinator-main">
        <header class="coordinator-topbar">
          <div>
            <p class="coordinator-page-kicker"><?= e($pageContext['section']); ?></p>
            <h1 class="coordinator-page-title"><?= e($pageContext['title']); ?></h1>
          </div>
          <div class="coordinator-user">
            <span class="coordinator-user-copy">
              <span class="coordinator-user-name"><?= e($viewer['name']); ?></span>
              <span class="coordinator-user-campus"><?= e($campusLabel); ?></span>
            </span>
            <span class="coordinator-avatar"><?= e($viewer['initial']); ?></span>
          </div>
        </header>

        <div class="coordinator-content">
<?= $mainContent !== '' ? $mainContent . "\n" : ''; ?>
        </div>

        <footer class="coordinator-footer">
          Oracle coordinator workspace for campus research monitoring and manuscript encoding.
        </footer>
      </main>
    </div>

    <script src="<?= e(app_link('assets/vendor/libs/jquery/jquery.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/libs/select2/select2.min.js') . '?v=' . $select2JsVersion); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/js/bootstrap.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/main.js')); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script>
      (function () {
        var validationAlertShown = false;

        function fieldLabel(field) {
          var label = field.closest ? field.closest("label") : null;
          var labelText = "";

          if (label) {
            var labelCaption = label.querySelector("span");
            labelText = labelCaption ? labelCaption.textContent.trim() : label.textContent.trim();
          }

          return labelText || field.getAttribute("aria-label") || field.name || "this field";
        }

        function fallbackAlerts() {
          document.querySelectorAll("[data-coord-swal]").forEach(function (element) {
            element.hidden = false;
          });
        }

        function showPageAlerts(index, alerts) {
          if (index >= alerts.length) {
            return;
          }

          var alert = alerts[index];
          window.Swal.fire({
            target: document.body,
            icon: alert.dataset.swalIcon || "info",
            title: alert.dataset.swalTitle || "",
            text: alert.dataset.swalText || alert.textContent.trim(),
            confirmButtonColor: "#15803d"
          }).then(function () {
            showPageAlerts(index + 1, alerts);
          });
        }

        document.addEventListener("DOMContentLoaded", function () {
          var alerts = Array.prototype.slice.call(document.querySelectorAll("[data-coord-swal]"));

          if (!window.Swal) {
            fallbackAlerts();
            return;
          }

          alerts.forEach(function (element) {
            element.hidden = true;
          });

          showPageAlerts(0, alerts);
        });

        document.addEventListener("invalid", function (event) {
          if (!window.Swal || validationAlertShown) {
            return;
          }

          var field = event.target;

          if (!field || !field.closest || !field.closest("form")) {
            return;
          }

          event.preventDefault();
          validationAlertShown = true;

          window.Swal.fire({
            target: document.body,
            icon: "warning",
            title: "Complete Required Field",
            text: "Please complete " + fieldLabel(field) + ".",
            confirmButtonColor: "#15803d"
          }).then(function () {
            validationAlertShown = false;

            if (field.focus) {
              field.focus();
            }
          });
        }, true);

        document.addEventListener("submit", function (event) {
          var form = event.target;

          if (!form || !form.matches || !form.matches("[data-coord-confirm]")) {
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
            target: document.body,
            icon: form.dataset.confirmIcon || "warning",
            title: form.dataset.confirmTitle || "Are you sure?",
            text: form.dataset.confirmText || "This action cannot be undone.",
            showCancelButton: true,
            confirmButtonText: form.dataset.confirmButton || "Yes, continue",
            cancelButtonText: form.dataset.cancelButton || "Cancel",
            confirmButtonColor: "#dc2626",
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
            focusCancel: true
          }).then(function (result) {
            if (result.isConfirmed) {
              form.dataset.confirmed = "1";

              window.Swal.fire({
                target: document.body,
                title: form.dataset.progressTitle || "Processing...",
                text: form.dataset.progressText || "Please wait.",
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
            }
          });
        });
      })();
    </script>
<?php if ($extraScripts !== ''): ?>
<?= $extraScripts . "\n"; ?>
<?php endif; ?>
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

        return '<a class="coordinator-menu-link' . e($active) . '" href="' . e(app_link($path)) . '">'
            . '<span class="coordinator-menu-icon"><i class="bx ' . e($icon) . '"></i></span>'
            . '<span><span class="coordinator-menu-title">' . e($title) . '</span>'
            . '<span class="coordinator-menu-note">' . e($note) . '</span></span>'
            . '</a>';
    }

    private static function viewerIdentity(): array
    {
        $account = Auth::account();
        $googleUser = Auth::googleUser();
        $name = trim((string) ($googleUser['name'] ?? $account['acc_name'] ?? 'Research Coordinator'));
        $email = trim((string) ($googleUser['email'] ?? $account['email'] ?? ''));

        return [
            'name' => $name !== '' ? $name : ($email !== '' ? $email : 'Research Coordinator'),
            'initial' => self::initial($name !== '' ? $name : 'R'),
        ];
    }

    private static function campusLabel(): string
    {
        $campusId = Auth::campusId();

        if ($campusId < 1) {
            return 'Campus not assigned';
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT campusname
                 FROM tblcampus
                 WHERE campusid = :campusid
                 LIMIT 1'
            );
            $statement->bindValue(':campusid', $campusId, PDO::PARAM_INT);
            $statement->execute();
            $campusName = trim((string) $statement->fetchColumn());

            return $campusName !== '' ? $campusName : 'Campus #' . $campusId;
        } catch (Throwable $exception) {
            return 'Campus #' . $campusId;
        }
    }

    private static function pageContext(string $currentPage, string $title): array
    {
        if ($currentPage === 'research') {
            return [
                'title' => 'Manuscript Management',
                'section' => 'Campus Records',
            ];
        }

        return [
            'title' => $title !== '' ? $title : 'Campus Analytics Dashboard',
            'section' => 'Research Analytics',
        ];
    }

    private static function initial(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'R';
        }

        if (function_exists('mb_substr')) {
            return strtoupper((string) mb_substr($trimmed, 0, 1, 'UTF-8'));
        }

        return strtoupper(substr($trimmed, 0, 1));
    }
}
