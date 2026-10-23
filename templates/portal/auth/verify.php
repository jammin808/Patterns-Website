<?php
/**
 * An email confirmation link: its page, with the button whose POST confirms the address (ask), and the result.
 *
 * @var string $state ask | confirmed | approval | invalid | disabled
 * @var bool $signedIn
 * @var string|null $action the button's address, with the link's token (ask only)
 * @var string|null $next where the person was going before signing up (AuthController::RETURN_KEY), once confirmed
 * @var string|null $article that place's article, at its comments, when it is one
 */
$next ??= null;
$article ??= null;
$action ??= null;
$signIn = '/portal/login/' . ($next !== null ? '?next=' . rawurlencode($next) : '');
$titles = [
    'ask' => 'Confirm your email address',
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
        <?php if ($state === 'ask'): ?>
            <p class="pt-lede">Press the button to confirm this is your email address and finish setting up your account.</p>
        <?php elseif ($state === 'confirmed'): ?>
            <p class="pt-lede">Thank you. Your account is ready.</p>
        <?php elseif ($state === 'approval'): ?>
            <p class="pt-lede">Thank you. One more step: your account is waiting for approval.</p>
        <?php elseif ($state === 'invalid'): ?>
            <p class="pt-lede">Confirmation links work once, for 48 hours.</p>
        <?php endif; ?>
    </header>

    <?php if ($state === 'ask' && $action !== null): ?>
        <form method="post" action="<?= e($action) ?>" class="pt-form">
            <?= csrf_field() ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary" data-busy="Confirming…">Confirm my email address</button>
            </div>
        </form>
    <?php elseif ($state === 'confirmed'): ?>
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
