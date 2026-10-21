<?php
/**
 * Choose a new password from a reset link, or set the first one from an invitation. The token is the only
 * authority: it travels in a hidden field and is checked again when the form is sent.
 *
 * @var bool $valid
 * @var bool $invite
 * @var string $purpose reset | invite
 * @var string $token
 * @var array<string, string> $errors
 * @var int $minLength
 * @var list<array{type: string, message: string}> $flashes
 */
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <?php if (!$valid): ?>
            <h1 class="pt-title">This link no longer works</h1>
            <p class="pt-lede"><?= $invite
                ? 'Invitation links work once, for seven days.'
                : 'Reset links work once, for one hour.' ?></p>
        <?php elseif ($invite): ?>
            <h1 class="pt-title">Set your password</h1>
            <p class="pt-lede">Welcome. Choose a password to finish setting up your account.</p>
        <?php else: ?>
            <h1 class="pt-title">Choose a new password</h1>
            <p class="pt-lede">Once it is saved, any browsers signed in to your account are signed out.</p>
        <?php endif; ?>
    </header>

    <?php if (!$valid): ?>
        <div class="pt-card">
            <div class="pt-card-body">
                <p>It may have expired, or it was already used. Ask for a new link and we will email it to you.</p>
            </div>
        </div>
        <div class="pt-actions">
            <a class="pt-btn pt-btn--primary" href="/portal/forgot/">Get a new link</a>
            <a class="pt-btn pt-btn--ghost" href="/portal/login/">Sign in</a>
        </div>
    <?php else: ?>
        <form method="post" action="/portal/reset/" class="pt-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="purpose" value="<?= e($purpose) ?>">
            <?= $this->partial('error-summary', [
                'errors' => $errors,
                'fields' => ['password' => 'reset-password', 'password_confirm' => 'reset-password-confirm'],
                'flashes' => $flashes ?? [],
                'action' => 'before saving',
                'failed' => 'Your password was not saved',
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'reset-password',
                'name' => 'password',
                'label' => $invite ? 'Password' : 'New password',
                'type' => 'password',
                'autocomplete' => 'new-password',
                'required' => true,
                'minlength' => $minLength,
                'maxlength' => 200,
                'help' => 'At least ' . $minLength . ' characters. A few unrelated words make a strong password that is easy to remember.',
                'error' => $errors['password'] ?? null,
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'reset-password-confirm',
                'name' => 'password_confirm',
                'label' => $invite ? 'Type the password again' : 'Type the new password again',
                'type' => 'password',
                'autocomplete' => 'new-password',
                'required' => true,
                'maxlength' => 200,
                'error' => $errors['password_confirm'] ?? null,
            ]) ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…"><?= $invite ? 'Set password' : 'Save new password' ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>
