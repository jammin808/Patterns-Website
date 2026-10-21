<?php
/**
 * The portal's home page: a welcome, quick actions, the account's open tickets and licences, the newest release
 * of each product, and for administrators what is waiting.
 *
 * @var string $firstName
 * @var bool $isAdmin
 * @var list<array<string, mixed>> $tickets open tickets, with has_reply
 * @var int $openTickets
 * @var int $allTickets
 * @var list<array<string, mixed>> $licences
 * @var int $licenceCount
 * @var array<int, string> $licenceNames licence id => tier or extra name
 * @var list<array<string, mixed>> $releases one per product, with may_download
 * @var array{approval: int, tickets: int, licences: int, messages: int}|null $waiting
 * @var array<string, string> $products
 * @var array<string, string> $ticketTypes
 * @var array<string, string> $ticketStatuses
 * @var array<string, string> $licenceStatuses
 * @var array<string, string> $channels
 */
$time = static fn (mixed $ts, bool $date = false): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e($date ? date_short((int) $ts) : ago((int) $ts)) . '</time>'
    : '';
$count = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
// A release card's title line is shown only when it says more than the card's heading: a title that only repeats
// the product and version ("Patterns 3.2.1", "v3.2.1"), in any case or spacing, is left out.
$plain = static fn (string $text): string => (string) preg_replace('/[^\p{L}\p{N}.]+/u', '', mb_strtolower($text));
$addsToHeading = static function (string $title, string $product, string $version) use ($plain): bool {
    $t = $plain($title);
    return $t !== '' && !in_array($t, [$plain($product . ' ' . $version), $plain($product . ' v' . $version), $plain($version), $plain('v' . $version)], true);
};
$actions = [
    ['/portal/tickets/new/?type=bug', 'Report a bug', 'Something not working as it should? Tell us what happened.'],
    ['/portal/tickets/new/?type=feature', 'Request a feature', 'An idea that would help your shows? Suggest it.'],
    ['/portal/tickets/new/?type=question', 'Ask a question', 'Not sure how something works? Ask us.'],
    ['/portal/downloads/', 'Downloads', 'Get the latest version of each product.'],
    ['/portal/licences/request/', 'Request a licence', 'Ask for a licence for your show PC.'],
];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Welcome, <?= e($firstName) ?></h1>
    <p class="pt-lede">Download the software, collect your licences and get help, all in one place.</p>
</header>

<?php if ($isAdmin && $waiting !== null):
    $waitingTotal = array_sum($waiting);
    $waitingLinks = [
        ['/portal/admin/users/?status=approval', $count($waiting['approval'], 'account waiting for approval', 'accounts waiting for approval'), $waiting['approval']],
        ['/portal/admin/tickets/?status=open', $count($waiting['tickets'], 'new ticket to triage', 'new tickets to triage'), $waiting['tickets']],
        ['/portal/admin/licences/', $count($waiting['licences'], 'licence request to handle', 'licence requests to handle'), $waiting['licences']],
        ['/portal/admin/messages/', $count($waiting['messages'], 'new message', 'new messages'), $waiting['messages']],
    ]; ?>
    <section class="pt-section" aria-labelledby="dash-admin">
        <div class="pt-card">
            <h2 class="pt-card-title" id="dash-admin">Administration</h2>
            <div class="pt-card-body">
                <?php if ($waitingTotal === 0): ?>
                    <p>Nothing is waiting for you right now.</p>
                <?php else: ?>
                    <p>Waiting for you:</p>
                    <ul>
                        <?php foreach ($waitingLinks as [$href, $label, $n]): ?>
                            <?php if ($n > 0): ?><li><a href="<?= e($href) ?>"><?= e($label) ?></a></li><?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="pt-card-footer">
                <a class="pt-btn pt-btn--secondary pt-btn--small" href="/portal/admin/">Open the admin overview</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="pt-section" aria-labelledby="dash-actions">
    <h2 class="pt-h2" id="dash-actions">Quick actions</h2>
    <div class="pt-grid pt-grid--3">
        <?php foreach ($actions as [$href, $title, $text]): ?>
            <a class="pt-card pt-card--link" href="<?= e($href) ?>">
                <span class="pt-card-title"><?= e($title) ?></span>
                <span class="pt-card-body"><?= e($text) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="pt-section" aria-labelledby="dash-tickets">
    <h2 class="pt-h2" id="dash-tickets">Your open tickets</h2>
    <?php if ($tickets === []): ?>
        <div class="pt-empty">
            <?php if ($allTickets === 0): ?>
                <p>You have not opened any tickets yet. When something is not working, or you have an idea or a question, start a ticket here.</p>
                <a class="pt-btn pt-btn--primary" href="/portal/tickets/new/">Open a ticket</a>
            <?php else: ?>
                <p>None of your tickets are open right now. Your earlier tickets are still there if you need them.</p>
                <a class="pt-btn pt-btn--secondary" href="/portal/tickets/">See all your tickets</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">Your open tickets, the most recently active first</caption>
                <thead>
                    <tr>
                        <th scope="col">Ticket</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t):
                        $tType = (string) $t['type'];
                        $tStatus = (string) $t['status']; ?>
                        <tr<?= $t['has_reply'] ? ' class="is-unread"' : '' ?>>
                            <th scope="row">
                                <a href="/portal/tickets/<?= (int) $t['id'] ?>/"><span class="pt-muted pt-mono">#<?= (int) $t['id'] ?></span> <?= e($t['title']) ?></a>
                                <?php if ($t['has_reply']): ?> <span class="pt-badge pt-badge--new">New reply</span><?php endif; ?>
                            </th>
                            <td data-label="Type"><span class="pt-badge pt-badge--<?= e($tType) ?>"><?= e($ticketTypes[$tType] ?? $tType) ?></span></td>
                            <td data-label="Status"><span class="pt-badge pt-badge--<?= e($tStatus) ?>"><?= e($ticketStatuses[$tStatus] ?? $tStatus) ?></span></td>
                            <td data-label="Last activity"><?= $time($t['last_activity_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pt-actions">
            <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/tickets/"><?= $openTickets > count($tickets) ? e('See all ' . $openTickets . ' open tickets') : 'See all your tickets' ?></a>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="dash-licences">
    <h2 class="pt-h2" id="dash-licences">Your licences</h2>
    <?php if ($licences === []): ?>
        <div class="pt-empty">
            <p>You have no licences yet. When you are ready, request one for your show PC and we will email you about the next step.</p>
            <a class="pt-btn pt-btn--primary" href="/portal/licences/request/">Request a licence</a>
        </div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">Your licences and licence requests, the newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">Licence</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Status</th>
                        <th scope="col">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($licences as $l):
                        $lStatus = (string) $l['status'];
                        $lProduct = (string) $l['product']; ?>
                        <tr>
                            <th scope="row"><a href="/portal/licences/<?= (int) $l['id'] ?>/"><?= e(($products[$lProduct] ?? $lProduct) . ' ' . ($licenceNames[(int) $l['id']] ?? $l['tier'])) ?><span class="u-visually-hidden">, reference <?= e($l['reference']) ?></span></a><?php if ((int) $l['quantity'] > 1): ?> <span class="pt-muted">× <?= (int) $l['quantity'] ?></span><?php endif; ?></th>
                            <td class="pt-mono" data-label="Reference"><?= e($l['reference']) ?></td>
                            <td data-label="Status"><span class="pt-badge pt-badge--<?= e($lStatus) ?>"><?= e($licenceStatuses[$lStatus] ?? $lStatus) ?></span></td>
                            <td data-label="Requested"><?= $time($l['created_at'], true) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pt-actions">
            <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/licences/"><?= $licenceCount > count($licences) ? e('See all ' . $licenceCount . ' licences') : 'See all your licences' ?></a>
            <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/licences/request/">Request another licence</a>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="dash-releases">
    <h2 class="pt-h2" id="dash-releases">Latest releases</h2>
    <?php if ($releases === []): ?>
        <div class="pt-empty">
            <p>There are no downloads published yet. New versions appear here and on the downloads page as soon as they are released.</p>
        </div>
    <?php else: ?>
        <div class="pt-grid pt-grid--2">
            <?php foreach ($releases as $r):
                $rProduct = (string) $r['product'];
                $rName = (string) ($products[$rProduct] ?? $rProduct);
                $rChannel = (string) $r['channel']; ?>
                <article class="pt-card">
                    <h3 class="pt-card-title"><?= e($rName) ?> <?= e($r['version']) ?></h3>
                    <p class="pt-card-meta">
                        <span class="pt-badge pt-badge--<?= e($rChannel) ?>"><?= e($channels[$rChannel] ?? $rChannel) ?></span>
                        <?php if ((int) $r['published_at'] > 0): ?>Released <?= $time($r['published_at'], true) ?><?php endif; ?>
                    </p>
                    <?php if ($addsToHeading((string) $r['title'], $rName, (string) $r['version'])): ?>
                        <p class="pt-card-body"><?= e($r['title']) ?></p>
                    <?php endif; ?>
                    <?php if (!$r['may_download']): ?>
                        <p class="pt-small pt-muted">For licence holders. Request a licence to download it.</p>
                    <?php endif; ?>
                    <div class="pt-card-footer">
                        <a href="/portal/downloads/#<?= e($rProduct) ?>"><?= e('Go to ' . ($products[$rProduct] ?? $rProduct) . ' downloads') ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
