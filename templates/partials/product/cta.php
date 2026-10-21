<?php
/**
 * The closing call to action on a product page: the next step (the same buttons as the hero), the assistant and
 * the product's help page.
 *
 * @var string $productKey
 * @var array<string, mixed> $product
 * @var string $title
 * @var string $text
 * @var list<array{label: string, href: string, primary?: bool}> $actions
 * @var string|null $id
 */
$id ??= 'next-step';
$name = (string) $product['name'];
?>
<section class="l-section l-section--tint c-pd-cta" aria-labelledby="<?= e($id) ?>-title">
    <div class="l-wrap c-pd-cta-grid">
        <div>
            <h2 id="<?= e($id) ?>-title"><?= e($title) ?></h2>
            <p class="c-lede u-mt-4"><?= e($text) ?></p>
            <div class="c-hero-actions">
                <?php foreach ($actions as $a): $ext = str_starts_with($a['href'], 'http'); ?>
                    <a class="c-btn <?= !empty($a['primary']) ? 'c-btn--primary' : 'c-btn--secondary' ?>" href="<?= e($a['href']) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>><?= e($a['label']) ?><?php if ($ext): ?><?= icon('external', 'c-icon--sm') ?><span class="u-visually-hidden"> (opens in a new tab)</span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="c-pd-cta-help">
            <a class="c-launcher" href="/contact/" role="button" data-chat-open aria-controls="chat-panel" aria-expanded="false">
                <span class="c-launcher-icon"><?= icon('chat') ?></span>
                <span><b>Ask the assistant</b><small>Setup, licences and show-day questions about <?= e($name) ?></small></span>
            </a>
            <ul class="c-support-links" role="list">
                <li><a href="/support/<?= e($productKey) ?>/"><span class="c-swatch"></span><span><?= e($name) ?> help<small>Getting started and troubleshooting</small></span></a></li>
                <li><a href="/downloads/#<?= e($productKey) ?>"><?= icon('download', 'c-icon--sm') ?><span>Downloads<small>The latest <?= e($name) ?> build</small></span></a></li>
            </ul>
        </div>
    </div>
</section>
