<?php
/**
 * /support/: the inline show assistant is the first-screen feature (docs/DESIGN.md 8.28), then help for each app,
 * what the client portal is for, the FAQ and contact routes, and the show-day note.
 *
 * @var array<string, mixed> $page
 * @var \Psc\View\Seo $seo
 */
$this->share('chatInline', true);
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');
$products = \Psc\Content\Content::products();

// What each help page covers, from its troubleshooting and getting-started topics.
$covers = [
    'patterns' => 'Screens, outputs, NDI and show files',
    'stagewatch' => 'Outputs, licence files, Companion and LTC',
    'agmessage' => 'The host laptop, devices and roles',
    'playmap' => 'Media, outputs, scanning and Companion',
];

$items = [];
foreach ($products as $key => $p) {
    $items[] = ['name' => $p['name'] . ' help', 'url' => $seo->absolute('/support/' . $key . '/')];
}
$items[] = ['name' => 'Frequently asked questions', 'url' => $seo->absolute('/support/faq/')];
$seo->add(\Psc\View\Schema::itemList($seo->canonical(), 'Support guides', $items));

$portal = [
    ['icon' => 'bug', 'title' => 'Report a bug', 'text' => 'Choose the app and how serious it is, from Low to Show-stopper, and attach up to five files.', 'href' => '/portal/tickets/new/?type=bug'],
    ['icon' => 'idea', 'title' => 'Request a feature', 'text' => 'Post an idea to the feature board, where signed-in clients can comment and vote.', 'href' => '/portal/tickets/new/?type=feature'],
    ['icon' => 'question', 'title' => 'Ask a question', 'text' => 'A private question about setup, a licence or your show, answered in the portal.', 'href' => '/portal/tickets/new/?type=question'],
    ['icon' => 'download', 'title' => 'Download builds', 'text' => 'Every build open to your account, with its release notes.', 'href' => '/portal/downloads/'],
    ['icon' => 'key', 'title' => 'Collect licence files', 'text' => 'Follow each request from Requested to Issued, then download the file.', 'href' => '/portal/licences/'],
];
?>
<section class="c-hero c-hero--page support-help-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <?php /* Source order is the phone order: the intro, the assistant, then what it can help with. From 64rem
               the intro and the list share the left column and the assistant takes the right. */ ?>
        <div class="c-hero-grid support-help-grid">
            <div class="support-help-intro">
                <p class="c-eyebrow">Help for all four apps</p>
                <h1 class="c-hero-title" id="page-title">Help and support for the four apps</h1>
                <p class="c-hero-lede">Ask the show assistant, work through a getting-started guide, or sign in to the client portal to report a bug.</p>
            </div>
            <div class="support-help-chat" id="assistant">
                <?= $this->partial('chat', ['inline' => true]) ?>
            </div>
            <div class="support-can">
                <h2 class="support-can-title">What the assistant can help with</h2>
                <ul class="c-facts" role="list">
                    <li>What each app does, and which one your show needs</li>
                    <li>Licences, prices and downloads</li>
                    <li>Requirements, getting started and first fixes</li>
                    <li>How the apps work together on one network</li>
                    <li>Finding the right page here or in the client portal</li>
                </ul>
                <p class="support-can-note">It can't see your account, licences or tickets, and it never changes anything for you. For those, <a href="/portal/tickets/new/?type=question">open a ticket</a> or <a href="/contact/?topic=support">contact us</a>.</p>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--flush" aria-labelledby="apps-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="apps-title">Help for each app</h2></div>
            <p class="c-lede">Getting started, system requirements and fixes for the common snags, one page per app.</p>
        </div>
        <ul class="c-support-links support-links" role="list">
            <?php foreach ($products as $key => $p): ?>
                <li data-product="<?= e($key) ?>"><a href="/support/<?= e($key) ?>/"><span class="c-swatch" aria-hidden="true"></span><span><?= e($p['name']) ?> help<small><?= e($covers[$key] ?? $p['role_line']) ?></small></span></a></li>
            <?php endforeach; ?>
            <li data-product="ecosystem"><a href="/support/faq/"><span class="c-swatch" aria-hidden="true"></span><span>Frequently asked questions<small>Requirements, licences and the portal</small></span></a></li>
        </ul>
    </div>
</section>

<section class="l-section l-section--tint" aria-labelledby="portal-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="portal-title">In the client portal</h2></div>
            <p class="c-lede">Bug reports, ideas and questions go in the portal, next to your downloads and licence files.</p>
        </div>
        <ul class="support-actions" role="list">
            <?php foreach ($portal as $a): ?>
                <li><a class="c-card c-card--link support-action" href="<?= e($a['href']) ?>">
                    <?= icon($a['icon'], 'c-icon--lg') ?>
                    <span class="c-card-title"><?= e($a['title']) ?></span>
                    <span class="c-card-body"><?= e($a['text']) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <div class="support-account">
            <p><?= icon('user', 'c-icon--sm') ?><span>All of these happen in the client portal, and a free client account gets you in. It takes a name, an email address and a password.</span></p>
            <div class="l-cluster">
                <a class="c-btn c-btn--primary" href="/portal/register/">Create a free account</a>
                <a class="c-btn c-btn--secondary" href="/portal/login/">Sign in</a>
            </div>
        </div>
    </div>
</section>

<section class="l-section" aria-labelledby="more-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><h2 id="more-title">Still looking?</h2></div>
            <p class="c-lede">Straight answers to the questions people ask most, or a message to a person.</p>
        </div>
        <div class="l-grid l-grid--3 support-more">
            <a class="c-card c-card--link" href="/support/faq/">
                <?= icon('doc', 'c-icon--lg') ?>
                <span class="c-card-title">Frequently asked questions</span>
                <span class="c-card-body">Requirements, licences and buying, how the apps work together, the client portal and the assistant.</span>
            </a>
            <a class="c-card c-card--link" href="/contact/">
                <?= icon('mail', 'c-icon--lg') ?>
                <span class="c-card-title">Contact us</span>
                <span class="c-card-body">Licences, rental and partnership enquiries, or anything else. No account needed.</span>
            </a>
            <div class="c-callout c-callout--info support-showday" role="note">
                <?= icon('clock') ?>
                <strong class="c-callout-title">Show in the next 48 hours?</strong>
                <p>Use the <a href="/contact/?topic=support">contact form</a>, choose Help with the software, and put the show date at the top of your message, so it is clear how soon you need an answer.</p>
            </div>
        </div>
    </div>
</section>
