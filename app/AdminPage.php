<?php

declare(strict_types=1);

final class AdminPage
{
    public static function render(array $options = []): void
    {
        Auth::requireLogin();

        $title = isset($options['title']) ? (string) $options['title'] : 'Administrator';
        $currentPage = isset($options['current_page']) ? (string) $options['current_page'] : 'dashboard';
        $mainContent = isset($options['main_content']) ? (string) $options['main_content'] : '';
        $extraHead = isset($options['extra_head']) ? trim((string) $options['extra_head']) : '';
        $extraScripts = isset($options['extra_scripts']) ? trim((string) $options['extra_scripts']) : '';
        $extraStyles = isset($options['extra_styles']) ? trim((string) $options['extra_styles']) : '';

        $viewer = self::viewerIdentity();
        $brandUrl = app_link('administrator/');
        $logoutAction = app_link('logout.php');

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
    <meta name="description" content="Sneat-based Oracle administration" />
    <link rel="icon" type="image/x-icon" href="<?= e(app_link('assets/img/favicon/favicon.ico')); ?>" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/fonts/boxicons.css')); ?>" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/core.css')); ?>" class="template-customizer-core-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/css/theme-default.css')); ?>" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="<?= e(app_link('assets/css/demo.css')); ?>" />
    <link rel="stylesheet" href="<?= e(app_link('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')); ?>" />
    <script src="<?= e(app_link('assets/vendor/js/helpers.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/config.js')); ?>"></script>
    <style>
      .app-brand-text {
        letter-spacing: 0.02em;
      }

      .admin-card-menu {
        padding: 1rem 0.9rem 1.15rem;
        background:
          radial-gradient(circle at top left, rgba(105, 108, 255, 0.14), transparent 30%),
          linear-gradient(180deg, #f7f8ff 0%, #eef2ff 100%);
        border-right: 0;
        box-shadow: 0 0.35rem 1.4rem rgba(67, 89, 113, 0.12);
        overflow-x: hidden;
      }

      .admin-card-menu .menu-inner-shadow {
        display: none;
        top: 6.1rem;
        left: 0.9rem;
        right: 0.9rem;
        width: auto;
        height: 1.5rem;
        border-radius: 1rem 1rem 0 0;
        background: linear-gradient(180deg, rgba(238, 242, 255, 0.98) 38%, rgba(238, 242, 255, 0) 100%);
      }

      .admin-card-menu .app-brand {
        height: auto;
        margin: 0 0 1rem;
        padding: 1rem;
        border-radius: 1.25rem;
        background: rgba(255, 255, 255, 0.9);
        box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.08);
      }

      .admin-card-menu .app-brand-link {
        display: flex;
        align-items: center;
        gap: 0.85rem;
      }

      .admin-card-menu .app-brand-logo.demo {
        margin-right: 0;
      }

      .admin-card-menu .app-brand-text.demo {
        font-size: 1.35rem;
        line-height: 1.1;
      }

      .admin-card-menu .layout-menu-toggle {
        border-width: 5px;
      }

      .admin-card-menu.menu-vertical .menu-inner {
        padding: 0.35rem 0.55rem 1rem 0 !important;
        overflow: visible;
        width: 100%;
        box-sizing: border-box;
      }

      .admin-card-menu.menu-vertical .menu-inner > .menu-item,
      .admin-card-menu.menu-vertical .menu-inner > .menu-header {
        margin: 0 0 0.85rem;
        width: 100%;
      }

      .admin-card-menu.menu-vertical .menu-inner > .menu-item:last-child {
        margin-bottom: 0;
      }

      .admin-card-menu.menu-vertical .menu-item .menu-link {
        margin: 0;
        padding: 0.95rem 1rem;
        border-radius: 1.1rem;
        border: 1px solid rgba(105, 108, 255, 0.16);
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 0.35rem 0.9rem rgba(67, 89, 113, 0.05);
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
        min-height: auto;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        white-space: normal;
        transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
      }

      .admin-card-menu.menu-vertical .menu-item .menu-link:hover {
        transform: translateY(-1px);
        background: #fff;
        box-shadow: 0 0.5rem 1rem rgba(67, 89, 113, 0.08);
      }

      .admin-card-menu.menu-vertical .menu-item .menu-toggle {
        padding-right: 3rem;
      }

      .admin-card-menu.menu-vertical .menu-item .menu-toggle::after {
        right: 1rem;
      }

      .admin-card-menu .menu-card-icon {
        width: 2.75rem;
        height: 2.75rem;
        flex: 0 0 2.75rem;
        border-radius: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(105, 108, 255, 0.12);
        color: #696cff;
        box-shadow: inset 0 0 0 1px rgba(105, 108, 255, 0.08);
      }

      .admin-card-menu .menu-card-icon-sub {
        width: 2.35rem;
        height: 2.35rem;
        flex-basis: 2.35rem;
        border-radius: 0.8rem;
      }

      .admin-card-menu .menu-card-icon .menu-icon,
      .admin-card-menu .menu-card-icon i {
        width: auto;
        margin: 0;
        font-size: 1.25rem;
      }

      .admin-card-menu .menu-card-copy {
        min-width: 0;
        flex: 1 1 auto;
        width: 100%;
        max-width: 100%;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
      }

      .admin-card-menu .menu-card-title {
        display: block;
        color: #566a7f;
        font-size: 0.95rem;
        font-weight: 700;
        line-height: 1.25;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
      }

      .admin-card-menu .menu-card-note {
        display: block;
        color: #8592a3;
        font-size: 0.78rem;
        line-height: 1.45;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
      }

      .admin-card-menu.menu-vertical .menu-header {
        margin: 0.2rem 0 0.55rem;
        padding: 0 0.25rem;
      }

      .admin-card-menu .menu-header::before {
        display: none;
      }

      .admin-card-menu .menu-header-text {
        display: inline-flex;
        align-items: center;
        min-height: 1.85rem;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        background: rgba(105, 108, 255, 0.1);
        color: #696cff;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.08em;
      }

      .admin-card-menu .menu-sub {
        margin: 0.7rem 0 0;
        padding: 0;
        width: 100%;
      }

      .admin-card-menu .menu-item.open > .menu-sub {
        display: grid;
        gap: 0.65rem;
      }

      .admin-card-menu .menu-sub > .menu-item {
        width: 100%;
      }

      .admin-card-menu .menu-sub > .menu-item > .menu-link {
        padding: 0.85rem 0.95rem;
        background: rgba(247, 248, 255, 0.94);
        border-color: rgba(105, 108, 255, 0.16);
        box-shadow: none;
      }

      .admin-card-menu .ps__rail-y {
        right: 0.1rem !important;
        width: 0.3rem !important;
        background: transparent !important;
      }

      .admin-card-menu .ps__thumb-y {
        right: 0 !important;
        width: 0.3rem !important;
        background-color: rgba(105, 108, 255, 0.32) !important;
      }

      .admin-card-menu .ps__rail-y:hover,
      .admin-card-menu .ps__rail-y:focus,
      .admin-card-menu .ps__rail-y.ps--clicking {
        background-color: rgba(105, 108, 255, 0.08) !important;
      }

      .admin-card-menu .ps__rail-y:hover > .ps__thumb-y,
      .admin-card-menu .ps__rail-y:focus > .ps__thumb-y,
      .admin-card-menu .ps__rail-y.ps--clicking > .ps__thumb-y {
        width: 0.35rem !important;
        background-color: rgba(105, 108, 255, 0.48) !important;
      }

      .admin-card-menu.bg-menu-theme .menu-sub > .menu-item > .menu-link::before,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active::before {
        display: none;
      }

      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active > .menu-link,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.open > .menu-link.menu-toggle {
        background: linear-gradient(135deg, #696cff 0%, #8a8dff 100%) !important;
        border-color: transparent;
        box-shadow: 0 1rem 2rem rgba(105, 108, 255, 0.26);
      }

      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active > .menu-link .menu-card-icon,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.open > .menu-link.menu-toggle .menu-card-icon {
        background: rgba(255, 255, 255, 0.18);
        color: #fff;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12);
      }

      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active > .menu-link .menu-card-title,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active > .menu-link .menu-card-note,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.open > .menu-link.menu-toggle .menu-card-title,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.open > .menu-link.menu-toggle .menu-card-note,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.active > .menu-link::after,
      .admin-card-menu.bg-menu-theme .menu-inner > .menu-item.open > .menu-link.menu-toggle::after {
        color: #fff !important;
      }

      .admin-card-menu.bg-menu-theme .menu-sub > .menu-item.active > .menu-link {
        background: rgba(105, 108, 255, 0.14) !important;
        border-color: rgba(105, 108, 255, 0.18);
        box-shadow: none;
      }

      .admin-card-menu.bg-menu-theme .menu-sub > .menu-item.active > .menu-link .menu-card-title {
        color: #4f56d9;
      }

      .admin-card-menu.bg-menu-theme .menu-sub > .menu-item.active > .menu-link .menu-card-note {
        color: #6f76e8;
      }

      .app-page-shell {
        min-height: calc(100vh - 70px);
      }

      .app-page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
      }

      .app-page-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #696cff;
      }

      .app-page-copy {
        color: #8592a3;
        margin-bottom: 0;
        max-width: 48rem;
      }

      .metric-card,
      .surface-card {
        border: 0;
        border-radius: 1rem;
        box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12);
      }

      .metric-card .metric-icon {
        width: 3rem;
        height: 3rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.75rem;
        font-size: 1.4rem;
      }

      .metric-card .metric-label,
      .muted-copy,
      .helper-copy {
        color: #8592a3;
      }

      .surface-card .card-header {
        border-bottom: 1px solid #eceef1;
        background: transparent;
      }

      .page-alert {
        border: 0;
        border-radius: 0.9rem;
        padding: 0.95rem 1rem;
        margin-bottom: 1rem;
      }

      .page-alert.success {
        color: #1f7a52;
        background: rgba(40, 199, 111, 0.16);
      }

      .page-alert.error {
        color: #b42318;
        background: rgba(255, 62, 29, 0.12);
      }

      .page-alert.warning {
        color: #9a6700;
        background: rgba(255, 171, 0, 0.16);
      }

      .app-table-responsive {
        overflow-x: auto;
      }

      .app-table {
        margin-bottom: 0;
        vertical-align: middle;
      }

      .app-table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #8592a3;
        white-space: nowrap;
      }

      .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 999px;
        padding: 0.38rem 0.7rem;
        font-size: 0.75rem;
        font-weight: 600;
      }

      .status-badge.success {
        color: #1f7a52;
        background: rgba(40, 199, 111, 0.14);
      }

      .status-badge.warning {
        color: #9a6700;
        background: rgba(255, 171, 0, 0.16);
      }

      .status-badge.danger {
        color: #b42318;
        background: rgba(255, 62, 29, 0.12);
      }

      .status-badge.info {
        color: #696cff;
        background: rgba(105, 108, 255, 0.14);
      }

      .dropdown-user-name {
        max-width: 12rem;
      }

      .avatar-initial {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        font-weight: 700;
      }

      .logout-menu-form {
        margin: 0;
      }

      .logout-menu-button {
        width: 100%;
        border: 0;
        background: transparent;
        text-align: left;
      }

      .app-footer-note {
        color: #8592a3;
        font-size: 0.875rem;
      }

      .main.users.chart-page .container {
        padding-top: 1.75rem;
        padding-bottom: 1.75rem;
      }

      .main-title-wrapper {
        margin-bottom: 1.5rem;
      }

      .main-title {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: #566a7f;
      }

      .white-block {
        background: #fff;
        border-radius: 1rem;
        box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12);
      }

      .white-block__title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: #566a7f;
      }

      .stat-cards {
        row-gap: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .stat-cards-item {
        height: 100%;
        padding: 1.25rem;
        border-radius: 1rem;
        background: #fff;
        box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12);
        display: flex;
        align-items: flex-start;
        gap: 1rem;
      }

      .stat-cards-icon {
        width: 3rem;
        height: 3rem;
        flex: 0 0 3rem;
        border-radius: 0.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .stat-cards-icon.primary { background: rgba(105, 108, 255, 0.16); color: #696cff; }
      .stat-cards-icon.success { background: rgba(40, 199, 111, 0.16); color: #1f7a52; }
      .stat-cards-icon.warning { background: rgba(255, 171, 0, 0.18); color: #9a6700; }
      .stat-cards-icon.purple { background: rgba(139, 92, 246, 0.16); color: #8e4ec6; }

      .stat-cards-info__num {
        margin: 0 0 0.25rem;
        font-size: 1.6rem;
        font-weight: 700;
        color: #566a7f;
      }

      .stat-cards-info__title {
        margin: 0;
        font-weight: 600;
        color: #566a7f;
      }

      .stat-cards-info__progress {
        margin: 0.3rem 0 0;
        color: #8592a3;
        font-size: 0.875rem;
      }

      .badge-active,
      .badge-disabled {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2rem;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
      }

      .badge-active {
        color: #1f7a52;
        background: rgba(40, 199, 111, 0.16);
      }

      .badge-disabled {
        color: #b42318;
        background: rgba(255, 62, 29, 0.12);
      }

      .form-label-wrapper {
        display: block;
      }

      .form-label {
        display: block;
        margin-bottom: 0.45rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #566a7f;
      }

      .form-input,
      textarea.form-input,
      .accounts-select,
      .research-types-select,
      .manuscripts-select {
        width: 100%;
        min-height: 2.75rem;
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        background: #fff;
        color: #566a7f;
        padding: 0.625rem 0.875rem;
      }

      .manuscripts-textarea {
        width: 100%;
        min-height: 7rem;
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        background: #fff;
        color: #566a7f;
        padding: 0.75rem 0.875rem;
      }

      .manuscripts-file-input {
        width: 100%;
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        background: #fff;
        color: #566a7f;
        padding: 0.625rem 0.875rem;
      }

      .primary-default-btn,
      .secondary-default-btn {
        min-height: 2.5rem;
        padding: 0.55rem 1rem;
        border-radius: 0.5rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid transparent;
      }

      .primary-default-btn {
        color: #fff;
        background: #696cff;
      }

      .primary-default-btn:hover {
        color: #fff;
        background: #5f62e6;
      }

      .secondary-default-btn {
        color: #566a7f;
        background: #fff;
        border-color: #d9dee3;
      }

      .secondary-default-btn:hover {
        color: #566a7f;
        background: #f5f5f9;
      }

      .sort-bar {
        margin-bottom: 1rem;
      }

      .search-wrapper {
        position: relative;
        display: block;
      }

      .search-wrapper i {
        position: absolute;
        left: 0.85rem;
        top: 50%;
        transform: translateY(-50%);
        color: #8592a3;
      }

      .search-wrapper .form-input {
        padding-left: 2.6rem;
      }

      .users-table.table-wrapper {
        background: #fff;
        border-radius: 1rem;
        box-shadow: 0 0.2rem 1rem rgba(67, 89, 113, 0.12);
        overflow: hidden;
      }

      .posts-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
      }

      .posts-table th,
      .posts-table td {
        padding: 0.95rem 1rem;
        border-bottom: 1px solid #eceef1;
        vertical-align: middle;
      }

      .posts-table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #8592a3;
        white-space: nowrap;
      }

      .posts-table tbody tr:last-child td {
        border-bottom: 0;
      }

      .users-table-info {
        background: #fcfcfd;
      }

      body {
        background: #ffffff;
        color: #374151;
        font-size: 13px;
      }

      .layout-wrapper,
      .layout-container,
      .layout-page,
      .content-wrapper,
      .content-footer,
      .bg-footer-theme {
        background: #ffffff !important;
      }

      .layout-page {
        min-height: 100vh;
      }

      .layout-navbar.navbar-detached {
        position: sticky;
        top: 0;
        z-index: 1030;
        margin: 0 !important;
        width: auto;
        max-width: none;
        border-radius: 0;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: none !important;
        background: #ffffff !important;
        backdrop-filter: none;
        min-height: 64px;
        padding: 0.65rem 1.35rem;
      }

      .layout-navbar .navbar-nav-right {
        width: 100%;
        min-height: 48px;
      }

      .layout-navbar .navbar-nav {
        gap: 0.35rem;
      }

      .layout-navbar .layout-menu-toggle .nav-link {
        width: 2.35rem;
        height: 2.35rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.8rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #374151;
        background: #ffffff;
      }

      .layout-navbar .layout-menu-toggle .nav-link:hover,
      .layout-navbar .layout-menu-toggle .nav-link:focus,
      .layout-navbar .nav-link:hover,
      .layout-navbar .nav-link:focus {
        background: #f9fafb;
        color: #111827;
      }

      .layout-navbar .layout-menu-toggle .nav-link,
      .layout-navbar .nav-link,
      .dropdown-menu,
      .dropdown-item {
        font-size: 0.84rem;
      }

      .dropdown-menu {
        border: 1px solid #e5e7eb;
        border-radius: 0.9rem;
        box-shadow: none;
      }

      .dropdown-item {
        padding: 0.55rem 0.9rem;
      }

      .layout-navbar .avatar {
        width: 2.2rem;
        height: 2.2rem;
      }

      .layout-navbar .avatar img,
      .layout-navbar .avatar .avatar-initial {
        width: 100%;
        height: 100%;
      }

      .layout-navbar .dropdown-user-name,
      .layout-navbar .text-muted {
        font-size: 0.82rem;
      }

      .app-page-shell {
        min-height: calc(100vh - 64px);
        background: #ffffff;
      }

      .container-xxl.flex-grow-1.container-p-y {
        max-width: none;
        padding-top: 1.35rem !important;
        padding-bottom: 2rem !important;
      }

      .app-page-header {
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .app-page-header h4 {
        margin-bottom: 0.35rem !important;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        font-size: 1.2rem;
        color: #111827;
      }

      .app-page-eyebrow {
        font-size: 0.64rem;
        letter-spacing: 0.06em;
        color: #15803d;
      }

      .app-page-copy,
      .muted-copy,
      .helper-copy,
      .app-footer-note {
        color: #6b7280;
        font-size: 0.82rem;
        line-height: 1.6;
      }

      .metric-card,
      .surface-card,
      .white-block,
      .stat-cards-item,
      .users-table.table-wrapper {
        border: 1px solid #e5e7eb;
        border-radius: 1rem;
        box-shadow: none;
        background: #ffffff;
      }

      .metric-card .card-body,
      .surface-card .card-body {
        padding: 1.1rem;
      }

      .surface-card .card-header {
        padding: 0.95rem 1.1rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .surface-card .card-header h5,
      .white-block__title {
        font-size: 0.92rem;
        color: #111827;
      }

      .metric-card .metric-label {
        font-size: 0.68rem;
        letter-spacing: 0.03em;
        text-transform: uppercase;
      }

      .metric-card h3 {
        font-size: 1.25rem;
        color: #111827;
      }

      .metric-card .metric-icon {
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 0.85rem;
      }

      .main-title {
        font-size: 1.16rem;
        color: #111827;
      }

      .app-table th,
      .posts-table th {
        font-size: 0.66rem;
      }

      .app-table td,
      .posts-table td,
      .form-label,
      .form-input,
      textarea.form-input,
      .accounts-select,
      .research-types-select,
      .manuscripts-select,
      .manuscripts-textarea,
      .manuscripts-file-input {
        font-size: 0.84rem;
      }

      .primary-default-btn,
      .secondary-default-btn,
      .btn {
        font-size: 0.84rem;
        border-radius: 0.7rem;
      }

      .page-alert {
        border-radius: 0.8rem;
      }

      .content-footer {
        border-top: 1px solid #e5e7eb;
      }

      .content-backdrop {
        background: transparent;
      }

      @media (max-width: 1199.98px) {
        .layout-navbar.navbar-detached {
          padding-left: 1rem;
          padding-right: 1rem;
        }
      }

      @media (max-width: 767.98px) {
        body {
          font-size: 13px;
        }

        .container-xxl.flex-grow-1.container-p-y {
          padding-left: 1rem;
          padding-right: 1rem;
        }

        .app-page-header {
          padding-bottom: 0.85rem;
        }
      }

<?= $extraStyles !== '' ? $extraStyles . "\n" : ''; ?>    </style>
<?php if ($extraHead !== ''): ?>
<?= $extraHead . "\n"; ?>
<?php endif; ?>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme admin-card-menu">
          <div class="app-brand demo">
            <a href="<?= e($brandUrl); ?>" class="app-brand-link">
              <span class="app-brand-logo demo">
                <svg width="25" viewBox="0 0 25 42" version="1.1" xmlns="http://www.w3.org/2000/svg">
                  <defs>
                    <path
                      d="M13.7918663,0.358365126 L3.39788168,7.44174259 C0.566865006,9.69408886 -0.379795268,12.4788597 0.557900856,15.7960551 C0.68998853,16.2305145 1.09562888,17.7872135 3.12357076,19.2293357 C3.8146334,19.7207684 5.32369333,20.3834223 7.65075054,21.2172976 L7.59773219,21.2525164 L2.63468769,24.5493413 C0.445452254,26.3002124 0.0884951797,28.5083815 1.56381646,31.1738486 C2.83770406,32.8170431 5.20850219,33.2640127 7.09180128,32.5391577 C8.347334,32.0559211 11.4559176,30.0011079 16.4175519,26.3747182 C18.0338572,24.4997857 18.6973423,22.4544883 18.4080071,20.2388261 C17.963753,17.5346866 16.1776345,15.5799961 13.0496516,14.3747546 L10.9194936,13.4715819 L18.6192054,7.984237 L13.7918663,0.358365126 Z"
                      id="path-1"
                    ></path>
                    <path
                      d="M5.47320593,6.00457225 C4.05321814,8.216144 4.36334763,10.0722806 6.40359441,11.5729822 C8.61520715,12.571656 10.0999176,13.2171421 10.8577257,13.5094407 L15.5088241,14.433041 L18.6192054,7.984237 C15.5364148,3.11535317 13.9273018,0.573395879 13.7918663,0.358365126 C13.5790555,0.511491653 10.8061687,2.3935607 5.47320593,6.00457225 Z"
                      id="path-3"
                    ></path>
                    <path
                      d="M7.50063644,21.2294429 L12.3234468,23.3159332 C14.1688022,24.7579751 14.397098,26.4880487 13.008334,28.506154 C11.6195701,30.5242593 10.3099883,31.790241 9.07958868,32.3040991 C5.78142938,33.4346997 4.13234973,34 4.13234973,34 C4.13234973,34 2.75489982,33.0538207 2.37032616e-14,31.1614621 C-0.55822714,27.8186216 -0.55822714,26.0572515 -4.05231404e-15,25.8773518 C0.83734071,25.6075023 2.77988457,22.8248993 3.3049379,22.52991 C3.65497346,22.3332504 5.05353963,21.8997614 7.50063644,21.2294429 Z"
                      id="path-4"
                    ></path>
                    <path
                      d="M20.6,7.13333333 L25.6,13.8 C26.2627417,14.6836556 26.0836556,15.9372583 25.2,16.6 C24.8538077,16.8596443 24.4327404,17 24,17 L14,17 C12.8954305,17 12,16.1045695 12,15 C12,14.5672596 12.1403557,14.1461923 12.4,13.8 L17.4,7.13333333 C18.0627417,6.24967773 19.3163444,6.07059163 20.2,6.73333333 C20.3516113,6.84704183 20.4862915,6.981722 20.6,7.13333333 Z"
                      id="path-5"
                    ></path>
                  </defs>
                  <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                    <g transform="translate(-27.000000, -15.000000)">
                      <g transform="translate(27.000000, 15.000000)">
                        <g transform="translate(0.000000, 8.000000)">
                          <mask id="mask-2" fill="white">
                            <use href="#path-1"></use>
                          </mask>
                          <use fill="#696cff" href="#path-1"></use>
                          <g mask="url(#mask-2)">
                            <use fill="#696cff" href="#path-3"></use>
                            <use fill-opacity="0.2" fill="#FFFFFF" href="#path-3"></use>
                          </g>
                          <g mask="url(#mask-2)">
                            <use fill="#696cff" href="#path-4"></use>
                            <use fill-opacity="0.2" fill="#FFFFFF" href="#path-4"></use>
                          </g>
                        </g>
                        <g transform="translate(19.000000, 11.000000) rotate(-300.000000) translate(-19.000000, -11.000000) ">
                          <use fill="#696cff" href="#path-5"></use>
                          <use fill-opacity="0.2" fill="#FFFFFF" href="#path-5"></use>
                        </g>
                      </g>
                    </g>
                  </g>
                </svg>
              </span>
              <span class="app-brand-text demo menu-text fw-bolder ms-2">Oracle</span>
            </a>
            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
              <i class="bx bx-chevron-left bx-sm align-middle"></i>
            </a>
          </div>
          <div class="menu-inner-shadow"></div>
<?= self::menuMarkup($currentPage); ?>
        </aside>

        <div class="layout-page">
          <nav
            class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
            id="layout-navbar"
          >
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
              <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
                <i class="bx bx-menu bx-sm"></i>
              </a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">
              <ul class="navbar-nav flex-row align-items-center ms-auto">
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
<?php if ($viewer['avatar_url'] !== ''): ?>
                      <img src="<?= e($viewer['avatar_url']); ?>" alt="<?= e($viewer['name']); ?>" class="w-px-40 h-auto rounded-circle" />
<?php else: ?>
                      <span class="avatar-initial rounded-circle bg-label-primary"><?= e($viewer['initial']); ?></span>
<?php endif; ?>
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="javascript:void(0);">
                        <div class="d-flex">
                          <div class="flex-shrink-0 me-3">
                            <div class="avatar avatar-online">
<?php if ($viewer['avatar_url'] !== ''): ?>
                              <img src="<?= e($viewer['avatar_url']); ?>" alt="<?= e($viewer['name']); ?>" class="w-px-40 h-auto rounded-circle" />
<?php else: ?>
                              <span class="avatar-initial rounded-circle bg-label-primary"><?= e($viewer['initial']); ?></span>
<?php endif; ?>
                            </div>
                          </div>
                          <div class="flex-grow-1">
                            <span class="fw-semibold d-block dropdown-user-name text-truncate"><?= e($viewer['name']); ?></span>
                            <small class="text-muted d-block text-truncate"><?= e($viewer['email']); ?></small>
                          </div>
                        </div>
                      </a>
                    </li>
                    <li><div class="dropdown-divider"></div></li>
                    <li>
                      <form class="logout-menu-form" method="post" action="<?= e($logoutAction); ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>" />
                        <button type="submit" class="dropdown-item logout-menu-button">
                          <i class="bx bx-power-off me-2"></i>
                          <span class="align-middle">Log Out</span>
                        </button>
                      </form>
                    </li>
                  </ul>
                </li>
              </ul>
            </div>
          </nav>

          <div class="content-wrapper app-page-shell">
<?= $mainContent !== '' ? $mainContent . "\n" : ''; ?>
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl d-flex flex-wrap justify-content-between py-3 flex-md-row flex-column">
                <div class="mb-2 mb-md-0 app-footer-note">
                  Oracle administrator workspace.
                </div>
                <div class="app-footer-note">
                  ORACLE 2026
                </div>
              </div>
            </footer>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="<?= e(app_link('assets/vendor/libs/jquery/jquery.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/libs/popper/popper.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/js/bootstrap.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js')); ?>"></script>
    <script src="<?= e(app_link('assets/vendor/js/menu.js')); ?>"></script>
    <script src="<?= e(app_link('plugins/feather.min.js')); ?>"></script>
    <script src="<?= e(app_link('assets/js/main.js')); ?>"></script>
    <script>
      if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
      }
    </script>
<?php if ($extraScripts !== ''): ?>
<?= $extraScripts . "\n"; ?>
<?php endif; ?>
  </body>
</html>
<?php
    }

    private static function menuMarkup(string $currentPage): string
    {
        $dashboard = self::menuItemClass($currentPage === 'dashboard');
        $accounts = self::menuItemClass($currentPage === 'accounts');
        $researchOpen = in_array($currentPage, ['research_type', 'manuscripts'], true);
        $researchGroup = self::menuItemClass($researchOpen, true);
        $researchType = self::menuItemClass($currentPage === 'research_type');
        $manuscripts = self::menuItemClass($currentPage === 'manuscripts');

        return '
          <ul class="menu-inner py-1">
            <li class="' . $dashboard . '">
              <a href="' . e(app_link('administrator/')) . '" class="menu-link">
                ' . self::menuCardContent('bx-home-circle', 'Dashboard', 'Overview of activity, counts, and recent manuscript updates.') . '
              </a>
            </li>
            <li class="' . $accounts . '">
              <a href="' . e(app_link('administrator/accounts.php')) . '" class="menu-link">
                ' . self::menuCardContent('bx-user', 'Accounts', 'Manage approved users, access status, and sign-in availability.') . '
              </a>
            </li>
            <li class="menu-header small text-uppercase">
              <span class="menu-header-text">Research Workspace</span>
            </li>
            <li class="' . $researchGroup . '">
              <a href="javascript:void(0);" class="menu-link menu-toggle">
                ' . self::menuCardContent('bx-book-content', 'Manage Research', 'Open the research setup and manuscript management tools.') . '
              </a>
              <ul class="menu-sub">
                <li class="' . $researchType . '">
                  <a href="' . e(app_link('administrator/research_type.php')) . '" class="menu-link">
                    ' . self::menuCardContent('bx-category-alt', 'Research Type', 'Maintain the categories available to manuscript records.', true) . '
                  </a>
                </li>
                <li class="' . $manuscripts . '">
                  <a href="' . e(app_link('administrator/manuscripts.php')) . '" class="menu-link">
                    ' . self::menuCardContent('bx-book-open', 'Manuscripts', 'Review detailed manuscript data, assignments, and uploads.', true) . '
                  </a>
                </li>
              </ul>
            </li>
          </ul>';
    }

    private static function menuCardContent(string $icon, string $title, string $note, bool $compact = false): string
    {
        $iconClass = $compact ? 'menu-card-icon menu-card-icon-sub' : 'menu-card-icon';

        return '
                <span class="' . e($iconClass) . '">
                  <i class="menu-icon tf-icons bx ' . e($icon) . '"></i>
                </span>
                <div class="menu-card-copy">
                  <span class="menu-card-title">' . e($title) . '</span>
                  <span class="menu-card-note">' . e($note) . '</span>
                </div>';
    }

    private static function menuItemClass(bool $active, bool $open = false): string
    {
        if ($active && $open) {
            return 'menu-item active open';
        }

        if ($active) {
            return 'menu-item active';
        }

        return 'menu-item';
    }

    private static function viewerIdentity(): array
    {
        $account = Auth::account();
        $googleUser = Auth::googleUser();
        $name = trim((string) ($googleUser['name'] ?? $account['acc_name'] ?? 'Approved User'));
        $email = trim((string) ($googleUser['email'] ?? $account['email'] ?? ''));
        $avatarUrl = trim((string) ($googleUser['picture'] ?? ''));

        if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            $avatarUrl = '';
        }

        return [
            'name' => $name !== '' ? $name : 'Approved User',
            'email' => $email !== '' ? $email : 'Authorized account',
            'avatar_url' => $avatarUrl,
            'initial' => self::initial($name !== '' ? $name : 'A'),
        ];
    }

    private static function initial(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'A';
        }

        if (function_exists('mb_substr')) {
            return strtoupper((string) mb_substr($trimmed, 0, 1, 'UTF-8'));
        }

        return strtoupper(substr($trimmed, 0, 1));
    }
}
