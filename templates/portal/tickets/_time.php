<?php
/**
 * A timestamp as <time>: relative ("3 hours ago") by default, or the short date; the machine-readable date is
 * always in the datetime attribute.
 *
 * @var int|string|null $ts
 * @var string $format ago | date
 */
$ts = (int) ($ts ?? 0);
$format ??= 'ago';
if ($ts > 0): ?><time datetime="<?= e(date(DATE_ATOM, $ts)) ?>"><?= e($format === 'date' ? date_short($ts) : ago($ts)) ?></time><?php endif;
