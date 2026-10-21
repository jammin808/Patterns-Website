<?php
/**
 * How licensing works (/licensing/): where each app stands today, the shared one-off and offline model (StageWatch's
 * terms, which Patterns and PlayMap will follow), the activation steps, the licensing FAQ (FAQPage schema here only)
 * and the way to request a licence. Facts: content/licensing/*.php and content/faq.php 'licensing'.
 *
 * @var \Psc\View\Seo $seo
 */
use Psc\Content\Content;
use Psc\View\Schema;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$lic = [];
foreach (Content::PRODUCTS as $key) {
    $lic[$key] = Content::licensing($key) ?? [];
}
$sw = $lic['stagewatch'];
$find = static function (array $list, string $field, string $value): ?array {
    foreach ($list as $row) {
        if (($row[$field] ?? null) === $value) {
            return $row;
        }
    }
    return null;
};
$from = null;
foreach (($sw['tiers'] ?? []) as $t) {
    if (is_numeric($t['price'] ?? null) && (float) $t['price'] > 0) {
        $from = $from === null ? (float) $t['price'] : min($from, (float) $t['price']);
    }
}
$prod = $find($sw['tiers'] ?? [], 'id', 'production');
$launch = $prod !== null && \Psc\Content\Content::launchPrice($prod) !== null ? price((string) \Psc\Content\Content::launchPrice($prod)) : null;
$policy = static fn (string $title): string => (string) ($find($sw['policies'] ?? [], 'title', $title)['text'] ?? '');
$extra = static fn (string $id): string => (string) ($find($sw['extras'] ?? [], 'id', $id)['text'] ?? '');
$sentences = preg_split('/(?<=\.)\s+/', (string) ($sw['intro'] ?? '')) ?: [];

$model = [
    'How a licence works' => [
        'One-off' => $policy('One-off, for ever'),
        'Offline activation' => $policy('Works offline'),
        'Tied to the show PC' => $policy('Machines'),
        'Moving to a new PC' => $policy('New PC'),
        'Updates' => $policy('Updates'),
    ],
    'Trials and ways to pay less' => [
        'Trial, then free' => implode(' ', array_slice($sentences, 0, 2)),
        'Nonprofit pricing' => $extra('nonprofit'),
        'Event passes' => $extra('event-pass'),
        'Rental houses' => $extra('rental'),
    ],
];

$status = [
    'stagewatch' => ['badge' => 'done', 'label' => 'On sale now', 'text' => (string) ($sw['model'] ?? ''), 'link' => 'StageWatch pricing'],
    'patterns' => ['badge' => 'planned', 'label' => 'Coming soon', 'text' => (string) ($lic['patterns']['model'] ?? ''), 'link' => 'Patterns licensing'],
    'playmap' => ['badge' => 'planned', 'label' => 'Coming soon', 'text' => (string) ($lic['playmap']['model'] ?? ''), 'link' => 'PlayMap licensing'],
    'agmessage' => ['badge' => 'done', 'label' => 'Managed service', 'text' => (string) ($lic['agmessage']['model'] ?? ''), 'link' => 'AGMessage pricing'],
];

$faqs = [];
foreach (content('faq') as $g) {
    if ($g['id'] === 'licensing') {
        $faqs = $g['items'];
    }
}
$intro = $faqs[0]['a'] ?? '';
$intro = preg_split('/(?<=\.)\s+/', (string) $intro)[0] ?? '';

$items = [];
foreach (['stagewatch', 'patterns', 'playmap', 'agmessage'] as $key) {
    $items[] = ['name' => (string) (product($key)['name'] ?? $key) . ' licensing', 'url' => url('/' . $key . '/licensing/')];
}
$seo->add(Schema::itemList($seo->canonical(), 'Licensing for each app', $items));
?>
<section class="c-hero c-hero--page" aria-labelledby="lic-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow">Licensing</p>
            <h1 class="c-hero-title" id="lic-title">How licensing works</h1>
            <p class="c-hero-lede"><?= e($intro) ?></p>
            <div class="c-hero-actions">
                <a class="c-btn c-btn--primary" href="/portal/licences/request/">Request a licence</a>
                <a class="c-btn c-btn--secondary" href="/stagewatch/licensing/">StageWatch pricing</a>
            </div>
        </div>
    </div>
</section>

<section class="l-section l-section--flush" id="status" aria-labelledby="status-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Today</p><h2 id="status-title">Where each app stands</h2></div>
            <p class="c-lede">StageWatch is on sale now. Patterns and PlayMap will follow the same model, and AGMessage is delivered as a managed service while self-run licences are prepared.</p>
        </div>
        <ul class="l-grid l-grid--4 hub-plain" role="list">
            <?php foreach ($status as $key => $st): $p = product($key); ?>
                <li>
                    <article class="c-card" data-product="<?= e($key) ?>">
                        <h3 class="c-card-title"><?= e($p['name'] ?? $key) ?></h3>
                        <p class="hub-card-apps hub-tag-first"><span class="c-badge c-badge--<?= e($st['badge']) ?>"><?= e($st['label']) ?></span><?php if (($p['status'] ?? '') === 'early-access'): ?><span class="c-tag">Early-access build</span><?php endif; ?></p>
                        <?php if ($key === 'stagewatch' && $from !== null): ?>
                            <p class="hub-from"><span>From</span> <b><?= e(price((string) $from)) ?></b><span>one-off</span></p>
                        <?php endif; ?>
                        <p class="c-card-body"><?= e($st['text']) ?></p>
                        <div class="c-card-footer"><a class="c-btn c-btn--secondary c-btn--sm" href="/<?= e($key) ?>/licensing/"><?= e($st['link']) ?></a></div>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="l-section l-section--tint" id="model" aria-labelledby="model-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">The model</p><h2 id="model-title">One licence model, starting with StageWatch</h2></div>
            <p class="c-lede">These are StageWatch's terms today. Patterns and PlayMap will follow the same model, and their prices and what each licence includes are still to be announced.</p>
        </div>
        <div class="l-split l-split--even">
            <?php foreach ($model as $title => $rows): $gid = 'model-' . md5($title); ?>
                <div>
                    <h3 class="hub-group-title" id="<?= e($gid) ?>"><?= e($title) ?></h3>
                    <dl class="c-specs c-specs--rows hub-rates" aria-labelledby="<?= e($gid) ?>">
                        <?php foreach ($rows as $dt => $dd): if ($dd === '') { continue; } ?>
                            <div><dt><?= e($dt) ?></dt><dd><p><?= e($dd) ?></p></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="activate" aria-labelledby="activate-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Activation</p><h2 id="activate-title">From request to licence file</h2></div>
            <p class="c-lede">You request and collect the licence online; activating it needs no internet at all. These are StageWatch's steps, and Patterns and PlayMap will work the same way.</p>
        </div>
        <div class="c-ladder c-ladder--static hub-ladder-untimed" data-product="stagewatch">
            <div class="c-ladder-head" aria-hidden="true"><span>Step</span><span>What to do</span><span>Where</span></div>
            <ol class="c-ladder-list" role="list">
                <li class="c-cue"><span class="c-cue-q">1</span><div class="c-cue-body"><h3>Copy your hardware ID</h3><p>In StageWatch, open Help → License → Plans and press Copy. Production needs two IDs: the main and the backup PC.</p></div><div class="c-cue-meta"><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>StageWatch</span></div></li>
                <li class="c-cue"><span class="c-cue-q">2</span><div class="c-cue-body"><h3>Request the licence</h3><p>Create a free client account on this site and request the licence with your hardware ID.</p></div><div class="c-cue-meta"><span class="c-app" data-product="ecosystem"><i class="c-swatch" aria-hidden="true"></i>Client portal</span></div></li>
                <li class="c-cue"><span class="c-cue-q">3</span><div class="c-cue-body"><h3>Pay, then collect the file</h3><p>Payment details arrive by email. Once paid, your licence file appears in the portal under Licences.</p></div><div class="c-cue-meta"><span class="c-app" data-product="ecosystem"><i class="c-swatch" aria-hidden="true"></i>Client portal</span></div></li>
                <li class="c-cue"><span class="c-cue-q">4</span><div class="c-cue-body"><h3>Install it and restart</h3><p>In StageWatch, open Help → License → Install license file, choose the file and restart StageWatch. No internet is needed at the venue.</p></div><div class="c-cue-meta"><span class="c-app"><i class="c-swatch" aria-hidden="true"></i>StageWatch</span></div></li>
            </ol>
        </div>
        <p class="hub-more"><a href="/portal/register/">Create a free client account</a><a href="/legal/licence-terms/">Software licence terms</a></p>
    </div>
</section>

<?php if ($faqs !== []): ?>
<section class="l-section l-section--tint" id="faq" aria-labelledby="faq-title">
    <div class="l-wrap l-split l-split--aside">
        <div>
            <p class="c-eyebrow">Questions</p>
            <h2 id="faq-title">Licences and buying, answered</h2>
            <p class="c-lede u-mt-4">Each app's licensing page has its own questions too, starting with <a href="/stagewatch/licensing/">StageWatch pricing</a>.</p>
        </div>
        <?= $this->partial('faq', ['faqs' => $faqs, 'name' => 'licensing-faq']) ?>
    </div>
</section>
<?php endif; ?>

<?= $this->partial('hub/cta', [
    'id' => 'cta-title',
    'title' => 'Ready for a licence file?',
    'text' => 'Request a StageWatch licence in the client portal, or ask about rental packs, nonprofit pricing and the apps still to launch.',
    'actions' => [
        ['label' => 'Request a licence', 'href' => '/portal/licences/request/', 'style' => 'primary'],
        ['label' => 'Ask about licences', 'href' => '/contact/?topic=sales', 'style' => 'secondary'],
    ],
]) ?>
