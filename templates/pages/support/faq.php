<?php
/**
 * /support/faq/: every group of content/faq.php under its own h2, with an in-page index that stays in view on wide
 * screens. One FAQPage node covers every question on the page (the accordions themselves add none).
 *
 * @var array<string, mixed> $page
 * @var \Psc\View\Seo $seo
 */
$groups = array_values(array_filter(content('faq'), static fn ($g): bool => is_array($g) && !empty($g['items'])));
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');

$all = [];
$total = 0;
foreach ($groups as $g) {
    foreach ($g['items'] as $f) {
        $all[] = ['q' => (string) $f['q'], 'a' => trim((string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', (string) $f['a']))];
        $total++;
    }
}
$seo->add(\Psc\View\Schema::faq($seo->canonical(), $all));
?>
<section class="c-hero c-hero--page" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow">Support</p>
            <h1 class="c-hero-title" id="page-title">Frequently asked questions</h1>
            <p class="c-hero-lede">Straight answers on requirements, licences, offline use, the client portal and how the four apps work together on one show network.</p>
        </div>
    </div>
</section>

<div class="l-section l-section--flush support-faq">
    <div class="l-wrap l-split l-split--aside support-faq-layout">
        <div class="support-faq-side">
            <nav class="support-index" aria-labelledby="index-title">
                <h2 class="support-index-title" id="index-title"><?= e($total) ?> questions in <?= e(count($groups)) ?> groups</h2>
                <ol class="support-index-list" role="list">
                    <?php foreach ($groups as $g): ?>
                        <li><a href="#<?= e($g['id']) ?>"><span><?= e($g['title']) ?></span><span class="c-tabs-count"><?= e(count($g['items'])) ?><span class="u-visually-hidden"> questions</span></span></a></li>
                    <?php endforeach; ?>
                </ol>
                <a class="c-launcher support-index-ask" href="/support/" role="button" data-chat-open aria-controls="chat-panel" aria-expanded="false">
                    <span class="c-launcher-icon"><?= icon('chat') ?></span>
                    <span><b>Ask the assistant</b><small>Ask in your own words</small></span>
                </a>
            </nav>
        </div>
        <div class="support-faq-groups">
            <?php foreach ($groups as $g): $gid = (string) $g['id']; ?>
                <section class="support-faq-group" id="<?= e($gid) ?>" aria-labelledby="<?= e($gid) ?>-title">
                    <h2 id="<?= e($gid) ?>-title"><?= e($g['title']) ?></h2>
                    <?= $this->partial('faq', ['faqs' => $g['items'], 'name' => 'faq-' . $gid, 'schema' => false]) ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<section class="l-section l-section--tint" aria-labelledby="more-title">
    <div class="l-wrap">
        <div class="support-closer">
            <div>
                <h2 id="more-title">Didn't find your answer?</h2>
                <p class="c-lede u-mt-4">Each app has a help page with getting started, requirements and troubleshooting. For anything else, send a message.</p>
            </div>
            <div class="support-closer-actions">
                <ul class="c-support-links support-links support-links--stack" role="list">
                    <?php foreach (\Psc\Content\Content::products() as $key => $p): ?>
                        <li data-product="<?= e($key) ?>"><a href="/support/<?= e($key) ?>/"><span class="c-swatch" aria-hidden="true"></span><span><?= e($p['name']) ?> help</span></a></li>
                    <?php endforeach; ?>
                </ul>
                <p class="l-cluster"><a href="/contact/">Contact us</a><a href="/support/">Support home</a><a href="/downloads/">Downloads</a></p>
            </div>
        </div>
    </div>
</section>
