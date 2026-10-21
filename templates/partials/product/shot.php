<?php
/**
 * A product screenshot. Captures near 16:10 use the shared 16:10 frame (partials/shot). A 16:9 capture sits whole in
 * the same frame, letterboxed on its black like a 16:9 picture on a 16:10 monitor, so nothing at its edges (such as
 * the show clock in a corner) is cut off. A much wider capture, such as a three-projector blend pattern, keeps its
 * own shape in the same dark bezel (docs/DESIGN.md §9).
 *
 * @var array<string, mixed> $shot
 * @var bool|null $eager
 * @var string|null $sizes
 */
$eager ??= false;
$sizes ??= '(width >= 64rem) 50vw, 92vw';
$w = (int) ($shot['width'] ?? 1600);
$hgt = max(1, (int) ($shot['height'] ?? 1000));
$ratio = $w / $hgt;
if ($ratio <= 1.7) {
    echo $this->partial('shot', ['shot' => $shot, 'eager' => $eager, 'sizes' => $sizes]);
    return;
}
$frame = $ratio > 2.0 ? 'c-pd-shot-free' : 'c-pd-shot-fit';
$small = (string) ($shot['src_small'] ?? '');
?>
<figure class="c-shot">
    <div class="c-shot-frame <?= $frame ?>">
        <img src="<?= e($small !== '' ? $small : $shot['src']) ?>"<?php if ($small !== ''): ?> srcset="<?= e($small) ?> 800w, <?= e($shot['src']) ?> 1600w" sizes="<?= e($sizes) ?>"<?php endif; ?>
             width="<?= e($w) ?>" height="<?= e($hgt) ?>" alt="<?= e($shot['alt']) ?>" <?= $eager ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
    </div>
    <?php if (!empty($shot['caption'])): ?><figcaption class="c-shot-caption"><?= e($shot['caption']) ?></figcaption><?php endif; ?>
</figure>
