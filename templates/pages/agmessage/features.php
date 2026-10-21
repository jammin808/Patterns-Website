<?php
/* AGMessage features (/agmessage/features/): the shared features page with AGMessage's H1 and closing step. */
$hero = [
    'h1' => 'AGMessage features',
    'actions' => [
        ['label' => 'Book a rehearsal demo', 'href' => 'https://agmessage.com/#book', 'primary' => true],
        ['label' => 'How it\'s delivered', 'href' => '/agmessage/licensing/'],
    ],
];
$closing = [
    'title' => 'See the back-room working',
    'text' => 'Book a rehearsal demo to see AGMessage running. For your AGM, it arrives with the laptop, the software set up for the meeting and a technician.',
];
echo $this->render('pages/product/features', get_defined_vars());
