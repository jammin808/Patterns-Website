<?php

declare(strict_types=1);

/*
 * Which feature group each product screenshot illustrates (screenshot file name => feature group id). The features
 * page shows a capture beside its group; the overview uses the first group's capture in its feature grid.
 * Included with require; it prints nothing.
 */

return [
    'patterns' => [
        'colour-bars' => 'test-patterns',
        'desk-led-wall' => 'led-walls',
        'led-wall-pattern' => 'led-walls',
        'blend-pattern' => 'projection',
        'edit-safe-preview' => 'desk',
        'show-panel' => 'desk',
        'run-mode' => 'cues',
        'countdown' => 'content',
    ],
    'playmap' => [
        'play-playlists' => 'playback',
        'map-3d-stage' => 'mapping',
        'map-pins' => 'mapping',
        'map-pen-mask' => 'mapping',
        'outputs-blend' => 'mapping',
        'scan-review' => 'scanning',
        'network-peers' => 'sync',
    ],
];
