<?php
/**
 * One article in a list (docs/DESIGN.md 8.36): the title's link covers the card, as on the workflow cards. The
 * picture, or its category's artwork, and the categories show above the title but are read after it, so a
 * screen reader meets the headline first. The newest article on a list's first page is drawn wide ($lead).
 *
 * @var array<string, mixed> $a  an ArticleStore::live() item, with picture (NewsController::picture()) or null
 * @var array<string, array{name: string}> $categories  Categories::all()
 * @var array<string, mixed> $copy  content/news.php
 * @var bool|null $lead
 */
$lead = (bool) ($lead ?? false);
$names = array_values(array_filter(array_map(static fn (string $k): string => $categories[$k]['name'] ?? '', $a['categories'])));
$ts = (int) $a['published_at'];
$minutes = \Psc\News\Prose::readMinutes((int) $a['word_count']);
$picture = $a['picture'] ?? null;
?>
<article class="c-card news-card<?= $lead ? ' news-card--lead' : '' ?>">
    <h3 class="c-card-title"><a href="/news/<?= e($a['slug']) ?>/"><?= e($a['title']) ?></a></h3>
    <?php if ($names !== []): ?><p class="news-card-cats"><?= e(implode(', ', $names)) ?></p><?php endif; ?>
    <p class="c-card-body"><?= e($a['dek']) ?></p>
    <p class="c-card-footer news-card-meta"><time datetime="<?= e(date(DATE_ATOM, $ts)) ?>"><?= e(date_short($ts)) ?></time><span><?= e(strtr((string) ($copy['meta']['read'] ?? ':n min read'), [':n' => (string) $minutes])) ?></span></p>
    <div class="news-card-media">
        <?php if (is_array($picture)): ?>
            <img src="<?= e($picture['src']) ?>" srcset="<?= e($picture['srcset']) ?>" sizes="<?= $lead ? '(width >= 64rem) 50vw, 92vw' : '(width >= 64rem) 400px, (width >= 40rem) 46vw, 92vw' ?>" width="<?= e($picture['width']) ?>" height="<?= e($picture['height']) ?>" alt=""<?= $lead ? '' : ' loading="lazy"' ?> decoding="async">
        <?php else: ?>
            <?= $this->partial('news/art', ['key' => (string) ($a['category'] ?? '')]) ?>
        <?php endif; ?>
    </div>
</article>
