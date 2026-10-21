<?php
/**
 * The support assistant (docs/DESIGN.md 8.28; behaviour in chat.js, the launcher's tuck in site.js).
 *
 * Without scripting: the scripted launcher stays tucked away and the <noscript> link to the contact page stands in
 * for it, and the form is the contact page's GET. chat.js makes every [data-chat-open] a button for the panel.
 * The status lamp says whether the assistant can answer at all, from configuration alone: public pages are cached
 * for every visitor, so nothing decided per visitor or per request (rate limits, the daily budget) is shown here.
 *
 * @var bool $inline the support page's inline panel instead of the floating one
 */
$inline ??= false;
$online = \Psc\Controllers\ChatController::online($app->config);
$onContact = ($app->request()?->path ?? '') === '/contact/';
$maxChars = $app->config->int('chat.max_input_chars', 1200);
$suggest = ['What does StageWatch do?', 'Which licence do I need?', 'Which app is for an AGM?', 'How do I report a bug?'];
?>
<div class="c-chat<?= $inline ? ' c-chat--inline' : '' ?>" data-chat<?= $inline ? ' data-chat-inline' : '' ?>>
    <?php if (!$inline): ?>
        <a class="c-chat-fab is-tucked" href="/contact/" data-chat-open data-chat-fab>
            <?php if ($online): ?><i class="c-lamp" aria-hidden="true"></i><?php endif; ?><span>Ask<span class="c-chat-fab-long"> the assistant</span></span></a>
        <?php if (!$onContact): ?>
            <noscript><a class="c-chat-fab" href="/contact/"><span>Get in touch</span></a></noscript>
        <?php endif; ?>
    <?php endif; ?>
    <section class="c-chat-panel" id="chat-panel" data-chat-panel<?= $inline ? '' : ' hidden' ?> aria-labelledby="chat-title">
        <header class="c-chat-head">
            <h2 id="chat-title">Show assistant</h2>
            <?php if ($online): ?>
                <span class="c-chat-status"><i class="c-lamp c-lamp--go" aria-hidden="true"></i>Online</span>
            <?php else: ?>
                <span class="c-chat-status"><i class="c-lamp c-lamp--off" aria-hidden="true"></i>Offline</span>
            <?php endif; ?>
            <span class="c-chat-tools">
                <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-reset aria-label="Start a new conversation"><?= icon('reset', 'c-icon--sm') ?></button>
                <?php if (!$inline): ?>
                    <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-close aria-label="Close the assistant"><?= icon('close', 'c-icon--sm') ?></button>
                <?php endif; ?>
            </span>
        </header>
        <?php if ($online): ?>
            <p class="c-chat-scope"><?= icon('info', 'c-icon--sm') ?><span><b>I can help with Patterns, StageWatch, AGMessage and PlayMap</b>: what they do, licensing, downloads, setup and support. For anything account-specific, I'll point you to a person.</span></p>
        <?php else: ?>
            <p class="c-chat-scope"><?= icon('info', 'c-icon--sm') ?><span><b>The assistant isn't available right now.</b> You can still <a href="/contact/">contact us</a> or, if you have an account, <a href="/portal/tickets/new/">open a support ticket</a>.</span></p>
        <?php endif; ?>
        <div class="c-chat-log" data-chat-log role="log" aria-live="polite" aria-label="Conversation"></div>
        <?php if ($online): ?>
            <div class="c-chat-suggest">
                <?php foreach ($suggest as $s): ?>
                    <button class="c-chat-chip" type="button" data-chat-suggest><?= e($s) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
