<?php
/**
 * The administration overview: what is waiting (accounts, tickets, licence requests, messages), assistant use,
 * the newest five of each waiting thing, and Industry Updates (held articles, comments to look at, the AI desk).
 *
 * @var array{total: int, pending: int, approval: int, active: int, disabled: int} $users
 * @var array<string, int> $openByType ticket type => tickets not yet closed
 * @var array{approval: int, tickets: int, licences: int, messages: int} $waiting
 * @var array{requested: int, awaiting_payment: int} $licencesWaiting
 * @var array{today: array<string, int>, week: array<string, int>} $assistant
 * @var array{held: int, reported: int, pending: int, desk: string, spent: float, budget: int, last: array<string, mixed>|null}|null $news
 *      Industry Updates (null when its tables cannot be read); desk is the desk's state key (AdminNewsDeskController::state())
 * @var array{approval: list<array<string, mixed>>, tickets: list<array<string, mixed>>, licences: list<array<string, mixed>>, messages: list<array<string, mixed>>} $latest
 * @var array<int, string> $licenceNames
 * @var array<string, string> $ticketTypes
 * @var array<string, string> $licenceStatuses
 * @var array<string, string> $products
 * @var array<string, string> $topics
 * @var bool $configShadowed a config/local.php in the web root is not the file being read
 * @var list<array{label: string, value: string, ok: bool}> $server  AdminController::serverFacts()
 */
$time = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(ago((int) $ts)) . '</time>'
    : '';
$n = static fn (int $v): string => number_format($v);
$typeTabs = ['bug' => 'Open bug reports', 'feature' => 'Open feature requests', 'question' => 'Open questions'];
$count = static fn (int $v, string $one, string $many): string => ($v === 0 ? 'No' : number_format($v)) . ' ' . ($v === 1 ? $one : $many);
$news ??= null;
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Admin overview</h1>
    <p class="pt-lede">What is waiting for you, and how the site is being used.</p>
</header>

<?php if (!empty($configShadowed)): ?>
<div class="pt-alert pt-alert--warning" role="status">A settings file was uploaded as <code>public_html/config/local.php</code>, but the site reads <code>patterns-data/config.php</code> outside <code>public_html</code>, so the upload has no effect. Make the change in <code>patterns-data/config.php</code>, then delete <code>config/local.php</code> from <code>public_html</code>.</div>
<?php endif; ?>

<section class="pt-section" aria-labelledby="ov-waiting">
    <h2 class="pt-h2" id="ov-waiting">Waiting for you</h2>
    <div class="pt-stats">
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($waiting['approval']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/users/?status=approval">Accounts waiting for approval</a></span>
        </div>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($waiting['tickets']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/tickets/?status=open">New tickets to triage</a></span>
        </div>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($licencesWaiting['requested']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/licences/?status=requested">New licence requests</a></span>
        </div>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($licencesWaiting['awaiting_payment']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/licences/?status=awaiting_payment">Licences awaiting payment</a></span>
        </div>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($waiting['messages']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/messages/">New messages</a></span>
        </div>
    </div>
</section>

<section class="pt-section" aria-labelledby="ov-totals">
    <h2 class="pt-h2" id="ov-totals">Accounts and tickets</h2>
    <div class="pt-stats">
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($users['total']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/users/">Accounts in all</a></span>
        </div>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= $n($users['pending']) ?></span>
            <span class="pt-stat-label"><a href="/portal/admin/users/?status=pending">Email not confirmed yet</a></span>
        </div>
        <?php foreach ($typeTabs as $type => $label): ?>
            <div class="pt-stat">
                <span class="pt-stat-value"><?= $n($openByType[$type] ?? 0) ?></span>
                <span class="pt-stat-label"><a href="/portal/admin/tickets/?type=<?= e($type) ?>&amp;status=active"><?= e($label) ?></a></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="pt-section" aria-labelledby="ov-assistant">
    <h2 class="pt-h2" id="ov-assistant">Support assistant</h2>
    <div class="pt-table-wrap">
        <table class="pt-table pt-table--stack">
            <caption>Assistant use today and over the last 7 days, including today (UTC days)</caption>
            <thead>
                <tr>
                    <th scope="col">Period</th>
                    <th scope="col" class="pt-num">Conversations</th>
                    <th scope="col" class="pt-num">Requests</th>
                    <th scope="col" class="pt-num">Tokens in</th>
                    <th scope="col" class="pt-num">Tokens out</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (['today' => 'Today', 'week' => 'Last 7 days'] as $key => $label):
                    $u = $assistant[$key]; ?>
                    <tr>
                        <th scope="row"><?= e($label) ?></th>
                        <td class="pt-num" data-label="Conversations"><?= $n((int) $u['conversations']) ?></td>
                        <td class="pt-num" data-label="Requests"><?= $n((int) $u['requests']) ?></td>
                        <td class="pt-num" data-label="Tokens in"><?= $n((int) $u['tokens_in']) ?></td>
                        <td class="pt-num" data-label="Tokens out"><?= $n((int) $u['tokens_out']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="pt-actions">
        <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/chats/">See the conversations</a>
        <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/chats/?refused=1">Only refused ones</a>
    </div>
</section>

<section class="pt-section" aria-labelledby="ov-server">
    <h2 class="pt-h2" id="ov-server">Server</h2>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption>The PHP this page ran on, as PHP reports it</caption>
            <thead>
                <tr>
                    <th scope="col">Setting</th>
                    <th scope="col">Value</th>
                    <th scope="col">State</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($server as $fact): ?>
                    <tr>
                        <th scope="row"><?= e($fact['label']) ?></th>
                        <td><?= e($fact['value']) ?></td>
                        <td><span class="pt-badge pt-badge--<?= $fact['ok'] ? 'active' : 'pending' ?>"><?= $fact['ok'] ? 'As intended' : 'Check this' ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="pt-grid pt-grid--2">
    <section class="pt-card" aria-labelledby="ov-approval">
        <h2 class="pt-card-title" id="ov-approval">Accounts waiting for approval</h2>
        <div class="pt-card-body">
            <?php if ($latest['approval'] === []): ?>
                <p class="pt-muted">No accounts are waiting for approval.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($latest['approval'] as $u): ?>
                        <li>
                            <a href="/portal/admin/users/<?= (int) $u['id'] ?>/"><?= e($u['name']) ?></a>
                            <span class="pt-small pt-muted"><?= e($u['email']) ?><?= ($u['company'] ?? '') !== '' ? ' · ' . e($u['company']) : '' ?> · registered <?= $time($u['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="pt-card-footer"><a href="/portal/admin/users/?status=approval">See every account waiting</a></div>
    </section>

    <section class="pt-card" aria-labelledby="ov-tickets">
        <h2 class="pt-card-title" id="ov-tickets">New tickets</h2>
        <div class="pt-card-body">
            <?php if ($latest['tickets'] === []): ?>
                <p class="pt-muted">No new tickets. Every ticket has been triaged.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($latest['tickets'] as $t):
                        $tType = (string) $t['type']; ?>
                        <li>
                            <a href="/portal/tickets/<?= (int) $t['id'] ?>/"><span class="pt-mono">#<?= (int) $t['id'] ?></span> <?= e($t['title']) ?></a>
                            <span class="pt-badge pt-badge--<?= e($tType) ?>"><?= e($ticketTypes[$tType] ?? $tType) ?></span>
                            <span class="pt-small pt-muted"><?= e($t['owner_name'] ?? 'Former account') ?> · <?= $time($t['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="pt-card-footer"><a href="/portal/admin/tickets/?status=open">See every new ticket</a></div>
    </section>

    <section class="pt-card" aria-labelledby="ov-licences">
        <h2 class="pt-card-title" id="ov-licences">Licence requests waiting</h2>
        <div class="pt-card-body">
            <?php if ($latest['licences'] === []): ?>
                <p class="pt-muted">No licence requests are waiting.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($latest['licences'] as $l):
                        $lStatus = (string) $l['status'];
                        $lProduct = (string) $l['product']; ?>
                        <li>
                            <a href="/portal/admin/licences/<?= (int) $l['id'] ?>/"><span class="pt-mono"><?= e($l['reference']) ?></span> <?= e(($products[$lProduct] ?? $lProduct) . ' ' . ($licenceNames[(int) $l['id']] ?? $l['tier'])) ?></a>
                            <span class="pt-badge pt-badge--<?= e($lStatus) ?>"><?= e($licenceStatuses[$lStatus] ?? $lStatus) ?></span>
                            <span class="pt-small pt-muted"><?= e($l['owner_name'] ?? 'Former account') ?> · <?= $time($l['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="pt-card-footer"><a href="/portal/admin/licences/">See every licence request</a></div>
    </section>

    <section class="pt-card" aria-labelledby="ov-messages">
        <h2 class="pt-card-title" id="ov-messages">New messages</h2>
        <div class="pt-card-body">
            <?php if ($latest['messages'] === []): ?>
                <p class="pt-muted">No new messages. You are all caught up.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($latest['messages'] as $m):
                        $mProduct = (string) ($m['product'] ?? ''); ?>
                        <li>
                            <a href="/portal/admin/messages/#message-<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a>
                            <span class="pt-small pt-muted"><?= e($topics[(string) $m['topic']] ?? $m['topic']) ?><?= $mProduct !== '' ? ' · ' . e($products[$mProduct] ?? $mProduct) : '' ?> · <?= $time($m['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="pt-card-footer"><a href="/portal/admin/messages/">Open the inbox</a></div>
    </section>

    <?php if ($news !== null):
        [$deskBadge, $deskWords] = match ($news['desk']) {
            'on' => ['active', 'Running'],
            'paused' => ['pending', 'Paused'],
            'budget' => ['pending', 'Waiting: the monthly cap is near'],
            'no_key' => ['disabled', 'Stopped: no API key is set'],
            'sdk_missing' => ['disabled', 'Stopped: the SDK is not installed'],
            'no_tick' => ['disabled', 'Stopped: the cron job has not ticked yet'],
            'stopped' => ['disabled', 'Stopped: the cron job is not ticking'],
            default => ['pending', 'Not running'],
        };
        $last = $news['last'];
        $runWords = ['queued' => 'waiting to start', 'running' => 'running now', 'done' => 'done', 'skipped' => 'skipped', 'failed' => 'failed']; ?>
        <section class="pt-card" aria-labelledby="ov-news">
            <h2 class="pt-card-title" id="ov-news">Industry Updates</h2>
            <div class="pt-card-body">
                <ul>
                    <li>
                        <a href="/portal/admin/news/?tab=held"><?= e($count($news['held'], 'article held for review', 'articles held for review')) ?></a>
                        <span class="pt-small pt-muted">Drafts the AI desk wrote, waiting for you to publish or delete them</span>
                    </li>
                    <li>
                        <a href="/portal/admin/news/comments/"><?= e($count($news['reported'], 'reported comment', 'reported comments')) ?></a>
                    </li>
                    <li>
                        <a href="/portal/admin/news/comments/?tab=pending"><?= e($count($news['pending'], 'comment waiting for approval', 'comments waiting for approval')) ?></a>
                    </li>
                    <li>
                        <a href="/portal/admin/news/desk/">The AI desk</a>
                        <span class="pt-badge pt-badge--<?= e($deskBadge) ?>"><?= e($deskWords) ?></span>
                        <span class="pt-small pt-muted">
                            <?php if ($last !== null): ?>Last session <?= $time($last['created_at']) ?>: <?= e($runWords[(string) $last['status']] ?? (string) $last['status']) ?>, <?= e($count((int) $last['articles'], 'article', 'articles')) ?> · <?php endif; ?>Spent this month: about <?= e(number_format($news['spent'], 2)) ?> of <?= e(number_format($news['budget'])) ?> USD (estimated)
                        </span>
                    </li>
                </ul>
            </div>
            <div class="pt-card-footer"><a href="/portal/admin/news/">See every article</a></div>
        </section>
    <?php endif; ?>
</div>
