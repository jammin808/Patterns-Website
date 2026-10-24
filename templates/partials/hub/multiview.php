<?php
/**
 * The four products as a multiview (docs/DESIGN.md 8.12): a drawn screen, the UMD strip with the name and the role,
 * the product's own category sentence, three facts and two links. The tiles share their rows (hub.css), so the facts
 * and links line up across the wall. Facts are short restatements of each product's
 * highlights in content/products/; StageWatch's "from" price is read from its licensing file, never typed here.
 */
$keys = \Psc\Content\Content::PRODUCTS;

$from = null;
foreach ((\Psc\Content\Content::licensing('stagewatch')['tiers'] ?? []) as $t) {
    if (is_numeric($t['price'] ?? null) && (float) $t['price'] > 0) {
        $from = $from === null ? (float) $t['price'] : min($from, (float) $t['price']);
    }
}

$cards = [
    'patterns' => [
        // The summary's first sentence, shortened to sit beside the other tiles' lines; the last fact says where it runs.
        'line' => 'Line up every screen with pixel-exact test patterns, then run the show on them.',
        'facts' => ['Builds in a safe preview until you TAKE', 'Slides, web video and lower thirds built in', 'Runs from a USB stick, with no install'],
        'overview' => 'Patterns test pattern generator',
    ],
    'stagewatch' => [
        'facts' => array_values(array_filter(['One countdown on every screen in the room', 'Cues that speakers and crew confirm', $from !== null ? 'One-off licences from ' . price((string) $from) : null])),
        'overview' => 'StageWatch stage timer',
    ],
    'agmessage' => [
        'facts' => ['Documents pushed to the directors\' screens', 'Tap-to-answer questions for the stage', 'Runs on the venue network, with no internet'],
        'overview' => 'AGMessage back-room messaging',
    ],
    'playmap' => [
        'facts' => ['A wall mapped in four clicks', 'All 7 HAP codecs decoded on the GPU', 'Closing the desk never blanks a projector'],
        'overview' => 'PlayMap projection mapping',
    ],
];
?>
<div class="c-multiview hub-multiview">
    <ul class="c-multiview-grid" role="list">
        <?php foreach ($keys as $key): $p = product($key); if ($p === null) { continue; } $c = $cards[$key];
            $summary = (string) ($p['summary'] ?? '');
            $first = $c['line'] ?? (preg_match('/^(.+?[.!?])(\s|$)/u', $summary, $m) ? $m[1] : $summary); ?>
            <li class="c-product-card" data-product="<?= e($key) ?>">
                <div class="c-product-card-screen c-screen" aria-hidden="true"><?= $this->partial('hub/screen-' . $key) ?></div>
                <div class="c-umd"><span class="c-umd-bar"></span><h3><?= e($p['name']) ?></h3><?php if (($p['status'] ?? '') === 'early-access'): ?><span class="c-tag hub-umd-tag">Early access</span><?php endif; ?><span class="c-umd-role"><?= e($p['role']) ?></span></div>
                <div class="c-product-card-body">
                    <p><?= e($first) ?></p>
                    <ul class="c-facts" role="list">
                        <?php foreach ($c['facts'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                    </ul>
                    <p class="c-links"><a href="/<?= e($key) ?>/"><?= e($c['overview']) ?></a><a href="/<?= e($key) ?>/features/"><?= e($p['name']) ?> features</a></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
