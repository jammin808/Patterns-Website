<?php
/* AGMessage pricing (/agmessage/licensing/): managed delivery per AGM, the agency rate and the pilot, then self-run licences that are coming. */
$h1 = 'AGMessage pricing and delivery';
$buyActions = [
    ['label' => 'Book a rehearsal demo', 'href' => 'https://agmessage.com/#book', 'primary' => true],
    ['label' => 'Ask for a quote', 'href' => '/contact/?topic=sales&product=agmessage'],
    ['label' => 'See the pilot offer', 'href' => 'https://agmessage.com/pilot/'],
];
$closing = [
    'title' => 'Plan AGMessage into your next AGM',
    'text' => 'Book a rehearsal demo, then tell us the dates, venues and scope of your AGMs for a proposal.',
    'actions' => [
        ['label' => 'Book a rehearsal demo', 'href' => 'https://agmessage.com/#book', 'primary' => true],
        ['label' => 'Ask for a quote', 'href' => '/contact/?topic=sales&product=agmessage'],
    ],
];
echo $this->render('pages/product/licensing', get_defined_vars());
