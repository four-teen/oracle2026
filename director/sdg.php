<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/DirectorAnalyticsPage.php';

$context = DirectorAnalyticsPage::load('sdg');
ob_start();
?>
<div class="da-page" data-sdg-page>
<?php DirectorAnalyticsPage::hero($context, 'sdg', 'Sustainable Development Goals', 'Research with purpose.', 'Understand how campus research connects to the 17 Sustainable Development Goals, and see where more tagging is needed.'); ?>
<?php if (!$context['error']): ?>
  <?php
  $data = $context['data'];
  $total = $data['total'];
  $coverage = ResearchAnalytics::percentage($data['aligned'], $total);
  $leaders = array_slice(array_values(array_filter($data['ranked'], static function (int $goal) use ($data): bool {
      return $data['counts'][$goal] > 0;
  })), 0, 3);
  DirectorAnalyticsPage::metrics([
      ['Manuscripts in view', number_format($total), 'All research records in this campus.', 'bx-collection'],
      ['With SDG tags', $coverage, number_format($data['aligned']) . ' manuscripts support at least one goal.', 'bx-check-circle'],
      ['Goals represented', $data['represented'] . ' / 17', 'Goals with at least one tagged manuscript.', 'bx-globe'],
      ['Without SDG tags', number_format($data['untagged']), 'Manuscripts with no valid goal tags yet.', 'bx-purchase-tag-alt'],
  ]);
  ?>
  <div class="da-overview">
    <section class="da-panel da-section" aria-labelledby="da-coverage-title">
      <p class="da-eyebrow">The big picture</p>
      <h3 id="da-coverage-title">How much research is tagged?</h3>
      <p class="da-description">Coverage counts manuscripts, rather than the number of tags.</p>
      <div class="da-coverage">
        <div class="da-ring" style="--coverage: <?= e($coverage); ?>;" role="img" aria-label="<?= e($coverage . ' of ' . number_format($total) . ' manuscripts have an SDG tag.'); ?>"><div><strong><?= e($coverage); ?></strong><span>SDG coverage</span></div></div>
        <div class="da-coverage-legend"><p><span>With SDG tags</span><strong><?= e(number_format($data['aligned'])); ?></strong></p><p><span>Without SDG tags</span><strong><?= e(number_format($data['untagged'])); ?></strong></p><p><span>Total manuscripts</span><strong><?= e(number_format($total)); ?></strong></p></div>
      </div>
      <div class="da-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p><?= $total === 0 ? 'This campus has no manuscripts yet. Statistics will appear as research records are added.' : ($data['untagged'] > 0 ? e(number_format($data['untagged'])) . ' manuscripts have no valid SDG tags yet. These records are outside the tagged total.' : 'Every manuscript in this campus has at least one valid SDG tag.'); ?></p></div>
    </section>
    <section class="da-panel da-section" aria-labelledby="da-leading-title">
      <p class="da-eyebrow">Leading areas</p>
      <h3 id="da-leading-title">Where research is strongest</h3>
      <p class="da-description">The three goals with the most tagged manuscripts.</p>
      <?php if ($leaders === []): ?>
        <div class="da-empty"><i class="bx bx-globe" aria-hidden="true"></i><h3>No tagged research yet</h3><p>Leading goals will appear when campus manuscripts have SDG tags.</p></div>
      <?php else: ?>
        <ol class="da-top-goals">
          <?php foreach ($leaders as $goal): ?>
            <li><img src="<?= e(app_link('assets/img/sdgs/sdg-' . str_pad((string) $goal, 2, '0', STR_PAD_LEFT) . '.png')); ?>" alt="" width="42" height="42"><div><span>SDG <?= e((string) $goal); ?></span><strong><?= e($data['options'][$goal]); ?></strong></div><div><b><?= e(number_format($data['counts'][$goal])); ?></b><small><?= e(ResearchAnalytics::percentage($data['counts'][$goal], $total)); ?> of manuscripts</small></div></li>
          <?php endforeach; ?>
        </ol>
        <div class="da-note"><i class="bx bx-bulb" aria-hidden="true"></i><p>These are the most frequently tagged goals in this campus. Tag counts describe research alignment, rather than measured impact.</p></div>
      <?php endif; ?>
    </section>
  </div>
  <section class="da-panel da-catalog" aria-labelledby="da-all-goals-title">
    <div class="da-section-heading"><div><h2 id="da-all-goals-title">Explore all 17 goals</h2><p class="da-description">Compare manuscript counts and each goal's share of campus research.</p></div><span class="da-count-badge">17 goals</span></div>
    <div class="da-toolbar" data-sdg-controls hidden>
      <div class="da-field"><label for="da-goal-search">Find a goal</label><input id="da-goal-search" type="search" placeholder="Search SDG number or goal name" autocomplete="off"></div>
      <div class="da-field da-field--sort"><label for="da-goal-sort">Sort goals</label><select id="da-goal-sort"><option value="count">Most manuscripts</option><option value="number">SDG number</option><option value="least">Fewest manuscripts</option></select></div>
    </div>
    <div class="da-filters" aria-label="Filter goals" data-sdg-controls hidden><button type="button" data-goal-filter="all" aria-pressed="true">All goals</button><button type="button" data-goal-filter="represented" aria-pressed="false">With research (<?= e((string) $data['represented']); ?>)</button><button type="button" data-goal-filter="empty" aria-pressed="false">No research (<?= e((string) (17 - $data['represented'])); ?>)</button></div>
    <p class="da-result-count" data-goal-result-count aria-live="polite">Showing 17 goals</p>
    <div class="da-goals" data-goal-list>
      <?php foreach ($data['ranked'] as $goal): ?>
        <?php $count = $data['counts'][$goal]; $share = ResearchAnalytics::percentage($count, $total); ?>
        <article class="da-goal" data-goal="<?= e((string) $goal); ?>" data-count="<?= e((string) $count); ?>" data-name="<?= e($data['options'][$goal]); ?>" style="--goal-color: <?= e(ResearchAnalytics::SDG_COLORS[$goal]); ?>;">
          <div class="da-goal-heading"><img src="<?= e(app_link('assets/img/sdgs/sdg-' . str_pad((string) $goal, 2, '0', STR_PAD_LEFT) . '.png')); ?>" alt="" width="54" height="54" loading="lazy"><div><span>SDG <?= e((string) $goal); ?></span><h3><?= e($data['options'][$goal]); ?></h3></div></div>
          <div class="da-goal-total"><strong><?= e(number_format($count)); ?></strong><span><?= $count === 1 ? 'manuscript' : 'manuscripts'; ?></span></div>
          <div class="da-meter" aria-hidden="true"><span style="width: <?= e($share); ?>;"></span></div>
          <p class="da-goal-share"><?= $count > 0 ? '<strong>' . e($share) . '</strong> of ' . e(number_format($total)) . ' campus manuscripts' : 'No manuscripts tagged to this goal yet.'; ?></p>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="da-empty" data-goal-empty hidden><i class="bx bx-search" aria-hidden="true"></i><h3>No goals match this view</h3><p>Try another goal name, number, or filter.</p></div>
    <div class="da-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Each manuscript counts once per goal. A manuscript can support several goals, so goal counts can exceed the total manuscripts and their percentages can add up to more than 100%.</p></div>
  </section>
<?php endif; ?>
</div>
<?php
DirectorAnalyticsPage::render('sdg', $context, (string) ob_get_clean());
