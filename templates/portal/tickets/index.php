<?php
/**
 * My tickets: tabs by type, a status filter, the most recently active first.
 *
 * @var \Psc\Support\Paginator $pager
 * @var string $type
 * @var string $status
 * @var array<string, int> $counts '' => all, then per type
 * @var array<string, string> $types
 * @var array<string, string> $statuses
 * @var array<string, string> $products
 */
$tabNames = ['' => 'All', 'bug' => 'Bug reports', 'feature' => 'Feature requests', 'question' => 'Questions'];
$tabUrl = static function (string $t) use ($status): string {
    $q = array_filter(['type' => $t, 'status' => $status], static fn (string $v): bool => $v !== '');
    return '/portal/tickets/' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$filtered = $type !== '' || $status !== '';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Tickets</h1>
    <p class="pt-lede">Your bug reports, feature requests and questions, with the latest activity first.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--primary" href="/portal/tickets/new/">New ticket</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/ideas/">Browse feature requests</a>
    </div>
</header>

<nav class="pt-tabs" aria-label="Ticket types">
    <?php foreach ($tabNames as $t => $name): ?>
        <a href="<?= e($tabUrl($t)) ?>"<?= $t === $type ? ' aria-current="page"' : '' ?>><?= e($name) ?> <span class="pt-muted">(<?= (int) ($counts[$t] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>

<form class="pt-search" method="get" action="/portal/tickets/" aria-label="Filter your tickets by status">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <label for="ticket-status">Status</label>
    <select id="ticket-status" name="status">
        <option value="">Any status</option>
        <?php foreach ($statuses as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Filter</button>
    <?php if ($status !== ''): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="<?= e($tabUrl($type)) ?>">Any status</a><?php endif; ?>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($filtered): ?>
            <p>No tickets match this filter.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/tickets/">Show all your tickets</a>
        <?php else: ?>
            <p>You have not opened any tickets yet. When something is not working, or you have an idea or a question, start here.</p>
            <a class="pt-btn pt-btn--primary" href="/portal/tickets/new/">Open a ticket</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table pt-table--stack">
            <caption class="u-visually-hidden">Your tickets, the most recently active first<?= $pager->pages() > 1 ? ' (page ' . $pager->page . ' of ' . $pager->pages() . ')' : '' ?></caption>
            <thead>
                <tr>
                    <th scope="col">Ticket</th>
                    <th scope="col">Type</th>
                    <th scope="col">Product</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="pt-num">Replies</th>
                    <th scope="col">Last activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $t):
                    $tType = (string) $t['type'];
                    $tStatus = (string) $t['status']; ?>
                    <tr>
                        <th scope="row"><a href="/portal/tickets/<?= (int) $t['id'] ?>/"><span class="pt-muted pt-mono">#<?= (int) $t['id'] ?></span> <?= e($t['title']) ?></a></th>
                        <td data-label="Type"><span class="pt-badge pt-badge--<?= e($tType) ?>"><?= e($types[$tType] ?? $tType) ?></span></td>
                        <td data-label="Product"><?= e($products[(string) $t['product']] ?? $t['product']) ?></td>
                        <td data-label="Status"><span class="pt-badge pt-badge--<?= e($tStatus) ?>"><?= e($statuses[$tStatus] ?? $tStatus) ?></span></td>
                        <td class="pt-num" data-label="Replies"><?= (int) $t['comment_count'] ?></td>
                        <td data-label="Last activity"><?= $this->render('portal/tickets/_time', ['ts' => $t['last_activity_at']]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
