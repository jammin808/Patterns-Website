<?php
/**
 * One licence request for staff: the request, the client, the files, and the forms that move it on.
 *
 * @var array<string, mixed> $licence
 * @var array<string, mixed>|null $owner
 * @var list<array<string, mixed>> $files
 * @var string $productName
 * @var string $optionName
 * @var list<string> $hardwareIds
 * @var array<string, string> $statuses
 * @var string|null $paymentLink the configured payment link that applies, if any
 * @var list<string> $extensions
 * @var int $maxFiles
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$id = (int) $licence['id'];
$status = (string) $licence['status'];
$action = '/portal/admin/licences/' . $id . '/';
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
$accept = implode(',', array_map(static fn (string $x): string => '.' . $x, $extensions));
$types = implode(', ', array_map('strtoupper', $extensions));
$labels = count($hardwareIds) === 2 ? ['Main PC', 'Backup PC'] : [];
$dateValue = static fn (mixed $ts): string => $ts !== null && (int) $ts > 0 ? date('Y-m-d', (int) $ts) : '';
$posted = (string) ($old['action'] ?? '');
$err = static fn (string $action, string $key): ?string => $posted === $action ? ($errors[$key] ?? null) : null;
$nextStatus = ['requested' => 'awaiting_payment', 'awaiting_payment' => 'issued', 'issued' => 'revoked', 'revoked' => 'issued'][$status] ?? '';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Licence requests</p>
    <h1 class="pt-title"><span class="pt-mono"><?= e($licence['reference']) ?></span>: <?= e($productName) ?> <?= e($optionName) ?></h1>
    <p class="pt-lede"><span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span> Requested <?= $time($licence['created_at']) ?><?= $licence['issued_at'] !== null ? ', issued ' . $time($licence['issued_at']) : '' ?>.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/licences/">Back to licence requests</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/licences/<?= $id ?>/">See the client’s view</a>
    </div>
</header>

<div class="pt-grid pt-grid--2">
    <section class="pt-card" aria-labelledby="licence-request">
        <h2 class="pt-card-title" id="licence-request">Request</h2>
        <div class="pt-card-body">
            <dl class="pt-kv">
                <dt>Product</dt>
                <dd><?= e($productName) ?></dd>
                <dt>Licence</dt>
                <dd><?= e($optionName) ?> <span class="pt-muted pt-mono">(<?= e($licence['tier']) ?>)</span></dd>
                <dt>Quantity</dt>
                <dd><?= (int) $licence['quantity'] ?><?= (int) $licence['quantity'] > 1 ? ' (5-pack)' : '' ?></dd>
                <dt>Nonprofit</dt>
                <dd><?= (int) $licence['nonprofit'] === 1 ? 'Yes' : 'No' ?></dd>
                <?php foreach ($hardwareIds as $i => $hw): ?>
                    <dt><?= e(($labels[$i] ?? (count($hardwareIds) > 1 ? 'PC ' . ($i + 1) : 'PC')) . ' hardware ID') ?></dt>
                    <dd class="pt-mono"><?= e($hw) ?></dd>
                <?php endforeach; ?>
                <?php if ($paymentLink !== null): ?>
                    <dt>Payment link</dt>
                    <dd class="pt-small pt-mono"><?= e($paymentLink) ?></dd>
                <?php endif; ?>
            </dl>
            <?php if (trim((string) ($licence['customer_note'] ?? '')) !== ''): ?>
                <h3 class="pt-card-title">Client’s note</h3>
                <div class="pt-prose"><?= md((string) $licence['customer_note']) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pt-card" aria-labelledby="licence-client">
        <h2 class="pt-card-title" id="licence-client">Client</h2>
        <div class="pt-card-body">
            <?php if ($owner === null): ?>
                <p class="pt-muted">This account no longer exists.</p>
            <?php else: ?>
                <dl class="pt-kv">
                    <dt>Name</dt>
                    <dd><a href="/portal/admin/users/<?= (int) $owner['id'] ?>/"><?= e($owner['name']) ?></a></dd>
                    <dt>Email</dt>
                    <dd><?= e($owner['email']) ?></dd>
                    <?php if ((string) ($owner['company'] ?? '') !== ''): ?>
                        <dt>Company</dt>
                        <dd><?= e($owner['company']) ?></dd>
                    <?php endif; ?>
                    <dt>Account</dt>
                    <dd><span class="pt-badge pt-badge--<?= e($owner['status']) ?>"><?= e(ucfirst((string) $owner['status'])) ?></span></dd>
                </dl>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="pt-section" aria-labelledby="licence-status" id="status">
    <h2 class="pt-h2" id="licence-status">Status</h2>
    <form class="pt-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_status">
        <?= $field([
            'name' => 'status',
            'id' => 'f-status',
            'label' => 'New status',
            'kind' => 'select',
            'value' => $posted === 'set_status' ? ($old['status'] ?? $nextStatus) : $nextStatus,
            'error' => $err('set_status', 'status'),
            'required' => true,
            'options' => array_filter($statuses, static fn (string $k): bool => $k !== $status, ARRAY_FILTER_USE_KEY),
            'help' => 'Now ' . mb_strtolower($statuses[$status] ?? $status) . '. The client is emailed about the change.',
        ]) ?>
        <?= $field([
            'name' => 'message',
            'id' => 'f-status-message',
            'label' => 'Message to the client',
            'kind' => 'textarea',
            'value' => $posted === 'set_status' ? ($old['message'] ?? '') : '',
            'error' => $err('set_status', 'message'),
            'help' => 'Added to the email, for example payment details or what happens next.',
            'attrs' => ['rows' => 4, 'maxlength' => 5000],
        ]) ?>
        <?= $field([
            'name' => 'files[]',
            'id' => 'f-status-files',
            'label' => 'Licence files to add',
            'kind' => 'file',
            'error' => $err('set_status', 'files'),
            'help' => 'Needed to issue a licence that has no file yet. ' . $types . ', up to 1 MB each, up to ' . $maxFiles . ' at a time.',
            'attrs' => ['accept' => $accept, 'multiple' => true],
        ]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary">Change status and email the client</button>
        </div>
    </form>
</section>

<section class="pt-section" aria-labelledby="licence-files-heading" id="files">
    <h2 class="pt-h2" id="licence-files-heading">Licence files</h2>
    <?php if ($files === []): ?>
        <p class="pt-muted">No files yet.</p>
    <?php else: ?>
        <ul class="pt-files" aria-label="Licence files">
            <?php foreach ($files as $f): ?>
                <li>
                    <a href="/portal/licences/<?= $id ?>/files/<?= (int) $f['id'] ?>/" download><?= e($f['original_name']) ?></a>
                    <span class="pt-small pt-muted"><?= e(filesize_human((int) $f['size'])) ?>, added <?= $time($f['created_at']) ?></span>
                    <form method="post" action="<?= e($action) ?>" class="pt-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_file">
                        <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
                        <button type="submit" class="pt-btn pt-btn--danger pt-btn--small">Delete<span class="u-visually-hidden"> <?= e($f['original_name']) ?></span></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="pt-small pt-muted"><?= $status === 'issued' ? 'The client can download these now.' : 'The client can download these once the licence is issued.' ?></p>

    <form class="pt-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <?= $field([
            'name' => 'files[]',
            'id' => 'f-upload-files',
            'label' => 'Add licence files',
            'kind' => 'file',
            'required' => true,
            'error' => $err('upload', 'files'),
            'help' => $types . ', up to 1 MB each, up to ' . $maxFiles . ' at a time.',
            'attrs' => ['accept' => $accept, 'multiple' => true],
        ]) ?>
        <?php if ($status === 'issued'): ?>
            <?= $field([
                'name' => 'notify',
                'kind' => 'checkbox',
                'label' => 'Email the client that a new licence file is ready',
                'checked' => $posted !== 'upload' || ($old['notify'] ?? '') === '1',
                'error' => $err('upload', 'notify'),
            ]) ?>
        <?php endif; ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--secondary">Upload</button>
        </div>
    </form>
</section>

<section class="pt-section" aria-labelledby="licence-details" id="details">
    <h2 class="pt-h2" id="licence-details">Dates and note</h2>
    <form class="pt-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="details">
        <?= $field([
            'name' => 'updates_until',
            'label' => 'Updates until',
            'kind' => 'date',
            'value' => $posted === 'details' && array_key_exists('updates_until', $old) ? $old['updates_until'] : $dateValue($licence['updates_until']),
            'error' => $err('details', 'updates_until'),
            'help' => 'Leave empty for lifetime updates. The client sees this date.',
        ]) ?>
        <?= $field([
            'name' => 'expires_at',
            'label' => 'Ends',
            'kind' => 'date',
            'value' => $posted === 'details' && array_key_exists('expires_at', $old) ? $old['expires_at'] : $dateValue($licence['expires_at']),
            'error' => $err('details', 'expires_at'),
            'help' => 'For time-limited licences such as an event pass. Leave empty if it never ends. The client sees this date.',
        ]) ?>
        <?= $field([
            'name' => 'admin_note',
            'label' => 'Note for administrators',
            'kind' => 'textarea',
            'value' => $posted === 'details' && array_key_exists('admin_note', $old) ? $old['admin_note'] : (string) ($licence['admin_note'] ?? ''),
            'error' => $err('details', 'admin_note'),
            'help' => 'Never shown to the client: payment received, invoice number and so on.',
            'attrs' => ['rows' => 4, 'maxlength' => 5000],
        ]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary">Save dates and note</button>
        </div>
    </form>
</section>
