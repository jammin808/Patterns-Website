<?php
/**
 * The full licence comparison (docs/DESIGN.md 8.21) from a licensing file's matrix: one column per tier, rows in
 * groups. The recommended column carries .is-rec on every cell. Where the table scrolls sideways (phones) the
 * feature column sticks and each group row repeats the licence names, because the header row cannot stick inside a
 * sideways scroller; those repeats are hidden from screen readers, which announce the real column headers.
 *
 * @var array<string, mixed> $licensing
 * @var array<string, mixed> $product
 */
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$tiersById = [];
foreach ($licensing['tiers'] as $t) {
    $tiersById[(string) $t['id']] = $t;
}
$cols = array_values(array_filter($licensing['matrix']['columns'], static fn ($c): bool => isset($tiersById[$c])));
$count = count($cols);
$rec = static fn (string $c): string => !empty($tiersById[$c]['highlight']) ? ' class="is-rec"' : '';
$name = (string) $product['name'];
$cell = static function (mixed $v): string {
    if ($v === true) {
        return '<span class="c-yes">' . icon('check') . '<span class="u-visually-hidden">Included</span></span>';
    }
    if ($v === false || $v === null) {
        return '<span class="c-no">' . icon('dash') . '<span class="u-visually-hidden">Not included</span></span>';
    }
    $s = (string) $v;
    if (preg_match('/^(\d+)\s*\((.+)\)$/', $s, $m)) {
        return '<span class="c-val">' . e($m[1]) . '</span> <small class="u-small u-muted">' . e($m[2]) . '</small>';
    }
    return preg_match('/^\d/', $s) ? '<span class="c-val">' . e($s) . '</span>' : e($s);
};
?>
<div class="c-compare c-pd-compare" data-compare>
    <p class="c-compare-hint"><?= icon('arrow', 'c-icon--sm') ?>Scroll sideways to compare all <?= e($h['words']($count)) ?> licences</p>
    <div class="c-compare-scroll" data-compare-scroll tabindex="0" role="region" aria-label="<?= e($name) ?> licence comparison">
        <table class="c-compare-table">
            <caption class="u-visually-hidden">What each <?= e($name) ?> licence includes</caption>
            <thead>
                <tr>
                    <th scope="col">Feature</th>
                    <?php foreach ($cols as $c): $t = $tiersById[$c]; $p = $h['tier']($t); ?>
                        <th scope="col"<?= $rec($c) ?>>
                            <span class="c-tier-head">
                                <?php if ($p['flag'] !== null): ?><span class="c-flag<?= $p['flagOutline'] ? ' c-flag--outline' : '' ?>"><?= e($p['flagOutline'] ? 'Launch' : $p['flag']) ?></span><?php endif; ?>
                                <span class="c-tier-name"><?= e($t['name']) ?></span>
                                <span class="c-price"><span class="c-price-amount"><?= e($p['figure']) ?></span><?php if ($p['was'] !== null): ?><s class="c-price-was"><span class="u-visually-hidden">was </span><?= e($p['was']) ?></s><?php endif; ?></span>
                            </span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td></td>
                    <?php foreach ($cols as $c): $t = $tiersById[$c]; $p = $h['tier']($t); $link = $t['cta'] ?? null; ?>
                        <td<?= $rec($c) ?>>
                            <?php if ($p['unit'] !== null): ?><span class="c-tier-head-sub"><?= e($p['unit']) ?></span><?php endif; ?>
                            <?php if (is_array($link)): [$first, $rest] = array_pad(explode(' ', (string) $link['label'], 2), 2, ''); ?>
                                <a class="c-btn c-btn--sm <?= !empty($t['highlight']) ? 'c-btn--primary' : 'c-btn--secondary' ?>" href="<?= e($link['href']) ?>"><?= e($first) ?><?php if ($rest !== ''): ?><span class="u-visually-hidden"> <?= e($rest) ?></span><?php endif; ?></a>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($licensing['matrix']['groups'] as $g): ?>
                    <tr class="c-compare-group">
                        <th scope="rowgroup"><?= e($g['title']) ?></th>
                        <?php foreach ($cols as $c): ?><td<?= $rec($c) ?> aria-hidden="true"><span class="c-pd-compare-remind"><?= e($tiersById[$c]['name']) ?></span></td><?php endforeach; ?>
                    </tr>
                    <?php foreach ($g['rows'] as $row): ?>
                        <tr>
                            <th scope="row"><?= e($row['feature']) ?></th>
                            <?php foreach ($cols as $c): ?><td<?= $rec($c) ?>><?= $cell($row['values'][$c] ?? null) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="c-fineprint"><?= e($licensing['model']) ?></p>
</div>
