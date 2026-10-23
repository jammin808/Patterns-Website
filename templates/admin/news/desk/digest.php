<?php
/**
 * The weekly digest on the desk (docs/NEWS.md §14.12): its state and the button that pauses or continues it, who gets
 * it and what the mailbox has sent in the last day, what the next digest holds with its preview, a test and its plain
 * text, unsubscribing someone who asked by email, and the last digests with how each one went.
 *
 * @var array{key: string, label: string, badge: string, text: string} $state
 * @var bool $enabled
 * @var string $driver mail.driver
 * @var array{confirmed: int, pending: int, unsubscribed: int, joined: int, left: int} $counts
 * @var int $sentDay the digest's messages in the last 24 hours
 * @var int $cap digest_per_day
 * @var bool $warn the list is nearing what one mailbox can send in a day
 * @var array<string, mixed> $preview the next digest as it stands (Composer::preview())
 * @var string $monday "19 October 2026"
 * @var string $label "Friday 23 October 2026 at 09:00"
 * @var string $text the next digest's plain-text part
 * @var string $email the administrator's own address, where a test goes
 * @var string $mailto mail.unsubscribe_to
 * @var list<array<string, mixed>> $past the last digests, the newest first, each with counts and unsent
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
use Psc\Controllers\Admin\AdminDigestController;
use Psc\News\Digest\Week;

$n = static fn (int $v): string => e(number_format($v));
$at = static fn (int $ts): string => '<time datetime="' . e(date(DATE_ATOM, $ts)) . '">' . e(date('j M Y, H:i', $ts)) . '</time>';
$shown = count($preview['articles']);
$whoError = $errors['unsubscribe_who'] ?? null;
$whoValue = is_string($old['unsubscribe_who'] ?? null) ? $old['unsubscribe_who'] : '';
$adminMethod = ($old['unsubscribe_method'] ?? '') === 'admin';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Industry Updates</p>
    <h1 class="pt-title">Weekly digest</h1>
    <p class="pt-lede">The weekly email of the week's new articles: who gets it, what the next one holds and how each one went.</p>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'digest']) ?>

<section class="pt-section" aria-labelledby="digest-state">
    <h2 class="pt-h2" id="digest-state">State</h2>
    <p class="pt-desk-state"><span class="pt-badge pt-badge--<?= e($state['badge']) ?>"><?= e($state['label']) ?></span> <?= e($state['text']) ?></p>
    <?php if ($driver === 'log'): ?>
        <div class="pt-alert pt-alert--info" role="status">This server writes emails to files (mail.driver is log), so nothing reaches anyone's inbox.</div>
    <?php endif; ?>
    <div class="pt-actions">
        <form method="post" action="<?= e(AdminDigestController::BASE) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $enabled ? 'pause' : 'resume' ?>">
            <button type="submit" class="pt-btn <?= $enabled ? 'pt-btn--secondary' : 'pt-btn--primary' ?>"><?= $enabled ? 'Pause the digest' : 'Continue the digest' ?></button>
        </form>
    </div>
</section>

<section class="pt-section" aria-labelledby="digest-subscribers">
    <h2 class="pt-h2" id="digest-subscribers">Subscribers</h2>
    <div class="pt-stats">
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['confirmed']) ?></span> <span class="pt-stat-label">Getting the digest</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['pending']) ?></span> <span class="pt-stat-label">Waiting to confirm</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['joined']) ?></span> <span class="pt-stat-label">Joined in the last 7 days</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['left']) ?></span> <span class="pt-stat-label">Left in the last 7 days</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['unsubscribed']) ?></span> <span class="pt-stat-label">Unsubscribed, kept so they are never sent it again</span></div>
    </div>
    <p class="pt-small">Sent in the last 24 hours: <?= $n($sentDay) ?> of the <?= $n($cap) ?> the settings allow.</p>
    <?php if ($warn): ?>
        <div class="pt-alert pt-alert--warning" role="status">The list is nearing what one mailbox can send in a day beside the site's other email. Giving the digest a mailbox of its own (such as updates@) or Hostinger Reach needs a change to the site first, as docs/DEPLOY.md says, so ask for it now.</div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="digest-next" id="next">
    <h2 class="pt-h2" id="digest-next">The next digest</h2>
    <p>Week of <?= e($monday) ?>, going out <?= e($label) ?>.</p>
    <?php if ($preview['sample']): ?>
        <p class="pt-muted">No article has gone live since the last digest yet, so nothing would be sent. The preview and a test show the newest articles instead.</p>
    <?php else: ?>
        <ol class="pt-desk-next">
            <?php foreach ($preview['articles'] as $a): ?>
                <li><a href="/portal/admin/news/<?= (int) $a['id'] ?>/"><?= e((string) $a['title']) ?></a> <span class="pt-muted"><?= e((string) $a['category_name']) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <?php if ((int) $preview['left_out'] > 0): ?><p class="pt-small">and <?= $n((int) $preview['left_out']) ?> more</p><?php endif; ?>
    <?php endif; ?>
    <div class="pt-actions">
        <a class="pt-btn pt-btn--ghost" href="<?= e(AdminDigestController::BASE . 'preview/') ?>" target="_blank" rel="noopener">Open the email preview<span class="u-visually-hidden"> (opens in a new tab)</span></a>
        <form method="post" action="<?= e(AdminDigestController::BASE) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test">
            <button type="submit" class="pt-btn pt-btn--secondary" aria-describedby="digest-test-help" data-busy="Sending…">Send me a test</button>
        </form>
    </div>
    <p class="pt-help" id="digest-test-help">Sends the next digest as it stands to <?= e($email) ?>, your own address, marked as a test. Its unsubscribe links change nothing.</p>
    <?php if ($text !== ''): ?>
        <details class="pt-desk-brief">
            <summary>The plain-text part</summary>
            <pre class="pt-mono pt-desk-rules"><?= e($text) ?></pre>
        </details>
    <?php endif; ?>
    <?php if ($shown === 0): ?><p class="pt-muted">No article is live yet, so the preview has nothing to show.</p><?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="digest-unsubscribe" id="unsubscribe">
    <h2 class="pt-h2" id="digest-unsubscribe">Unsubscribe someone</h2>
    <form class="pt-form" method="post" action="<?= e(AdminDigestController::BASE) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unsubscribe">
        <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => ['unsubscribe_who' => 'f-unsubscribe-who'], 'flashes' => $flashes ?? [], 'own' => $whoError !== null, 'id' => 'unsubscribe-summary']) ?>
        <?= $this->render('portal/licences/_field', ['name' => 'unsubscribe_who', 'id' => 'f-unsubscribe-who', 'label' => 'Email address, or the token from an unsubscribe email', 'value' => $whoValue, 'required' => true,
            'help' => 'Requests to ' . $mailto . ' arrive wherever that address delivers (docs/DEPLOY.md, section 8): act on each within 2 days. Their subject holds the token, which finds the subscription even when the request was forwarded.',
            'error' => $whoError, 'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 500]]) ?>
        <fieldset class="pt-fieldset">
            <legend>How they asked</legend>
            <div class="pt-field pt-field--inline">
                <input type="radio" id="f-unsubscribe-method-mailto" name="unsubscribe_method" value="mailto"<?= $adminMethod ? '' : ' checked' ?>>
                <label for="f-unsubscribe-method-mailto">By email to <?= e($mailto) ?></label>
            </div>
            <div class="pt-field pt-field--inline">
                <input type="radio" id="f-unsubscribe-method-admin" name="unsubscribe_method" value="admin"<?= $adminMethod ? ' checked' : '' ?>>
                <label for="f-unsubscribe-method-admin">Some other way</label>
            </div>
        </fieldset>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--secondary">Unsubscribe</button>
        </div>
    </form>
</section>

<section class="pt-section" aria-labelledby="digest-past">
    <h2 class="pt-h2" id="digest-past">Past digests</h2>
    <?php if ($past === []): ?>
        <div class="pt-empty"><p>No digest has gone out yet.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption>The last 12 digests</caption>
                <thead>
                    <tr>
                        <th scope="col">Week</th>
                        <th scope="col">Sent from</th>
                        <th scope="col" class="pt-num">Articles</th>
                        <th scope="col">State</th>
                        <th scope="col" class="pt-num">Sent</th>
                        <th scope="col" class="pt-num">Not sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($past as $d):
                        [$badge, $word] = AdminDigestController::DIGEST_STATES[$d['state']] ?? ['closed', ucfirst((string) $d['state'])];
                        $c = $d['counts'];
                        $articles = is_array($d['articles']) ? count($d['articles']) + (int) $d['left_out'] : 0; ?>
                        <tr>
                            <th scope="row"><a href="<?= e(AdminDigestController::BASE . (int) $d['id'] . '/') ?>">Week of <?= e(AdminDigestController::longDate(Week::monday((string) $d['week']))) ?></a></th>
                            <td data-label="Sent from"><?= $at((int) $d['composed_at']) ?></td>
                            <td class="pt-num" data-label="Articles"><?= $n($articles) ?></td>
                            <td data-label="State"><span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($word) ?></span></td>
                            <td class="pt-num" data-label="Sent"><?= $n($c['sent']) ?></td>
                            <td class="pt-num" data-label="Not sent"><?= $n($c['failed'] + $c['bounced'] + $c['claimed'] + (int) $d['unsent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
