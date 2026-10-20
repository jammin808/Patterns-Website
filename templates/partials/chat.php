<?php
/**
 * The support assistant (docs/DESIGN.md 8.28; behaviour in chat.js). Without JavaScript the launcher is a link to
 * the contact page and the form posts nowhere harmful (it is the contact page's GET).
 *
 * @var bool $inline the support page's inline panel instead of the floating one
 */
$inline ??= false;
$maxChars = $app->config->int('chat.max_input_chars', 1200);
$suggest = ['What does StageWatch do?', 'Which licence do I need?', 'Which app is for an AGM?', 'How do I report a bug?'];
?>
<div class="c-chat<?= $inline ? ' c-chat--inline' : '' ?>" data-chat<?= $inline ? ' data-chat-inline' : '' ?>>
    <?php if (!$inline): ?>
        <a class="c-chat-fab is-tucked" href="/contact/" role="button" data-chat-open data-chat-fab aria-expanded="false" aria-controls="chat-panel">
            <i class="c-lamp" aria-hidden="true"></i><span>Ask<span class="c-chat-fab-long"> the assistant</span></span></a>
    <?php endif; ?>
    <section class="c-chat-panel" id="chat-panel" data-chat-panel<?= $inline ? '' : ' hidden' ?> aria-labelledby="chat-title">
        <header class="c-chat-head">
            <h2 id="chat-title">Show assistant</h2>
            <span class="c-chat-status"><i class="c-lamp c-lamp--go" aria-hidden="true"></i>Online</span>
            <span class="c-chat-tools">
                <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-reset aria-label="Start a new conversation"><?= icon('reset', 'c-icon--sm') ?></button>
                <?php if (!$inline): ?>
                    <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-close aria-label="Close the assistant"><?= icon('close', 'c-icon--sm') ?></button>
                <?php endif; ?>
            </span>
        </header>
        <p class="c-chat-scope"><?= icon('info', 'c-icon--sm') ?><span><b>I can help with Patterns, StageWatch, AGMessage and PlayMap</b>: what they do, licensing, downloads, setup and support. For anything account-specific, I'll point you to a person.</span></p>
        <div class="c-chat-log" data-chat-log role="log" aria-live="polite" aria-label="Conversation"></div>
        <div class="c-chat-suggest">
            <?php foreach ($suggest as $s): ?>
                <button class="c-chat-chip" type="button" data-chat-suggest><?= e($s) ?></button>
            <?php endforeach; ?>
        </div>
        <form class="c-chat-form" data-chat-form action="/contact/" method="get">
            <label class="u-visually-hidden" for="chat-input">Ask about Patterns, StageWatch, AGMessage or PlayMap</label>
            <textarea class="c-chat-input" id="chat-input" name="q" data-chat-input rows="1" maxlength="<?= e($maxChars) ?>" placeholder="Ask about your show"></textarea>
            <button class="c-chat-send" type="submit" aria-label="Send"><?= icon('send', 'c-icon--sm') ?></button>
            <p class="c-chat-count" data-chat-count></p>
        </form>
        <p class="c-chat-foot">Answers come from an AI and can be wrong: check them before doors. Don't paste licence keys, passwords or personal details. <a href="/legal/privacy/">Privacy</a></p>
        <p class="u-visually-hidden" data-chat-status role="status"></p>
    </section>
</div>
