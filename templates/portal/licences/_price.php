<?php
/**
 * A requestable licence's price as the licensing pages show it: a launch price leads, with the list price struck
 * through after it (read as "was £79") and the words "launch price"; any other price is its own label from the
 * licensing content ("£15 for 30 days"), unchanged.
 *
 * @var array<string, mixed> $option see LicenceController::options()
 */
$h = require PSC_ROOT . '/templates/partials/product/helpers.php';
$p = $h['tier']($option);
if ($p['was'] !== null): ?><strong><?= e($p['amount']) ?></strong> <s class="pt-muted"><span class="u-visually-hidden">was </span><?= e($p['was']) ?></s> launch price<?php
else: ?><?= e((string) $option['price_label']) ?><?php if ((string) $option['price_note'] !== ''): ?> <span class="pt-muted">(<?= e((string) $option['price_note']) ?>)</span><?php endif; ?><?php
endif;
