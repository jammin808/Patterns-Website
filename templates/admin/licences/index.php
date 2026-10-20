<?php
/**
 * Every licence request: tabs by status, a product filter and a search by reference or email.
 *
 * @var \Psc\Support\Paginator $pager rows carry user_name and user_email
 * @var array{status: string, product: string, q: string} $filters
 * @var bool $filtered
 * @var array<string, int> $counts '' => all, then per status
 * @var array<string, string> $statuses
 * @var array<string, string> $products
 * @var array<string, array<string, string>> $options product => tier => name
 */
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
$tabUrl = static function (string $status) use ($filters): string {
    $q = array_filter(['status' => $status, 'product' => $filters['product'], 'q' => $filters['q']], static fn (string $v): bool => $v !== '');
    return '/portal/admin/licences/' . ($q !== [] ? '?' . http_build_query($q) : '');
};
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Licence requests</h1>
    <p class="pt-lede">Every request and licence. Open one to change its status, add the licence file or keep a note.</p>
</header>

<nav class="pt-tabs" aria-label="Licence status">
    <?php foreach (['' => 'All'] + $statuses as $key => $label): ?>
        <a href="<?= e($tabUrl((string) $key)) ?>"<?= (string) $key === $filters['status'] ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="pt-muted">(<?= (int) ($counts[$key] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>

<form class="pt-search" method="get" action="/portal/admin/licences/">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <?= $field(['name' => 'q', 'id' => 'licences-q', 'label' => 'Search', 'kind' => 'search', 'value' => $filters['q'], 'help' => 'A reference such as SW-7KQ2MX, or part of an email address.', 'markOptional' => false, 'attrs' => ['maxlength' => 190, 'autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'product', 'id' => 'licences-product', 'label' => 'Product', 'kind' => 'select', 'value' => $filters['product'], 'markOptional' => false, 'options' => ['' => 'All products'] + $products]) ?>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Apply</button>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/licences/">Clear filters</a><?php endif; ?>
    </div>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <p><?= $filtered ? 'No licences match these filters.' : 'No licence requests yet. When clients request a licence, it appears here and you are emailed.' ?></p>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--secondary" href="/portal/admin/licences/">Clear filters</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption class="u-visually-hidden">Licence requests, the newest first (<?= (int) $pager->total ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Licence</th>
                    <th scope="col">Client</th>
                    <th scope="col">Status</th>
                    <th scope="col">Requested</th>
                    <th scope="col">Issued</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $l):
                    $product = (string) $l['product'];
                    $status = (string) $l['status']; ?>
                    <tr>
                        <th scope="row"><a class="pt-mono" href="/portal/admin/licences/<?= (int) $l['id'] ?>/"><?= e($l['reference']) ?></a></th>
                        <td>
                            <?= e(($products[$product] ?? $product) . ' ' . ($options[$product][(string) $l['tier']] ?? (string) $l['tier'])) ?>
                            <?php if ((int) $l['quantity'] > 1): ?><span class="pt-muted">× <?= (int) $l['quantity'] ?></span><?php endif; ?>
                            <?php if ((int) $l['nonprofit'] === 1): ?><span class="pt-small pt-muted">Nonprofit</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['user_email'] !== null): ?>
                                <a href="/portal/admin/users/<?= (int) $l['user_id'] ?>/"><?= e($l['user_name']) ?></a>
                                <span class="pt-small pt-muted"><?= e($l['user_email']) ?></span>
                            <?php else: ?>
                                <span class="pt-muted">Account deleted</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span></td>
                        <td><?= $time($l['created_at']) ?></td>
                        <td><?= $l['issued_at'] !== null ? $time($l['issued_at']) : '<span class="pt-muted">Not yet</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
