<?php
/**
 * One licence as a tier card (docs/DESIGN.md 8.21) from a content/licensing/<key>.php tier. The recommended tier
 * (highlight) takes the product's colour through .is-rec; a launch price shows the full price struck through.
 * Tiers without a price show their price label ("Coming soon", "Price on application") at a calmer size.
 *
 * @var array<string, mixed> $tier
 * @var bool|null $cta      false hides the button (a coming-soon ladder has one shared button)
 * @var bool|null $compact  a smaller card without the includes list
 */
$cta ??= true;
$compact ??= false;
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$p = $h['tier']($tier);
$id = (string) $tier['id'];
$rec = !empty($tier['highlight']);
$link = $tier['cta'] ?? null;
$external = is_array($link) && str_starts_with((string) $link['href'], 'http');
$classes = 'c-tier' . ($rec ? ' is-rec' : '') . ($compact ? ' c-pd-tier--compact' : '');
?>
<article class="<?= e($classes) ?>" id="<?= e($id) ?>" aria-labelledby="tier-<?= e($id) ?>">
    <?php if ($p['flag'] !== null): ?><span class="c-flag<?= $p['flagOutline'] ? ' c-flag--outline' : '' ?>"><?= e($p['flag']) ?></span><?php endif; ?>
    <h3 class="c-tier-name" id="tier-<?= e($id) ?>"><?= e($tier['name']) ?></h3>
    <p class="c-price">
        <span class="c-price-amount<?= $p['tbc'] ? ' c-pd-price-tbc' : '' ?>"><?= e($p['amount']) ?></span>
        <?php if ($p['was'] !== null): ?><s class="c-price-was"><span class="u-visually-hidden">was </span><?= e($p['was']) ?></s><?php endif; ?>
        <?php if ($p['unit'] !== null): ?><span class="c-price-unit"><?= e($p['unit']) ?></span><?php endif; ?>
    </p>
    <?php if (!empty($tier['summary'])): ?><p class="c-tier-sub"><?= e($tier['summary']) ?></p><?php endif; ?>
    <?php if (!$compact && !empty($tier['includes'])): ?>
        <ul class="c-tier-list" role="list">
            <?php foreach ($tier['includes'] as $inc): ?><li><?= icon('check') ?><span><?= e($inc) ?></span></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!$compact && !empty($tier['you_provide'])): ?>
        <div class="c-pd-tier-ask">
            <h4>What we ask in return</h4>
            <ul class="c-facts" role="list"><?php foreach ($tier['you_provide'] as $ask): ?><li><?= e($ask) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($cta && is_array($link)): ?>
        <div class="c-tier-cta">
            <a class="c-btn <?= $rec ? 'c-btn--primary' : 'c-btn--secondary' ?>" href="<?= e($link['href']) ?>"<?= $external ? ' target="_blank" rel="noopener"' : '' ?>>
                <?= e($link['label']) ?><?php if ($external): ?><?= icon('external', 'c-icon--sm') ?><span class="u-visually-hidden"> (opens in a new tab)</span><?php endif; ?>
            </a>
        </div>
    <?php endif; ?>
</article>
