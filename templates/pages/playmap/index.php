<?php
/* PlayMap overview (/playmap/): the shared product overview with the 3D stage as its picture (early access). */
$hero = [
    'h1' => 'PlayMap: play it, map it, run the show',
    'actions' => [
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#playmap', 'primary' => true],
        ['label' => 'Licensing', 'href' => '/playmap/licensing/'],
    ],
    'visual' => 'shot',
    'shot' => 'map-3d-stage',
];
$closing = [
    'title' => 'Map your next show with PlayMap',
    'text' => 'PlayMap is in early access. Create a free client account and download today\'s portable build, or choose Demo when it starts to explore with no projector.',
];
echo $this->render('pages/product/overview', get_defined_vars());
