<?php
/**
 * The result of an email confirmation link.
 *
 * @var string $state confirmed | approval | invalid | disabled
 * @var bool $signedIn
 * @var string|null $next where the person was going before signing up (AuthController::RETURN_KEY), once confirmed
 * @var string|null $article that place's article, at its comments, when it is one
 */
$next ??= null;
$article ??= null;
$signIn = '/portal/login/' . ($next !== null ? '?next=' . rawurlencode($next) : '');
$titles = [
    'confirmed' => 'Email address confirmed',
    'approval' => 'Email address confirmed',
    'invalid' => 'This link no longer works',
    'disabled' => 'This account cannot sign in',
];
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title"><?= e($titles[$state] ?? $titles['invalid']) ?></h1>
        <?php if ($state === 'confirmed'): ?>
            <p class="pt-lede">Thank you. Your account is ready.</p>
        <?php elseif ($state === 'approval'): ?>
            <p class="pt-lede">Thank you. One more step: your account is waiting for approval.</p>
        <?php elseif ($state === 'invalid'): ?>
            <p class="pt-lede">Confirmation links work once, for 48 hours.</p>
        <?php endif; ?>
    </header>

    <?php if ($state === 'confirmed'): ?>
        <?php if ($article !== null && !$signedIn): ?>
            <p>Sign in and you will go straight back to the article, ready to comment.</p>
        <?php endif; ?>
        <div class="pt-actions">
            <?php if ($signedIn): ?>
                <?php if ($article !== null): ?>
                    <a class="pt-btn pt-btn--primary" href="<?= e($article) ?>">Continue to the article</a>
                    <a class="pt-btn pt-btn--ghost" href="/portal/">Go to your dashboard</a>
                <?php else: ?>
                    <a class="pt-btn pt-btn--primary" href="/portal/">Go to your dashboard</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="pt-btn pt-btn--primary" href="<?= e($signIn) ?>">Sign in</a>
                <?php if ($article !== null): ?>
                    <a class="pt-btn pt-btn--ghost" href="<?= e($article) ?>">Continue to the article</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php elseif ($state === 'approval'): ?>
        <div class="pt-card">
            <div class="pt-card-body">
                <p>A person checks each new account before it can sign in. We will email you when yours is ready. There is nothing else you need to do.</p>
            </div>
        </div>
    <?php elseif ($state === 'disabled'): ?>
        <div class="pt-alert pt-alert--danger" role="alert">
            <p>Your email address is confirmed, but this account is switched off. If you think this is a mistake, please <a href="/contact/?topic=support">contact us</a>.</p>
        </div>
    <?php else: ?>
        <div class="pt-card">
            <div class="pt-card-body">
                <p>If you have already confirmed your address, you can <a href="/portal/login/">sign in</a> now.</p>
                <p>Otherwise, enter your email address and we will send a new link.</p>
            </div>
        </div>
        <?php if (!$signedIn): ?>
            <?= $this->render('portal/auth/_resend', ['email' => '', 'idPrefix' => 'verify-resend', 'buttonLabel' => 'Send a new link']) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
