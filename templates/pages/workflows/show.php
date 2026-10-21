<?php
/**
 * /workflows/<slug>/: one workflow (content/workflows.php). The steps are a cue ladder (docs/DESIGN.md 8.17) the
 * reader can step through with GO; the summary box says what it needs; HowTo and TechArticle describe it for search.
 *
 * @var array<string, mixed> $page  $page['workflow'] is the slug
 * @var \Psc\View\Seo $seo
 */
$workflows = content('workflows');
$slug = (string) ($page['workflow'] ?? '');
$wf = $workflows[$slug] ?? null;
if (!is_array($wf)) {
    return;
}
$apps = array_values(array_filter((array) ($wf['products'] ?? []), static fn ($k): bool => is_string($k) && product($k) !== null));
$lead = $apps[0] ?? 'patterns';
$leadProduct = product($lead) ?? [];
$steps = array_values((array) ($wf['steps'] ?? []));
$updatedTs = isset($page['updated']) ? strtotime((string) $page['updated']) : false;
$publisher = (string) (content('site')['publisher']['name'] ?? 'Ben Rohleder');
$base = rtrim($seo->absolute('/'), '/');

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');

// The overview link each app's cluster owns (the SEO brief's cannibalisation table).
$overviewAnchor = [
    'patterns' => 'Patterns test pattern generator',
    'stagewatch' => 'StageWatch stage timer',
    'agmessage' => 'AGMessage back-room messaging',
    'playmap' => 'PlayMap projection mapping',
];

$seo->add(\Psc\View\Schema::howTo(
    $seo->canonical(),
    (string) ($wf['h1'] ?? $wf['title']),
    (string) $wf['summary'],
    array_map(static fn (array $s): array => ['name' => (string) $s['title'], 'text' => (string) $s['text']], $steps),
));
$article = [
    '@type' => 'TechArticle',
    '@id' => $seo->canonical() . '#article',
    'headline' => (string) ($wf['h1'] ?? $wf['title']),
    'description' => (string) $wf['summary'],
    'author' => ['@type' => 'Person', 'name' => $publisher],
    'publisher' => ['@id' => \Psc\View\Schema::organizationId($base)],
    'about' => array_map(static fn (string $k): array => ['@type' => 'SoftwareApplication', '@id' => $base . '/' . $k . '/#software', 'name' => (string) (product($k)['name'] ?? $k)], $apps),
    'inLanguage' => 'en-GB',
    'isPartOf' => ['@id' => \Psc\View\Schema::websiteId($base)],
];
if ($updatedTs !== false) {
    $article['dateModified'] = date('Y-m-d', $updatedTs);
}
$seo->add($article);

$related = [];
foreach ((array) ($wf['related'] ?? []) as $r) {
    if (isset($workflows[$r])) {
        $related[$r] = $workflows[$r];
    }
}
$firstTitle = (string) ($steps[0]['title'] ?? '');

// The apps this workflow shares with its related ones, for a lede that is true of every pairing.
$shared = [];
foreach ($related as $rwf) {
    foreach (array_intersect($apps, (array) ($rwf['products'] ?? [])) as $k) {
        $shared[$k] = (string) (product($k)['name'] ?? $k);
    }
}
$shared = array_values(array_intersect_key($shared, array_flip($apps)));
$sharedText = count($shared) > 1 ? implode(', ', array_slice($shared, 0, -1)) . ' and ' . end($shared) : ($shared[0] ?? '');
?>
<section class="c-hero c-hero--page support-wf-hero" aria-labelledby="wf-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow support-wf-eyebrow"><span class="support-runbar support-runbar--mark" aria-hidden="true"><?php foreach ($apps as $k): ?><i data-product="<?= e($k) ?>"></i><?php endforeach; ?></span>Show workflow</p>
                <h1 class="c-hero-title" id="wf-title"><?= e($wf['h1'] ?? $wf['title']) ?></h1>
                <p class="c-hero-lede"><?= e($wf['summary']) ?></p>
                <div class="c-hero-actions">
                    <a class="c-btn c-btn--primary" href="#steps">See the <?= e(count($steps)) ?> steps</a>
                    <a class="c-btn c-btn--secondary" href="/downloads/#<?= e($lead) ?>">Get <?= e($leadProduct['name'] ?? '') ?></a>
                </div>
                <?php if ($updatedTs !== false): ?>
                    <p class="c-hero-note">By <?= e($publisher) ?>, updated <time datetime="<?= e(date('Y-m-d', $updatedTs)) ?>"><?= e(date_short($updatedTs)) ?></time>.</p>
                <?php endif; ?>
            </div>
            <div class="support-glance">
                <h2 class="support-glance-title" id="glance-title">Before you start</h2>
                <dl class="support-glance-list">
                    <div>
                        <dt>Apps</dt>
                        <dd class="l-cluster support-glance-apps">
                            <?php foreach ($apps as $k): ?>
                                <a class="c-app" data-product="<?= e($k) ?>" href="/<?= e($k) ?>/"><i class="c-swatch" aria-hidden="true"></i><?= e(product($k)['name'] ?? $k) ?></a>
                            <?php endforeach; ?>
                        </dd>
                    </div>
                    <div><dt>Who it is for</dt><dd><?= e($wf['audience'] ?? '') ?></dd></div>
                    <div><dt>When and how long</dt><dd><?= e($wf['duration'] ?? '') ?></dd></div>
                    <div>
                        <dt>You need</dt>
                        <dd>
                            <ul class="c-facts support-glance-needs" role="list">
                                <?php foreach ((array) ($wf['you_need'] ?? []) as $need): ?>
                                    <li><?= e($need) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--flush" id="steps" aria-labelledby="steps-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="steps-title">Step by step</h2></div>
            <p class="c-lede">Each step is a cue with the app that does it.<span class="support-js-only"> Press GO as you finish one, and the next one stands by.</span></p>
        </div>
        <div class="c-ladder support-ladder-app" data-ladder>
            <div class="c-ladder-head" aria-hidden="true"><span>Cue</span><span>Step</span><span>App</span><span>State</span></div>
            <ol class="c-ladder-list" role="list">
                <?php foreach ($steps as $i => $s): $k = (string) ($s['product'] ?? $lead); $q = 'Q' . ($i + 1); ?>
                    <li class="c-cue" data-cue="<?= e($q) ?>" data-state="<?= $i === 0 ? 'standby' : 'waiting' ?>" data-product="<?= e($k) ?>">
                        <span class="c-cue-q"><?= e($q) ?></span>
                        <div class="c-cue-body"><h3><?= e($s['title']) ?></h3><p><?= e($s['text']) ?></p></div>
                        <div class="c-cue-meta">
                            <span class="c-app"><i class="c-swatch" aria-hidden="true"></i><?= e(product($k)['name'] ?? $k) ?></span>
                            <span class="c-cue-state"><i class="c-lamp" aria-hidden="true"></i><span data-cue-label><?= $i === 0 ? 'Standby' : 'Waiting' ?></span></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="c-ladder-controls support-js-only">
                <button class="c-go" type="button" data-ladder-go>GO <small data-ladder-go-q>Q1</small></button>
                <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-ladder-back>Back</button>
                <p class="c-ladder-status" data-ladder-status aria-live="polite">Standing by: Q1, <?= e($firstTitle) ?>.</p>
            </div>
        </div>
        <div class="c-callout c-callout--success support-wf-outcome" role="note">
            <?= icon('ok') ?>
            <strong class="c-callout-title">What you end up with</strong>
            <p><?= e($wf['outcome'] ?? '') ?></p>
        </div>
    </div>
</section>

<?php if (!empty($wf['tips'])): ?>
<section class="l-section" aria-labelledby="tips-title">
    <div class="l-wrap">
        <div class="c-section-head c-section-head--stack">
            <div><h2 id="tips-title">Tips</h2></div>
        </div>
        <ul class="support-tips" role="list">
            <?php foreach ($wf['tips'] as $tip): ?>
                <li><?= e($tip) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($wf['faqs'])): ?>
<section class="l-section l-section--tint" aria-labelledby="faq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <h2 id="faq-title">Questions</h2>
            <p class="c-lede u-mt-4">About this workflow. There are more answers in the <a href="/support/faq/">frequently asked questions</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $wf['faqs'], 'name' => 'wf-faq']) ?>
    </div>
</section>
<?php endif; ?>

<section class="l-section" aria-labelledby="apps-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="apps-title"><?= count($apps) > 1 ? 'The apps in this workflow' : 'The app in this workflow' ?></h2></div>
            <p class="c-lede"><?= count($apps) > 1 ? 'Each one works on its own. On the same network, they find each other and share the show.' : e($leadProduct['name'] ?? '') . ' works on its own, and finds the other apps on the same network when the show grows.' ?></p>
        </div>
        <div class="support-wf-apps">
            <?php foreach ($apps as $k): $p = product($k) ?? []; ?>
                <article class="c-card support-wf-app<?= count($apps) === 1 ? ' support-wf-app--wide' : '' ?>" data-product="<?= e($k) ?>">
                    <h3 class="support-wf-app-name"><span class="c-swatch" aria-hidden="true"></span><?= e($p['name'] ?? $k) ?> <span class="c-role"><?= e($p['role'] ?? '') ?></span></h3>
                    <p class="c-card-body"><?= e($p['definition'] ?? '') ?></p>
                    <p class="c-links support-wf-app-links"><a href="/<?= e($k) ?>/"><?= e($overviewAnchor[$k] ?? ($p['name'] ?? $k)) ?></a><a href="/support/<?= e($k) ?>/"><?= e($p['name'] ?? $k) ?> help</a></p>
                    <div class="c-card-footer"><a class="c-btn c-btn--primary c-btn--sm" href="/downloads/#<?= e($k) ?>">Get <?= e($p['name'] ?? $k) ?></a></div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($related !== []): ?>
<section class="l-section l-section--tint" aria-labelledby="related-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="related-title">Related workflows</h2></div>
            <?php if ($sharedText !== ''): ?><p class="c-lede">More jobs for <?= e($sharedText) ?>.</p><?php endif; ?>
        </div>
        <div class="l-grid l-grid--2">
            <?php foreach ($related as $rslug => $rwf): ?>
                <?= $this->partial('support/workflow-card', ['slug' => $rslug, 'wf' => $rwf, 'tag' => 'h3']) ?>
            <?php endforeach; ?>
        </div>
        <p class="support-wf-all u-mt-6"><a href="/workflows/">See all <?= e(count($workflows)) ?> show workflows</a></p>
    </div>
</section>
<?php endif; ?>
