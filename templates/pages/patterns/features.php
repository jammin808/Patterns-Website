<?php
/* Patterns features (/patterns/features/): the shared features page with Patterns' H1 and closing step. */
$hero = [
    'h1' => 'Everything Patterns does, from load-in to the last cue',
    'actions' => [
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#patterns', 'primary' => true],
        ['label' => 'Licensing', 'href' => '/patterns/licensing/'],
    ],
];
$closing = [
    'title' => 'Take Patterns to your next load-in',
    'text' => 'Download Patterns from the client portal with a free client account, put it on a USB stick and open it on the show laptop. Nothing to install, no admin rights.',
];
echo $this->render('pages/product/features', get_defined_vars());
