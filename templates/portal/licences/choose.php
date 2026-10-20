<?php
/**
 * The first step of a licence request: choose the licence. Each card links to its request form. Products whose
 * licences are not on sale yet get a note that links to the contact form.
 *
 * @var array<string, array{name: string, options: array<string, array<string, mixed>>}> $choices
 * @var array<string, string> $comingSoon key => name
 * @var string $product the product chosen with ?product=, or ''
 * @var bool $tierMissing a ?tier= was asked for that cannot be requested
 */
?>
<header class="pt-header">
    <p class="pt-eyebrow">Licences</p>
    <h1 class="pt-title">Request a licence</h1>
    <p class="pt-lede">Choose the licence you would like. Next you add your PC’s hardware ID, and we email you about payment.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/licences/">Back to your licences</a>
    </div>
</header>

<?php if ($tierMissing): ?>
    <div class="pt-alert pt-alert--info" role="status">That licence cannot be requested here. Please choose one of the licences below.</div>
<?php endif; ?>

<?php if ($choices === []): ?>
    <div class="pt-empty">
        <p>No licences can be requested online at the moment. To ask about one, please get in touch.</p>
        <a class="pt-btn pt-btn--primary" href="/contact/?topic=sales">Contact us</a>
    </div>
<?php endif; ?>

<?php foreach ($choices as $key => $choice): ?>
    <section class="pt-section" aria-labelledby="choose-<?= e($key) ?>">
        <h2 class="pt-h2" id="choose-<?= e($key) ?>"><?= e($choice['name']) ?></h2>
        <div class="pt-grid pt-grid--3">
            <?php foreach ($choice['options'] as $tierId => $o): ?>
                <a class="pt-card pt-card--link" href="/portal/licences/request/?product=<?= e(rawurlencode($key)) ?>&amp;tier=<?= e(rawurlencode((string) $tierId)) ?>#form">
                    <span class="pt-card-title"><?= e($choice['name']) ?> <?= e($o['name']) ?></span>
                    <span class="pt-card-meta"><?= e($o['price_label']) ?><?php if ($o['price_note'] !== ''): ?> · <?= e($o['price_note']) ?><?php endif; ?></span>
                    <span class="pt-card-body"><?= e($o['summary']) ?></span>
                    <span class="pt-card-footer pt-small"><?= (int) $o['machines'] === 1 ? 'For 1 PC' : 'For ' . (int) $o['machines'] . ' PCs' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="pt-small"><a href="/<?= e($key) ?>/licensing/">Compare <?= e($choice['name']) ?> licences</a></p>
    </section>
<?php endforeach; ?>

<?php foreach ($comingSoon as $key => $name): ?>
    <div class="pt-alert pt-alert--info" role="status">
        <?= e($name) ?> licences are coming soon. <a href="/contact/?topic=sales&amp;product=<?= e(rawurlencode($key)) ?>">Register your interest</a> and you will be among the first to hear.
    </div>
<?php endforeach; ?>

<?php if ($product !== ''): ?>
    <p><a href="/portal/licences/request/">See licences for every product</a></p>
<?php endif; ?>
