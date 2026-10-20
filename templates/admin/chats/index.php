<?php
/**
 * The support assistant's conversations, the most recently active first: who, where, turns, tokens, and how
 * many answers carried each refusal or hand-over verdict, with a filter for conversations that had a refusal.
 *
 * @var \Psc\Support\Paginator $pager rows carry user_name and user_email (null for visitors)
 * @var bool $refused
 * @var array<string, array<string, int>> $counts conversation id => verdict (and feedback) => count
 * @var array<string, string> $links conversation id => transcript URL
 * @var array<string, string> $verdicts
 * @var list<string> $counted verdict column order
 */
$time = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(ago((int) $ts)) . '</time>'
    : '';
$dash = '<span class="pt-muted">0</span>';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Assistant chats</h1>
    <p class="pt-lede">Conversations with the support assistant on the public site. Open one to read the whole transcript.</p>
</header>

<nav class="pt-tabs" aria-label="Filter conversations">
    <a href="/portal/admin/chats/"<?= !$refused ? ' aria-current="page"' : '' ?>>All conversations</a>
    <a href="/portal/admin/chats/?refused=1"<?= $refused ? ' aria-current="page"' : '' ?>>Only refused</a>
</nav>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($refused): ?>
            <p>No conversations had a refused answer.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/admin/chats/">Show all conversations</a>
        <?php else: ?>
            <p>No conversations yet. When visitors use the assistant on the public site, their conversations appear here.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption class="u-visually-hidden"><?= $refused ? 'Conversations with at least one refused answer' : 'Assistant conversations' ?>, the most recently active first (<?= (int) $pager->total ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col">Conversation</th>
                    <th scope="col">Who</th>
                    <th scope="col">Page</th>
                    <th scope="col" class="pt-num">Turns</th>
                    <th scope="col" class="pt-num">Tokens</th>
                    <?php foreach ($counted as $v): ?>
                        <th scope="col" class="pt-num"><?= e($verdicts[$v] ?? $v) ?></th>
                    <?php endforeach; ?>
                    <th scope="col" class="pt-num">Feedback</th>
                    <th scope="col">Last message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $c):
                    $cid = (string) $c['id'];
                    $row = $counts[$cid] ?? []; ?>
                    <tr>
                        <th scope="row"><a href="<?= e($links[$cid] ?? '/portal/admin/chats/?conversation=' . rawurlencode($cid)) ?>">Started <?= $time($c['created_at']) ?><span class="u-visually-hidden">: transcript of the conversation on <?= e(($c['page'] ?? '') !== '' ? $c['page'] : 'an unknown page') ?></span></a></th>
                        <td>
                            <?php if ($c['user_id'] !== null && $c['user_email'] !== null): ?>
                                <a href="/portal/admin/users/<?= (int) $c['user_id'] ?>/"><?= e($c['user_name']) ?></a>
                            <?php elseif ($c['user_id'] !== null): ?>
                                <span class="pt-muted">Former account</span>
                            <?php else: ?>
                                <span class="pt-muted">Visitor</span>
                            <?php endif; ?>
                        </td>
                        <td class="pt-mono pt-small"><?= e($c['page'] ?? '') ?></td>
                        <td class="pt-num"><?= (int) $c['turns'] ?></td>
                        <td class="pt-num"><?= e(number_format((int) $c['tokens_in'] + (int) $c['tokens_out'])) ?></td>
                        <?php foreach ($counted as $v): ?>
                            <td class="pt-num"><?= ($row[$v] ?? 0) > 0 ? (int) $row[$v] : $dash ?></td>
                        <?php endforeach; ?>
                        <td class="pt-num"><?= ($row['feedback'] ?? 0) > 0 ? (int) $row['feedback'] : $dash ?></td>
                        <td><?= $time($c['last_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
