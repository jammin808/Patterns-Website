<?php
/**
 * /workflows/: the six show workflows as cards, an index of which app each one uses, and the ItemList for search.
 *
 * @var array<string, mixed> $page
 * @var \Psc\View\Seo $seo
 */
$workflows = content('workflows');
$products = \Psc\Content\Content::products();
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');

$items = [];
foreach ($workflows as $slug => $wf) {
    $items[] = ['name' => (string) $wf['title'], 'url' => $seo->absolute('/workflows/' . $slug . '/')];
}
$seo->add(\Psc\View\Schema::itemList($seo->canonical(), 'Show workflows', $items));

// Which workflows each app takes part in, in ecosystem order.
$byApp = [];
foreach ($products as $key => $p) {
    foreach ($workflows as $slug => $wf) {
        if (in_array($key, (array) $wf['products'], true)) {
            $byApp[$key][$slug] = $wf;
        }
    }
}
$count = count($workflows);
?>
<section class="c-hero c-hero--page support-hero-split" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow">From load-in to the last cue</p>
                <h1 class="c-hero-title" id="page-title">Show workflows</h1>
            </div>
            <div>
                <p class="c-hero-lede">Step-by-step guides for the jobs that fill a show day, from lining up an LED wall to running the whole show from one Stream Deck. Each step names the app that does it.</p>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--flush support-wf-list" aria-labelledby="all-title">
    <div class="l-wrap">
        <h2 class="u-visually-hidden" id="all-title">All <?= e($count) ?> workflows</h2>
        <div class="l-grid l-grid--3">
            <?php foreach ($workflows as $slug => $wf): ?>
                <?= $this->partial('support/workflow-card', ['slug' => $slug, 'wf' => $wf, 'tag' => 'h3']) ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" aria-labelledby="by-app-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <h2 id="by-app-title">Workflows by app</h2>
            <p class="c-lede u-mt-4">Already run one of the apps? Start with the workflows it takes part in.</p>
        </div>
        <dl class="c-specs c-specs--rows support-byapp">
            <?php foreach ($byApp as $key => $list): $p = $products[$key]; ?>
                <div data-product="<?= e($key) ?>">
                    <dt><a class="support-byapp-name" href="/<?= e($key) ?>/"><span class="c-swatch" aria-hidden="true"></span><?= e($p['name']) ?></a></dt>
                    <dd>
                        <ul class="support-byapp-links" role="list">
                            <?php foreach ($list as $slug => $wf): ?>
                                <li><a href="/workflows/<?= e($slug) ?>/"><?= e($wf['title']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>

<section class="l-section" aria-labelledby="help-title">
    <div class="l-wrap">
        <div class="support-closer">
            <div>
                <h2 id="help-title">Stuck on a step?</h2>
                <p class="c-lede u-mt-4">The assistant knows every workflow and the four apps behind them. For anything it cannot answer, a person can help.</p>
            </div>
            <div class="support-closer-actions">
                <a class="c-launcher" href="/support/" role="button" data-chat-open aria-controls="chat-panel" aria-expanded="false">
                    <span class="c-launcher-icon"><?= icon('chat') ?></span>
                    <span><b>Ask the assistant</b><small>Setup, line-up, licences and show-day fixes</small></span>
                </a>
                <p class="l-cluster"><a href="/support/">Support and help pages</a><a href="/downloads/">Downloads</a><a href="/ecosystem/">How the apps work together</a></p>
            </div>
        </div>
    </div>
</section>
