<?php
/**
 * The way across the Industry Updates silo, at the foot of a list (docs/NEWS.md §10): on the hub, a link to each
 * category hub that has live articles; on a category's hub, the hub and the other categories that have them. Plain
 * links, so every hub is one step from every other, for readers and crawlers alike.
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var array<string, array{name: string}> $categories  Categories::all()
 * @var array<string, int> $counts  live articles per category, only those with any
 * @var string|null $category  the category shown, or null for the hub
 */
use Psc\News\Paths;

$silo = is_array($copy['silo'] ?? null) ? $copy['silo'] : [];
$others = array_diff_key(array_intersect_key($categories, $counts), $category !== null ? [$category => true] : []);
if ($others === [] && $category === null) {
    return;
}
?>
<nav class="news-silo" aria-labelledby="news-silo-title">
    <h2 class="news-silo-title" id="news-silo-title"><?= e($category !== null ? ($silo['category'] ?? 'More Industry Updates') : ($silo['index'] ?? 'Browse by category')) ?></h2>
    <ul class="news-silo-list" role="list">
        <?php if ($category !== null): ?>
            <li><a href="<?= e(Paths::index()) ?>"><?= e($silo['all'] ?? 'Every article') ?></a></li>
        <?php endif; ?>
        <?php foreach ($others as $key => $c): ?>
            <li><a href="<?= e(Paths::category($key)) ?>"><?= e($c['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
