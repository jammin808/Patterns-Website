<?php
/**
 * The feature-request board: public feature requests with their votes, filtered, searched and sorted.
 *
 * @var \Psc\Support\Paginator $pager rows carry voted (the viewer's vote, 0 or 1)
 * @var array{product: string, status: string, sort: string, q: string} $filters
 * @var bool $filtered
 * @var string $returnTo this page's address, for the vote buttons to come back to
 * @var array<string, string> $products
 * @var array<string, string> $statuses
 * @var array<string, string> $sorts
 */
$field = fn (array $p): string => $this->render('portal/tickets/_field', $p);
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Feature requests</h1>
    <p class="pt-lede">Ideas from people who use the software. Vote for the ones that would help your shows, or suggest your own.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--primary" href="/portal/tickets/new/?type=feature">Suggest a feature</a>
    </div>
</header>

<form class="pt-search" method="get" action="/portal/ideas/" role="search" aria-label="Search and filter feature requests">
    <?= $field(['name' => 'q', 'id' => 'board-q', 'label' => 'Search titles', 'kind' => 'search', 'value' => $filters['q'], 'markOptional' => false, 'attrs' => ['maxlength' => 100, 'autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'product', 'id' => 'board-product', 'label' => 'Product', 'kind' => 'select', 'value' => $filters['product'], 'markOptional' => false, 'options' => ['' => 'All products'] + $products]) ?>
    <?= $field(['name' => 'status', 'id' => 'board-status', 'label' => 'Status', 'kind' => 'select', 'value' => $filters['status'], 'markOptional' => false, 'options' => ['' => 'Any status'] + $statuses]) ?>
    <?= $field(['name' => 'sort', 'id' => 'board-sort', 'label' => 'Sort by', 'kind' => 'select', 'value' => $filters['sort'], 'markOptional' => false, 'options' => $sorts]) ?>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Apply</button>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/ideas/">Clear filters</a><?php endif; ?>
    </div>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($filtered): ?>
            <p>No feature requests match your search. Try fewer words, or another product or status.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/ideas/">Clear filters</a>
        <?php else: ?>
            <p>No feature requests yet. Yours could be the first.</p>
            <a class="pt-btn pt-btn--primary" href="/portal/tickets/new/?type=feature">Suggest a feature</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="pt-small pt-muted"><?= (int) $pager->total ?> <?= $pager->total === 1 ? 'request' : 'requests' ?><?= $filtered ? ' match' . ($pager->total === 1 ? 'es' : '') . ' your filters' : '' ?>.</p>
    <div class="pt-table-wrap">
        <table class="pt-table pt-table--stack">
            <caption class="u-visually-hidden">Feature requests, sorted by <?= e(mb_strtolower($sorts[$filters['sort']] ?? 'most votes')) ?></caption>
            <thead>
                <tr>
                    <th scope="col">Votes</th>
                    <th scope="col">Request</th>
                    <th scope="col">Product</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="pt-num">Replies</th>
                    <th scope="col">Last activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $t):
                    $tStatus = (string) $t['status']; ?>
                    <tr id="ticket-<?= (int) $t['id'] ?>">
                        <td data-label="Votes"><?= $this->render('portal/tickets/_vote', ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'votes' => (int) $t['votes'], 'voted' => (int) $t['voted'] > 0, 'return' => $returnTo]) ?></td>
                        <th scope="row"><a href="/portal/tickets/<?= (int) $t['id'] ?>/"><?= e($t['title']) ?></a></th>
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
