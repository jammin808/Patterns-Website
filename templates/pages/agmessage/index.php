<?php
/* AGMessage overview (/agmessage/): the shared product overview with the drawn back-room screen as its picture. */
$hero = [
    'h1' => 'AGMessage: back-room command for AGMs and board meetings',
    'actions' => [
        ['label' => 'Book a rehearsal demo', 'href' => 'https://agmessage.com/#book', 'primary' => true],
        ['label' => 'How it\'s delivered', 'href' => '/agmessage/licensing/'],
    ],
    'visual' => 'agmessage',
];
$closing = [
    'title' => 'See AGMessage before your next AGM',
    'text' => 'Book a rehearsal demo and watch the back-room work. For the meeting itself, AGMessage arrives with the laptop and a technician, priced on application.',
];
echo $this->render('pages/product/overview', get_defined_vars());
