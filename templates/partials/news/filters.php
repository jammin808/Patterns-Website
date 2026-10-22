<?php
/**
 * The bar above a list (docs/NEWS.md §10): the categories that have live articles, with their counts, as a
 * wrapping list of links to their hubs; below 40rem the same choice as a form with a select and a Show button (a GET
 * to /news/, which sends each category to its own hub); and the search, which looks through every article. Links and
 * GET forms only, so the page needs no script and stays the same for every visitor.
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var array<string, array{name: string}> $categories  Categories::all()
 * @var array<string, int> $counts  live articles per category, only those with any
 * @var int $all  live articles in all
 * @var string|null $category  the category shown, or null for the index
 * @var string $q  the search
 * @var bool $exact  this is the list's own first page (no search, page 1)
 */
$shown = $counts;
if ($category !== null && !isset($shown[$category])) {
    $shown[$category] = 0;
}
$ordered = array_intersect_key($categories, $shown);
$count = static fn (int $n): string => '<span class="news-cats-count"><span class="u-visually-hidden">, </span>' . e($n) . '<span class="u-visually-hidden"> ' . ($n === 1 ? 'article' : 'articles') . '</span></span>';
$current = static fn (bool $on): string => $on ? ($exact ? ' aria-current="page"' : ' aria-current="true"') : '';
$search = is_array($copy['search'] ?? null) ? $copy['search'] : [];
$pick = is_array($copy['pick'] ?? null) ? $copy['pick'] : [];
$hub = \Psc\News\Paths::index();
?>
<div class="news-bar">
    <nav class="news-cats-nav u-hide-sm" aria-label="<?= e($copy['categories_label'] ?? 'Categories') ?>">
        <ul class="news-cats" role="list">
            <li><a href="<?= e($hub) ?>"<?= $current($category === null && $q === '') ?>><?= e($copy['all'] ?? 'All') ?> <?= $count($all) ?></a></li>
            <?php foreach ($ordered as $key => $c): ?>
                <li><a href="<?= e(\Psc\News\Paths::category($key)) ?>"<?= $current($category === $key) ?>><?= e($c['name']) ?> <?= $count((int) $shown[$key]) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <form class="c-search news-pick u-show-sm" method="get" action="<?= e($hub) ?>">
        <label for="news-pick"><?= e($pick['label'] ?? 'Category') ?></label>
        <select id="news-pick" name="category">
            <option value=""><?= e($copy['all'] ?? 'All') ?> (<?= e($all) ?>)</option>
            <?php foreach ($ordered as $key => $c): ?>
                <option value="<?= e($key) ?>"<?= $category === $key ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= e($shown[$key]) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button class="c-btn c-btn--secondary c-btn--sm" type="submit"><?= e($pick['button'] ?? 'Show') ?></button>
    </form>
    <form class="c-search news-search" method="get" action="<?= e($hub) ?>" role="search" aria-label="<?= e($search['label'] ?? 'Search Industry Updates') ?>">
        <div class="c-search-field">
            <?= icon('search', 'c-icon--sm') ?>
            <label class="u-visually-hidden" for="news-q"><?= e($search['label'] ?? 'Search Industry Updates') ?></label>
            <input id="news-q" type="search" name="q" value="<?= e($q) ?>" maxlength="<?= e(\Psc\Controllers\NewsController::QUERY_MAX) ?>" placeholder="<?= e($search['placeholder'] ?? '') ?>" autocomplete="off" aria-describedby="news-q-help">
        </div>
        <button class="c-btn c-btn--secondary c-btn--sm" type="submit"><?= e($search['button'] ?? 'Search') ?></button>
        <p class="c-help news-search-help" id="news-q-help"><?= e($search['help'] ?? '') ?></p>
    </form>
</div>
