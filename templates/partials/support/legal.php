<?php
/**
 * A legal page (docs/DESIGN.md 8.31): the Markdown in content/legal/<name>.md as .c-prose in the prose column, the
 * page's name as the h1, the date it last changed and the links between the four legal pages.
 *
 * @var string $name  privacy | terms | licence-terms | cookies
 * @var array<string, mixed> $page
 */
// Each page's closing help points to the route that fits its questions (topics are ContactController::TOPICS).
$docs = [
    'privacy' => ['h1' => 'Privacy policy', 'path' => '/legal/privacy/', 'label' => 'Privacy',
        'help' => 'Send a message and choose Something else as the topic. Signed in to the client portal, you can download your data or delete your account from the Account page.',
        'contact' => '/contact/?topic=other', 'more' => ['label' => 'Account and data questions', 'href' => '/support/faq/#portal']],
    'terms' => ['h1' => 'Website and portal terms of use', 'path' => '/legal/terms/', 'label' => 'Website terms',
        'help' => 'Send a message and choose Something else as the topic.',
        'contact' => '/contact/?topic=other', 'more' => ['label' => 'Client portal questions', 'href' => '/support/faq/#portal']],
    'licence-terms' => ['h1' => 'Software licence terms', 'path' => '/legal/licence-terms/', 'label' => 'Licence terms',
        'help' => 'Send a message and choose Buying a licence or pricing, or A licence I already have. Licence holders can also ask in a ticket in the client portal.',
        'contact' => '/contact/?topic=licence', 'more' => ['label' => 'How licensing works', 'href' => '/licensing/']],
    'cookies' => ['h1' => 'Cookies', 'path' => '/legal/cookies/', 'label' => 'Cookies',
        'help' => 'Send a message and choose Something else as the topic.',
        'contact' => '/contact/?topic=other', 'more' => ['label' => 'Read the privacy policy', 'href' => '/legal/privacy/']],
];
$doc = $docs[$name] ?? $docs['privacy'];
$file = PSC_ROOT . '/content/legal/' . $name . '.md';
$markdown = is_file($file) ? (string) file_get_contents($file) : '';
$updatedTs = isset($page['updated']) ? strtotime((string) $page['updated']) : false;
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');
?>
<section class="c-hero c-hero--page support-legal-hero" aria-labelledby="legal-title">
    <div class="l-wrap l-wrap--prose">
        <div class="c-hero-grid">
            <h1 class="c-hero-title" id="legal-title"><?= e($doc['h1']) ?></h1>
            <p class="c-hero-lede"><?= e($page['description'] ?? '') ?></p>
            <?php if ($updatedTs !== false): ?>
                <p class="support-legal-date">Last updated <time datetime="<?= e(date('Y-m-d', $updatedTs)) ?>"><?= e(date_short($updatedTs)) ?></time></p>
            <?php endif; ?>
        </div>
        <nav class="c-subnav support-legal-nav" aria-label="Legal pages">
            <?php foreach ($docs as $key => $d): ?>
                <a href="<?= e($d['path']) ?>"<?= $key === $name ? ' aria-current="page"' : '' ?>><?= e($d['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>

<div class="l-section l-section--flush support-legal-body">
    <div class="l-wrap l-wrap--prose">
        <div class="c-prose"><?= md($markdown) ?></div>
        <?php if ($name === 'cookies' && \Psc\View\Analytics::forPage($app ?? \Psc\App::get()) !== ''): ?>
            <p class="support-legal-consent"><button type="button" class="c-btn c-btn--secondary" data-consent-open hidden>Change your cookie choice</button></p>
        <?php endif; ?>
    </div>
</div>

<section class="l-section l-section--tight" aria-labelledby="legal-help">
    <div class="l-wrap l-wrap--prose">
        <div class="support-legal-help">
            <div>
                <h2 id="legal-help">Questions about this page?</h2>
                <p><?= e($doc['help']) ?></p>
            </div>
            <div class="l-cluster">
                <a class="c-btn c-btn--primary" href="<?= e($doc['contact']) ?>">Contact us</a>
                <a class="c-btn c-btn--secondary" href="<?= e($doc['more']['href']) ?>"><?= e($doc['more']['label']) ?></a>
            </div>
        </div>
    </div>
</section>
