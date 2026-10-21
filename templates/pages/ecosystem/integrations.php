<?php
/**
 * Integrations (/ecosystem/integrations/): every integration as a chip, grouped by category, then the full matrix
 * of which app supports what (with each integration's own words), the Companion and Stream Deck story, the NDI
 * runtime note and each product's features page. Facts: content/ecosystem.php 'integrations' and each product's
 * own 'integrations' entries.
 *
 * @var \Psc\View\Seo $seo
 */
use Psc\Content\Content;
use Psc\View\Schema;

$eco = content('ecosystem');
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$products = Content::PRODUCTS;
$name = static fn (string $key): string => (string) (product($key)['name'] ?? ucfirst($key));

// The chip's mark: a short abbreviation in place of a logo (docs/DESIGN.md 8.14).
$marks = [
    'Bitfocus Companion and Stream Deck' => 'BFC', 'Phones, tablets and web browsers' => 'WEB', 'MIDI controllers' => 'MIDI',
    'Arduino, Raspberry Pi and ESP32' => 'I/O', 'NDI®' => 'NDI', 'Vision mixers' => 'MIX', 'vMix and OBS Studio' => 'OBS',
    'Streaming over RTMP and SRT' => 'SRT', 'Capture cards and cameras' => 'CAP', 'OMT and Spout' => 'OMT',
    'Zoom and Microsoft Teams' => 'VC', 'The show network (Show Bus)' => 'BUS', 'OSC' => 'OSC',
    'HTTP, WebSocket and TCP control' => 'HTTP', 'Projectors' => 'PJ', 'Media servers and room control' => 'CTRL',
    'Excel, CSV and Google Sheets' => 'CSV', 'Art-Net and sACN' => 'DMX', 'Philips Hue' => 'HUE', 'Timecode' => 'LTC',
    'Time servers' => 'NTP', 'Microsoft PowerPoint' => 'PPT', 'PDF, Word and Excel documents' => 'PDF',
    'Google Slides, YouTube, Vimeo and web pages' => 'WWW', 'Claude by Anthropic' => 'AI', 'Local AI models' => 'LLM',
    'MCP (Model Context Protocol)' => 'MCP',
];
$byCat = [];
foreach ($eco['integrations'] as $i) {
    $byCat[$i['category']][] = $i;
}
$anchor = static fn (string $id): string => 'cat-' . $id;

// Companion: each module in its product's own words.
$modules = [];
foreach ($products as $key) {
    foreach ((product($key)['integrations'] ?? []) as $i) {
        if (str_starts_with((string) $i['name'], 'Bitfocus Companion')) {
            $modules[$key] = (string) $i['text'];
        }
    }
}
$companion = null;
$ndi = null;
foreach ($eco['integrations'] as $i) {
    if ($i['name'] === 'Bitfocus Companion and Stream Deck') {
        $companion = $i;
    }
    if ($i['name'] === 'NDI®') {
        $ndi = $i;
    }
}
$ndiSetup = null;
foreach (content('faq') as $g) {
    foreach ($g['items'] as $f) {
        if ($f['q'] === 'What do I need for NDI?') {
            $ndiSetup = $f['a'];
        }
    }
}

$list = [];
foreach ($eco['integration_categories'] as $c) {
    $list[] = ['name' => (string) $c['title'], 'url' => $seo->canonical() . '#' . $anchor((string) $c['id'])];
}
$seo->add(Schema::itemList($seo->canonical(), 'Integration categories', $list));
?>
<section class="c-hero c-hero--page" aria-labelledby="int-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow">Ecosystem</p>
            <h1 class="c-hero-title" id="int-title">Integrations: Companion, Stream Deck, NDI, OSC and MIDI</h1>
            <p class="c-hero-lede"><?= e($eco['integrations_intro']) ?></p>
            <nav class="hub-jump u-mt-6" aria-label="Integration categories">
                <?php foreach ($eco['integration_categories'] as $c): ?><a href="#<?= e($anchor($c['id'])) ?>"><?= e($c['title']) ?></a><?php endforeach; ?>
                <a href="#matrix">Which app supports what</a>
            </nav>
        </div>
    </div>
</section>

<section class="l-section l-section--flush l-section--tint" id="all" aria-labelledby="all-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">At a glance</p><h2 id="all-title">Everything the apps connect to</h2></div>
            <p class="c-lede">Grouped by what it does in the room. Under each name are the apps that support it today.</p>
        </div>
        <div class="hub-cats">
            <?php foreach ($eco['integration_categories'] as $c): $items = $byCat[$c['title']] ?? []; if ($items === []) { continue; } ?>
                <div class="hub-cat" id="<?= e($anchor($c['id'])) ?>">
                    <h3><?= e($c['title']) ?></h3>
                    <p><?= e($c['text']) ?></p>
                    <ul class="c-chips" role="list">
                        <?php foreach ($items as $i): ?>
                            <li class="c-chip"><span class="c-chip-mark" aria-hidden="true"><?= e($marks[$i['name']] ?? strtoupper(substr((string) $i['name'], 0, 3))) ?></span><span><?= e($i['name']) ?><small><?= e(implode(', ', array_map($name, $i['products']))) ?></small></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="matrix" aria-labelledby="matrix-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">The matrix</p><h2 id="matrix-title">Which app supports what</h2></div>
            <p class="c-lede">Every integration with what it does, and a tick for each app that supports it today. The product pages have the detail.</p>
        </div>
        <div class="c-table-wrap">
            <table class="c-table c-table--stack hub-matrix" role="table">
                <caption class="u-visually-hidden">Integrations and the apps that support them</caption>
                <thead role="rowgroup">
                    <tr role="row">
                        <th scope="col" role="columnheader">Integration</th>
                        <?php foreach ($products as $key): ?>
                            <th scope="col" role="columnheader" class="hub-matrix-app" data-product="<?= e($key) ?>"><span class="c-app"><i class="c-swatch" aria-hidden="true"></i><?= e($name($key)) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <?php foreach ($eco['integration_categories'] as $c): $items = $byCat[$c['title']] ?? []; if ($items === []) { continue; } ?>
                    <tbody role="rowgroup">
                        <tr class="hub-matrix-group" role="row"><th scope="rowgroup" colspan="<?= count($products) + 1 ?>" role="rowheader"><?= e($c['title']) ?></th></tr>
                        <?php foreach ($items as $i): ?>
                            <tr role="row">
                                <th scope="row" role="rowheader"><?= e($i['name']) ?><small><?= e($i['text']) ?></small></th>
                                <?php foreach ($products as $key): $yes = in_array($key, $i['products'], true); ?>
                                    <td data-label="<?= e($name($key)) ?>" role="cell" data-product="<?= e($key) ?>">
                                        <?php if ($yes): ?><span class="c-yes"><?= icon('check') ?><span class="u-visually-hidden">Supported</span></span><?php else: ?><span class="c-no"><?= icon('dash') ?><span class="u-visually-hidden">Not supported</span></span><?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <p class="c-fineprint">Some integrations need a particular licence, such as StageWatch Pro or Production; each product's features and licensing pages say which.</p>
    </div>
</section>

<?php if ($companion !== null): ?>
<section class="l-section l-section--tint" id="companion" aria-labelledby="companion-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Bitfocus Companion and Stream Deck</p><h2 id="companion-title">The picture, the clock and the playback on one Stream Deck</h2></div>
            <p class="c-lede"><?= e($companion['text']) ?></p>
        </div>
        <div class="c-features">
            <?php foreach ($modules as $key => $text): ?>
                <article class="c-feature" data-product="<?= e($key) ?>"><?= icon('deck') ?><h3>The <?= e($name($key)) ?> module</h3><p><?= e($text) ?></p></article>
            <?php endforeach; ?>
            <article class="c-feature" data-product="agmessage"><?= icon('system') ?><h3>AGMessage beside the deck</h3><p>AGMessage is used from a browser, so keep its laptop or tablet beside the deck.</p></article>
        </div>
        <div class="c-hero-actions">
            <a class="c-btn c-btn--primary" href="/workflows/stream-deck-show-control/">Run the show from a Stream Deck</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($ndi !== null): ?>
<section class="l-section" id="ndi" aria-labelledby="ndi-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">NDI®</p>
            <h2 id="ndi-title">NDI in, NDI out</h2>
            <p class="c-lede u-mt-4"><?= e($ndi['text']) ?></p>
        </div>
        <div class="l-stack">
            <div class="c-callout c-callout--info" role="note"><?= icon('info') ?><strong class="c-callout-title">The NDI Runtime is yours to install</strong><p><?= e($ndiSetup ?? 'Install the free NDI Runtime on each PC that sends or receives NDI.') ?></p></div>
            <ul class="c-chips" role="list">
                <?php foreach ($ndi['products'] as $k): ?>
                    <li class="c-chip" data-product="<?= e($k) ?>"><span class="c-chip-mark" aria-hidden="true">NDI</span><span><?= e($name($k)) ?><small><?= $k === 'stagewatch' ? 'Sends' : 'Sends and receives' ?></small></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="c-fineprint">NDI® is a registered trademark of Vizrt NDI AB.</p>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="l-section l-section--tint" id="features" aria-labelledby="features-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">In each app</p><h2 id="features-title">Every integration, in its app's own detail</h2></div>
            <p class="c-lede">Each app's features page lists what it connects to, and what each connection does.</p>
        </div>
        <ul class="l-grid l-grid--4 hub-plain" role="list">
            <?php foreach ($products as $key): $p = product($key); ?>
                <li><a class="c-card c-card--link" data-product="<?= e($key) ?>" href="/<?= e($key) ?>/features/#works-with"><span class="c-card-title"><?= e($p['name']) ?> integrations</span><span class="c-card-meta hub-tag-first"><?= e($p['role_line'] ?? '') ?></span><span class="c-card-body"><?= e(count($p['integrations'] ?? [])) ?> integrations, each with what it does.</span></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<?= $this->partial('hub/cta', [
    'id' => 'cta-title',
    'title' => 'Plug the show into the rig you already have.',
    'text' => 'See how the four apps share one show network, or put the whole show on one Stream Deck.',
    'actions' => [
        ['label' => 'Explore the ecosystem', 'href' => '/ecosystem/', 'style' => 'primary'],
        ['label' => 'Browse show workflows', 'href' => '/workflows/', 'style' => 'secondary'],
    ],
]) ?>
