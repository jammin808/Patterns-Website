<?php
/**
 * Ecosystem (/ecosystem/): the four pillars, then the picture before the detail: the signal flow, what the apps do
 * for each other, Show Bus in plain words, control surfaces, reliability, AI with approval, what is coming and the
 * ecosystem FAQ (FAQPage schema here). Every fact is content/ecosystem.php; product names and roles come from
 * content/products/. The overview links use the anchors of the SEO brief's cannibalisation table.
 *
 * @var \Psc\View\Seo $seo
 */
use Psc\Content\Content;
use Psc\View\Schema;

$eco = content('ecosystem');
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$items = [];
foreach (Content::PRODUCTS as $key) {
    $p = product($key);
    if ($p !== null) {
        $items[] = ['name' => (string) $p['name'], 'url' => url('/' . $key . '/')];
    }
}
$seo->add(Schema::itemList($seo->canonical(), 'The apps in the Patterns ecosystem', $items));

$name = static fn (string $key): string => (string) (product($key)['name'] ?? ucfirst($key));
$overview = ['patterns' => 'Patterns test pattern generator', 'stagewatch' => 'StageWatch stage timer', 'agmessage' => 'AGMessage back-room messaging', 'playmap' => 'PlayMap projection mapping'];
$chip = static fn (string $key): string => '<span class="c-app" data-product="' . e($key) . '"><i class="c-swatch" aria-hidden="true"></i>' . e((string) (product($key)['name'] ?? $key)) . '</span>';

// Connections, grouped by the apps they join.
$groups = [
    'patterns-stagewatch' => ['title' => 'Patterns and StageWatch', 'apps' => ['patterns', 'stagewatch'], 'items' => []],
    'agmessage' => ['title' => 'AGMessage and StageWatch', 'apps' => ['agmessage', 'stagewatch'], 'items' => []],
    'playmap' => ['title' => 'PlayMap with Patterns and StageWatch', 'apps' => ['playmap', 'patterns', 'stagewatch'], 'items' => []],
    'machines' => ['title' => 'Across several machines', 'apps' => ['playmap', 'stagewatch'], 'items' => []],
];
foreach ($eco['connections'] as $c) {
    $set = array_unique([$c['from'], $c['to']]);
    $g = count($set) === 1 ? 'machines' : (in_array('playmap', $set, true) ? 'playmap' : (in_array('agmessage', $set, true) ? 'agmessage' : 'patterns-stagewatch'));
    $groups[$g]['items'][] = $c;
}

$controls = array_values(array_filter($eco['integrations'], static fn (array $i): bool => $i['category'] === 'Control surfaces'));
$controlIcons = ['deck', 'system', 'filter', 'pattern'];
$network = null;
foreach ((product('playmap')['screenshots'] ?? []) as $s) {
    if (str_contains((string) $s['src'], 'network-peers')) {
        $network = $s;
    }
}
$ai = $eco['ai'];
$aiPick = array_slice($ai['principles'], 0, 2);
foreach ($ai['principles'] as $pr) {
    if (in_array($pr['title'], ['Offline first', 'Your own key, on your machine'], true)) {
        $aiPick[] = $pr;
    }
}
?>
<section class="c-hero c-hero--page" aria-labelledby="eco-title">
    <div class="l-wrap">
        <div class="c-hero-grid hub-hero-split">
            <div>
                <p class="c-eyebrow">Ecosystem</p>
                <h1 class="c-hero-title" id="eco-title"><?= e($eco['h1']) ?></h1>
                <p class="c-hero-lede"><?= e($eco['definition']) ?></p>
                <div class="c-hero-actions">
                    <a class="c-btn c-btn--primary" href="#connections">See what they do together</a>
                    <a class="c-btn c-btn--secondary" href="/ecosystem/integrations/">Browse integrations</a>
                </div>
            </div>
            <p class="c-stops hub-stops"><span class="hub-stop">Every pixel<span data-product="patterns">.</span></span> <span class="hub-stop">Every second<span data-product="stagewatch">.</span></span> <span class="hub-stop">Every person<span data-product="agmessage">.</span></span> <span class="hub-stop">Every surface<span data-product="playmap">.</span></span></p>
        </div>
    </div>
</section>

<section class="l-section l-section--flush l-section--tint" id="pillars" aria-labelledby="pillars-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">The four parts of a show</p><h2 id="pillars-title"><?= e($eco['principle']['title']) ?></h2></div>
            <p class="c-lede"><?= e($eco['principle']['text']) ?></p>
        </div>
        <div class="c-features">
            <?php foreach ($eco['pillars'] as $pl): ?>
                <article class="c-feature" data-product="<?= e($pl['product']) ?>">
                    <p><span class="c-role"><?= e($pl['role']) ?></span></p>
                    <h3><?= e($pl['line']) ?></h3>
                    <p><?= e($pl['text']) ?></p>
                    <p class="c-links"><a href="<?= e($pl['href']) ?>"><?= e($overview[$pl['product']] ?? $name($pl['product'])) ?></a></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="flow" aria-labelledby="flow-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Signal flow</p><h2 id="flow-title">How they connect</h2></div>
            <p class="c-lede">Controllers reach the apps, the apps share Show Bus, and each one feeds its part of the room. Out of the box, an AI's proposal stops at the amber gate.</p>
        </div>
        <?= $this->partial('hub/flow') ?>
    </div>
</section>

<section class="l-section l-section--tint" id="connections" aria-labelledby="connections-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Working together</p><h2 id="connections-title">What the apps do for each other</h2></div>
            <p class="c-lede">Each link below works today on the venue network. Where one needs a particular licence, it says so.</p>
        </div>
        <?php foreach ($groups as $gid => $g): if ($g['items'] === []) { continue; } ?>
            <div class="hub-pair">
                <h3 class="hub-pair-title"><span class="hub-pair-apps" aria-hidden="true"><?php foreach ($g['apps'] as $a): ?><i class="c-swatch" data-product="<?= e($a) ?>"></i><?php endforeach; ?></span><?= e($g['title']) ?></h3>
                <div class="l-grid l-grid--3">
                    <?php foreach ($g['items'] as $c): ?>
                        <article class="c-card">
                            <p class="hub-card-apps"><?= $chip($c['from']) ?><?php if ($c['to'] !== $c['from']): ?><span class="hub-wire" aria-hidden="true"></span><span class="u-visually-hidden"> with </span><?= $chip($c['to']) ?><?php endif; ?></p>
                            <h4 class="c-card-title"><?= e($c['title']) ?></h4>
                            <p class="c-card-body"><?= e($c['text']) ?></p>
                            <?php if (!empty($c['needs'])): ?><p class="c-card-footer hub-needs"><?= icon('key', 'c-icon--sm') ?><span>Needs <?= e($c['needs']) ?></span></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="l-section" id="show-bus" aria-labelledby="bus-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Show Bus</p><h2 id="bus-title"><?= e($eco['show_bus']['title']) ?></h2></div>
            <p class="c-lede"><?= e($eco['show_bus']['intro']) ?></p>
        </div>
        <div class="c-features">
            <?php foreach ($eco['show_bus']['points'] as $pt): ?>
                <article class="c-feature"><?= icon((string) ($pt['icon'] ?? 'bus')) ?><h3><?= e($pt['title']) ?></h3><p><?= e($pt['text']) ?></p></article>
            <?php endforeach; ?>
        </div>
        <div class="l-split l-split--even u-mt-8 hub-offline">
            <?php if ($network !== null): ?>
                <?= $this->partial('shot', ['shot' => $network, 'sizes' => '(width >= 64rem) 45vw, 92vw']) ?>
            <?php endif; ?>
            <div>
                <h3 id="offline-title"><?= e($eco['show_bus']['offline']['title']) ?></h3>
                <ul class="c-tier-list u-mt-4" role="list" aria-labelledby="offline-title">
                    <?php foreach ($eco['show_bus']['offline']['works'] as $w): ?><li><?= icon('check') ?><?= e($w) ?></li><?php endforeach; ?>
                </ul>
                <h3 class="u-mt-6" id="online-title">What needs an internet connection</h3>
                <ul class="c-facts u-mt-4" role="list" aria-labelledby="online-title">
                    <?php foreach ($eco['show_bus']['offline']['needs_internet'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" id="control" aria-labelledby="control-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Control surfaces</p><h2 id="control-title">Run it from the kit in your hands</h2></div>
            <p class="c-lede">Stream Decks, phones, MIDI controllers and buttons in the room all drive the same show, and the keys light from each app's live state.</p>
        </div>
        <div class="c-features">
            <?php foreach ($controls as $i => $ctl): ?>
                <article class="c-feature"><?= icon($controlIcons[$i] ?? 'deck') ?><h3><?= e($ctl['name']) ?></h3><p><?= e($ctl['text']) ?></p>
                    <p class="hub-card-apps"><?php foreach ($ctl['products'] as $k) { echo $chip($k); } ?></p></article>
            <?php endforeach; ?>
        </div>
        <p class="hub-more"><a href="/workflows/stream-deck-show-control/">Run the show from a Stream Deck</a><a href="/ecosystem/integrations/">Every integration, app by app</a></p>
    </div>
</section>

<section class="l-section" id="reliability" aria-labelledby="reliability-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Reliability</p><h2 id="reliability-title"><?= e($eco['reliability']['title']) ?></h2></div>
            <p class="c-lede"><?= e($eco['reliability']['intro']) ?></p>
        </div>
        <div class="l-grid l-grid--3">
            <?php foreach ($eco['reliability']['points'] as $pt): ?>
                <article class="c-feature"><h3><?= e($pt['title']) ?></h3><p><?= e($pt['text']) ?></p>
                    <p class="hub-card-apps"><?php foreach ($pt['products'] as $k) { echo $chip($k); } ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" id="ai" aria-labelledby="ai-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">AI with approval</p>
            <h2 id="ai-title"><?= e($ai['title']) ?></h2>
            <p class="c-lede u-mt-4"><?= e($ai['intro']) ?></p>
            <div class="c-hero-actions"><a class="c-btn c-btn--secondary" href="/ecosystem/ai/">How AI works in the apps</a></div>
        </div>
        <div class="l-grid l-grid--2">
            <?php foreach ($aiPick as $i => $pr): ?>
                <article class="c-feature"><?= icon(['approve', 'ticket', 'bus', 'key'][$i] ?? 'approve') ?><h3><?= e($pr['title']) ?></h3><p><?= e($pr['text']) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="coming" aria-labelledby="coming-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Roadmap</p><h2 id="coming-title"><?= e($eco['roadmap']['title']) ?></h2></div>
            <p class="c-lede"><?= e($eco['roadmap']['intro']) ?></p>
        </div>
        <ul class="l-grid l-grid--4 hub-coming" role="list">
            <?php foreach ($eco['roadmap']['items'] as $r):
                $text = (string) preg_replace('/^Coming:\s*/', '', (string) $r['text']); ?>
                <li class="hub-coming-item"><span class="c-badge c-badge--planned"><?= e($r['label']) ?></span><h3><?= e($r['title']) ?></h3><p><?= e(ucfirst($text)) ?></p></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="l-section l-section--tint" id="faq" aria-labelledby="faq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <p class="c-eyebrow">Questions</p>
            <h2 id="faq-title">The ecosystem, answered</h2>
            <p class="c-lede u-mt-4">For licences, downloads and each app's own questions, see the <a href="/support/faq/">frequently asked questions</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $eco['faqs'], 'name' => 'eco-faq']) ?>
    </div>
</section>

<?= $this->partial('hub/cta', [
    'id' => 'cta-title',
    'title' => 'Start with one app. Add the others when the show needs them.',
    'text' => 'On the same network they find each other, share live status and confirm every command, with no internet and no cloud.',
    'actions' => [
        ['label' => 'Browse show workflows', 'href' => '/workflows/', 'style' => 'primary'],
        ['label' => 'Go to downloads', 'href' => '/downloads/', 'style' => 'secondary'],
    ],
]) ?>
