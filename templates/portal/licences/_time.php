<?php
/**
 * A timestamp as <time>: the short date by default, or relative ("3 hours ago"); the machine-readable date is
 * always in the datetime attribute.
 *
 * @var int|string|null $ts
 * @var string $format date | ago
 */
$ts = (int) ($ts ?? 0);
$format ??= 'date';
if ($ts > 0): ?><time datetime="<?= e(date(DATE_ATOM, $ts)) ?>"><?= e($format === 'ago' ? ago($ts) : date_short($ts)) ?></time><?php endif;
