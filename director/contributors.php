<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/DirectorAnalyticsPage.php';

$context = DirectorAnalyticsPage::load('contributors');
$requestedRole = isset($_GET['role']) && is_string($_GET['role']) ? $_GET['role'] : 'advisers';
$selectedRole = in_array($requestedRole, ['advisers', 'panelists', 'statisticians', 'english-critics'], true) ? $requestedRole : 'advisers';
ob_start();
?>
<div class="da-page" data-contributors-page data-initial-role="<?= e($selectedRole); ?>">
<?php DirectorAnalyticsPage::hero($context, 'contributors', 'People behind the research', 'Recognize every contribution.', 'Explore the people supporting campus research, compare manuscript coverage by role, and find contributors in the rankings.'); ?>
<?php if (!$context['error']): ?>
  <?php
  $data = $context['data'];
  $total = $context['summary']['total'];
  $activeRoles = count(array_filter($data['roles'], static function (array $role): bool { return $role['people'] !== []; }));
  DirectorAnalyticsPage::metrics([
      ['People contributing', number_format($data['people']), 'Distinct accounts across all four roles.', 'bx-group'],
      ['Campus manuscripts', number_format($total), 'The manuscript total used for coverage.', 'bx-collection'],
      ['Recorded assignments', number_format($data['assignments']), 'One person, role, and manuscript per assignment.', 'bx-user-check'],
      ['Roles represented', $activeRoles . ' / 4', 'Roles with at least one linked contributor.', 'bx-network-chart'],
  ]);
  ?>
  <section class="da-panel da-section" aria-labelledby="da-role-coverage-title">
    <p class="da-eyebrow">Research support at a glance</p>
    <h2 id="da-role-coverage-title">How many manuscripts have a linked contributor?</h2>
    <p class="da-description">Coverage shows the share of campus manuscripts with at least one linked account in each role.</p>
    <div class="da-role-coverage">
      <?php foreach ($data['roles'] as $role): ?>
        <?php $coverage = ResearchAnalytics::percentage($role['recorded_total'], $total); ?>
        <article><div class="da-role-heading"><i class="bx <?= e($role['icon']); ?>" aria-hidden="true"></i><?= e($role['label']); ?></div><p class="da-role-value"><?= e($coverage); ?></p><div class="da-meter" role="img" aria-label="<?= e($role['label'] . ': ' . $role['recorded_total'] . ' of ' . $total . ' manuscripts (' . $coverage . ')'); ?>"><span style="width: <?= e($coverage); ?>;"></span></div><p class="da-description"><?= e(number_format($role['recorded_total'])); ?> of <?= e(number_format($total)); ?> manuscripts<br><?= e(number_format(count($role['people']))); ?> contributors</p></article>
      <?php endforeach; ?>
    </div>
    <div class="da-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p>A manuscript can have several panelists, but counts once in panelist coverage. Only assignments linked to an account are included; names entered as text alone are outside these statistics.</p></div>
  </section>
  <section class="da-panel da-roster" aria-labelledby="da-roster-title">
    <div class="da-section-heading"><div><h2 id="da-roster-title">Explore contributors</h2><p class="da-description">Choose a role to see every contributor, ranked by supported manuscripts.</p></div><span class="da-count-badge">4 roles</span></div>
    <div class="da-tabs" role="tablist" aria-label="Contributor roles" data-role-tabs hidden>
      <?php foreach ($data['roles'] as $key => $role): ?>
        <button type="button" role="tab" id="da-tab-<?= e($key); ?>" aria-controls="da-panel-<?= e($key); ?>" aria-selected="<?= $key === $selectedRole ? 'true' : 'false'; ?>" tabindex="<?= $key === $selectedRole ? '0' : '-1'; ?>" data-role-tab="<?= e($key); ?>"><i class="bx <?= e($role['icon']); ?>" aria-hidden="true"></i><?= e($role['label']); ?></button>
      <?php endforeach; ?>
    </div>
    <div class="da-toolbar" data-people-controls hidden><div class="da-field"><label for="da-people-search">Find a contributor in this role</label><input id="da-people-search" type="search" placeholder="Search a contributor's name" autocomplete="off"></div><div class="da-field da-field--sort"><label for="da-people-sort">Sort contributors</label><select id="da-people-sort"><option value="count">Most manuscripts</option><option value="name">Name A–Z</option></select></div></div>
    <?php foreach ($data['roles'] as $key => $role): ?>
      <div class="da-role-panel" id="da-panel-<?= e($key); ?>" role="tabpanel" aria-labelledby="da-tab-<?= e($key); ?>" tabindex="0" data-role-panel="<?= e($key); ?>">
        <h3><?= e($role['label']); ?></h3>
        <p class="da-result-count" data-people-result-count aria-live="polite"><?= e(number_format(count($role['people']))); ?> contributors</p>
        <?php if ($role['people'] === []): ?>
          <div class="da-empty"><i class="bx <?= e($role['icon']); ?>" aria-hidden="true"></i><h3>No <?= e(strtolower($role['label'])); ?> linked yet</h3><p><?= $total === 0 ? 'This campus has no manuscripts yet.' : 'Statistics will appear when campus manuscripts have linked accounts for this role.'; ?></p></div>
        <?php else: ?>
          <div class="da-roster-grid da-roster-columns" aria-hidden="true"><span>Rank</span><span>Contributor</span><span>Manuscripts</span><span>Campus share</span></div>
          <ol class="da-people" aria-label="<?= e($role['label'] . ' ranked by manuscript count'); ?>" data-people-list>
          <?php $previous = null; $rank = 0; $leading = max(1, (int) $role['people'][0]['total']); ?>
          <?php foreach ($role['people'] as $index => $person): ?>
            <?php if ($previous !== $person['total']) { $rank = $index + 1; } $previous = $person['total']; ?>
            <li class="da-roster-grid da-person" data-person-name="<?= e($person['name']); ?>" data-person-count="<?= e((string) $person['total']); ?>" data-person-id="<?= e((string) $person['id']); ?>">
              <span class="da-rank<?= $rank <= 3 ? ' da-rank--leading' : ''; ?>"><span class="visually-hidden">Rank </span><?= e((string) $rank); ?></span>
              <div><span class="da-person-name"><?= e($person['name']); ?></span><div class="da-meter" aria-hidden="true"><span style="width: <?= e(ResearchAnalytics::percentage($person['total'], $leading)); ?>;"></span></div></div>
              <strong class="da-person-total"><?= e(number_format($person['total'])); ?><span class="visually-hidden"> manuscripts</span></strong>
              <span class="da-person-share"><?= e(ResearchAnalytics::percentage($person['total'], $total)); ?><span class="visually-hidden"> of campus manuscripts</span></span>
            </li>
          <?php endforeach; ?>
          </ol>
          <div class="da-empty" data-people-empty hidden><i class="bx bx-search" aria-hidden="true"></i><h3>No matching contributors</h3><p>Try another name or choose a different role.</p></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="da-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Rankings count each manuscript once per person in a role. Equal totals share a rank. Search and sorting keep the original ranks. The same person can appear in several roles; the people total counts that account once.</p></div>
  </section>
<?php endif; ?>
</div>
<?php
DirectorAnalyticsPage::render('contributors', $context, (string) ob_get_clean());
