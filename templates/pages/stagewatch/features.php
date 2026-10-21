<?php
/* StageWatch features (/stagewatch/features/): the shared features page with StageWatch's H1 and closing step. */
$hero = [
    'h1' => 'StageWatch features',
    'actions' => [
        ['label' => 'Start the 14-day trial', 'href' => '/downloads/#stagewatch', 'primary' => true],
        ['label' => 'See pricing', 'href' => '/stagewatch/licensing/'],
    ],
];
$closing = [
    'title' => 'Try every feature for 14 days',
    'text' => 'The trial unlocks everything in Production, with no watermark, from the first time you open StageWatch. Licences are one-off, from £9.',
];
echo $this->render('pages/product/features', get_defined_vars());
