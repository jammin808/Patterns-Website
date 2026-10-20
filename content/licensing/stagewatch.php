<?php

declare(strict_types=1);

/*
 * StageWatch licensing: the tiers, prices (GBP, one-off) and the feature matrix, matching the app's own pricing
 * table. This file is the reference shape for every product's licensing data:
 *
 *   pricing_status  'live' (prices shown, licences requestable) | 'coming-soon' (structure shown, no prices)
 *   tiers[]         id, name, price (numeric string, or null when not sold), price_label (display), period,
 *                   machines, summary, includes[], highlight, badge, requestable (a licence can be requested),
 *                   hardware_ids (how many IDs the request needs), cta {label, href}
 *   matrix          columns (tier ids) and groups of rows: feature => values per column (true, false, or text)
 *   extras[]        title, price, text: event passes, nonprofit, rental packs
 *   policies[]      title, text: updates, machines, activation, moving PCs
 *   how_to_buy[]    steps
 *   faqs[]          q, a (plain text)
 */

return [
    'product' => 'stagewatch',
    'pricing_status' => 'live',
    'model' => 'One-off licences in GBP. Activated offline with a licence file, tied to your show PC, and they keep working for ever.',
    'intro' => 'Start with a 14-day trial of everything. After that StageWatch stays free as a basic, watermarked timer, or you pick the licence that fits your shows. No subscription, no licence server and no internet connection needed at the venue.',
    'tiers' => [
        [
            'id' => 'trial',
            'name' => 'Trial',
            'price' => '0',
            'price_label' => 'Free for 14 days',
            'period' => '14 days',
            'machines' => null,
            'summary' => 'Every feature, no watermark, for 14 days from first launch.',
            'includes' => ['Every Production feature', 'No watermark', 'Starts on first launch'],
            'highlight' => false,
            'requestable' => false,
            'hardware_ids' => 0,
            'cta' => ['label' => 'Download the trial', 'href' => '/downloads/#stagewatch'],
        ],
        [
            'id' => 'free',
            'name' => 'Free',
            'price' => '0',
            'price_label' => 'Free',
            'period' => 'after the trial',
            'machines' => null,
            'summary' => 'A basic timer for ever: countdown, count-up, clock, messages and the full-screen output, watermarked.',
            'includes' => ['Countdown, count-up and time of day', 'Typed messages, warning colours, blackout', '2 presets', '"StageWatch by Ben Rohleder" on every output'],
            'highlight' => false,
            'requestable' => false,
            'hardware_ids' => 0,
            'cta' => ['label' => 'Download', 'href' => '/downloads/#stagewatch'],
        ],
        [
            'id' => 'standard',
            'name' => 'Standard',
            'price' => '9',
            'price_label' => '£9',
            'period' => 'one-off',
            'machines' => 1,
            'summary' => 'The core timer: all presets, end-at timing, alerts, quick messages, holding images, NDI and keyed outputs.',
            'includes' => ['12 presets and end-at (elastic) timing', 'Quick messages and sound alerts', 'NDI, chroma-key and floating outputs', 'Holding images and cue ladders', 'Lifetime updates for Standard'],
            'highlight' => false,
            'requestable' => true,
            'hardware_ids' => 1,
            'cta' => ['label' => 'Request Standard', 'href' => '/portal/licences/request/?product=stagewatch&tier=standard'],
        ],
        [
            'id' => 'pro',
            'name' => 'Pro',
            'price' => '29',
            'price_label' => '£29',
            'period' => 'one-off',
            'machines' => 1,
            'summary' => 'One operator, one room: the running order, browser remotes and role pages, Companion, PowerPoint, Q&A and triggers.',
            'includes' => ['Everything in Standard', 'Running order with ahead/behind and CSV import', 'Browser viewer, remote and role pages', 'Bitfocus Companion, HTTP and OSC', 'PowerPoint slide awareness and rehearsal ghost', 'Lifetime updates for Pro'],
            'highlight' => true,
            'badge' => 'Best for one room',
            'requestable' => true,
            'hardware_ids' => 1,
            'cta' => ['label' => 'Request Pro', 'href' => '/portal/licences/request/?product=stagewatch&tier=pro'],
        ],
        [
            'id' => 'production',
            'name' => 'Production',
            'price' => '79',
            'price_label' => '£79',
            'price_note' => 'Launch price £59',
            'period' => 'one-off',
            'machines' => 2,
            'summary' => 'The crew loop and multi-room kit, for a main and a backup PC.',
            'includes' => ['Everything in Pro', 'Acknowledged cues, departments and green-room calls', 'Multi-room dashboard and cue lights (Art-Net, sACN, Hue)', 'LTC, forecast and curfew trim suggestions', 'vMix and Patterns VT countdowns, MCP for AI tools', 'Main + backup PC'],
            'highlight' => false,
            'requestable' => true,
            'hardware_ids' => 2,
            'cta' => ['label' => 'Request Production', 'href' => '/portal/licences/request/?product=stagewatch&tier=production'],
        ],
    ],
    'extras' => [
        [
            'id' => 'event-pass',
            'title' => 'Production event pass',
            'price' => '15',
            'price_label' => '£15 for 30 days',
            'text' => 'Everything in Production for one event, on one PC. It warns for its last 3 days, keeps working for 2 days\' grace after it ends, and the £15 counts towards a full Production licence.',
            'requestable' => true,
            'hardware_ids' => 1,
        ],
        [
            'id' => 'nonprofit',
            'title' => 'Nonprofit pricing',
            'price' => null,
            'price_label' => '30% off',
            'text' => 'Charities, churches, schools and universities get 30% off everything: Standard £6, Pro £20, Production £55, event pass £11. Name your organisation when you request the licence.',
        ],
        [
            'id' => 'rental',
            'title' => 'Rental houses',
            'price' => null,
            'price_label' => '20% off 5-packs',
            'text' => 'Five licences at 20% off for rental and production companies. Ask for a 5-pack when you request.',
        ],
    ],
    'policies' => [
        ['title' => 'One-off, for ever', 'text' => 'Every licence is a one-off purchase that keeps working on the machines it was issued for. There is no subscription.'],
        ['title' => 'Works offline', 'text' => 'Activation is a licence file you install from Help → License. No internet connection is needed at the venue, and a running show is never interrupted by a licence check.'],
        ['title' => 'Machines', 'text' => 'Standard and Pro cover one PC. Production covers a main and a backup PC. Changing networks, docks, VPNs or Wi-Fi never affects a licence.'],
        ['title' => 'Updates', 'text' => 'Standard and Pro get updates for their tier for life. Early Production licences get lifetime updates; later Production licences include a year of updates, then £20 a year to keep updating. A version you are entitled to keeps running as Production for ever.'],
        ['title' => 'New PC', 'text' => 'A licence follows the PC\'s hardware ID. After a Windows reinstall or a new motherboard, send the new hardware ID and a fresh licence file is issued.'],
    ],
    'how_to_buy' => [
        'Install StageWatch and use the 14-day trial.',
        'In StageWatch, open Help → License → Plans and press Copy to copy your hardware ID (two IDs for Production: main and backup).',
        'Create a free client account on this site and request the licence with your hardware ID.',
        'You receive payment details by email; once paid, your licence file appears in the portal under Licences.',
        'In StageWatch, open Help → License → Install license file, choose the file and restart StageWatch.',
    ],
    'matrix' => [
        'columns' => ['trial', 'free', 'standard', 'pro', 'production'],
        'groups' => [
            [
                'title' => 'The timer',
                'rows' => [
                    ['feature' => 'Countdown, count-up, time of day, live nudge', 'values' => ['trial' => true, 'free' => true, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Full-screen output window', 'values' => ['trial' => true, 'free' => 'Watermarked', 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Typed messages, warning colours, progress bar, blackout', 'values' => ['trial' => true, 'free' => true, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Show files, 12-hour clock, crash recovery', 'values' => ['trial' => true, 'free' => true, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Presets', 'values' => ['trial' => '12', 'free' => '2', 'standard' => '12', 'pro' => '12', 'production' => '12']],
                    ['feature' => 'Quick messages, sound alerts, end-at (elastic) timing', 'values' => ['trial' => true, 'free' => false, 'standard' => true, 'pro' => true, 'production' => true]],
                ],
            ],
            [
                'title' => 'Screens and outputs',
                'rows' => [
                    ['feature' => 'Chroma-key and floating output, NDI output', 'values' => ['trial' => true, 'free' => false, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Holding images on every screen', 'values' => ['trial' => true, 'free' => false, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => 'Cue ladders, calm clock, colour-blind colours, briefing preview', 'values' => ['trial' => true, 'free' => false, 'standard' => true, 'pro' => true, 'production' => true]],
                    ['feature' => '3D hologram and egg-timer displays', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                ],
            ],
            [
                'title' => 'Running the show',
                'rows' => [
                    ['feature' => 'Running order: Next, auto-advance, ahead/behind, CSV import', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                    ['feature' => 'Schedule recovery and curfews', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                    ['feature' => 'PowerPoint slides and rehearsal ghost, presenter PCs', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                    ['feature' => 'Audience Q&A, triggers, CSV report', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                ],
            ],
            [
                'title' => 'Control and integration',
                'rows' => [
                    ['feature' => 'Browser viewer, remote and role pages', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                    ['feature' => 'Bitfocus Companion, HTTP and OSC', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => true, 'production' => true]],
                    ['feature' => 'vMix and Patterns VT countdowns', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'MCP for AI tools', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                ],
            ],
            [
                'title' => 'The crew loop and multi-room',
                'rows' => [
                    ['feature' => 'Acknowledged cues, departments STANDBY/GO', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'Green-room calls and pocket buzz', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'End-of-show forecast, curfew trim suggestions, producer assistant', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'Multi-room dashboard', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'Cue lights over Art-Net, sACN or Philips Hue', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'LTC timecode and time-server drift check', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                    ['feature' => 'Speech pace and the full printable report', 'values' => ['trial' => true, 'free' => false, 'standard' => false, 'pro' => false, 'production' => true]],
                ],
            ],
            [
                'title' => 'Licence',
                'rows' => [
                    ['feature' => 'Machines per licence', 'values' => ['trial' => 'This PC', 'free' => 'Any', 'standard' => '1', 'pro' => '1', 'production' => '2 (main + backup)']],
                    ['feature' => 'Watermark on every output', 'values' => ['trial' => false, 'free' => true, 'standard' => false, 'pro' => false, 'production' => false]],
                ],
            ],
        ],
    ],
    'faqs' => [
        ['q' => 'Is StageWatch a subscription?', 'a' => 'No. Every StageWatch licence is a one-off purchase in GBP that keeps working for ever on the PC it was issued for.'],
        ['q' => 'Does StageWatch need the internet to activate?', 'a' => 'No. You install a licence file from Help → License. No internet connection is needed to activate or to run a show.'],
        ['q' => 'What happens when the 14-day trial ends?', 'a' => 'StageWatch keeps working as a basic timer with two presets, and every output carries a small "StageWatch by Ben Rohleder" watermark until you install a licence.'],
        ['q' => 'Which licence do I need for Bitfocus Companion?', 'a' => 'Pro or Production. Companion, the browser remotes, HTTP and OSC are part of Pro.'],
        ['q' => 'Can I use StageWatch on a main and a backup PC?', 'a' => 'Yes, with Production. It covers two machines, and you send both hardware IDs when you request it.'],
        ['q' => 'Can an event pass cut my show short?', 'a' => 'No. The pass warns in its last three days, keeps working for two days of grace after it ends, and the licence is only checked when StageWatch starts.'],
        ['q' => 'Do you offer nonprofit pricing?', 'a' => 'Yes. Charities, churches, schools and universities get 30% off every licence.'],
        ['q' => 'What if I replace my PC?', 'a' => 'Send the new PC\'s hardware ID and a fresh licence file is issued for it.'],
        ['q' => 'Why does the app say "Stagewatch"?', 'a' => 'It is the same product. The app and installer write the name as one word with a small w; the brand is StageWatch.'],
    ],
];
