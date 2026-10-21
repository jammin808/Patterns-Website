<?php
/**
 * A screenshot in the 16:10 frame (docs/DESIGN.md 8.19) from a product's 'screenshots' entry.
 *
 * @var array{src: string, src_small?: string, width?: int, height?: int, alt: string, caption?: string} $shot
 * @var string|null $sizes
 * @var bool|null $eager  true for the first screen (no lazy loading)
 */
$sizes ??= '(width >= 64rem) 50vw, 92vw';
$eager ??= false;
$src = (string) $shot['src'];
$small = (string) ($shot['src_small'] ?? '');
?>
<figure class="c-shot">
    <div class="c-shot-frame">
        <img src="<?= e($small !== '' ? $small : $src) ?>"<?php if ($small !== ''): ?> srcset="<?= e(shot_srcset($shot)) ?>" sizes="<?= e($sizes) ?>"<?php endif; ?>
             width="<?= e($shot['width'] ?? 1600) ?>" height="<?= e($shot['height'] ?? 1000) ?>" alt="<?= e($shot['alt']) ?>"
             <?= $eager ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
    </div>
    <?php if (!empty($shot['caption'])): ?><figcaption class="c-shot-caption"><?= e($shot['caption']) ?></figcaption><?php endif; ?>
</figure>
