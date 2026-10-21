<?php
/* Patterns pricing (/patterns/licensing/): the shared licensing page while Patterns licences are coming soon. */
$h1 = 'Patterns pricing and licences';
$buyActions = [
    ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=patterns', 'primary' => true],
    ['label' => 'Create a client account', 'href' => '/portal/register/'],
];
$closing = [
    'title' => 'Hear first when Patterns licences launch',
    'text' => 'Register your interest and you will be among the first to hear about prices. Patterns itself is ready in the client portal\'s downloads today.',
    'actions' => [
        ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=patterns', 'primary' => true],
        ['label' => 'Get it from the client portal', 'href' => '/downloads/#patterns'],
    ],
];
echo $this->render('pages/product/licensing', get_defined_vars());
