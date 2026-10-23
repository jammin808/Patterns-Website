<?php
/**
 * /news/standards/: Industry Updates' editorial standards (docs/NEWS.md §10), a registry page drawn by PageController.
 * The hero (the trail goes in it), then one reading column: how the section is made, what the editor does, how to
 * report a mistake, the corrections policy and who publishes it. The words are content/news.php 'standards', in
 * Markdown; :editor is the site's publisher (content/site.php publisher.name, as the about page names it), and
 * :publishing says what happens to an article that passes the checks as the desk's auto_publish setting stands, so
 * the page never says articles publish themselves while the editor publishes each one. Each section has an id, so
 * the AI note, the corrections note on an article and the about page can link to the part they mean.
 *
 * @var array<string, mixed> $page  the registry entry
 */
use Psc\News\Paths;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news.css')) . '">');
$this->push('head', '<link rel="alternate" type="application/rss+xml" title="' . e(content('news')['feed']['label'] ?? 'Industry Updates RSS feed') . '" href="' . e(Paths::feed()) . '">');

$copy = content('news');
$standards = is_array($copy['standards'] ?? null) ? $copy['standards'] : [];
$editor = (string) (content('site')['publisher']['name'] ?? '');
$editor = $editor !== '' ? $editor : 'the publisher of this site';
$sections = is_array($standards['sections'] ?? null) ? $standards['sections'] : [];
$publishing = is_array($standards['publishing'] ?? null) ? $standards['publishing'] : [];
// With the settings unreadable the page still answers, with the words that are true either way.
try {
    $mode = (new \Psc\News\Settings(\Psc\App::get()))->get('auto_publish') === true ? 'auto' : 'editor';
} catch (\Throwable $e) {
    \Psc\App::get()->log->exception($e, 'news standards');
    $mode = 'unknown';
}
$words = [':editor' => $editor, ':publishing' => (string) ($publishing[$mode] ?? '')];
?>
<section class="c-hero c-hero--page news-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow"><?= e($standards['eyebrow'] ?? ($copy['name'] ?? 'Industry Updates')) ?></p>
                <h1 class="c-hero-title" id="page-title"><?= e($standards['title'] ?? 'Editorial standards') ?></h1>
            </div>
            <div>
                <p class="c-hero-lede"><?= e($standards['lede'] ?? ($page['description'] ?? '')) ?></p>
            </div>
        </div>
    </div>
</section>

<div class="l-section l-section--flush news-story">
    <div class="l-wrap l-wrap--prose news-story-wrap news-standards">
        <?php if (count($sections) > 1): ?>
            <nav class="news-standards-toc" aria-labelledby="news-standards-toc">
                <h2 class="u-visually-hidden" id="news-standards-toc">On this page</h2>
                <ul role="list">
                    <?php foreach ($sections as $s): ?>
                        <li><a href="#<?= e($s['id']) ?>"><?= e($s['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <?php foreach ($sections as $s): ?>
            <section class="news-part" id="<?= e($s['id']) ?>" aria-labelledby="<?= e($s['id']) ?>-title">
                <h2 id="<?= e($s['id']) ?>-title"><?= e($s['title']) ?></h2>
                <div class="c-prose"><?= md(strtr((string) $s['body'], $words)) ?></div>
            </section>
        <?php endforeach; ?>
        <p class="news-standards-back"><a class="c-btn c-btn--secondary" href="<?= e(Paths::index()) ?>"><?= e($copy['all_link'] ?? 'See every article') ?></a></p>
    </div>
</div>
