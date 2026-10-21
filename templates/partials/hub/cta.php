<?php
/**
 * The closing call to action on the hub pages: the four app bars, one heading, one line, two or three actions.
 * An action with 'chat' opens the assistant, so it is a button (docs/DESIGN.md 8.28) that hides without the script.
 *
 * @var string $id       heading id
 * @var string $title
 * @var string|null $text
 * @var list<array{label: string, href: string, style?: string, chat?: bool}> $actions  style: primary | secondary | ghost
 */
$text ??= null;
?>
<section class="l-section hub-cta" aria-labelledby="<?= e($id) ?>">
    <div class="l-wrap">
        <span class="c-bars c-bars--apps hub-cta-bars" aria-hidden="true"></span>
        <div class="hub-cta-grid">
            <h2 id="<?= e($id) ?>"><?= e($title) ?></h2>
            <div>
                <?php if ($text !== null): ?><p class="c-lede"><?= e($text) ?></p><?php endif; ?>
                <div class="c-hero-actions">
                    <?php foreach ($actions as $a): $style = $a['style'] ?? 'secondary'; ?>
                        <?php if (!empty($a['chat'])): ?>
                            <button class="c-btn c-btn--<?= e($style) ?> hub-js-only" type="button" data-chat-open aria-controls="chat-panel" aria-expanded="false"><?= icon('chat') ?><?= e($a['label']) ?></button>
                        <?php else: ?>
                            <a class="c-btn c-btn--<?= e($style) ?>" href="<?= e($a['href']) ?>"><?= e($a['label']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
