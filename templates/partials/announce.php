<?php
/* The launch-price strip (docs/DESIGN.md 8.2), from StageWatch's licensing data; hidden once dismissed. */
$lic = \Psc\Content\Content::licensing('stagewatch');
$production = null;
foreach (($lic['tiers'] ?? []) as $t) {
    if (($t['id'] ?? '') === 'production') {
        $production = $t;
    }
}
if ($production === null || empty($production['price_note']) || !preg_match('/£\s?(\d+)/', (string) $production['price_note'], $m)) {
    return;
}
?>
<div class="c-announce" data-dismissible="launch-2026" role="region" aria-label="Announcement">
    <div class="l-wrap c-announce-bar">
        <p class="c-announce-text"><i class="c-lamp c-lamp--standby" aria-hidden="true"></i>
            <span>StageWatch Production: <b>£<?= e($m[1]) ?></b> <s><span class="u-visually-hidden">was </span><?= e(price((string) $production['price'])) ?></s> launch price, one-off.</span>
            <a href="/stagewatch/licensing/">See pricing</a></p>
        <button class="c-announce-close" type="button" data-dismiss aria-label="Dismiss the announcement"><?= icon('close', 'c-icon--sm') ?></button>
    </div>
</div>
