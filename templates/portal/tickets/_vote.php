<?php
/**
 * The vote toggle for a public feature request: one button whose pressed state is the viewer's vote. The label
 * stays the same whichever way it is pressed; a visible line says when the viewer has voted.
 *
 * @var int $id
 * @var string $title
 * @var int $votes
 * @var bool $voted
 * @var string $return where to come back to: /portal/ideas/… or the ticket's own page
 */
$votes = (int) $votes;
?>
<form method="post" action="/portal/tickets/<?= (int) $id ?>/vote/">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <button type="submit" class="pt-vote" aria-pressed="<?= $voted ? 'true' : 'false' ?>">
        <span class="pt-vote-count"><?= $votes ?></span>
        <span class="pt-small"><?= $votes === 1 ? 'vote' : 'votes' ?></span><span class="u-visually-hidden">, vote for “<?= e($title) ?>”</span>
    </button>
    <?php if ($voted): ?><span class="pt-small pt-muted">You voted</span><?php endif; ?>
</form>
