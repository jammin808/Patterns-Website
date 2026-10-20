<?php
/**
 * The result of an email confirmation link.
 *
 * @var string $state confirmed | approval | invalid | disabled
 * @var bool $signedIn
 */
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
        <div class="pt-actions">
            <?php if ($signedIn): ?>
                <a class="pt-btn pt-btn--primary" href="/portal/">Go to your dashboard</a>
            <?php else: ?>
                <a class="pt-btn pt-btn--primary" href="/portal/login/">Sign in</a>
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
