<?php
/**
 * A product's overview page (/<key>/), shared by the four products. The thin wrapper in pages/<key>/index.php sets
 * what differs and renders this with get_defined_vars().
 *
 * Every fact comes from content/products/<key>.php ($product) and content/licensing/<key>.php ($licensing).
 * The FAQ carries the product's FAQPage schema (on this page only); the controller adds the SoftwareApplication.
 *
 * @var string $productKey
 * @var array<string, mixed> $product
 * @var array<string, mixed> $licensing
 * @var array{h1: string, actions: list<array{label: string, href: string, primary?: bool}>, visual: string, shot?: string} $hero
 * @var array{title: string, text: string} $closing
 * @var array<string, string>|null $shotGroups  screenshot file name => feature group id (default: product/shot-groups)
 */
$shotGroups ??= (require PSC_ROOT . '/templates/partials/product/shot-groups.php')[$productKey] ?? [];
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$name = (string) $product['name'];
$key = $productKey;
$shots = $product['screenshots'] ?? [];
$shotName = static fn (array $s): string => basename((string) $s['src'], '.webp');

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/product.css')) . '">');
if (($hero['visual'] ?? '') === 'stagewatch') {
    $this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/countdown.js')) . '"></script>');
}

// The hero picture: the wrapper's named screenshot, or the first.
$heroShot = null;
if (($hero['visual'] ?? '') === 'shot' && $shots !== []) {
    foreach ($shots as $s) {
        if ($shotName($s) === ($hero['shot'] ?? '')) {
            $heroShot = $s;
        }
    }
    $heroShot ??= $shots[0];
}

// The H1 is the SEO brief's: the name as a wordmark, the category phrase under it.
$h1 = (string) $hero['h1'];
$h1Name = null;
$h1Line = $h1;
if (str_starts_with($h1, $name . ':')) {
    $h1Name = $name . ':';
    $h1Line = ltrim(substr($h1, strlen($name) + 1));
}

// Licences in one line, from the licensing file.
$paid = array_values(array_filter(array_map(static fn (array $t): ?float => isset($t['price']) && is_numeric($t['price']) && (float) $t['price'] > 0 ? (float) $t['price'] : null, $licensing['tiers'] ?? [])));
$first = $licensing['tiers'][0] ?? [];
$licenceLine = match (true) {
    $paid !== [] => 'One-off, from ' . price((string) min($paid)),
    ($licensing['pricing_status'] ?? '') === 'coming-soon' && ($first['price_label'] ?? '') === 'Coming soon' => 'Coming soon',
    default => trim((string) ($first['price_label'] ?? '') . ', ' . (string) ($first['period'] ?? ''), ', '),
};
$specs = array_filter([
    'Runs on' => $product['os'] ?? null,
    'Install' => $product['distribution'] ?? null,
    'Version' => $product['version'] ?? null,
    'Pricing' => $licenceLine,
]);

// Feature groups for the bento: a wide one with a picture, a tall one with its first facts, then five more.
$groups = $product['feature_groups'] ?? [];
$bento = array_slice($groups, 0, 7);
$fits = static fn (array $s): bool => (int) $s['width'] / max(1, (int) $s['height']) <= 2.0;
$bentoShot = null;
foreach ($shots as $s) {
    if ($bento !== [] && ($shotGroups[$shotName($s)] ?? null) === $bento[0]['id'] && $s !== $heroShot && $fits($s)) {
        $bentoShot ??= $s;
    }
}
// The strip under the hero: the next three captures not already on the page.
$strip = array_slice(array_values(array_filter($shots, static fn (array $s): bool => $s !== $heroShot && $s !== $bentoShot && $fits($s))), 0, 3);

$workflows = content('workflows');
$flows = array_values(array_filter(array_map(static fn ($slug): ?array => isset($workflows[$slug]) ? ['slug' => $slug] + $workflows[$slug] : null, $product['workflows'] ?? [])));
$related = $h['related']((string) ($product['ecosystem'] ?? ''), $key);
$anchor = ['patterns' => 'Patterns test pattern generator', 'stagewatch' => 'StageWatch stage timer', 'agmessage' => 'AGMessage back-room messaging', 'playmap' => 'PlayMap projection mapping'];
$featuresUrl = '/' . $key . '/features/';
$roadmap = array_map(static fn ($r): string => ucfirst((string) preg_replace('/^Coming:\s*/i', '', (string) $r)), $product['roadmap'] ?? []);
$ext = static fn (string $href): bool => str_starts_with($href, 'http');
?>
<section class="c-hero c-hero--product" aria-labelledby="p-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-product-tag"><span class="c-swatch"></span><span class="c-role"><?= e($product['role']) ?></span><span class="c-pd-roleline"><?= e($product['role_line']) ?></span>
                    <?php if (($product['status'] ?? '') === 'early-access'): ?><span class="c-tag">Early access</span><?php endif; ?>
                </p>
                <h1 class="c-hero-title c-pd-title" id="p-title"><?php if ($h1Name !== null): ?><span class="c-pd-title-name"><?= e($h1Name) ?></span> <span class="c-pd-title-line"><?= $h['nowrap']($h1Line) ?></span><?php else: ?><?= $h['nowrap']($h1) ?><?php endif; ?></h1>
                <p class="c-hero-lede c-pd-definition"><?= e($product['definition']) ?></p>
                <div class="c-hero-actions">
                    <?php foreach ($hero['actions'] as $a): $x = $ext($a['href']); ?>
                        <a class="c-btn <?= !empty($a['primary']) ? 'c-btn--primary' : 'c-btn--secondary' ?>" href="<?= e($a['href']) ?>"<?= $x ? ' target="_blank" rel="noopener"' : '' ?>><?= e($a['label']) ?><?php if ($x): ?><?= icon('external', 'c-icon--sm') ?><span class="u-visually-hidden"> (opens in a new tab)</span><?php endif; ?></a>
                    <?php endforeach; ?>
                </div>
                <dl class="c-specs c-pd-specs u-mt-6">
                    <?php foreach ($specs as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd<?= $label === 'Version' ? ' class="u-mono"' : '' ?>><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php if ($hero['visual'] === 'stagewatch'): ?>
                <?= $this->partial('product/visual-stagewatch') ?>
            <?php elseif ($hero['visual'] === 'agmessage'): ?>
                <?= $this->partial('product/visual-agmessage') ?>
            <?php elseif ($heroShot !== null): ?>
                <div class="c-hero-visual"><?= $this->partial('product/shot', ['shot' => $heroShot, 'eager' => true, 'sizes' => '(width >= 64rem) 54vw, 92vw']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($strip !== []): ?>
            <div class="c-shots<?= count($strip) === 2 ? ' c-shots--2' : '' ?>" data-scroll-region="<?= e($name) ?> screenshots">
                <?php foreach ($strip as $s): ?><?= $this->partial('shot', ['shot' => $s, 'sizes' => '(width >= 48rem) 30vw, 84vw']) ?><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?= $this->partial('product/subnav', ['productKey' => $key, 'product' => $product, 'class' => 'u-mt-6']) ?>
    </div>
</section>

<section class="l-section" aria-labelledby="does-title">
    <div class="l-wrap l-split c-pd-does">
        <div class="c-pd-sticky">
            <h2 id="does-title">What <?= e($name) ?> does</h2>
            <p class="c-lede u-mt-4"><?= e($h['lead']((string) $product['pitch'], (string) $product['definition'])) ?></p>
            <?php if (!empty($product['audiences'])): ?>
                <h3 class="c-pd-minor u-mt-6">Made for</h3>
                <ul class="c-pd-audience" role="list">
                    <?php foreach ($product['audiences'] as $who): ?><li><?= e($who) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="c-features c-pd-highlights">
            <?php foreach ($product['highlights'] as $hl): ?>
                <article class="c-feature"><h3><?= e($hl['title']) ?></h3><p><?= e($hl['text']) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($bento !== []): ?>
<section class="l-section l-section--tint" aria-labelledby="features-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="features-title">Key features</h2>
            <p class="c-lede"><?= e(ucfirst($h['words'](count($bento)))) ?> of the <?= e($h['words'](count($groups))) ?> feature groups at a glance, each linked to its full detail.</p>
        </div>
        <div class="c-bento c-pd-bento">
            <?php foreach ($bento as $i => $g):
                $gid = (string) $g['id'];
                $media = $i === 0 ? $bentoShot : null;
                $variant = $i === 0 ? ' c-bento-item--wide' : ($i === 1 ? ' c-bento-item--tall' : '');
                $facts = match (true) { $i === 0 => $media === null ? 3 : 2, $i === 1 => 4, default => 0 }; ?>
                <article class="c-bento-item<?= $variant ?>">
                    <?php if ($media !== null): ?>
                        <div class="c-bento-media c-screen"><img src="<?= e($media['src_small'] ?? $media['src']) ?>" width="<?= e($media['width']) ?>" height="<?= e($media['height']) ?>" alt="<?= e($media['alt']) ?>" loading="lazy" decoding="async"></div>
                    <?php else: ?>
                        <?= icon((string) ($g['icon'] ?? 'check'), 'c-icon--lg c-pd-bento-icon') ?>
                    <?php endif; ?>
                    <h3><a href="<?= e($featuresUrl . '#' . $gid) ?>"><?= e($g['title']) ?></a></h3>
                    <p><?= e($g['benefit']) ?></p>
                    <?php if ($facts > 0): ?>
                        <ul class="c-facts" role="list"><?php foreach (array_slice($g['items'], 0, $facts) as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if (!empty($g['plan'])): ?><p class="c-pd-plan"><?= icon('key', 'c-icon--sm') ?><span><span class="u-visually-hidden">Licence: </span><?= e($g['plan']) ?></span></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="c-pd-more"><a href="<?= e($featuresUrl) ?>">See every <?= e($name) ?> feature</a></p>
    </div>
</section>
<?php endif; ?>

<?php if ($flows !== []): $all = count($workflows); ?>
<section class="l-section" aria-labelledby="flows-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="flows-title">Workflows with <?= e($name) ?></h2>
            <p class="c-lede">Step-by-step guides for the jobs <?= e($name) ?> does on show day, from the first check to the last cue.</p>
        </div>
        <ul class="l-grid l-grid--2 c-pd-flows" role="list">
            <?php foreach ($flows as $f): ?>
                <li>
                    <a class="c-card c-card--link" data-product="<?= e($key) ?>" href="/workflows/<?= e($f['slug']) ?>/">
                        <span class="c-card-title"><?= e($f['title']) ?></span>
                        <span class="c-card-body"><?= e($f['summary']) ?></span>
                        <span class="c-card-footer">
                            <?php foreach ($f['products'] as $pk): $pp = product((string) $pk); if ($pp === null) { continue; } ?>
                                <span class="c-app" data-product="<?= e($pk) ?>"><i class="c-swatch"></i><?= e($pp['name']) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if (count($flows) % 2 === 1): ?>
                <li>
                    <a class="c-card c-card--link c-pd-flows-all" href="/workflows/">
                        <span class="c-card-title">See all <?= e($h['words']($all)) ?> show workflows</span>
                        <span class="c-card-body">LED walls, projector blends, conference timing, AGM back-rooms, mapping and Stream Deck control, each step by step.</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($product['integrations'])): ?>
<section class="l-section l-section--tight" aria-labelledby="works-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="works-title">Works with</h2>
            <p class="c-lede"><?= e($name) ?> connects to the gear and software already in the rig. <a href="<?= e($featuresUrl) ?>#works-with">See what each connection does</a>.</p>
        </div>
        <ul class="c-chips" role="list">
            <?php foreach ($product['integrations'] as $int): $m = $h['mark']((string) $int['name']); ?>
                <li class="c-chip">
                    <span class="c-chip-mark" aria-hidden="true"<?= $m['product'] !== null ? ' data-product="' . e($m['product']) . '"' : '' ?>><?= $m['product'] !== null ? '<span class="c-swatch"></span>' : e($m['mark']) ?></span><?= e($int['name']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($product['ai'])): $ai = $product['ai']; ?>
<section class="l-section l-section--tint" aria-labelledby="ai-title">
    <div class="l-wrap l-split l-split--even">
        <div class="c-pd-sticky">
            <h2 id="ai-title"><?= e($ai['title']) ?></h2>
            <p class="c-lede u-mt-4"><?= e($ai['text']) ?></p>
            <div class="c-flow-proposal c-pd-gate u-mt-6" aria-hidden="true">
                <div class="c-flow-node c-flow-node--ai"><h3><?= icon('idea', 'c-icon--sm') ?>The AI</h3><p>Drafts, suggests or proposes</p></div>
                <span class="c-flow-wire c-flow-wire--proposal">Proposal</span>
                <div class="c-flow-gate"><h3><?= icon('approve', 'c-icon--sm') ?>A person approves</h3><span class="c-flow-gate-state"><i class="c-lamp c-lamp--standby"></i>Standing by for you</span></div>
            </div>
            <p class="c-pd-more"><a href="/ecosystem/ai/">How AI works across the four apps</a></p>
        </div>
        <ul class="c-facts c-pd-list" role="list">
            <?php foreach ($ai['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($product['ecosystem'])): ?>
<section class="l-section" aria-labelledby="eco-title">
    <div class="l-wrap l-split">
        <div>
            <h2 id="eco-title">With the other apps</h2>
            <p class="c-pd-prose u-mt-4"><?= e($product['ecosystem']) ?></p>
        </div>
        <ul class="c-support-links c-pd-siblings" role="list">
            <?php foreach ($related as $rk): $rp = product($rk); ?>
                <li data-product="<?= e($rk) ?>"><a href="/<?= e($rk) ?>/"><span class="c-swatch"></span><span><?= e($anchor[$rk] ?? $rp['name']) ?><small><?= e($rp['role_line']) ?></small></span></a></li>
            <?php endforeach; ?>
            <li data-product="ecosystem"><a href="/ecosystem/"><span class="c-swatch"></span><span>How the apps work together<small>Four apps, one show network</small></span></a></li>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($product['architecture'])): ?>
<section class="l-section l-section--tint" aria-labelledby="built-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <h2 id="built-title">Built for show day</h2>
            <p class="c-lede">What keeps <?= e($name) ?> running when the room is full and the doors are open.</p>
        </div>
        <ul class="c-pd-cells" role="list">
            <?php foreach ($product['architecture'] as $a): ?><li><h3><?= e($a['title']) ?></h3><p><?= e($a['text']) ?></p></li><?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<section class="l-section" aria-labelledby="req-title">
    <div class="l-wrap l-split l-split--even">
        <div>
            <h2 id="req-title">Requirements</h2>
            <dl class="c-specs c-specs--rows u-mt-6">
                <?php foreach ($product['requirements'] as $r): ?><div><dt><?= e($r['label']) ?></dt><dd><?= e($r['value']) ?></dd></div><?php endforeach; ?>
            </dl>
        </div>
        <?php if ($roadmap !== []): ?>
            <section aria-labelledby="coming-title">
                <h2 id="coming-title">Coming to <?= e($name) ?></h2>
                <ul class="c-pd-roadmap u-mt-6" role="list">
                    <?php foreach ($roadmap as $r): ?><li><span class="c-badge c-badge--planned">Coming</span><span><?= e($r) ?></span></li><?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($product['faqs'])): ?>
<section class="l-section" aria-labelledby="faq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <h2 id="faq-title"><?= e($name) ?> questions</h2>
            <p class="c-lede u-mt-4">Straight answers before the get-in. Setup steps and fixes are on the <a href="/support/<?= e($key) ?>/"><?= e($name) ?> help page</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $product['faqs'], 'name' => $key . '-faq', 'openFirst' => true]) ?>
    </div>
</section>
<?php endif; ?>

<?= $this->partial('product/cta', ['productKey' => $key, 'product' => $product, 'title' => $closing['title'], 'text' => $closing['text'], 'actions' => $hero['actions']]) ?>
