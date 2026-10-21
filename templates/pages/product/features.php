<?php
/**
 * A product's features page (/<key>/features/), shared by the four products: an index of the feature groups beside
 * one section per group (its id is the anchor the overview and the SEO brief link to), then every integration and
 * the glossary. The wrapper in pages/<key>/features.php sets the H1 and the closing step.
 *
 * Adds an ItemList of the feature groups to the page's structured data.
 *
 * @var string $productKey
 * @var array<string, mixed> $product
 * @var array<string, mixed> $licensing
 * @var \Psc\View\Seo $seo
 * @var array{h1: string, actions: list<array{label: string, href: string, primary?: bool}>} $hero
 * @var array{title: string, text: string} $closing
 * @var array<string, string>|null $shotGroups  screenshot file name => feature group id (default: product/shot-groups)
 */
$shotGroups ??= (require PSC_ROOT . '/templates/partials/product/shot-groups.php')[$productKey] ?? [];
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$name = (string) $product['name'];
$key = $productKey;
$groups = $product['feature_groups'] ?? [];
$shots = $product['screenshots'] ?? [];

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/product.css')) . '">');
$seo->add(\Psc\View\Schema::itemList(
    $seo->canonical(),
    $name . ' feature groups',
    array_map(static fn (array $g): array => ['name' => (string) $g['title'], 'url' => $seo->canonical() . '#' . $g['id']], $groups),
));

$byGroup = [];
foreach ($shots as $s) {
    $gid = $shotGroups[basename((string) $s['src'], '.webp')] ?? null;
    if ($gid !== null) {
        $byGroup[$gid][] = $s;
    }
}
$workflows = content('workflows');
$flows = array_values(array_filter(array_map(static fn ($slug): ?array => isset($workflows[$slug]) ? ['slug' => $slug] + $workflows[$slug] : null, $product['workflows'] ?? [])));
$index = array_map(static fn (array $g): array => ['id' => (string) $g['id'], 'title' => (string) $g['title']], $groups);
if (!empty($product['integrations'])) {
    $index[] = ['id' => 'works-with', 'title' => 'Integrations'];
}
if ($flows !== []) {
    $index[] = ['id' => 'workflows', 'title' => 'Workflows'];
}
if (!empty($product['glossary'])) {
    $index[] = ['id' => 'glossary', 'title' => 'Glossary'];
}
?>
<section class="c-hero c-hero--page c-pd-hero" aria-labelledby="f-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow"><?= e($name) ?></p>
            <h1 class="c-hero-title" id="f-title"><?= $h['nowrap']($hero['h1']) ?></h1>
            <p class="c-hero-lede"><?= e($product['definition']) ?></p>
            <div class="c-hero-actions">
                <?php foreach ($hero['actions'] as $a): $x = str_starts_with($a['href'], 'http'); ?>
                    <a class="c-btn <?= !empty($a['primary']) ? 'c-btn--primary' : 'c-btn--secondary' ?>" href="<?= e($a['href']) ?>"<?= $x ? ' target="_blank" rel="noopener"' : '' ?>><?= e($a['label']) ?><?php if ($x): ?><?= icon('external', 'c-icon--sm') ?><span class="u-visually-hidden"> (opens in a new tab)</span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?= $this->partial('product/subnav', ['productKey' => $key, 'product' => $product]) ?>
    </div>
</section>

<div class="l-wrap c-pd-layout">
    <?php /* Beside the groups from 64rem; above them on narrower screens, folded so the first group starts on the first screen. */ ?>
    <nav class="c-pd-index" aria-labelledby="index-title">
        <p class="c-pd-index-title" id="index-title">On this page</p>
        <ol role="list">
            <?php foreach ($index as $it): ?><li><a href="#<?= e($it['id']) ?>"><?= e($it['title']) ?></a></li><?php endforeach; ?>
        </ol>
    </nav>
    <nav class="c-pd-index-sm" aria-labelledby="index-sm-title">
        <details>
            <summary><span id="index-sm-title">On this page</span> <span class="c-pd-index-count"><?= e(count($index)) ?> sections</span><?= icon('chevron', 'c-icon--sm') ?></summary>
            <ol role="list">
                <?php foreach ($index as $it): ?><li><a href="#<?= e($it['id']) ?>"><?= e($it['title']) ?></a></li><?php endforeach; ?>
            </ol>
        </details>
    </nav>

    <div class="c-pd-groups">
        <?php foreach ($groups as $g): $gid = (string) $g['id']; $gs = $byGroup[$gid] ?? []; ?>
            <section class="c-pd-group" id="<?= e($gid) ?>" aria-labelledby="<?= e($gid) ?>-title">
                <div class="c-pd-group-head">
                    <?= icon((string) ($g['icon'] ?? 'check')) ?>
                    <h2 id="<?= e($gid) ?>-title"><?= e($g['title']) ?></h2>
                    <p class="c-lede"><?= e($g['benefit']) ?></p>
                </div>
                <?php if (!empty($g['plan'])): ?>
                    <p class="c-pd-plan"><?= icon('key', 'c-icon--sm') ?><span><span class="u-visually-hidden">Licence: </span><?= e($g['plan']) ?>. <a href="/<?= e($key) ?>/licensing/">Compare <?= e($name) ?> licences</a></span></p>
                <?php endif; ?>
                <ul class="c-facts c-pd-facts" role="list">
                    <?php foreach ($g['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?>
                </ul>
                <?php if (count($gs) === 1): ?>
                    <?= $this->partial('product/shot', ['shot' => $gs[0], 'sizes' => '(width >= 64rem) 62vw, 92vw']) ?>
                <?php elseif (count($gs) > 1): ?>
                    <div class="c-shots c-shots--2" data-scroll-region="<?= e($g['title']) ?>: screenshots">
                        <?php foreach ($gs as $s): ?><?= $this->partial('product/shot', ['shot' => $s, 'sizes' => '(width >= 64rem) 31vw, 84vw']) ?><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if (!empty($product['integrations'])): ?>
            <section class="c-pd-group" id="works-with" aria-labelledby="works-with-title">
                <div class="c-pd-group-head">
                    <?= icon('bus') ?>
                    <h2 id="works-with-title">Integrations</h2>
                    <p class="c-lede">What <?= e($name) ?> connects to, and what each connection does.</p>
                </div>
                <div class="c-features c-pd-integrations">
                    <?php foreach ($product['integrations'] as $int): $m = $h['mark']((string) $int['name']); ?>
                        <article class="c-feature">
                            <span class="c-chip-mark" aria-hidden="true"<?= $m['product'] !== null ? ' data-product="' . e($m['product']) . '"' : '' ?>><?= $m['product'] !== null ? '<span class="c-swatch"></span>' : e($m['mark']) ?></span>
                            <h3><?= e($int['name']) ?></h3>
                            <p><?= e($int['text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="c-pd-more u-mt-0"><a href="/ecosystem/integrations/">Integrations across the four apps</a></p>
            </section>
        <?php endif; ?>

        <?php if ($flows !== []): ?>
            <section class="c-pd-group" id="workflows" aria-labelledby="workflows-title">
                <div class="c-pd-group-head">
                    <?= icon('play') ?>
                    <h2 id="workflows-title">Workflows with <?= e($name) ?></h2>
                    <p class="c-lede">Step-by-step workflows that use these features on show day.</p>
                </div>
                <ul class="c-support-links c-pd-flowlinks" role="list">
                    <?php foreach ($flows as $f): ?>
                        <li><a href="/workflows/<?= e($f['slug']) ?>/"><span class="c-swatch"></span><span><?= e($f['title']) ?> <small><?= e($f['duration'] ?? 'Step by step') ?></small></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($product['glossary'])): ?>
            <section class="c-pd-group" id="glossary" aria-labelledby="glossary-title">
                <div class="c-pd-group-head">
                    <?= icon('doc') ?>
                    <h2 id="glossary-title">Glossary</h2>
                    <p class="c-lede">The words <?= e($name) ?> uses on its screens and in its help, in plain English.</p>
                </div>
                <dl class="c-pd-glossary">
                    <?php foreach ($product['glossary'] as $gl): ?><div><dt><?= e($gl['term']) ?></dt><dd><?= e($gl['definition']) ?></dd></div><?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>
    </div>
</div>

<?= $this->partial('product/cta', ['productKey' => $key, 'product' => $product, 'title' => $closing['title'], 'text' => $closing['text'], 'actions' => $hero['actions']]) ?>
