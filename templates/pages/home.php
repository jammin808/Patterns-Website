<?php
/**
 * Home (/): the one bold thing is the live vision mixer (docs/DESIGN.md 8.11). Then the colour legend, the four apps
 * as a multiview, how they connect, one workflow as a cue ladder (the steps' titles only; the workflow page has the
 * detail), the licensing status, help, the newest Industry Updates articles (a quiet row, from the latest-articles
 * file, so no database is read), a short FAQ and the closing call to action. Facts come from content/; the FAQ
 * questions carry their FAQPage schema here only.
 *
 * @var \Psc\View\Seo $seo
 */
use Psc\Content\Content;
use Psc\View\Schema;

$site = content('site');
$this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/hero.js')) . '"></script>');
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$items = [];
foreach (Content::PRODUCTS as $key) {
    $p = product($key);
    if ($p !== null) {
        $items[] = ['name' => (string) $p['name'], 'url' => url('/' . $key . '/')];
    }
}
$seo->add(Schema::itemList($seo->canonical(), 'The four Patterns Show Control apps', $items));

// The featured workflow: its steps become cues; the first two are done and the third is standing by. The "See all"
// link counts the workflows in content/workflows.php, so it never names a number the list has outgrown.
$workflowCount = count(content('workflows'));
$wf = content('workflows')['conference-timing'] ?? null;
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';

// Licensing: StageWatch's real tiers; the others' status from their own files.
$sw = Content::licensing('stagewatch') ?? [];
$tier = static function (string $id) use ($sw): ?array {
    foreach (($sw['tiers'] ?? []) as $t) {
        if (($t['id'] ?? '') === $id) {
            return $t;
        }
    }
    return null;
};
$std = $tier('standard');
$pro = $tier('pro');
$prod = $tier('production');
$trial = $tier('trial');
$launch = $prod !== null && \Psc\Content\Content::launchPrice($prod) !== null ? price((string) \Psc\Content\Content::launchPrice($prod)) : null;

// Home FAQ: general questions only (licensing questions live on /licensing/, ecosystem ones on /ecosystem/).
$pick = ['What is Patterns Show Control?', 'Which app do I need?', 'Is there a Mac or Linux version?', 'Do I need to install anything?', 'Do phones and tablets need an app?'];
$faqs = [];
foreach (content('faq') as $group) {
    foreach ($group['items'] as $f) {
        if (in_array($f['q'], $pick, true)) {
            $faqs[array_search($f['q'], $pick, true)] = $f;
        }
    }
}
ksort($faqs);
$faqs = array_values($faqs);
?>
<section class="c-hero hub-home-hero" aria-labelledby="hero-title" data-chat-tuck>
    <div class="l-wrap c-hero-grid">
        <div>
            <h1 class="c-hero-title" id="hero-title">Show control software for the people who run the room</h1>
            <p class="c-hero-lede"><?= e($site['summary']) ?></p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="/ecosystem/">Explore the ecosystem</a>
                <a class="c-btn c-btn--secondary" href="/downloads/#stagewatch">Start a free trial</a>
            </div>
            <p class="c-hero-note">The free trial is StageWatch with every feature for 14 days. The apps work with Bitfocus Companion and Stream Deck, OSC, MIDI and NDI.</p>
        </div>
        <div class="c-hero-visual">
            <div class="c-mixer" data-hero role="group" aria-label="Test pattern preview and programme">
                <div class="c-monitor c-reg c-bars-poster"><canvas data-hero-pgm role="img" aria-label="Programme monitor showing SMPTE colour bars"></canvas></div>
                <div class="c-umd">
                    <span class="c-tally c-tally--pgm">PGM</span>
                    <span class="c-umd-name" data-hero-pgm-name>SMPTE bars 75%</span>
                    <span class="c-umd-meta">1920×1200 50p</span>
                    <span class="c-umd-tc" data-hero-tc><?= e(date('H:i:s')) ?>:00</span>
                </div>
                <div class="c-takebar">
                    <div class="c-pvw">
                        <canvas data-hero-pvw width="192" height="120" aria-hidden="true"></canvas>
                        <div class="c-pvw-text"><span class="c-tally c-tally--pvw">PVW</span><span class="c-pvw-name" data-hero-pvw-name>Crosshatch 16×10</span></div>
                    </div>
                    <div class="c-take-actions">
                        <fieldset class="c-seg">
                            <legend class="u-visually-hidden">Transition</legend>
                            <label><input type="radio" name="hero-trans" value="dissolve" data-hero-trans checked><span>Dissolve</span></label>
                            <label><input type="radio" name="hero-trans" value="dip" data-hero-trans><span>Dip</span></label>
                            <label><input type="radio" name="hero-trans" value="wipe" data-hero-trans><span>Wipe</span></label>
                            <label><input type="radio" name="hero-trans" value="push" data-hero-trans><span>Push</span></label>
                        </fieldset>
                        <button class="c-btn c-btn--secondary c-btn--sm c-auto" type="button" aria-pressed="true" data-hero-auto><i class="c-lamp" aria-hidden="true"></i>Auto demo</button>
                        <button class="c-take" type="button" data-hero-take>TAKE</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="c-legend" aria-label="How colour is used">
    <div class="l-wrap c-legend-inner">
        <p class="c-legend-title">Every colour on this site means one thing. Four bars belong to the apps; green and red keep their broadcast meaning, and amber always means standing by.</p>
        <ul class="c-legend-list" role="list">
            <li><b>Reference</b><span>The ecosystem, Show Bus</span></li>
            <li><b>StageWatch</b><span>The time</span></li>
            <li><b>Patterns</b><span>The picture</span></li>
            <li><b>Preview, GO</b><span>Ready, done, safe</span></li>
            <li><b>AGMessage</b><span>The people</span></li>
            <li><b>Programme</b><span>Live, over time, errors</span></li>
            <li><b>PlayMap</b><span>Mapping and playback</span></li>
            <li><b>Standing by</b><span>STANDBY, approvals, focus</span></li>
        </ul>
    </div>
</section>

<section class="l-section l-section--flush" id="products" aria-labelledby="products-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Products</p><h2 id="products-title">One room, four jobs</h2></div>
            <p class="c-lede">Each app owns one part of the show. Use the one you need tonight, and add the others when the show grows.</p>
        </div>
        <?= $this->partial('hub/multiview') ?>
    </div>
</section>

<section class="l-section l-section--tint" id="connect" aria-labelledby="connect-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Ecosystem</p><h2 id="connect-title">How they connect</h2></div>
            <p class="c-lede">On the same network the apps find each other, share live status and confirm every command. Out of the box, Patterns, StageWatch and AGMessage hold what an AI proposes for a person, and PlayMap's high-risk steps always wait for one.</p>
        </div>
        <?= $this->partial('hub/flow') ?>
        <p class="hub-more"><a href="/ecosystem/">How the four apps work together</a><a href="/ecosystem/integrations/">Every integration, app by app</a></p>
    </div>
</section>

<?php if ($wf !== null): ?>
<section class="l-section" id="workflow" aria-labelledby="workflow-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Workflows</p><h2 id="workflow-title">A conference day, cue by cue</h2></div>
            <p class="c-lede"><?= e($wf['summary']) ?></p>
        </div>
        <div class="c-ladder hub-ladder-untimed" data-ladder>
            <div class="c-ladder-head" aria-hidden="true"><span>Cue</span><span>Step</span><span>App</span><span>State</span></div>
            <ol class="c-ladder-list" role="list">
                <?php foreach ($wf['steps'] as $i => $step):
                    $q = 'Q' . ($i + 1);
                    $state = $i < 2 ? 'done' : ($i === 2 ? 'standby' : 'waiting');
                    $sp = product((string) $step['product']); ?>
                    <li class="c-cue" data-cue="<?= e($q) ?>" data-state="<?= e($state) ?>" data-product="<?= e($step['product']) ?>">
                        <span class="c-cue-q"><?= e($q) ?></span>
                        <div class="c-cue-body"><h3><?= e($step['title']) ?></h3></div>
                        <div class="c-cue-meta"><span class="c-app"><i class="c-swatch" aria-hidden="true"></i><?= e($sp['name'] ?? $step['product']) ?></span><span class="c-cue-state"><i class="c-lamp" aria-hidden="true"></i><span data-cue-label><?= e(ucfirst($state)) ?></span></span></div>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="c-ladder-controls">
                <button class="c-go" type="button" data-ladder-go>GO <small data-ladder-go-q>Q3</small></button>
                <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-ladder-back>Back</button>
                <p class="c-ladder-status" data-ladder-status aria-live="polite">Standing by: Q3, <?= e($wf['steps'][2]['title'] ?? '') ?>.</p>
                <a href="/workflows/">See all <?= e($h['words']($workflowCount)) ?> workflows</a>
            </div>
        </div>
        <p class="hub-more"><a href="/workflows/conference-timing/">Run a conference day on time, step by step</a></p>
    </div>
</section>
<?php endif; ?>

<section class="l-section l-section--tint" id="licensing" aria-labelledby="licensing-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">Licensing</p>
            <h2 id="licensing-title">Buy once. Run it offline.</h2>
            <p class="c-lede u-mt-4">A licence is a one-off purchase and a file you install on the show PC. No subscription, no licence server and no internet at the venue.</p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--secondary" href="/licensing/">How licensing works</a>
            </div>
        </div>
        <dl class="c-specs c-specs--rows hub-rates">
            <div data-product="stagewatch">
                <dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>StageWatch</span><span class="c-badge c-badge--done">On sale now</span></dt>
                <dd>
                    <?php if ($std && $pro && $prod): ?>
                        <ul class="hub-rate-prices" role="list">
                            <li><span><?= e($std['name']) ?></span><b><?= e(price((string) $std['price'])) ?></b></li>
                            <li><span><?= e($pro['name']) ?></span><b><?= e(price((string) $pro['price'])) ?></b></li>
                            <li><span><?= e($prod['name']) ?><?= $launch !== null ? ', launch price' : '' ?></span><b><?= e($launch ?? price((string) $prod['price'])) ?><?php if ($launch !== null): ?><s><span class="u-visually-hidden">was </span><?= e(price((string) $prod['price'])) ?></s><?php endif; ?></b></li>
                        </ul>
                    <?php endif; ?>
                    <p>One-off, in pounds sterling.<?= $trial ? ' Try every feature free for ' . e((string) $trial['period']) . ', with no watermark.' : '' ?></p>
                    <p><a href="/stagewatch/licensing/">StageWatch pricing</a></p>
                </dd>
            </div>
            <div data-product="patterns">
                <dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>Patterns</span><span class="c-badge c-badge--planned">Coming soon</span></dt>
                <dd><p>Licences and prices are coming, on the same one-off, offline model as StageWatch.</p><p><a href="/patterns/licensing/">Patterns licensing</a></p></dd>
            </div>
            <div data-product="playmap">
                <dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>PlayMap</span><span class="c-badge c-badge--planned">Coming soon</span></dt>
                <dd><p>Today's early-access build is in the client portal. Licences will follow the same one-off, offline model.</p><p><a href="/playmap/licensing/">PlayMap licensing and early access</a></p></dd>
            </div>
            <div data-product="agmessage">
                <dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>AGMessage</span><span class="c-badge c-badge--done">Managed service</span></dt>
                <dd><p>Delivered for each AGM with a venue laptop and a technician, priced on application. Self-run licences are coming.</p><p><a href="/agmessage/licensing/">AGMessage pricing and delivery</a></p></dd>
            </div>
        </dl>
    </div>
</section>

<section class="l-section" id="support" aria-labelledby="support-title">
    <div class="l-wrap l-split l-split--even">
        <div>
            <p class="c-eyebrow">Support</p>
            <h2 id="support-title">Help, before the doors open</h2>
            <p class="c-lede u-mt-4">The assistant answers from the support pages for all four apps, and points you to a person when it can't.</p>
            <button class="c-launcher u-mt-6 hub-js-only" type="button" data-chat-open aria-controls="chat-panel" aria-expanded="false"><span class="c-launcher-icon"><?= icon('chat') ?></span><span><b>Ask the assistant</b><small>What the apps do, licences, downloads and setup</small></span></button>
        </div>
        <div>
            <ul class="c-support-links hub-support-links" role="list">
                <li data-product="patterns"><a href="/support/patterns/"><span class="c-swatch" aria-hidden="true"></span><span>Patterns help<small>Screens, playback, NDI and show files</small></span></a></li>
                <li data-product="stagewatch"><a href="/support/stagewatch/"><span class="c-swatch" aria-hidden="true"></span><span>StageWatch help<small>Outputs, licence files, PowerPoint, Companion</small></span></a></li>
                <li data-product="agmessage"><a href="/support/agmessage/"><span class="c-swatch" aria-hidden="true"></span><span>AGMessage help<small>The host laptop, Wi-Fi and roles</small></span></a></li>
                <li data-product="playmap"><a href="/support/playmap/"><span class="c-swatch" aria-hidden="true"></span><span>PlayMap help<small>Outputs, mapping, scanning and sync</small></span></a></li>
            </ul>
            <p class="hub-more"><a href="/support/">Support home</a><a href="/downloads/">Downloads</a><a href="/portal/login/">Client portal sign-in</a><a href="/portal/register/">Create a free account</a></p>
        </div>
    </div>
</section>

<?php $latest = content('news')['latest'] ?? []; ?>
<?= $this->partial('news/latest', [
    'items' => \Psc\News\Latest::forCategories(\Psc\App::get(), []),
    'hubs' => [],
    'title' => (string) ($latest['home_title'] ?? 'Latest from the news desk'),
    'lede' => (string) ($latest['home_lede'] ?? ''),
    'id' => 'news-latest-title',
]) ?>

<?php if ($faqs !== []): ?>
<section class="l-section l-section--tint" id="faq" aria-labelledby="faq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <p class="c-eyebrow">Questions</p>
            <h2 id="faq-title">Questions before the show</h2>
            <p class="c-lede u-mt-4">Straight answers to what people ask first. There are more in the <a href="/support/faq/">frequently asked questions</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $faqs, 'name' => 'home-faq']) ?>
    </div>
</section>
<?php endif; ?>

<?= $this->partial('hub/cta', [
    'id' => 'cta-title',
    'title' => (string) ($site['tagline'] ?? 'Four show apps. One network. Every decision stays yours.'),
    'text' => 'Start with the app tonight\'s show needs. The others find it on the network when you add them.',
    'actions' => [
        ['label' => 'Explore the ecosystem', 'href' => '/ecosystem/', 'style' => 'primary'],
        ['label' => 'Start a free trial', 'href' => '/downloads/#stagewatch', 'style' => 'secondary'],
    ],
]) ?>
