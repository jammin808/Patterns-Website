<?php
/* StageWatch overview (/stagewatch/): the shared product overview with the live confidence monitor as its picture. */
$hero = [
    'h1' => 'StageWatch: the stage timer and show-calling desk for live events',
    'actions' => [
        ['label' => 'Start the 14-day trial', 'href' => '/downloads/#stagewatch', 'primary' => true],
        ['label' => 'See pricing', 'href' => '/stagewatch/licensing/'],
    ],
    'visual' => 'stagewatch',
];
$closing = [
    'title' => 'Time your next show with StageWatch',
    'text' => 'Every feature for 14 days from the first launch, with no watermark. Then keep the free timer, or choose a one-off licence from £9.',
];
echo $this->render('pages/product/overview', get_defined_vars());
