<?php
/**
 * A product's licensing page (/<key>/licensing/), shared by the four products, from content/licensing/<key>.php.
 *
 *   live         tier cards (the first free tier is a card, later free tiers a slim strip), the comparison table
 *                from the matrix, extras (event pass, nonprofit, rental), policies, how to buy, FAQ
 *   coming-soon  the planned ladder with "Coming soon" and one Register interest button, no table
 *   self_run     (AGMessage) the managed offers first, then the self-run ladder that is coming
 *
 * The licensing FAQ carries its own FAQPage schema; AGMessage's managed delivery is also described as a Service.
 *
 * @var string $productKey
 * @var array<string, mixed> $product
 * @var array<string, mixed> $licensing
 * @var \Psc\View\Seo $seo
 * @var string $h1
 * @var array{title: string, text: string, actions: list<array{label: string, href: string, primary?: bool}>} $closing
 * @var list<array{label: string, href: string, primary?: bool}>|null $tierActions  under a coming-soon ladder
 * @var list<array{label: string, href: string, primary?: bool}>|null $buyActions   under the how-to-buy steps
 */
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$name = (string) $product['name'];
$key = $productKey;
$status = (string) ($licensing['pricing_status'] ?? 'live');
$selfRun = $licensing['self_run'] ?? null;
$live = $status === 'live';
$interest = '/contact/?topic=sales&product=' . rawurlencode($key);
$tierActions ??= [['label' => 'Register interest', 'href' => $interest, 'primary' => true]];
$buyActions ??= [];

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/product.css')) . '">');
if (is_array($selfRun)) {
    $seo->add([
        '@type' => 'Service',
        '@id' => $seo->canonical() . '#service',
        'name' => $name . ' managed delivery',
        'serviceType' => 'AGM back-room messaging, managed delivery',
        'description' => (string) $licensing['model'],
        'provider' => ['@id' => \Psc\View\Schema::organizationId($app->baseUrl())],
        'areaServed' => 'GB',
        'audience' => ['@type' => 'BusinessAudience', 'audienceType' => 'Event production agencies'],
        'url' => $seo->canonical(),
    ]);
}

// Later free tiers (StageWatch's free timer after the trial) are a slim strip under the cards.
$cards = [];
$strips = [];
foreach ($licensing['tiers'] as $i => $t) {
    $p = $h['tier']($t);
    if ($live && $i > 0 && $p['free']) {
        $strips[] = $t;
    } else {
        $cards[] = $t;
    }
}
$hasMatrix = $live && !empty($licensing['matrix']['groups']);
$extras = $licensing['extras'] ?? [];
$pass = null;
$deals = [];
foreach ($extras as $x) {
    if (($x['id'] ?? '') === 'event-pass') {
        $pass = $x;
    } else {
        $deals[] = $x;
    }
}
$request = '/portal/licences/request/?product=' . rawurlencode($key);
$dealCta = ['nonprofit' => 'Request with nonprofit pricing', 'rental' => 'Request a 5-pack'];
$policiesTitle = match (true) {
    is_array($selfRun) => 'Good to know before you book',
    $live => 'How ' . $name . ' licences work',
    default => 'How ' . $name . ' licences will work',
};
$buyTitle = match (true) {
    is_array($selfRun) => 'How to book AGMessage',
    $live => 'How to buy',
    default => 'How to register interest',
};
$btn = static function (array $a, string $size = ''): string {
    $x = str_starts_with($a['href'], 'http');
    return '<a class="c-btn ' . (!empty($a['primary']) ? 'c-btn--primary' : 'c-btn--secondary') . ($size !== '' ? ' ' . $size : '') . '" href="' . e($a['href']) . '"'
        . ($x ? ' target="_blank" rel="noopener"' : '') . '>' . e($a['label'])
        . ($x ? icon('external', 'c-icon--sm') . '<span class="u-visually-hidden"> (opens in a new tab)</span>' : '') . '</a>';
};
?>
<section class="c-hero c-hero--page c-pd-hero" aria-labelledby="l-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow"><?= e($name) ?></p>
            <h1 class="c-hero-title" id="l-title"><?= $h['nowrap']($h1) ?></h1>
            <div>
                <p class="c-hero-lede"><?= e($licensing['model']) ?></p>
                <p class="c-hero-note"><?= e($product['definition']) ?></p>
            </div>
            <p class="c-pd-hero-aside"><?= e($licensing['intro']) ?></p>
        </div>
        <?= $this->partial('product/subnav', ['productKey' => $key, 'product' => $product]) ?>
    </div>
</section>

<section class="l-section l-section--flush c-pd-tiers" aria-labelledby="tiers-title">
    <div class="l-wrap">
        <div class="c-section-head c-section-head--stack">
            <h2 id="tiers-title"><?= e(match (true) { is_array($selfRun) => 'Delivered for each AGM', $live => 'Choose a licence', default => 'The licences planned' }) ?></h2>
        </div>
        <div class="c-tiers">
            <?php foreach ($cards as $t): ?><?= $this->partial('product/tier', ['tier' => $t, 'cta' => $live || is_array($selfRun), 'compact' => !$live && !is_array($selfRun)]) ?><?php endforeach; ?>
        </div>
        <?php foreach ($strips as $t): $p = $h['tier']($t); $link = $t['cta'] ?? null; ?>
            <div class="c-pd-free" id="<?= e($t['id']) ?>">
                <div>
                    <h3 class="c-tier-name"><?= e($t['name']) ?></h3>
                    <p class="c-price"><span class="c-price-amount"><?= e($p['amount']) ?></span><?php if ($p['unit'] !== null): ?><span class="c-price-unit"><?= e($p['unit']) ?></span><?php endif; ?></p>
                </div>
                <div>
                    <p class="c-tier-sub"><?= e($t['summary']) ?></p>
                    <?php if (!empty($t['includes'])): ?><ul class="c-tier-list u-mt-2" role="list"><?php foreach ($t['includes'] as $inc): ?><li><?= icon('check') ?><span><?= e($inc) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
                </div>
                <?php if (is_array($link)): ?><a class="c-btn c-btn--secondary" href="<?= e($link['href']) ?>"><?= e($link['label']) ?><span class="u-visually-hidden"> the free <?= e($name) ?> timer</span></a><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$live && !is_array($selfRun)): ?>
            <div class="c-pd-tiers-note">
                <p>Prices and what each licence includes will be announced together. Register your interest to hear first.</p>
                <div class="l-cluster"><?php foreach ($tierActions as $a): ?><?= $btn($a) ?><?php endforeach; ?></div>
            </div>
        <?php endif; ?>
        <?php if ($hasMatrix): ?><p class="c-pd-jump"><a href="#compare">Compare every feature, licence by licence</a></p><?php endif; ?>
    </div>
</section>

<?php if (is_array($selfRun)): ?>
<section class="l-section" aria-labelledby="self-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div>
                <p class="c-pd-soon"><span class="c-badge c-badge--planned">Coming soon</span></p>
                <h2 id="self-title"><?= e($selfRun['title']) ?></h2>
            </div>
            <p class="c-lede"><?= e($selfRun['text']) ?></p>
        </div>
        <div class="c-tiers">
            <?php foreach ($selfRun['tiers'] as $t): ?><?= $this->partial('product/tier', ['tier' => $t, 'cta' => false, 'compact' => true]) ?><?php endforeach; ?>
        </div>
        <?php if (!empty($selfRun['cta'])): ?>
            <div class="c-pd-tiers-note">
                <p>Hear first when self-run licences and their prices are announced.</p>
                <?= $btn(['label' => $selfRun['cta']['label'], 'href' => $selfRun['cta']['href'], 'primary' => true]) ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($hasMatrix): ?>
<section class="l-section" id="compare" aria-labelledby="compare-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="compare-title">Compare every feature</h2>
            <p class="c-lede">Every <?= e($name) ?> licence side by side, from the free timer to Production.</p>
        </div>
        <?= $this->partial('product/compare', ['licensing' => $licensing, 'product' => $product]) ?>
    </div>
</section>
<?php endif; ?>

<?php if ($pass !== null || $deals !== []): ?>
<section class="l-section l-section--tint" aria-labelledby="extras-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="extras-title">Event passes and discounts</h2>
            <p class="c-lede">For one big event, for charities, churches, schools and universities, and for rental houses.</p>
        </div>
        <?php if ($pass !== null): $pp = price((string) $pass['price']); $for = trim((string) preg_replace('/^' . preg_quote($pp, '/') . '/', '', (string) ($pass['price_label'] ?? ''))); ?>
            <div class="c-pass" id="<?= e($pass['id']) ?>">
                <?= icon('ticket') ?>
                <b><?= e($pass['title']) ?> <span class="c-price-amount"><?= e($pp) ?></span><?php if ($for !== ''): ?> <span class="c-pd-pass-for"><?= e($for) ?></span><?php endif; ?></b>
                <p><?= e($pass['text']) ?></p>
                <?php if (!empty($pass['requestable'])): ?><a class="c-btn c-btn--secondary c-btn--sm" href="<?= e($request . '&tier=' . rawurlencode((string) $pass['id'])) ?>">Request an event pass</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($deals !== []): ?>
            <div class="l-grid l-grid--2 u-mt-6">
                <?php foreach ($deals as $d): ?>
                    <article class="c-card" id="<?= e($d['id']) ?>">
                        <h3 class="c-card-title"><?= e($d['title']) ?></h3>
                        <p class="c-price"><span class="c-price-amount c-pd-price-tbc"><?= e($d['price_label']) ?></span></p>
                        <p class="c-card-body"><?= e($d['text']) ?></p>
                        <?php if ($live && isset($dealCta[$d['id']])): ?><div class="c-card-footer"><a class="c-btn c-btn--secondary c-btn--sm" href="<?= e($request) ?>"><?= e($dealCta[$d['id']]) ?></a></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($licensing['policies'])): ?>
<section class="l-section" aria-labelledby="policies-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="policies-title"><?= e($policiesTitle) ?></h2>
            <?php if (is_array($selfRun)): ?>
                <p class="c-lede">What one managed delivery covers, and the promise behind the pilot.</p>
            <?php else: ?>
                <p class="c-lede">The terms in plain words. The <a href="/licensing/">guide to how licensing works</a> covers hardware IDs, licence files and moving to a new PC across the four apps.</p>
            <?php endif; ?>
        </div>
        <ul class="c-pd-cells" role="list">
            <?php foreach ($licensing['policies'] as $pol): ?><li><h3><?= e($pol['title']) ?></h3><p><?= e($pol['text']) ?></p></li><?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($licensing['how_to_buy'])): ?>
<section class="l-section l-section--tint" aria-labelledby="buy-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <h2 id="buy-title"><?= e($buyTitle) ?></h2>
            <p class="c-lede u-mt-4"><?= e(ucfirst($h['words'](count($licensing['how_to_buy'])))) ?> <?= e(match (true) {
                is_array($selfRun) => 'steps, from a rehearsal demo to the meeting day.',
                $live => 'steps, from the trial to a licence file on your show PC.',
                default => 'steps to hear first when licences and prices are announced.',
            }) ?></p>
        </div>
        <div class="c-ladder c-ladder--static c-pd-steps" data-product="<?= e($key) ?>">
            <div class="c-ladder-head" aria-hidden="true"><span>Step</span><span>What to do</span></div>
            <ol class="c-ladder-list" role="list">
                <?php foreach ($licensing['how_to_buy'] as $i => $step): ?>
                    <li class="c-cue"><span class="c-cue-q"><?= $i + 1 ?></span><div class="c-cue-body"><p><?= e($step) ?></p></div></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($buyActions !== []): ?>
                <div class="c-ladder-controls"><?php foreach ($buyActions as $a): ?><?= $btn($a, 'c-btn--sm') ?><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($licensing['faqs'])): ?>
<section class="l-section" aria-labelledby="lfaq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <h2 id="lfaq-title"><?= e(is_array($selfRun) ? 'Pricing and delivery questions' : 'Licence questions') ?></h2>
            <p class="c-lede u-mt-4">More on licences, trials and moving machines in the <a href="/support/faq/">frequently asked questions</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $licensing['faqs'], 'name' => $key . '-licence-faq', 'openFirst' => true]) ?>
    </div>
</section>
<?php endif; ?>

<?= $this->partial('product/cta', ['productKey' => $key, 'product' => $product, 'title' => $closing['title'], 'text' => $closing['text'], 'actions' => $closing['actions'], 'id' => 'licence-next']) ?>
