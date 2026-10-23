<?php
/**
 * "Latest from Industry Updates" on the home page and the product pages (docs/DESIGN.md 8.36): up to three of the
 * newest articles as a quiet hairline row (each its headline, its category and the date it went live), then the way
 * to the section and to the hubs the strip draws from. The articles come from the latest-articles file the tick
 * writes (Psc\News\Latest), never from the database, so the page stays the same for every visitor; with no file, or
 * nothing in it, nothing is drawn. Its own small stylesheet is pushed only when it is drawn.
 *
 * @var list<array{title: string, path: string, at: int, category: string}> $items
 * @var list<string> $hubs  the category keys whose hubs it links to, after the section's
 * @var string $title
 * @var string $lede
 * @var string $id  the heading's id
 */
use Psc\News\Categories;
use Psc\News\Paths;

if ($items === []) {
    return;
}
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news-latest.css')) . '">');
$latest = content('news')['latest'] ?? [];
$latest = is_array($latest) ? $latest : [];
?>
<section class="l-section l-section--tight news-latest" aria-labelledby="<?= e($id) ?>">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow"><?= e($latest['eyebrow'] ?? 'Industry Updates') ?></p><h2 id="<?= e($id) ?>"><?= e($title) ?></h2></div>
            <p class="c-lede"><?= e($lede) ?></p>
        </div>
        <ol class="news-latest-list" role="list">
            <?php foreach ($items as $it): $name = Categories::name($it['category']); ?>
                <li class="news-latest-item">
                    <h3 class="news-latest-title"><a href="<?= e($it['path']) ?>"><?= e($it['title']) ?></a></h3>
                    <p class="news-latest-meta"><?php if ($name !== ''): ?><span><?= e($name) ?></span><?php endif; ?><time datetime="<?= e(date(DATE_ATOM, $it['at'])) ?>"><?= e(date_short($it['at'])) ?></time></p>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="news-latest-more">
            <a href="<?= e(Paths::index()) ?>"><?= e($latest['all'] ?? 'All Industry Updates') ?></a>
            <?php foreach ($hubs as $key): if (!Categories::has($key)) { continue; } ?>
                <a href="<?= e(Paths::category($key)) ?>"><?= e(strtr((string) ($latest['more'] ?? ':category news'), [':category' => Categories::name($key)])) ?></a>
            <?php endforeach; ?>
        </p>
    </div>
</section>
