<?php
/**
 * Sign in.
 *
 * @var string $next a safe same-site path to go to afterwards
 * @var string|null $notice unverified | approval | disabled (only after a correct password)
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var string $registration open | approval | invite
 */
$email = $old['email'] ?? '';
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title">Sign in</h1>
        <p class="pt-lede">Downloads, licences and support for your Patterns Show Control software.</p>
    </header>

    <?php if ($notice === 'unverified'): ?>
        <div class="pt-alert pt-alert--warning" role="status">
            <p><strong>Please confirm your email address first.</strong> We sent you a link when you created your account. If you cannot find it, check your spam folder or ask for a new one.</p>
        </div>
        <?= $this->render('portal/auth/_resend', ['email' => $email, 'idPrefix' => 'login-resend', 'buttonLabel' => 'Send a new confirmation link']) ?>
    <?php elseif ($notice === 'approval'): ?>
        <div class="pt-alert pt-alert--info" role="status">
            <p><strong>Your account is waiting for approval.</strong> Your email address is confirmed, and we are checking the account. We will email you when you can sign in.</p>
        </div>
    <?php elseif ($notice === 'disabled'): ?>
        <div class="pt-alert pt-alert--danger" role="alert">
            <p><strong>This account cannot sign in.</strong> If you think this is a mistake, please <a href="/contact/?topic=support">contact us</a>.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="/portal/login/" class="pt-form">
        <?= csrf_field() ?>
        <?php if ($next !== '/portal/'): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'login-email',
            'name' => 'email',
            'label' => 'Email address',
            'type' => 'email',
            'value' => $email,
            'autocomplete' => 'username',
            'spellcheck' => 'false',
            'required' => true,
            'maxlength' => 190,
            'error' => $errors['email'] ?? null,
        ]) ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'login-password',
            'name' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'autocomplete' => 'current-password',
            'required' => true,
            'maxlength' => 200,
            'error' => $errors['password'] ?? null,
        ]) ?>
        <div class="pt-field pt-field--inline">
            <input id="login-remember" name="remember" type="checkbox" value="1"<?= ($old['remember'] ?? '') === '1' ? ' checked' : '' ?> aria-describedby="login-remember-help">
            <label for="login-remember">Keep me signed in</label>
            <p class="pt-help" id="login-remember-help">For up to <?= e($app->config->int('security.remember_days', 30)) ?> days on this browser. Leave it unticked on a shared computer.</p>
        </div>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary">Sign in</button>
            <a class="pt-btn pt-btn--ghost" href="/portal/forgot/">Forgotten your password?</a>
        </div>
    </form>

    <?php /* Open sign-up is offered once, by the portal's Sign in / Create an account switch above the card. When
           accounts are by invitation, the card says how to get one instead and offers no sign-up. */ ?>
    <?php if ($registration === 'invite'): ?>
        <p class="pt-small pt-muted">New here? Accounts are by invitation. <a href="/contact/">Contact us</a> if you need one.</p>
    <?php endif; ?>
</div>
