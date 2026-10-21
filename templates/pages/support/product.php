<?php
/**
 * /support/<key>/: one app's help page. Getting started as numbered steps, the system requirements, troubleshooting
 * (its own FAQPage: a different question set from the overview's FAQ), where to download, how to report a bug, the
 * app's help-related questions and the way back to its overview. <main> carries data-product, so --accent is the app's.
 *
 * @var array<string, mixed> $page
 * @var array<string, mixed>|null $product
 * @var string|null $productKey
 * @var \Psc\View\Seo $seo
 */
if ($product === null || $productKey === null) {
    return;
}
$key = $productKey;
$name = (string) $product['name'];
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');

// The product sub-navigation: the same four links as the Products menu (content/site.php).
$subnav = [];
foreach ((content('site')['nav'] ?? []) as $item) {
    foreach (($item['children'] ?? []) as $child) {
        if (($child['product'] ?? '') === $key) {
            $subnav = (array) ($child['links'] ?? []);
        }
    }
}
if ($subnav === []) {
    $subnav = [['label' => 'Overview', 'href' => '/' . $key . '/'], ['label' => 'Features', 'href' => '/' . $key . '/features/'], ['label' => 'Licensing', 'href' => '/' . $key . '/licensing/'], ['label' => 'Help', 'href' => '/support/' . $key . '/']];
}

$steps = array_values((array) ($product['getting_started'] ?? []));
$requirements = array_values((array) ($product['requirements'] ?? []));
$trouble = array_values((array) ($product['troubleshooting'] ?? []));
// Help-related questions from the app's own FAQ: everything except buying, pricing and the AI policy, which have their own pages.
$questions = array_values(array_filter((array) ($product['faqs'] ?? []), static fn (array $f): bool => !preg_match('/licen[cs]|pric|cost|buy|trial|free mode|\bAI\b/i', (string) $f['q'])));
$questions = array_slice($questions, 0, 6);
$early = ($product['status'] ?? '') === 'early-access';
$bugUrl = '/portal/tickets/new/?type=bug&product=' . rawurlencode($key);
$jump = [
    'getting-started' => 'Getting started',
    'requirements' => 'System requirements',
    'troubleshooting' => 'Troubleshooting',
    'download' => 'Download and report a bug',
];
if ($questions !== []) {
    $jump['questions'] = 'Questions';
}
?>
<section class="c-hero c-hero--page support-ph-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-product-tag"><span class="c-swatch" aria-hidden="true"></span><?= e($name) ?> <span class="c-role"><?= e($product['role'] ?? '') ?></span></p>
                <h1 class="c-hero-title" id="page-title"><?= e($name) ?> help</h1>
                <p class="c-hero-lede"><?= e($product['definition'] ?? '') ?></p>
                <div class="c-hero-actions">
                    <a class="c-btn c-btn--primary" href="/downloads/#<?= e($key) ?>">Download <?= e($name) ?></a>
                    <a class="c-btn c-btn--secondary" href="<?= e($bugUrl) ?>">Report a bug</a>
                </div>
            </div>
            <nav class="support-ph-jump" aria-labelledby="jump-title">
                <h2 class="support-ph-jump-title" id="jump-title">On this page</h2>
                <ol role="list">
                    <?php foreach ($jump as $id => $label): ?>
                        <li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        </div>
        <nav class="c-subnav" aria-label="<?= e($name) ?> pages">
            <?php foreach ($subnav as $l): ?>
                <a href="<?= e($l['href']) ?>"<?= $l['href'] === '/support/' . $key . '/' ? ' aria-current="page"' : '' ?>><?= e($l['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>

<?php if ($steps !== []): ?>
<section class="l-section l-section--flush support-ph-first" id="getting-started" aria-labelledby="start-title">
    <div class="l-wrap l-split l-split--aside">
        <div class="support-ph-side">
            <h2 id="start-title">Getting started</h2>
            <p class="c-lede u-mt-4">From the download to running your first show, in <?= e(count($steps)) ?> steps.</p>
        </div>
        <div class="c-ladder c-ladder--static support-ladder-plain">
            <ol class="c-ladder-list" role="list">
                <?php foreach ($steps as $i => $s): ?>
                    <li class="c-cue">
                        <span class="c-cue-q" aria-hidden="true"><?= e($i + 1) ?></span>
                        <div class="c-cue-body"><h3><span class="u-visually-hidden">Step <?= e($i + 1) ?>: </span><?= e($s['title']) ?></h3><p><?= e($s['text']) ?></p></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($requirements !== []): ?>
<section class="l-section l-section--tint" id="requirements" aria-labelledby="req-title">
    <div class="l-wrap l-split l-split--aside">
        <div class="support-ph-side">
            <h2 id="req-title">System requirements</h2>
            <p class="c-lede u-mt-4">What <?= e($name) ?> needs on the show machine, and the extras that only some features use.</p>
        </div>
        <dl class="c-specs c-specs--rows support-ph-reqs">
            <?php foreach ($requirements as $r): ?>
                <div><dt><?= e($r['label']) ?></dt><dd><?= e($r['value']) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>
<?php endif; ?>

<?php if ($trouble !== []): ?>
<section class="l-section" id="troubleshooting" aria-labelledby="trouble-title">
    <div class="l-wrap l-split l-split--aside">
        <div class="support-ph-side">
            <h2 id="trouble-title">Troubleshooting</h2>
            <p class="c-lede u-mt-4">Fixes for the things people run into most. If yours is not here, <a href="<?= e($bugUrl) ?>">report a bug</a> with what you see on screen.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $trouble, 'name' => 'trouble']) ?>
    </div>
</section>
<?php endif; ?>

<section class="l-section l-section--tint" id="download" aria-labelledby="download-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="download-title">Download and report a bug</h2></div>
            <p class="c-lede">Builds and bug reports both live in the client portal. A free account is enough for both.</p>
        </div>
        <div class="l-grid l-grid--2">
            <article class="c-card support-ph-card" aria-labelledby="where-title">
                <?= icon('download', 'c-icon--lg') ?>
                <h3 class="c-card-title" id="where-title">Where to download <?= e($name) ?></h3>
                <dl class="c-kv">
                    <dt>Format</dt><dd><?= e($product['distribution'] ?? '') ?></dd>
                    <dt>Runs on</dt><dd><?= e($product['os'] ?? '') ?></dd>
                    <?php if (!empty($product['version'])): ?><dt>Version</dt><dd class="u-mono"><?= e($product['version']) ?></dd><?php endif; ?>
                    <?php if ($early): ?><dt>Status</dt><dd>Early access</dd><?php endif; ?>
                </dl>
                <p class="c-card-body"><?= e($steps[0]['text'] ?? '') ?></p>
                <div class="c-card-footer">
                    <a class="c-btn c-btn--primary c-btn--sm" href="/portal/downloads/">Sign in to download</a>
                    <a class="c-btn c-btn--secondary c-btn--sm" href="/downloads/#<?= e($key) ?>">All downloads</a>
                </div>
            </article>
            <article class="c-card support-ph-card" aria-labelledby="bug-title">
                <?= icon('bug', 'c-icon--lg') ?>
                <h3 class="c-card-title" id="bug-title">How to report a <?= e($name) ?> bug</h3>
                <ol class="support-ph-steps">
                    <li>Sign in to the client portal, choose New ticket, then Report a bug.</li>
                    <li>Choose <?= e($name) ?> and how serious it is, from Low to Show-stopper.</li>
                    <li>Say what happened and what you expected, and attach up to five files, such as screenshots or the app's support information.</li>
                </ol>
                <p class="c-card-body">Bug reports stay private between you and the developer.</p>
                <div class="c-card-footer">
                    <a class="c-btn c-btn--primary c-btn--sm" href="<?= e($bugUrl) ?>">Report a <?= e($name) ?> bug</a>
                    <a class="c-btn c-btn--secondary c-btn--sm" href="/portal/tickets/new/?type=feature&amp;product=<?= e(rawurlencode($key)) ?>">Request a feature</a>
                </div>
            </article>
        </div>
    </div>
</section>

<?php if ($questions !== []): ?>
<section class="l-section" id="questions" aria-labelledby="questions-title">
    <div class="l-wrap l-split l-split--aside">
        <div class="support-ph-side">
            <h2 id="questions-title">Questions about <?= e($name) ?></h2>
            <p class="c-lede u-mt-4">More in the frequently asked questions:</p>
            <ul class="support-ph-faqlinks" role="list">
                <li><a href="/support/faq/#setup">Requirements and setup</a></li>
                <li><a href="/support/faq/#licensing">Licences and buying</a></li>
                <li><a href="/support/faq/#together">Working together</a></li>
                <li><a href="/support/faq/#portal">The client portal and support</a></li>
            </ul>
        </div>
        <?= $this->partial('faq', ['faqs' => $questions, 'name' => 'questions', 'schema' => false]) ?>
    </div>
</section>
<?php endif; ?>

<section class="l-section l-section--tint" aria-labelledby="next-title">
    <div class="l-wrap">
        <div class="support-closer">
            <div>
                <h2 id="next-title">Still stuck?</h2>
                <p class="c-lede u-mt-4">Ask the assistant in your own words. It can point you to the right fix, or to a person.</p>
                <p class="support-ph-back u-mt-6"><a href="/<?= e($key) ?>/">Back to the <?= e($name) ?> overview</a></p>
            </div>
            <div class="support-closer-actions">
                <a class="c-launcher" href="/contact/?topic=support&amp;product=<?= e(rawurlencode($key)) ?>" role="button" data-chat-open aria-controls="chat-panel" aria-expanded="false">
                    <span class="c-launcher-icon"><?= icon('chat') ?></span>
                    <span><b>Ask the assistant</b><small><?= e($name) ?> setup, licences and show-day fixes</small></span>
                </a>
                <p class="l-cluster"><a href="/contact/?topic=support&amp;product=<?= e(rawurlencode($key)) ?>">Contact us</a><a href="/support/">Support home</a><a href="/workflows/">Show workflows</a></p>
            </div>
        </div>
    </div>
</section>
