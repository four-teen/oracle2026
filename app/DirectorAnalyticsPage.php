<?php

declare(strict_types=1);

require_once __DIR__ . '/ResearchAnalytics.php';

final class DirectorAnalyticsPage
{
    public static function load(string $page): array
    {
        Auth::requireDirector();
        $context = ['campus' => ['id' => Auth::campusId(), 'label' => 'Campus research', 'options' => []],
            'summary' => ['total' => 0, 'latest_update' => ''], 'error' => false];
        try {
            $pdo = Database::connection();
            Database::ensureDirectorAnalyticsSchema();
            $context['campus'] = ResearchAnalytics::campus($pdo, Auth::campusId(), $_GET['campus'] ?? '');
            $context['summary'] = ResearchAnalytics::summary($pdo, $context['campus']['id']);
            $context['data'] = $page === 'sdg'
                ? ResearchAnalytics::sdgs($pdo, $context['campus']['id'])
                : ResearchAnalytics::contributors($pdo, $context['campus']['id']);
        } catch (Throwable $exception) {
            error_log('Director ' . $page . ' analytics: ' . $exception->getMessage());
            $context['error'] = true;
        }
        return $context;
    }

    public static function hero(array $context, string $page, string $eyebrow, string $title, string $copy): void
    {
        $campus = $context['campus'];
        $date = strtotime($context['summary']['latest_update']);
        ?>
<section class="da-hero">
  <div class="da-hero-copy">
    <p class="da-eyebrow"><?= e($eyebrow); ?></p>
    <h2><?= e($title); ?></h2>
    <p><?= e($copy); ?></p>
    <span class="da-scope"><span aria-hidden="true"></span><?= e($campus['label']); ?> campus <span class="da-scope-divider" aria-hidden="true">&middot;</span> <?= $date !== false ? 'Updated ' . e(date('M j, Y', $date)) : 'No updates yet'; ?></span>
  </div>
  <?php if ($campus['options'] !== []): ?>
  <form class="da-campus-form" method="get" action="<?= e(app_link('director/' . $page . '.php')); ?>">
    <label for="da-campus">Choose a campus</label>
    <div><select id="da-campus" name="campus">
      <?php foreach ($campus['options'] as $option): ?>
        <option value="<?= e((string) $option['campusid']); ?>"<?= (int) $option['campusid'] === $campus['id'] ? ' selected' : ''; ?>><?= e($option['campusname']); ?></option>
      <?php endforeach; ?>
    </select><button type="submit">View <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button></div>
  </form>
  <?php endif; ?>
</section>
<?php if ($context['error']): ?>
<div class="da-panel da-empty" role="alert"><i class="bx bx-info-circle" aria-hidden="true"></i><h3>Statistics could not be loaded</h3><p>Please reload this page or try another campus.</p><a class="coord-btn-secondary" href="<?= e(app_link('director/' . $page . '.php') . '?campus=' . $campus['id']); ?>">Try again</a></div>
<?php endif; ?>
<?php
    }

    public static function metrics(array $metrics): void
    {
        ?>
<section class="da-metrics" aria-label="Statistics at a glance">
  <?php foreach ($metrics as $metric): ?>
    <article class="da-panel da-metric"><div><span><?= e($metric[0]); ?></span><i class="bx <?= e($metric[3]); ?>" aria-hidden="true"></i></div><strong><?= e($metric[1]); ?></strong><p><?= e($metric[2]); ?></p></article>
  <?php endforeach; ?>
</section>
<?php
    }

    public static function render(string $page, array $context, string $content): void
    {
        $css = 'assets/css/director-analytics.css';
        $js = 'assets/js/director-analytics.js';
        CoordinatorPage::render([
            'title' => $page === 'sdg' ? 'SDG Statistics' : 'Research Contributors',
            'current_page' => $page,
            'campus_label' => $context['campus']['label'],
            'campus_id' => $context['campus']['id'],
            'main_content' => $content,
            'loading_error' => $context['error'],
            'extra_head' => '<link rel="stylesheet" href="' . e(app_link($css)) . '?v=' . filemtime(dirname(__DIR__) . '/' . $css) . '">',
            'extra_scripts' => '<script src="' . e(app_link($js)) . '?v=' . filemtime(dirname(__DIR__) . '/' . $js) . '" data-director-page-script defer></script>',
        ]);
    }
}
