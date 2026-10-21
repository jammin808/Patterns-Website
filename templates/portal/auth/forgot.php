<?php
/**
 * Ask for a password reset link. After sending, the same answer for every address.
 *
 * @var bool $sent
 * @var array<string, string> $old
 * @var array<string, string> $errors
 */
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title">Reset your password</h1>
        <?php if ($sent): ?>
            <p class="pt-lede">Check your email for a link to choose a new password.</p>
        <?php else: ?>
            <p class="pt-lede">Enter the email address you sign in with and we will send you a link to choose a new password.</p>
        <?php endif; ?>
    </header>

    <?php if ($sent): ?>
        <div class="pt-alert pt-alert--success" role="status">
            <p>If that address has an account, a reset link is on its way. The link works for one hour and only once.</p>
        </div>
        <section class="pt-section" aria-labelledby="forgot-help">
            <h2 class="pt-h2" id="forgot-help">Nothing arrived?</h2>
            <p>Emails can take a little while to arrive. Check your spam or junk folder, or <a href="/portal/forgot/">try another address</a>.</p>
            <p class="pt-small"><a href="/portal/login/">Back to sign in</a></p>
        </section>
    <?php else: ?>
        <form method="post" action="/portal/forgot/" class="pt-form">
            <?= csrf_field() ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'forgot-email',
                'name' => 'email',
                'label' => 'Email address',
                'type' => 'email',
                'value' => $old['email'] ?? '',
                'autocomplete' => 'email',
                'spellcheck' => 'false',
                'required' => true,
                'maxlength' => 190,
                'error' => $errors['email'] ?? null,
            ]) ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary">Send reset link</button>
                <a class="pt-btn pt-btn--ghost" href="/portal/login/">Back to sign in</a>
            </div>
        </form>
    <?php endif; ?>
</div>
