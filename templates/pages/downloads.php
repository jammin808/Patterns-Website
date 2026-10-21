<?php
/**
 * /downloads/: what is available for each app, what it needs and how to get it. The builds themselves are served
 * from the client portal, so this page makes no database calls and is the same for every visitor.
 *
 * @var array<string, mixed> $page
 * @var \Psc\View\Seo $seo
 */
$products = \Psc\Content\Content::products();
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');
$base = rtrim($seo->absolute('/'), '/');

// How each app reaches you, in the words of its own content files.
$how = [
    'patterns' => [
        'available' => 'Patterns for Windows, as a portable app. Choose the full download if your show plays or streams video: it includes the video engine.',
        'get' => 'Sign in to the client portal and open Downloads. A free account is enough. Patterns licences are coming soon, with prices announced when they launch.',
        'cta' => ['label' => 'Sign in to download Patterns', 'href' => '/portal/downloads/'],
        'more' => ['label' => 'How Patterns will be licensed', 'href' => '/patterns/licensing/'],
    ],
    'stagewatch' => [
        'available' => 'The StageWatch installer for Windows, with a 14-day trial of every feature, no watermark, from the first time you open it. After the trial StageWatch stays free as a basic, watermarked timer.',
        'get' => 'Sign in to the client portal and open Downloads; a free account is enough for the trial. When it ends, request the licence that fits your shows in the portal and install the licence file you receive.',
        'cta' => ['label' => 'Download the 14-day trial', 'href' => '/portal/downloads/'],
        'more' => ['label' => 'StageWatch pricing', 'href' => '/stagewatch/licensing/'],
    ],
    'agmessage' => [
        'available' => 'AGMessage is delivered as a managed service for each AGM: a venue laptop, AGMessage set up for your meeting and a technician through setup, rehearsal and the meeting day.',
        'get' => 'For a managed AGM, your technician brings the laptop ready to go. If AGMessage is set up for your account, download it from your client portal. To run it on your own laptop, tell us on the contact page.',
        'cta' => ['label' => 'Ask about managed delivery', 'href' => '/contact/?topic=sales&product=agmessage'],
        'more' => ['label' => 'How AGMessage is delivered', 'href' => '/agmessage/licensing/'],
    ],
    'playmap' => [
        'available' => 'PlayMap is in early access. Today\'s portable build is in the client portal, so you can put it through its paces on your own rig while licences are prepared.',
        'get' => 'Create a free client account and download the build from the portal\'s Downloads. Register your interest and you will be among the first to hear about pricing and trials.',
        'cta' => ['label' => 'Get early access', 'href' => '/portal/downloads/'],
        'more' => ['label' => 'How PlayMap will be licensed', 'href' => '/playmap/licensing/'],
    ],
];
$short = [
    'patterns' => 'Portable app',
    'stagewatch' => 'Installer and 14-day trial',
    'agmessage' => 'Managed delivery',
    'playmap' => 'Early access build',
];

// Each app as a SoftwareApplication stub that points here for its download.
foreach ($products as $key => $p) {
    $node = [
        '@type' => 'SoftwareApplication',
        '@id' => $base . '/' . $key . '/#software',
        'name' => (string) $p['name'],
        'url' => $base . '/' . $key . '/',
        'operatingSystem' => (string) ($p['schema_os'] ?? $p['os'] ?? ''),
        'applicationCategory' => (string) ($p['schema_category'] ?? 'MultimediaApplication'),
        'downloadUrl' => $seo->canonical() . '#' . $key,
    ];
    if (!empty($p['version'])) {
        $node['softwareVersion'] = (string) $p['version'];
    }
    $seo->add($node);
}
?>
<section class="c-hero c-hero--page support-dl-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow">Windows builds and Companion modules</p>
                <h1 class="c-hero-title" id="page-title">Download Patterns, StageWatch, AGMessage and PlayMap</h1>
                <p class="c-hero-lede">The Windows builds of the four apps, the Bitfocus Companion modules and their release notes are all in the client portal. Start StageWatch's 14-day trial or PlayMap's early access with a free account.</p>
                <div class="c-hero-actions">
                    <a class="c-btn c-btn--primary" href="/portal/downloads/">Sign in to download</a>
                    <a class="c-btn c-btn--secondary" href="/portal/register/">Create a free account</a>
                </div>
            </div>
            <div class="support-glance">
                <h2 class="support-glance-title" id="how-title">How downloads work</h2>
                <ol class="support-dl-how" role="list">
                    <li><span class="support-dl-n" aria-hidden="true">1</span><div><b>Create a free client account</b><span>Your name, an email address and a password, then confirm the address.</span></div></li>
                    <li><span class="support-dl-n" aria-hidden="true">2</span><div><b>Sign in and open Downloads</b><span>Every build open to your account is there, with its release notes.</span></div></li>
                    <li><span class="support-dl-n" aria-hidden="true">3</span><div><b>Add a licence when you need one</b><span>A free account covers trials and most builds. A few builds are for licence holders of that app.</span></div></li>
                </ol>
            </div>
        </div>
    </div>
</section>

<nav class="l-section l-section--flush support-dl-index" aria-label="Downloads by app">
    <div class="l-wrap">
        <ul class="c-support-links" role="list">
            <?php foreach ($products as $key => $p): ?>
                <li data-product="<?= e($key) ?>"><a href="#<?= e($key) ?>"><span class="c-swatch" aria-hidden="true"></span><span><?= e($p['name']) ?><small><?= e($short[$key] ?? '') ?></small></span></a></li>
            <?php endforeach; ?>
            <li data-product="companion"><a href="#companion"><span class="c-swatch" aria-hidden="true"></span><span>Companion modules<small>Stream Deck control</small></span></a></li>
        </ul>
    </div>
</nav>

<?php foreach ($products as $key => $p): $h = $how[$key] ?? null; if ($h === null) { continue; } $reqs = array_slice((array) ($p['requirements'] ?? []), 0, 5); ?>
<section class="l-section l-section--tight support-dl-app" id="<?= e($key) ?>" data-product="<?= e($key) ?>" aria-labelledby="<?= e($key) ?>-title">
    <div class="l-wrap l-split l-split--even">
        <div class="support-dl-main">
            <h2 class="support-dl-name" id="<?= e($key) ?>-title"><span class="c-swatch" aria-hidden="true"></span><?= e($p['name']) ?> <span class="c-role"><?= e($p['role'] ?? '') ?></span></h2>
            <p class="c-lede u-mt-4"><?= e($p['definition'] ?? '') ?></p>
            <dl class="c-specs support-dl-specs">
                <div><dt>Format</dt><dd><?= e($p['distribution'] ?? '') ?></dd></div>
                <div><dt>Runs on</dt><dd><?= e($p['os'] ?? '') ?></dd></div>
                <?php if (!empty($p['version'])): ?><div><dt>Version</dt><dd class="u-mono"><?= e($p['version']) ?></dd></div><?php endif; ?>
                <?php if (($p['status'] ?? '') === 'early-access'): ?><div><dt>Status</dt><dd>Early access</dd></div><?php endif; ?>
            </dl>
            <h3 class="support-dl-h">What is available</h3>
            <p class="support-dl-p"><?= e($h['available']) ?></p>
            <h3 class="support-dl-h">How to get it</h3>
            <p class="support-dl-p"><?= e($h['get']) ?></p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="<?= e($h['cta']['href']) ?>"><?= e($h['cta']['label']) ?></a>
                <a class="c-btn c-btn--secondary" href="<?= e($h['more']['href']) ?>"><?= e($h['more']['label']) ?></a>
            </div>
        </div>
        <div class="support-dl-reqs">
            <h3 class="support-dl-h">What <?= e($p['name']) ?> needs</h3>
            <dl class="c-specs c-specs--rows">
                <?php foreach ($reqs as $r): ?>
                    <div><dt><?= e($r['label']) ?></dt><dd><?= e($r['value']) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <p class="support-dl-links"><a href="/support/<?= e($key) ?>/#requirements">All <?= e($p['name']) ?> requirements</a><a href="/support/<?= e($key) ?>/#getting-started">Getting started with <?= e($p['name']) ?></a></p>
        </div>
    </div>
</section>
<?php endforeach; ?>

<section class="l-section l-section--tight l-section--tint support-dl-app" id="companion" data-product="companion" aria-labelledby="companion-title">
    <div class="l-wrap l-split l-split--even">
        <div class="support-dl-main">
            <h2 class="support-dl-name support-dl-name--wrap" id="companion-title"><span class="c-swatch" aria-hidden="true"></span><span>Bitfocus Companion modules</span></h2>
            <p class="c-lede u-mt-4">Patterns, StageWatch and PlayMap each have their own Companion module. Load all three into one Companion and one Stream Deck carries the picture, the clock and the playback.</p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="/portal/downloads/">Sign in for the modules</a>
                <a class="c-btn c-btn--secondary" href="/workflows/stream-deck-show-control/">Run the show from a Stream Deck</a>
            </div>
        </div>
        <dl class="c-specs c-specs--rows support-dl-modules">
            <div data-product="stagewatch"><dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>StageWatch</span></dt><dd>Comes with StageWatch. Needs Companion 5.0 or newer, and StageWatch Pro, Production or the trial.</dd></div>
            <div data-product="patterns"><dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>Patterns</span></dt><dd>In the client portal's Downloads. Needs Companion 5 or later.</dd></div>
            <div data-product="playmap"><dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>PlayMap</span></dt><dd>In the client portal's Downloads. Needs Companion 5.1 or later.</dd></div>
            <div data-product="agmessage"><dt><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>AGMessage</span></dt><dd>No module: AGMessage is used from a browser, so keep its laptop or tablet beside the deck.</dd></div>
        </dl>
    </div>
</section>

<section class="l-section" aria-labelledby="after-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="after-title">The first time you open an app</h2></div>
            <p class="c-lede">Two things Windows may ask, and what to choose.</p>
        </div>
        <div class="l-grid l-grid--2">
            <div class="c-callout c-callout--info support-dl-note" role="note">
                <?= icon('shield') ?>
                <strong class="c-callout-title">Windows SmartScreen asks you to confirm</strong>
                <p>That can happen the first time you open an app downloaded from the internet. If you downloaded it from the client portal, choose More info, then Run anyway.</p>
            </div>
            <div class="c-callout c-callout--info support-dl-note" role="note">
                <?= icon('network') ?>
                <strong class="c-callout-title">Windows Firewall asks about an app</strong>
                <p>Allow it on private networks. The apps talk to each other, to phones and tablets and to Companion over the venue network, and other devices cannot connect until Windows lets them.</p>
            </div>
        </div>
        <p class="support-dl-help u-mt-6">Something else? Each app has a help page: <a href="/support/patterns/">Patterns help</a>, <a href="/support/stagewatch/">StageWatch help</a>, <a href="/support/agmessage/">AGMessage help</a> and <a href="/support/playmap/">PlayMap help</a>, or see the <a href="/support/faq/#setup">requirements and setup questions</a>.</p>
    </div>
</section>
