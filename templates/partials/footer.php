<?php
/* The site footer (docs/DESIGN.md 8.29), from content/site.php. */
$site = content('site');
$productHome = ['/patterns/' => 'patterns', '/stagewatch/' => 'stagewatch', '/agmessage/' => 'agmessage', '/playmap/' => 'playmap'];
?>
<footer class="c-footer">
    <span class="c-bars" aria-hidden="true"></span>
    <div class="l-wrap">
        <div class="c-footer-grid">
            <div class="c-footer-brand">
                <a class="c-brand" href="/" aria-label="Patterns Show Control, home"><span class="c-brand-mark" aria-hidden="true"></span><span class="c-brand-name">Patterns <span>Show Control</span></span></a>
                <p><?= e($site['footer_note'] ?? '') ?></p>
            </div>
            <?php foreach (($site['footer'] ?? []) as $i => $col): $id = 'ft-' . $i; ?>
                <nav aria-labelledby="<?= e($id) ?>">
                    <h2 class="c-footer-title" id="<?= e($id) ?>"><?= e($col['title']) ?></h2>
                    <ul class="c-footer-links" role="list">
                        <?php foreach ($col['links'] as $l): $p = $productHome[$l['href']] ?? null; ?>
                            <li<?= $p !== null ? ' data-product="' . e($p) . '"' : '' ?>><a href="<?= e($l['href']) ?>"><?= $p !== null ? '<i class="c-swatch" aria-hidden="true"></i>' : '' ?><?= e($l['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endforeach; ?>
        </div>
        <div class="c-footer-legal">
            <p>© <?= e(date('Y')) ?> <?= e($site['publisher']['name'] ?? 'Ben Rohleder') ?></p>
            <ul role="list">
                <li><a href="/legal/privacy/">Privacy</a></li>
                <li><a href="/legal/cookies/">Cookies</a></li>
                <?php if (\Psc\View\Analytics::forPage($app ?? \Psc\App::get()) !== ''): ?>
                    <li><button type="button" class="c-footer-consent" data-consent-open hidden>Cookie settings</button></li>
                <?php endif; ?>
                <li><a href="/legal/terms/">Terms</a></li>
                <li><a href="/legal/licence-terms/">Licence terms</a></li>
            </ul>
            <p class="c-footer-tm"><?= e($site['trademarks'] ?? '') ?></p>
        </div>
    </div>
</footer>
