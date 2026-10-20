<?php
/**
 * Create an account (or, when accounts are by invitation, say so and point to the contact page).
 *
 * @var string $registration open | approval | invite
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var int $minLength
 */
$termsError = $errors['terms'] ?? null;
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title">Create an account</h1>
        <?php if ($registration === 'invite'): ?>
            <p class="pt-lede">Portal accounts are by invitation at the moment.</p>
        <?php else: ?>
            <p class="pt-lede">One account for downloads, licence requests, bug reports and feature requests.</p>
        <?php endif; ?>
    </header>

    <?php if ($registration === 'invite'): ?>
        <div class="pt-card">
            <div class="pt-card-body">
                <p>If you would like an account, <a href="/contact/?topic=support">send us a message</a> and we will invite you by email.</p>
                <p>Already have one? <a href="/portal/login/">Sign in</a>.</p>
            </div>
        </div>
    <?php else: ?>
        <?php if ($registration === 'approval'): ?>
            <div class="pt-alert pt-alert--info" role="status">
                <p>New accounts are checked by a person before they can sign in. After you confirm your email address, we will email you when your account is ready.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="/portal/register/" class="pt-form">
            <?= csrf_field() ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'register-name',
                'name' => 'name',
                'label' => 'Your name',
                'value' => $old['name'] ?? '',
                'autocomplete' => 'name',
                'required' => true,
                'maxlength' => 120,
                'error' => $errors['name'] ?? null,
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'register-email',
                'name' => 'email',
                'label' => 'Email address',
                'type' => 'email',
                'value' => $old['email'] ?? '',
                'autocomplete' => 'email',
                'spellcheck' => 'false',
                'required' => true,
                'maxlength' => 190,
                'help' => 'We will send a link to confirm it.',
                'error' => $errors['email'] ?? null,
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'register-company',
                'name' => 'company',
                'label' => 'Company or organisation',
                'value' => $old['company'] ?? '',
                'autocomplete' => 'organization',
                'maxlength' => 160,
                'error' => $errors['company'] ?? null,
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'register-password',
                'name' => 'password',
                'label' => 'Password',
                'type' => 'password',
                'autocomplete' => 'new-password',
                'required' => true,
                'minlength' => $minLength,
                'maxlength' => 200,
                'help' => 'At least ' . $minLength . ' characters. A few unrelated words make a strong password that is easy to remember.',
                'error' => $errors['password'] ?? null,
            ]) ?>

            <fieldset class="pt-fieldset">
                <legend>Terms and emails</legend>
                <div class="pt-field pt-field--inline">
                    <input id="register-terms" name="terms" type="checkbox" value="1" required<?= ($old['terms'] ?? '') === '1' ? ' checked' : '' ?><?= $termsError !== null ? ' aria-invalid="true" aria-describedby="register-terms-error"' : '' ?>>
                    <label for="register-terms">I accept the <a href="/legal/terms/">terms of use</a> and have read the <a href="/legal/privacy/">privacy policy</a>.</label>
                    <?php if ($termsError !== null): ?>
                        <p class="pt-error" id="register-terms-error"><?= e($termsError) ?></p>
                    <?php endif; ?>
                </div>
                <div class="pt-field pt-field--inline">
                    <input id="register-marketing" name="marketing" type="checkbox" value="1"<?= ($old['marketing'] ?? '') === '1' ? ' checked' : '' ?> aria-describedby="register-marketing-help">
                    <label for="register-marketing">Email me about new releases and features</label>
                    <p class="pt-help" id="register-marketing-help">You can turn this off at any time on your account page.</p>
                </div>
            </fieldset>

            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary">Create account</button>
            </div>
        </form>

        <p class="pt-small">Already have an account? <a href="/portal/login/">Sign in</a>.</p>
    <?php endif; ?>
</div>
