<?php
/**
 * Ask for a new email confirmation link: the form on a page of its own, where a refused request comes back to with
 * the address kept (a send faster than a person types, an address in the wrong format). The answer is the same for
 * every address, so the page never says whether one has an account.
 *
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var bool $quick the last send came faster than a person fills the form in: nothing was sent, the input is back
 * @var list<array{type: string, message: string}> $flashes
 */
$quick ??= false;
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title">Get a new confirmation link</h1>
        <p class="pt-lede">Enter the email address you created your account with. If it is waiting to be confirmed, we will send a new link that works for 48 hours.</p>
    </header>

    <form method="post" action="/portal/verify/resend/" class="pt-form" novalidate>
        <?= csrf_field() ?>
        <?= form_guard('resend', $old) ?>
        <?= $this->partial('error-summary', [
            'errors' => $errors,
            'fields' => ['email' => 'resend-email'],
            'flashes' => $flashes ?? [],
            'action' => 'before sending',
            'failed' => $quick ? 'The link is not sent yet' : 'The link was not sent',
        ]) ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'resend-email',
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
            <button type="submit" class="pt-btn pt-btn--primary" data-busy="Sending…">Send a new link</button>
            <a class="pt-btn pt-btn--ghost" href="/portal/login/">Back to sign in</a>
        </div>
    </form>
</div>
