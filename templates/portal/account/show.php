<?php
/**
 * The signed-in person's account: details, password, their comments on Industry Updates articles (each linking to its
 * comment page, the one place a comment on an article no longer live can be found and deleted), a copy of their data,
 * and deleting the account.
 *
 * @var array<string, mixed> $account
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var int $minLength
 * @var array{licences: int, tickets: int} $kept
 * @var int $newsComments the account's comments on Industry Updates articles, in every status
 * @var list<array<string, mixed>> $newsList the newest of them (Comments::ofAccount()), each with its article's slug, title,
 *   category and article_live
 * @var string $deleteWord
 * @var bool $soleAdmin the only active administrator (the account cannot be deleted until there is another)
 * @var bool|null $digest the weekly Industry Updates digest goes to the account (Subscribers::isSubscribed()), or null
 *   when that cannot be read; its tick is consent of its own, apart from the product-news tick (docs/NEWS.md §14.7),
 *   and digest_shown carries the state it was drawn against, so a save acts only on a tick the person moved
 * @var list<array{type: string, message: string}> $flashes
 */
$soleAdmin ??= false;
$newsComments ??= 0;
$newsList ??= [];
$digest ??= null;
$digestWords = content('news')['digest']['account'] ?? [];
$commentStates = ['visible' => ['public', 'Showing'], 'pending' => ['pending', 'Waiting for approval'], 'hidden' => ['private', 'Hidden by a moderator']];
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
// Values typed into the details form win after a validation error; otherwise the saved ones. The digest's tick comes
// back with the state it was first drawn against, never the state at this draw, so the next save still compares it
// with the page the person saw: '1', '0', or empty when that page could not read it.
$fromProfile = array_key_exists('name', $old);
$name = $fromProfile ? $old['name'] : (string) $account['name'];
$company = $fromProfile ? ($old['company'] ?? '') : (string) ($account['company'] ?? '');
$marketing = $fromProfile ? ($old['marketing'] ?? '') === '1' : (int) $account['marketing_opt_in'] === 1;
$digestTicked = $fromProfile ? ($old['digest'] ?? '') === '1' : $digest === true;
$digestShown = $fromProfile
    ? (in_array($old['digest_shown'] ?? '', ['0', '1'], true) ? $old['digest_shown'] : '')
    : ($digest === null ? '' : ($digest ? '1' : '0'));
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
        <input type="hidden" name="digest_shown" value="<?= $digestShown ?>">
        <div class="pt-field pt-field--inline">
            <input id="profile-digest" name="digest" type="checkbox" value="1"<?= $digestTicked ? ' checked' : '' ?> aria-describedby="profile-digest-help">
            <label for="profile-digest"><?= e($digestWords['label'] ?? 'Email me the weekly Industry Updates digest') ?></label>
            <p class="pt-help" id="profile-digest-help"><?= e($digestWords['help'] ?? '') ?></p>
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

<?php if ($newsList !== []): ?>
<section class="pt-section" id="comments" aria-labelledby="comments-title">
    <h2 class="pt-h2" id="comments-title">Your comments on Industry Updates</h2>
    <p>Each comment's page shows it with its article and lets you delete it, including comments on articles that are no longer published.<?php if ($newsComments > count($newsList)): ?> These are your newest <?= count($newsList) ?> of <?= e($newsCount) ?>; your data copy has every one.<?php endif; ?></p>
    <div class="pt-table-wrap"><table class="pt-table pt-table--stack">
        <caption class="u-visually-hidden">Your comments on Industry Updates articles, the newest first</caption>
        <thead><tr><th scope="col">Comment</th><th scope="col">Article</th><th scope="col">Status</th><th scope="col">Posted</th></tr></thead>
        <tbody>
            <?php foreach ($newsList as $c):
                [$badge, $label] = $commentStates[(string) $c['status']] ?? ['private', ucfirst((string) $c['status'])]; ?>
                <tr>
                    <th scope="row"><a href="<?= e(\Psc\News\Paths::comment($c)) ?>#comment-<?= (int) $c['id'] ?>"><?= e(\Psc\News\Prose::cut((string) preg_replace('/\s+/u', ' ', (string) $c['body']), 80)) ?></a></th>
                    <td data-label="Article"><?= $c['article_live'] ? e($c['title']) : '<span class="pt-muted">An article that is not published at the moment</span>' ?></td>
                    <td data-label="Status"><span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($label) ?></span></td>
                    <td data-label="Posted"><time datetime="<?= e(date('c', (int) $c['created_at'])) ?>"><?= e(date_short($c['created_at'])) ?></time></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="pt-section" id="data" aria-labelledby="data-title">
    <h2 class="pt-h2" id="data-title">Your data</h2>
    <p>Download a copy of everything your account holds: your details, tickets and their comments, licences, your comments on Industry Updates articles (including any waiting for approval or hidden) and the comments you reported, any conversations with the support assistant while you were signed in, and your weekly digest subscription. It is a JSON file, which most text editors can open.</p>
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
    <?php if ($digest === true): ?>
    <p>The weekly Industry Updates digest stops, and your address is deleted from it.</p>
    <?php endif; ?>
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
