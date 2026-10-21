<?php
/**
 * One workflow as a card (the workflows hub and "related workflows"): a top bar in the colours of the apps it uses,
 * the title as the link, the summary, the apps as chips and how long it takes. The title's link covers the card.
 *
 * @var string $slug
 * @var array<string, mixed> $wf  an entry of content/workflows.php
 * @var string|null $tag  the heading level for the title (h2 or h3)
 */
$tag = in_array($tag ?? 'h3', ['h2', 'h3'], true) ? ($tag ?? 'h3') : 'h3';
$apps = array_values(array_filter((array) ($wf['products'] ?? []), 'is_string'));
$steps = count((array) ($wf['steps'] ?? []));
?>
<article class="c-card support-wfcard">
    <span class="support-runbar" aria-hidden="true"><?php foreach ($apps as $key): ?><i data-product="<?= e($key) ?>"></i><?php endforeach; ?></span>
    <span class="c-card-meta"><?= e($steps) ?> steps</span>
    <<?= $tag ?> class="c-card-title"><a href="/workflows/<?= e($slug) ?>/"><?= e($wf['title'] ?? '') ?></a></<?= $tag ?>>
    <p class="c-card-body"><?= e($wf['summary'] ?? '') ?></p>
    <div class="c-card-footer support-wfcard-foot">
        <ul class="support-wfcard-apps" role="list" aria-label="Apps used">
            <?php foreach ($apps as $key): $p = product($key); ?>
                <li><span class="c-app" data-product="<?= e($key) ?>"><i class="c-swatch" aria-hidden="true"></i><?= e($p['name'] ?? $key) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <p class="support-wfcard-time"><?= icon('clock', 'c-icon--sm') ?><span><?= e($wf['duration'] ?? '') ?></span></p>
    </div>
</article>
