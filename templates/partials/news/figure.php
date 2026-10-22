<?php
/**
 * An article image as a figure: every stored width in srcset, its size (no layout shift), its alt text, and the
 * caption and credit when it has them. The lead image loads at once; the others when they come near the screen.
 *
 * @var array{src: string, srcset: string, width: int, height: int, alt: string, caption: string, credit: string, credit_url: string} $picture
 * @var array<string, mixed> $copy  content/news.php
 * @var bool|null $eager
 */
$eager = (bool) ($eager ?? false);
$credit = $picture['credit'];
?>
<figure class="news-figure">
    <img src="<?= e($picture['src']) ?>" srcset="<?= e($picture['srcset']) ?>" sizes="(width >= 52rem) 760px, 92vw" width="<?= e($picture['width']) ?>" height="<?= e($picture['height']) ?>" alt="<?= e($picture['alt']) ?>"<?= $eager ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
    <?php if ($picture['caption'] !== '' || $credit !== ''): ?>
        <figcaption class="news-figure-caption">
            <?php if ($picture['caption'] !== ''): ?><span><?= e($picture['caption']) ?></span><?php endif; ?>
            <?php if ($credit !== ''): ?>
                <span class="news-figure-credit"><?= e($copy['meta']['credit'] ?? 'Image') ?>: <?php if ($picture['credit_url'] !== ''): ?><a href="<?= e($picture['credit_url']) ?>" rel="nofollow noopener" target="_blank"><?= e($credit) ?><span class="u-visually-hidden"> (<?= e($copy['new_tab'] ?? 'opens in a new tab') ?>)</span></a><?php else: ?><?= e($credit) ?><?php endif; ?></span>
            <?php endif; ?>
        </figcaption>
    <?php endif; ?>
</figure>
