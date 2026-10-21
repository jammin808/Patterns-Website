<?php
/* PlayMap features (/playmap/features/): the shared features page with PlayMap's H1 and closing step. */
$hero = [
    'h1' => 'PlayMap features',
    'actions' => [
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#playmap', 'primary' => true],
        ['label' => 'Licensing', 'href' => '/playmap/licensing/'],
    ],
];
$closing = [
    'title' => 'Put PlayMap through its paces',
    'text' => 'PlayMap is in early access: download today\'s portable build from the client portal and try it on your own rig, or choose Demo to explore with no projector.',
];
echo $this->render('pages/product/features', get_defined_vars());
