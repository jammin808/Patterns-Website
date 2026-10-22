<?php

declare(strict_types=1);

/* Site-wide data: the brand, the one-sentence summary for llms.txt and the assistant, the header navigation (Products is the mega-menu), the footer columns, the trademarks line and the training-crawler policy. */

return [
    'name' => 'Patterns Show Control',
    'summary' => 'Patterns Show Control makes four Windows apps for live events: Patterns for the picture, StageWatch for the show clock, AGMessage for the back-room at AGMs and board meetings, and PlayMap for media playback and projection mapping, which work on their own or together on the venue\'s own network.',
    'llms_intro' => 'Patterns Show Control is the home of the Patterns ecosystem, made in the UK by Ben Rohleder for the crews who run conferences, AGMs and live events. The four apps run without the internet, find each other on the venue network and confirm every command between them. Out of the box, Patterns, StageWatch and AGMessage run nothing an AI suggests until a person approves it, and PlayMap\'s high-risk actions always wait for a person. This site covers what each app does, how they work together, licensing, downloads and support.',
    'tagline' => 'Four show apps. One network. Every decision stays yours.',
    'description' => 'Test patterns, stage timers, back-room messaging and projection mapping for live events: four Windows apps that work together on one show network.',
    'publisher' => ['name' => 'Ben Rohleder', 'country' => 'United Kingdom'],

    'nav' => [
        ['label' => 'Ecosystem', 'href' => '/ecosystem/'],
        [
            'label' => 'Products',
            'children' => [
                [
                    'product' => 'patterns',
                    'label' => 'Patterns',
                    'line' => 'Test patterns and show content on every screen',
                    'href' => '/patterns/',
                    'links' => [
                        ['label' => 'Overview', 'href' => '/patterns/'],
                        ['label' => 'Features', 'href' => '/patterns/features/'],
                        ['label' => 'Licensing', 'href' => '/patterns/licensing/'],
                        ['label' => 'Help', 'href' => '/support/patterns/'],
                    ],
                ],
                [
                    'product' => 'stagewatch',
                    'label' => 'StageWatch',
                    'line' => 'Stage timer and show calling',
                    'href' => '/stagewatch/',
                    'links' => [
                        ['label' => 'Overview', 'href' => '/stagewatch/'],
                        ['label' => 'Features', 'href' => '/stagewatch/features/'],
                        ['label' => 'Licensing', 'href' => '/stagewatch/licensing/'],
                        ['label' => 'Help', 'href' => '/support/stagewatch/'],
                    ],
                ],
                [
                    'product' => 'agmessage',
                    'label' => 'AGMessage',
                    'line' => 'Back-room messaging for AGMs and boards',
                    'href' => '/agmessage/',
                    'links' => [
                        ['label' => 'Overview', 'href' => '/agmessage/'],
                        ['label' => 'Features', 'href' => '/agmessage/features/'],
                        ['label' => 'Licensing', 'href' => '/agmessage/licensing/'],
                        ['label' => 'Help', 'href' => '/support/agmessage/'],
                    ],
                ],
                [
                    'product' => 'playmap',
                    'label' => 'PlayMap',
                    'line' => 'Media playback and projection mapping',
                    'href' => '/playmap/',
                    'links' => [
                        ['label' => 'Overview', 'href' => '/playmap/'],
                        ['label' => 'Features', 'href' => '/playmap/features/'],
                        ['label' => 'Licensing', 'href' => '/playmap/licensing/'],
                        ['label' => 'Help', 'href' => '/support/playmap/'],
                    ],
                ],
            ],
            'footer' => [
                ['label' => 'How the apps work together', 'href' => '/ecosystem/'],
                ['label' => 'Integrations', 'href' => '/ecosystem/integrations/'],
                ['label' => 'Downloads', 'href' => '/downloads/'],
            ],
        ],
        ['label' => 'Workflows', 'href' => '/workflows/'],
        ['label' => 'Licensing', 'href' => '/licensing/'],
        // Industry Updates; the bar has room for a short label only (docs/NEWS.md §2.1).
        ['label' => 'News', 'href' => '/news/'],
        ['label' => 'Support', 'href' => '/support/'],
    ],

    // Beside the navigation: the two places a returning client goes most.
    'actions' => [
        ['label' => 'Downloads', 'href' => '/downloads/', 'style' => 'secondary'],
        ['label' => 'Sign in', 'href' => '/portal/login/', 'style' => 'primary'],
    ],

    'footer' => [
        [
            'title' => 'Products',
            'links' => [
                ['label' => 'Patterns', 'href' => '/patterns/'],
                ['label' => 'Patterns features', 'href' => '/patterns/features/'],
                ['label' => 'Patterns licensing', 'href' => '/patterns/licensing/'],
                ['label' => 'StageWatch', 'href' => '/stagewatch/'],
                ['label' => 'StageWatch features', 'href' => '/stagewatch/features/'],
                ['label' => 'StageWatch pricing', 'href' => '/stagewatch/licensing/'],
                ['label' => 'AGMessage', 'href' => '/agmessage/'],
                ['label' => 'AGMessage features', 'href' => '/agmessage/features/'],
                ['label' => 'AGMessage licensing', 'href' => '/agmessage/licensing/'],
                ['label' => 'PlayMap', 'href' => '/playmap/'],
                ['label' => 'PlayMap features', 'href' => '/playmap/features/'],
                ['label' => 'PlayMap licensing', 'href' => '/playmap/licensing/'],
            ],
        ],
        [
            'title' => 'Ecosystem',
            'links' => [
                ['label' => 'How the apps work together', 'href' => '/ecosystem/'],
                ['label' => 'Integrations', 'href' => '/ecosystem/integrations/'],
                ['label' => 'AI with approval', 'href' => '/ecosystem/ai/'],
                ['label' => 'All workflows', 'href' => '/workflows/'],
                ['label' => 'Line up an LED wall', 'href' => '/workflows/led-wall-line-up/'],
                ['label' => 'Blend projectors', 'href' => '/workflows/projector-edge-blending/'],
                ['label' => 'Run a conference day on time', 'href' => '/workflows/conference-timing/'],
                ['label' => 'Answer AGM shareholder questions', 'href' => '/workflows/agm-shareholder-questions/'],
                ['label' => 'Map an object with one projector', 'href' => '/workflows/projection-mapping-an-object/'],
                ['label' => 'Run the show from a Stream Deck', 'href' => '/workflows/stream-deck-show-control/'],
            ],
        ],
        [
            'title' => 'Support',
            'links' => [
                ['label' => 'Support home', 'href' => '/support/'],
                ['label' => 'Frequently asked questions', 'href' => '/support/faq/'],
                ['label' => 'Downloads', 'href' => '/downloads/'],
                ['label' => 'Patterns help', 'href' => '/support/patterns/'],
                ['label' => 'StageWatch help', 'href' => '/support/stagewatch/'],
                ['label' => 'AGMessage help', 'href' => '/support/agmessage/'],
                ['label' => 'PlayMap help', 'href' => '/support/playmap/'],
                ['label' => 'Client portal sign-in', 'href' => '/portal/login/'],
                ['label' => 'Create a free account', 'href' => '/portal/register/'],
            ],
        ],
        [
            'title' => 'Company',
            'links' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'About', 'href' => '/about/'],
                ['label' => 'Industry Updates', 'href' => '/news/'],
                ['label' => 'Contact', 'href' => '/contact/'],
                ['label' => 'How licensing works', 'href' => '/licensing/'],
                ['label' => 'Software licence terms', 'href' => '/legal/licence-terms/'],
                ['label' => 'Website terms', 'href' => '/legal/terms/'],
                ['label' => 'Privacy', 'href' => '/legal/privacy/'],
                ['label' => 'Cookies', 'href' => '/legal/cookies/'],
            ],
        ],
    ],

    'footer_note' => 'Made in the UK by Ben Rohleder, for the crews who run conferences, AGMs and live events.',
    'trademarks' => 'NDI® is a registered trademark of Vizrt NDI AB. Bitfocus Companion, Stream Deck, PowerPoint, vMix, Philips Hue and other names belong to their owners.',
    'block_training' => false, // robots.txt: true asks model-training crawlers to stay away
];
