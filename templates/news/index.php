<?php
/**
 * /news/ and /news/{key}/, and their pages: the Industry Updates hubs (docs/NEWS.md §10). The hero (the section's
 * name, or the category's name and intro, and under the intro the follow row for the page's own feed), the category
 * bar and search,
 * then one page of live articles: on a list's first page the newest is drawn wide, the rest as a grid, then the
 * pager, then the way across the silo (the hub and every category with articles). A search lists its results under a heading of their own. The page reads the same
 * for every visitor (no session, no form token).
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var array<string, array{name: string, title: string, description: string, intro: string, icon: string}> $categories
 * @var string|null $category  the category's key, or null for the index
 * @var array<string, int> $counts
 * @var int $all
 * @var list<array<string, mixed>> $articles  ArticleStore::live() items, each with picture
 * @var bool $lead  the first page of a list, not a search
 * @var string $q
 * @var int $pageNo
 * @var int $pages
 * @var int $total
 * @var callable(int): string $pageLink  the address of a page of this list
 * @var bool $unavailable  the articles could not be read
 * @var list<array{0: string, 1: string}> $feeds  the RSS feeds the page names: address, title
 */
use Psc\News\Paths;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news.css')) . '">');
foreach ($feeds as [$feedHref, $feedTitle]) {
    $this->push('head', '<link rel="alternate" type="application/rss+xml" title="' . e($feedTitle) . '" href="' . e($feedHref) . '">');
}

$index = is_array($copy['index'] ?? null) ? $copy['index'] : [];
$list = is_array($copy['list'] ?? null) ? $copy['list'] : [];
$empty = is_array($copy['empty'] ?? null) ? $copy['empty'] : [];
$c = $category !== null ? ($categories[$category] ?? null) : null;

if ($q !== '') {
    $heading = strtr((string) ($list['results'] ?? 'Results for “:q”'), [':q' => $q]);
} elseif ($c !== null) {
    $heading = strtr((string) ($list['category'] ?? 'Latest in :category'), [':category' => $c['name']]);
} else {
    $heading = (string) ($list['latest'] ?? 'Latest articles');
}
if ($pageNo > 1) {
    $heading .= ', page ' . $pageNo;
}
$message = match (true) {
    $unavailable => (string) ($empty['unavailable'] ?? ''),
    $q !== '' => (string) ($empty['search'] ?? ''),
    $c !== null => (string) ($empty['category'] ?? ''),
    default => (string) ($empty['index'] ?? ''),
};
?>
<section class="c-hero c-hero--page news-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow"><?= e($c !== null ? ($copy['name'] ?? 'Industry Updates') : ($index['eyebrow'] ?? 'AV and live event news')) ?></p>
                <h1 class="c-hero-title" id="page-title"><?= e($c['name'] ?? ($index['title'] ?? 'Industry Updates')) ?></h1>
            </div>
            <div>
                <p class="c-hero-lede"><?= e($c['intro'] ?? ($index['lede'] ?? '')) ?></p>
            </div>
            <?= $this->partial('news/follow', ['copy' => $copy, 'feed' => $feeds[0][0], 'feedTitle' => $feeds[0][1]]) ?>
        </div>
    </div>
</section>

<section class="l-section l-section--flush news-list" aria-labelledby="news-list-title">
    <div class="l-wrap">
        <?php if ($all > 0 || $q !== ''): ?>
            <?= $this->partial('news/filters', ['copy' => $copy, 'categories' => $categories, 'counts' => $counts, 'all' => $all, 'category' => $category, 'q' => $q, 'exact' => $q === '' && $pageNo === 1]) ?>
        <?php endif; ?>
        <h2 class="<?= $q !== '' ? 'news-list-title' : 'u-visually-hidden' ?>" id="news-list-title"><?= e($heading) ?></h2>
        <?php if ($q !== '' && $total > 0): ?>
            <p class="news-count"><?= e($total === 1 ? ($list['count_one'] ?? '') : strtr((string) ($list['count_many'] ?? ':n articles.'), [':n' => (string) $total])) ?></p>
        <?php endif; ?>
        <?php if ($articles === []): ?>
            <div class="c-empty news-empty">
                <span class="c-empty-mark" aria-hidden="true"></span>
                <p><?= e($message) ?></p>
                <?php if (!$unavailable && ($q !== '' || $c !== null)): ?>
                    <a class="c-btn c-btn--secondary" href="<?= e(Paths::index()) ?>"><?= e($copy['all_link'] ?? 'See every article') ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="news-grid">
                <?php foreach ($articles as $i => $a): ?>
                    <?= $this->partial('news/card', ['a' => $a, 'categories' => $categories, 'copy' => $copy, 'lead' => $lead && $i === 0]) ?>
                <?php endforeach; ?>
            </div>
            <?= $this->partial('news/pager', ['link' => $pageLink, 'page' => $pageNo, 'pages' => $pages, 'total' => $total, 'perPage' => \Psc\Controllers\NewsController::PER_PAGE]) ?>
        <?php endif; ?>
        <?php if ($q === '' && !$unavailable): ?>
            <?= $this->partial('news/silo', ['copy' => $copy, 'categories' => $categories, 'counts' => $counts, 'category' => $category]) ?>
        <?php endif; ?>
    </div>
</section>
