<?php
/* Patterns overview (/patterns/): the shared product overview with Patterns' first screen and closing step. */
$hero = [
    'h1' => 'Patterns: test patterns, switching and playout for every screen in the room',
    'actions' => [
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#patterns', 'primary' => true],
        ['label' => 'Licensing', 'href' => '/patterns/licensing/'],
    ],
    'visual' => 'shot',
    'shot' => 'show-panel',
];
$closing = [
    'title' => 'Put Patterns on the show laptop',
    'text' => 'Download Patterns from the client portal with a free client account. It runs from any folder or a USB stick, with nothing to install and no admin rights.',
];
echo $this->render('pages/product/overview', get_defined_vars());
