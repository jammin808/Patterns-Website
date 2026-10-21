<?php
/**
 * About (/about/): who makes the apps and for whom, the principles the four apps share, the four products and how
 * to get in touch. Only what content/site.php, content/pages.php and content/ecosystem.php say; no biography beyond
 * them. The Person node the AboutPage is about is in every page's base graph (Schema::person()).
 *
 * @var \Psc\View\Seo $seo
 * @var array<string, mixed> $page
 */
use Psc\Content\Content;

$site = content('site');
$eco = content('ecosystem');
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$publisher = (string) ($site['publisher']['name'] ?? 'Ben Rohleder');

$intro = preg_split('/(?<=\.)\s+/', (string) ($site['llms_intro'] ?? '')) ?: [];
$arch = [];
foreach ($eco['architecture'] as $a) {
    $arch[$a['title']] = $a['text'];
}
$aiFirst = $eco['ai']['principles'][0]['text'] ?? '';
$principles = [
    ['icon' => 'bus', 'title' => 'Offline on the venue network', 'text' => $arch['The venue network is enough'] ?? ''],
    ['icon' => 'reset', 'title' => 'Built to keep running', 'text' => (string) ($eco['reliability']['intro'] ?? '')],
    // The principle whole: what Patterns, StageWatch and AGMessage hold for a person, and what PlayMap does.
    ['icon' => 'approve', 'title' => 'People stay in charge', 'text' => implode(' ', array_slice(preg_split('/(?<=\.)\s+/', (string) $aiFirst) ?: [], 0, 3))],
    ['icon' => 'check', 'title' => 'Confirmed, not hopeful', 'text' => $arch['Confirmed, not hopeful'] ?? ''],
];
$email = (string) ($site['email'] ?? '');
$apps = array_map(static fn (string $k): string => (string) (product($k)['name'] ?? $k), Content::PRODUCTS);
?>
<section class="c-hero c-hero--page" aria-labelledby="about-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow"><?= e($site['name'] ?? 'Patterns Show Control') ?></p>
            <h1 class="c-hero-title" id="about-title">About Patterns Show Control</h1>
            <p class="c-hero-lede"><?= e($page['description'] ?? '') ?></p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="/ecosystem/">How the four apps work together</a>
                <a class="c-btn c-btn--secondary" href="#contact">Get in touch</a>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--flush" id="who" aria-labelledby="who-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">Who makes it</p>
            <h2 id="who-title">Made in the UK, for the people who run the room</h2>
        </div>
        <div class="l-stack">
            <div class="c-prose">
                <?php foreach (array_slice($intro, 1, 1) as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
            </div>
            <dl class="c-kv">
                <dt>Made by</dt><dd><?= e($publisher) ?></dd>
                <dt>Based in</dt><dd><?= e($site['publisher']['country'] ?? 'United Kingdom') ?></dd>
                <dt>Made for</dt><dd>The crews who run conferences, AGMs and live events</dd>
                <dt>The apps</dt><dd><?= e(implode(', ', array_slice($apps, 0, -1)) . ' and ' . end($apps)) ?>, for Windows</dd>
            </dl>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" id="principles" aria-labelledby="principles-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">What the apps share</p><h2 id="principles-title">Four habits of a show that cannot stop</h2></div>
            <p class="c-lede">The four apps share the same principles, so they behave alike when it matters.</p>
        </div>
        <div class="c-features">
            <?php foreach ($principles as $pr): if ($pr['text'] === '') { continue; } ?>
                <article class="c-feature"><?= icon($pr['icon']) ?><h3><?= e($pr['title']) ?></h3><p><?= e($pr['text']) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="products" aria-labelledby="products-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">The apps</p><h2 id="products-title">Four apps, one show network</h2></div>
            <p class="c-lede">Each owns one part of the show and works on its own. Together they share one network. <a href="/ecosystem/">See how they work together</a>.</p>
        </div>
        <ul class="l-grid l-grid--4 hub-plain" role="list">
            <?php foreach (Content::PRODUCTS as $key): $p = product($key); if ($p === null) { continue; } ?>
                <li>
                    <a class="c-card c-card--link" data-product="<?= e($key) ?>" href="/<?= e($key) ?>/">
                        <span class="c-card-title"><?= e($p['name']) ?></span>
                        <span class="hub-card-apps hub-tag-first"><span class="c-role"><?= e($p['role']) ?></span></span>
                        <span class="c-card-body"><?= e($p['tagline'] ?? '') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="l-section l-section--tint" id="contact" aria-labelledby="contact-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">Get in touch</p>
            <h2 id="contact-title">Talk to the developer</h2>
            <p class="c-lede u-mt-4">Questions about a licence, an AGM or a show next week? Write in, or ask the assistant first.</p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="/contact/">Send a message</a>
                <button class="c-btn c-btn--secondary hub-js-only" type="button" data-chat-open aria-controls="chat-panel" aria-expanded="false"><?= icon('chat') ?>Ask the assistant</button>
            </div>
        </div>
        <dl class="c-specs c-specs--rows hub-rates">
            <?php if ($email !== ''): ?><div><dt>Email</dt><dd><p><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p></dd></div><?php endif; ?>
            <div><dt>Contact form</dt><dd><p>For sales, support, rental and partnership questions. <a href="/contact/">Open the contact form</a></p></dd></div>
            <div><dt>Support</dt><dd><p>Guides, troubleshooting and answers for each app. <a href="/support/">Go to support</a></p></dd></div>
            <div><dt>Client portal</dt><dd><p>Downloads, licence files, bug reports and feature requests. <a href="/portal/login/">Sign in to the client portal</a></p></dd></div>
        </dl>
    </div>
</section>
