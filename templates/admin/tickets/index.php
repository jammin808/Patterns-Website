<?php
/**
 * Every ticket, for staff: counts by status, filters, and a table that links to each ticket's page (where the
 * "Manage this ticket" form lives).
 *
 * @var \Psc\Support\Paginator $pager rows carry owner_name and owner_email
 * @var array{type: string, product: string, status: string, visibility: string, sort: string, q: string} $filters
 * @var bool $filtered
 * @var array<string, int> $counts per status
 * @var array<string, string> $types
 * @var array<string, string> $products
 * @var array<string, string> $statuses
 * @var array<string, string> $statusFilters
 * @var array<string, string> $severities
 * @var array<string, string> $visibilities
 * @var array<string, string> $sorts
 */
$field = fn (array $p): string => $this->render('portal/tickets/_field', $p);
$active = ['open', 'triaged', 'planned', 'in_progress'];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">All tickets</h1>
    <p class="pt-lede">Every ticket from every client. Open one to reply, change its status or make it public.</p>
</header>

<div class="pt-stats">
    <?php foreach ($active as $s): ?>
        <div class="pt-stat">
            <span class="pt-stat-value"><?= (int) ($counts[$s] ?? 0) ?></span>
            <span class="pt-stat-label"><?= e($statuses[$s]) ?></span>
        </div>
    <?php endforeach; ?>
</div>

<form class="pt-search" method="get" action="/portal/admin/tickets/" role="search" aria-label="Search and filter tickets">
    <?= $field(['name' => 'q', 'id' => 'admin-q', 'label' => 'Search', 'kind' => 'search', 'value' => $filters['q'], 'help' => 'A title, a client’s name or email, or a ticket number such as #12.', 'markOptional' => false, 'attrs' => ['maxlength' => 100, 'autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'type', 'id' => 'admin-type', 'label' => 'Type', 'kind' => 'select', 'value' => $filters['type'], 'markOptional' => false, 'options' => ['' => 'Any type'] + $types]) ?>
    <?= $field(['name' => 'product', 'id' => 'admin-product', 'label' => 'Product', 'kind' => 'select', 'value' => $filters['product'], 'markOptional' => false, 'options' => ['' => 'All products'] + $products]) ?>
    <?= $field(['name' => 'status', 'id' => 'admin-status', 'label' => 'Status', 'kind' => 'select', 'value' => $filters['status'], 'markOptional' => false, 'options' => ['' => 'Any status'] + $statusFilters]) ?>
    <?= $field(['name' => 'visibility', 'id' => 'admin-visibility', 'label' => 'Visibility', 'kind' => 'select', 'value' => $filters['visibility'], 'markOptional' => false, 'options' => ['' => 'Private or public'] + $visibilities]) ?>
    <?= $field(['name' => 'sort', 'id' => 'admin-sort', 'label' => 'Sort by', 'kind' => 'select', 'value' => $filters['sort'], 'markOptional' => false, 'options' => $sorts]) ?>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Apply</button>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/tickets/">Clear filters</a><?php endif; ?>
    </div>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <p><?= $filtered ? 'No tickets match these filters.' : 'No tickets yet. When clients report bugs, suggest features or ask questions, they appear here.' ?></p>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--secondary" href="/portal/admin/tickets/">Clear filters</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table pt-table--stack">
            <caption class="u-visually-hidden">Tickets, sorted by <?= e(mb_strtolower($sorts[$filters['sort']] ?? 'recently active')) ?> (<?= (int) $pager->total ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col" class="pt-num">#</th>
                    <th scope="col">Ticket</th>
                    <th scope="col">Type</th>
                    <th scope="col">Product</th>
                    <th scope="col">Status</th>
                    <th scope="col">Severity</th>
                    <th scope="col">From</th>
                    <th scope="col">Visibility</th>
                    <th scope="col" class="pt-num">Votes</th>
                    <th scope="col" class="pt-num">Replies</th>
                    <th scope="col">Last activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $t):
                    $tType = (string) $t['type'];
                    $tStatus = (string) $t['status'];
                    $tVis = (string) $t['visibility'];
                    $tSev = (string) ($t['severity'] ?? ''); ?>
                    <tr>
                        <td class="pt-num pt-mono" data-label="#"><?= (int) $t['id'] ?></td>
                        <th scope="row"><a href="/portal/tickets/<?= (int) $t['id'] ?>/"><?= e($t['title']) ?></a></th>
                        <td data-label="Type"><span class="pt-badge pt-badge--<?= e($tType) ?>"><?= e($types[$tType] ?? $tType) ?></span></td>
                        <td data-label="Product"><?= e($products[(string) $t['product']] ?? $t['product']) ?></td>
                        <td data-label="Status"><span class="pt-badge pt-badge--<?= e($tStatus) ?>"><?= e($statuses[$tStatus] ?? $tStatus) ?></span></td>
                        <td data-label="Severity"><?php if ($tSev !== ''): ?><span class="pt-badge pt-badge--<?= e($tSev) ?>"><?= e($severities[$tSev] ?? $tSev) ?></span><?php else: ?><span class="pt-muted">Not set</span><?php endif; ?></td>
                        <td data-label="From"><?php if ($t['owner_email'] !== null): ?><a href="/portal/admin/users/<?= (int) $t['user_id'] ?>/"><bdi><?= e($t['owner_name']) ?></bdi></a> <small><?= e($t['owner_email']) ?></small><?php else: ?><span class="pt-muted">Former account</span><?php endif; ?></td>
                        <td data-label="Visibility"><span class="pt-badge pt-badge--<?= e($tVis) ?>"><?= e($visibilities[$tVis] ?? $tVis) ?></span></td>
                        <td class="pt-num" data-label="Votes"><?= $tType === 'feature' ? (int) $t['votes'] : '<span class="pt-muted">None</span>' ?></td>
                        <td class="pt-num" data-label="Replies"><?= (int) $t['comment_count'] ?></td>
                        <td data-label="Last activity"><?= $this->render('portal/tickets/_time', ['ts' => $t['last_activity_at']]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
