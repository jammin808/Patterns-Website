<?php
/**
 * A run's status as a badge with its word: queued (hollow dot), running (amber), done (green), skipped (muted) or
 * failed (red, with a dash).
 *
 * @var string $status
 */
[$badge, $label] = [
    'queued' => ['requested', 'Queued'],
    'running' => ['in_progress', 'Running'],
    'done' => ['done', 'Done'],
    'skipped' => ['closed', 'Skipped'],
    'failed' => ['declined', 'Failed'],
][$status] ?? ['closed', ucfirst($status)];
?><span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($label) ?></span>
