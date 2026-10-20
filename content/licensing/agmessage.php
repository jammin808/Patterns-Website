<?php

declare(strict_types=1);

// AGMessage licensing: delivered today as a managed service per AGM (with the agency rate and the pilot, priced on application);
// self-run licences will follow StageWatch's one-off, offline model and are coming soon ('self_run').

return [
    'product' => 'agmessage',
    'pricing_status' => 'coming-soon',
    'model' => 'AGMessage is delivered as a managed service for each AGM: the laptop, the software set up for your client\'s meeting and a technician through setup, rehearsal and the meeting day, quoted on application.',
    'intro' => 'AGMessage arrives as one line item on your invoice: a high-spec venue laptop, AGMessage set up for your client\'s meeting, and a client-facing technician running the back-room through setup, rehearsal and the meeting itself. Agencies with five or more AGMs a year get a partnership rate quoted against their roster, and a pilot lets you try it on one real AGM, refunded in full if the back-room team is not satisfied. Book a rehearsal demo on agmessage.com to see it running.',
    'tiers' => [
        [
            'id' => 'managed',
            'name' => 'Managed delivery for one AGM',
            'price' => null,
            'price_label' => 'Price on application',
            'period' => 'per AGM',
            'machines' => null,
            'summary' => 'Two days of managed delivery for one AGM: a high-spec venue laptop, AGMessage set up for your client\'s meeting, and our client-facing technician running the back-room across setup, rehearsal and the live meeting day.',
            'includes' => [
                'A high-spec venue laptop',
                'AGMessage set up and named for your client\'s meeting',
                'A client-facing technician for setup, rehearsal and the meeting day',
                'Hardware, software and technician as one line item on your invoice',
            ],
            'highlight' => true,
            'badge' => 'How AGMessage is delivered',
            'requestable' => false,
            'hardware_ids' => 0,
            'cta' => ['label' => 'Ask for a quote', 'href' => '/contact/?topic=sales&product=agmessage'],
        ],
        [
            'id' => 'agencies',
            'name' => 'Agency partnership rate',
            'price' => null,
            'price_label' => 'Price on application',
            'period' => 'five or more AGMs a year',
            'machines' => null,
            'summary' => 'For agencies producing five or more AGMs a year. We quote against your planned event roster rather than a flat seat licence, and plan capacity around the UK AGM season from May to July.',
            'includes' => [
                'A quote against your planned roster of AGMs',
                'Capacity planned around the UK AGM season',
            ],
            'highlight' => false,
            'requestable' => false,
            'hardware_ids' => 0,
            'cta' => ['label' => 'Ask for a partnership quote', 'href' => '/contact/?topic=sales&product=agmessage'],
        ],
        [
            'id' => 'pilot',
            'name' => 'The pilot: one AGM, one fixed fee',
            'price' => null,
            'price_label' => 'Fixed fee on application',
            'period' => 'one AGM',
            'machines' => null,
            'summary' => 'Run AGMessage on one real AGM in your portfolio for one fixed fee, the same as the standard per-event rate, with one handover at the end. If the back-room team is not satisfied, we refund the fee in full and write off the work, and that promise is stated in writing on the proposal.',
            'includes' => [
                'Hardware, software and technician as one line item',
                'The same fee as the standard per-event rate',
                'A full refund if the back-room team is not satisfied, in writing on the proposal',
            ],
            'you_provide' => [
                'A real AGM date in your portfolio',
                'An introduction to the end client',
                'A 20-minute debrief afterwards',
                'Permission to name you in a one-paragraph case study',
            ],
            'highlight' => false,
            'requestable' => false,
            'hardware_ids' => 0,
            'cta' => ['label' => 'See the pilot offer', 'href' => 'https://agmessage.com/pilot/'],
        ],
    ],
    'self_run' => [
        'title' => 'Run AGMessage yourself',
        'status' => 'coming-soon',
        'text' => 'Self-run licences for teams who want to run the back-room on their own laptop are coming. They will follow the same model as StageWatch: one-off licences, activated offline with a licence file and tied to your machine, with a trial first. Prices are not announced yet.',
        'tiers' => [
            ['id' => 'trial', 'name' => 'Trial', 'price' => null, 'price_label' => 'Coming soon', 'summary' => 'Try AGMessage on your own laptop before you buy.'],
            ['id' => 'standard', 'name' => 'Standard', 'price' => null, 'price_label' => 'Coming soon', 'summary' => 'A one-off licence for one host laptop.'],
            ['id' => 'pro', 'name' => 'Pro', 'price' => null, 'price_label' => 'Coming soon', 'summary' => 'For teams who run several meetings a year.'],
            ['id' => 'production', 'name' => 'Production', 'price' => null, 'price_label' => 'Coming soon', 'summary' => 'For production companies and agencies, with a backup laptop.'],
        ],
        'cta' => ['label' => 'Register interest', 'href' => '/contact/?topic=sales&product=agmessage'],
    ],
    'extras' => [],
    'policies' => [
        ['title' => 'One line item', 'text' => 'Managed delivery brings the laptop, the software and the technician together, so the back-room has one supplier and one line on the invoice.'],
        ['title' => 'Quoted for your meeting', 'text' => 'Every AGM is priced on application, against its dates and scope. Agencies with five or more AGMs a year are quoted against the planned roster, not a flat seat licence.'],
        ['title' => 'The pilot promise', 'text' => 'On a pilot, if the back-room team is not satisfied, the fee is refunded in full and the work written off. The promise is written into the proposal.'],
        ['title' => 'No licence server, no internet', 'text' => 'AGMessage runs on the venue\'s own network with no internet connection and no licence to activate. Only the optional AI drafts and remote access need the internet.'],
        ['title' => 'AI is optional', 'text' => 'The AI drafts use an Anthropic API key added on the host laptop, and Anthropic bills that key\'s holder for its use. Everything else works without it.'],
    ],
    'how_to_buy' => [
        'Book a rehearsal demo on agmessage.com and see AGMessage running.',
        'Tell us about the AGMs you are producing: the dates, the venues and the scope.',
        'Receive a proposal for each AGM, or a partnership quote against your roster if you produce five or more a year.',
        'On the day, the technician brings the laptop and runs the back-room through setup, rehearsal and the meeting.',
    ],
    'matrix' => [
        'columns' => [],
        'groups' => [],
    ],
    'faqs' => [
        ['q' => 'How much does AGMessage cost?', 'a' => 'Each AGM is priced on application, because every meeting\'s dates and scope differ. Book a rehearsal demo on agmessage.com, or tell us about your meeting on the contact page, for a quote.'],
        ['q' => 'What does the managed delivery include?', 'a' => 'Two days of managed delivery for one AGM: a high-spec venue laptop, AGMessage set up for your client\'s meeting, and a client-facing technician running the back-room across setup, rehearsal and the meeting day, all as one line item.'],
        ['q' => 'Is there a rate for agencies?', 'a' => 'Yes. Agencies producing five or more AGMs a year get a partnership rate, quoted against the planned event roster rather than a flat seat licence.'],
        ['q' => 'Can we try AGMessage on one AGM first?', 'a' => 'Yes. The pilot runs AGMessage on one real AGM in your portfolio for one fixed fee, the same as the standard per-event rate, with one handover at the end.'],
        ['q' => 'What if the back-room team is not satisfied with the pilot?', 'a' => 'We refund the fee in full and write off the work. That promise is stated in writing on the proposal.'],
        ['q' => 'What do you ask for in return for a pilot?', 'a' => 'A real AGM date in your portfolio, an introduction to the end client, a 20-minute debrief afterwards and permission to name you in a one-paragraph case study.'],
        ['q' => 'Can we run AGMessage ourselves?', 'a' => 'Not yet. Today AGMessage is delivered as a managed service. Self-run licences on the same one-off, offline model as StageWatch are coming: register interest on the contact page under Buying a licence or pricing.'],
        ['q' => 'Does AGMessage need a licence server or the internet?', 'a' => 'No. AGMessage runs on the venue\'s own network. Only the optional AI drafts and remote access need an internet connection.'],
        ['q' => 'Do the AI drafts cost extra?', 'a' => 'The AI drafts are optional. They use an Anthropic API key added on the host laptop, and Anthropic bills that key\'s holder for its use.'],
    ],
];
