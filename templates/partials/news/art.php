<?php
/**
 * A category's monochrome artwork (public/assets/img/news-art.svg, docs/DESIGN.md 8.36), in place of an article's
 * picture. It is decoration, so it is hidden from assistive technology; the tile's tones are classes, never colours.
 *
 * @var string $key  a category key
 * @var bool|null $wide  the article page's band rather than a card's 16:9 tile
 */
if (!\Psc\News\Categories::has($key)) {
    return;
}
?>
<span class="news-art<?= !empty($wide) ? ' news-art--wide' : '' ?>"><svg class="news-art-svg" viewBox="0 0 160 90" aria-hidden="true" focusable="false"><use href="<?= e(asset('/assets/img/news-art.svg')) ?>#art-<?= e($key) ?>"/></svg></span>
