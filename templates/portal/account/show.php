<?php
/**
 * The signed-in person's account: details, password, a copy of their data, and deleting the account.
 *
 * @var array<string, mixed> $account
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var int $minLength
 * @var array{licences: int, tickets: int} $kept
 * @var int $newsComments the account's comments on Industry Updates articles, in every status
 * @var string $deleteWord
 * @var bool $soleAdmin the only active administrator (the account cannot be deleted until there is another)
 * @var list<array{type: string, message: string}> $flashes
 */
$soleAdmin ??= false;
$newsComments ??= 0;
// Which of the three forms was sent last (its hidden "form" field comes back with the kept input), so a refusal no
// field explains is said in that form's error summary and nowhere else.
$posted = (string) ($old['form'] ?? '');
$summary = fn (string $form, array $fields, string $action, string $failed): string => $this->partial('error-summary', [
    'errors' => $errors,
    'fields' => $fields,
    'flashes' => $flashes ?? [],
    'own' => $posted === $form,
    'id' => $form . '-errors',
    'action' => $action,
    'failed' => $failed,
]);
// Values typed into the details form win after a validation error; otherwise the saved ones.
$fromProfile = array_key_exists('name', $old);
$name = $fromProfile ? $old['name'] : (string) $account['name'];
$company = $fromProfile ? ($old['company'] ?? '') : (string) ($account['company'] ?? '');
$marketing = $fromProfile ? ($old['marketing'] ?? '') === '1' : (int) $account['marketing_opt_in'] === 1;
$isAdmin = ($account['role'] ?? '') === 'admin';
$licenceCount = $kept['licences'] . ' ' . ($kept['licences'] === 1 ? 'licence' : 'licences');
$ticketCount = $kept['tickets'] . ' ' . ($kept['tickets'] === 1 ? 'ticket' : 'tickets');
$newsCount = $newsComments . ' ' . ($newsComments === 1 ? 'comment' : 'comments');
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Your account</h1>
    <p class="pt-lede">Your details, your password and the data we hold about you.</p>
</header>

<section class="pt-section" id="details" aria-labelledby="details-title">
    <h2 class="pt-h2" id="details-title">Your details</h2>
    <dl class="pt-kv">
        <dt>Email address</dt>
        <dd><?= e($account['email']) ?> <span class="pt-small pt-muted">To change it, <a href="/contact/?topic=support">contact us</a>.</span></dd>
        <dt>Account type</dt>
        <dd><span class="pt-badge pt-badge--<?= $isAdmin ? 'admin' : 'client' ?>"><?= $isAdmin ? 'Administrator' : 'Client' ?></span></dd>
        <dt>Member since</dt>
        <dd><time datetime="<?= e(date('c', (int) $account['created_at'])) ?>"><?= e(date_short($account['created_at'])) ?></time></dd>
        <?php if (!empty($account['last_login_at'])): ?>
            <dt>Last signed in</dt>
            <dd><time datetime="<?= e(date('c', (int) $account['last_login_at'])) ?>"><?= e(ago($account['last_login_at'])) ?></time></dd>
        <?php endif; ?>
    </dl>

    <form method="post" action="/portal/account/profile/" class="pt-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">
        <?= $summary('profile', ['name' => 'profile-name', 'company' => 'profile-company'], 'before saving', 'Your details were not saved') ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'profile-name',
            'name' => 'name',
            'label' => 'Your name',
            'value' => $name,
            'autocomplete' => 'name',
            'required' => true,
            'maxlength' => 120,
            'error' => $errors['name'] ?? null,
        ]) ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'profile-company',
            'name' => 'company',
            'label' => 'Company or organisation',
            'value' => $company,
            'autocomplete' => 'organization',
            'maxlength' => 160,
            'error' => $errors['company'] ?? null,
        ]) ?>
        <div class="pt-field pt-field--inline">
            <input id="profile-marketing" name="marketing" type="checkbox" value="1"<?= $marketing ? ' checked' : '' ?> aria-describedby="profile-marketing-help">
            <label for="profile-marketing">Email me about new releases and features</label>
            <p class="pt-help" id="profile-marketing-help">Untick this at any time to stop them.</p>
        </div>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…">Save details</button>
        </div>
    </form>
</section>

<section class="pt-section" id="password" aria-labelledby="password-title">
    <h2 class="pt-h2" id="password-title">Password</h2>
    <p>Changing your password signs out any other browsers that are signed in to this account. This one stays signed in.</p>
    <form method="post" action="/portal/account/password/" class="pt-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="password">
        <?= $summary('password', ['current_password' => 'password-current', 'new_password' => 'password-new', 'new_password_confirm' => 'password-confirm'], 'before saving', 'Your password was not changed') ?>
        <input type="email" name="username" value="<?= e($account['email']) ?>" autocomplete="username" readonly hidden>
        <?= $this->render('portal/auth/_field', [
            'id' => 'password-current',
            'name' => 'current_password',
            'label' => 'Current password',
            'type' => 'password',
            'autocomplete' => 'current-password',
            'required' => true,
            'maxlength' => 200,
            'error' => $errors['current_password'] ?? null,
        ]) ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'password-new',
            'name' => 'new_password',
            'label' => 'New password',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
            'minlength' => $minLength,
            'maxlength' => 200,
            'help' => 'At least ' . $minLength . ' characters. A few unrelated words make a strong password that is easy to remember.',
            'error' => $errors['new_password'] ?? null,
        ]) ?>
        <?= $this->render('portal/auth/_field', [
            'id' => 'password-confirm',
            'name' => 'new_password_confirm',
            'label' => 'Type the new password again',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
            'maxlength' => 200,
            'error' => $errors['new_password_confirm'] ?? null,
        ]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary" data-busy="Changing password…">Change password</button>
        </div>
    </form>
</section>

<section class="pt-section" id="data" aria-labelledby="data-title">
    <h2 class="pt-h2" id="data-title">Your data</h2>
    <p>Download a copy of everything your account holds: your details, tickets and their comments, licences, your comments on Industry Updates articles (including any waiting for approval or hidden) and the comments you reported, and any conversations with the support assistant while you were signed in. It is a JSON file, which most text editors can open.</p>
    <div class="pt-actions">
        <a class="pt-btn pt-btn--secondary" href="/portal/account/export/">Download my data</a>
    </div>
    <p class="pt-small pt-muted">How we use your data is explained in the <a href="/legal/privacy/">privacy policy</a>.</p>
</section>

<section class="pt-section" id="delete" aria-labelledby="delete-title">
    <h2 class="pt-h2" id="delete-title">Delete your account</h2>
    <div class="pt-alert pt-alert--warning" role="status">
        <p><strong>This cannot be undone.</strong> You will be signed out and will not be able to sign in again with this account.</p>
    </div>
    <p>We remove your name, email address, company and password straight away. Some records stay because they are business records:</p>
    <ul>
        <li>Licences stay on record<?php if ($kept['licences'] > 0): ?> (you have <?= e($licenceCount) ?>)<?php endif; ?>. Download any licence files you need first.</li>
        <li>Tickets and their comments stay<?php if ($kept['tickets'] > 0): ?> (you have <?= e($ticketCount) ?>)<?php endif; ?>, so conversations still make sense. They show &ldquo;Deleted user&rdquo; as the author.</li>
    </ul>
    <p>Your comments on Industry Updates articles<?php if ($newsComments > 0): ?> (you have <?= e($newsCount) ?>)<?php endif; ?> are public, so they are deleted for good with your account, and so are any reports you made about other people's comments.</p>
    <p>If you would like a copy of your data, <a href="/portal/account/export/">download it</a> before you continue.</p>

    <?php if ($soleAdmin): ?>
    <div class="pt-alert pt-alert--info" role="status">
        <p><strong>You are the only administrator.</strong> To delete this account, first make another account an administrator on the <a href="/portal/admin/users/">Users</a> page.</p>
    </div>
    <?php else: ?>
    <form method="post" action="/portal/account/delete/" class="pt-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="delete">
        <?= $summary('delete', ['delete_password' => 'delete-password', 'delete_confirm' => 'delete-confirm'], 'to delete your account', 'Your account was not deleted') ?>
        <fieldset class="pt-fieldset">
            <legend>Confirm deletion</legend>
            <input type="email" name="username" value="<?= e($account['email']) ?>" autocomplete="username" readonly hidden>
            <?= $this->render('portal/auth/_field', [
                'id' => 'delete-password',
                'name' => 'delete_password',
                'label' => 'Your current password',
                'type' => 'password',
                'autocomplete' => 'current-password',
                'required' => true,
                'maxlength' => 200,
                'error' => $errors['delete_password'] ?? null,
            ]) ?>
            <?= $this->render('portal/auth/_field', [
                'id' => 'delete-confirm',
                'name' => 'delete_confirm',
                'label' => 'Type ' . $deleteWord . ' to confirm',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                'required' => true,
                'maxlength' => 20,
                'help' => 'In capital letters.',
                'error' => $errors['delete_confirm'] ?? null,
            ]) ?>
        </fieldset>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--danger" data-busy="Deleting…">Delete my account</button>
        </div>
    </form>
    <?php endif; ?>
</section>
