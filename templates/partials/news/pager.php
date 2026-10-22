<?php
/**
 * The public pager (docs/DESIGN.md 8.26): Previous, the first and last page numbers with those beside this one,
 * the articles shown and Next. Page 1 is always the clean address (?page=1 redirects there), and nothing is drawn
 * when everything fits on one page.
 *
 * @var string $path  the list's address
 * @var array<string, string> $query  the list's other parameters (a search's q)
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var int $perPage
 */
if ($pages <= 1) {
    return;
}
$url = static function (int $n) use ($path, $query): string {
    $qs = http_build_query($query + ($n > 1 ? ['page' => (string) $n] : []));
    return $path . ($qs !== '' ? '?' . $qs : '');
};
$shown = array_values(array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn (int $n): bool => $n >= 1 && $n <= $pages)));
sort($shown);
$first = ($page - 1) * $perPage + 1;
$last = min($total, $page * $perPage);
?>
<nav class="c-pager news-pager" aria-label="Pages">
    <?php if ($page > 1): ?><a rel="prev" href="<?= e($url($page - 1)) ?>">Previous</a><?php endif; ?>
    <ol role="list">
        <?php $before = 0; foreach ($shown as $n): ?>
            <?php if ($n > $before + 1): ?><li aria-hidden="true"><span>…</span></li><?php endif; ?>
            <li><a href="<?= e($url($n)) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><span class="u-visually-hidden">Page </span><?= e($n) ?></a></li>
        <?php $before = $n; endforeach; ?>
    </ol>
    <span class="c-pager-info"><?= e($first) ?> to <?= e($last) ?> of <?= e($total) ?></span>
    <?php if ($page < $pages): ?><a rel="next" href="<?= e($url($page + 1)) ?>">Next</a><?php endif; ?>
</nav>
