<?php
/* StageWatch pricing (/stagewatch/licensing/): the shared licensing page with live prices and the full comparison. */
$h1 = 'StageWatch pricing: one-off licences that work offline';
$buyActions = [
    ['label' => 'Create a client account', 'href' => '/portal/register/', 'primary' => true],
    ['label' => 'Request a licence', 'href' => '/portal/licences/request/?product=stagewatch'],
    ['label' => 'Download the trial', 'href' => '/downloads/#stagewatch'],
];
$closing = [
    'title' => 'Start with 14 days of everything',
    'text' => 'Download StageWatch and every feature is unlocked for 14 days from the first launch. When you know which licence fits, request it from the client portal.',
    'actions' => [
        ['label' => 'Start the 14-day trial', 'href' => '/downloads/#stagewatch', 'primary' => true],
        ['label' => 'Request a licence', 'href' => '/portal/licences/request/?product=stagewatch'],
    ],
];
echo $this->render('pages/product/licensing', get_defined_vars());
