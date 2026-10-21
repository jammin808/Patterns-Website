<?php
/* PlayMap pricing (/playmap/licensing/): the shared licensing page during early access, with licences coming soon. */
$h1 = 'PlayMap pricing and early access';
$tierActions = [
    ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=playmap', 'primary' => true],
    ['label' => 'Get it from the client portal', 'href' => '/downloads/#playmap'],
];
$buyActions = [
    ['label' => 'Create a client account', 'href' => '/portal/register/', 'primary' => true],
    ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=playmap'],
];
$closing = [
    'title' => 'Try PlayMap in early access',
    'text' => 'Download today\'s portable build from the client portal and put it through its paces on your own rig while licences are prepared.',
    'actions' => [
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#playmap', 'primary' => true],
        ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=playmap'],
    ],
];
echo $this->render('pages/product/licensing', get_defined_vars());
