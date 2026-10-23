<?php

declare(strict_types=1);

/*
 * Industry Updates, the news section (docs/NEWS.md): its name, the desk's byline, the index page's words, the
 * verdict and section labels every article uses, and the fixed categories. A category's title is its page's title
 * tag (60 characters or fewer), its description the meta description (50 to 155); its icon is a symbol in
 * public/assets/img/icons.svg. The keys never change: they are stored on every article and are part of each
 * category page's address.
 */

return [
    'name' => 'Industry Updates',
    'byline' => 'Industry Updates desk',

    'index' => [
        'eyebrow' => 'AV and live event news',
        'title' => 'Industry Updates',
        'lede' => 'What changed in AV, live events and streaming, what it means for freelancers and corporate event teams, and whether it is worth testing. Verified facts are kept apart from speculation.',
    ],
    'search' => [
        'label' => 'Search Industry Updates',
        'button' => 'Search',
        'help' => 'Searches the headlines and standfirsts.',
        'placeholder' => 'Search the articles',
        // A search's meta description (its page is never indexed, but a shared link still shows it).
        'description' => 'Industry Updates articles whose headline or standfirst matches your search, the newest first.',
    ],
    'categories_label' => 'Categories',
    'all' => 'All',
    'all_link' => 'See every article',
    // The category form that stands in for the list on phones.
    'pick' => ['label' => 'Category', 'button' => 'Show'],
    'empty' => [
        'index' => 'Nothing published yet. The first articles are on their way, so check back soon.',
        'category' => 'Nothing in this category yet. Try another category, or see every article.',
        'search' => 'No articles match that search. Try fewer or different words, or browse a category.',
        'unavailable' => 'The articles cannot be shown just now. Please try again in a minute.',
    ],
    // A list's heading (:category, :q and :n are filled in) and its count.
    'list' => [
        'latest' => 'Latest articles',
        'category' => 'Latest in :category',
        'results' => 'Results for “:q”',
        'count_one' => 'One article, the newest first.',
        'count_many' => ':n articles, the newest first.',
    ],
    // An article's meta line.
    'meta' => [
        'read' => ':n min read',
        'updated' => 'Updated',
        'by' => 'By',
        'unpublished' => 'Not published yet',
        'source' => 'Source',
        'credit' => 'Image',
    ],
    // What each source is, beside its link.
    'kinds' => [
        'primary' => 'Primary source',
        'press' => 'Press',
        'report' => 'Report',
        'standard' => 'Standard',
        'other' => 'Other',
    ],
    'new_tab' => 'opens in a new tab',
    'preview' => [
        'title' => 'Preview: not published',
        'live' => 'Preview: this article is live',
        'scheduled' => 'Scheduled to go live :time.',
        'draft' => 'A draft: only administrators can see it.',
        'comments' => 'Comments appear here once the article is live.',
    ],
    'feed' => [
        'title' => 'Industry Updates from Patterns Show Control',
        'description' => 'AV and live event industry news for freelance crew and corporate event teams, with verified facts kept apart from speculation.',
        'label' => 'Industry Updates RSS feed',
        // A category's own feed (:title is the title above, :category the category's name).
        'category_title' => ':title: :category',
        'category_label' => ':category: Industry Updates RSS feed',
    ],
    // The follow row on the hub and on each category's hub: the page's own feed, two feed readers that follow it in
    // one step, and Google's preferred-sources page for the site (each a plain link; nothing is loaded from them).
    'follow' => [
        'label' => 'Follow',
        'rss' => 'RSS feed',
        'feedly' => 'Feedly',
        'inoreader' => 'Inoreader',
        'google' => 'Google preferred source',
        'google_hint' => 'Add Patterns Show Control as a',
    ],
    // The share row at the end of an article: plain links that open each network's own share page, and a copy button.
    'share' => [
        'title' => 'Share this article',
        'on' => 'Share on',
        'copy' => 'Copy link',
    ],
    // The dated corrections an article shows under its headline (docs/NEWS.md §10), and the way to the policy.
    'corrections' => [
        'one' => 'Correction',
        'many' => 'Corrections',
        'policy' => 'How we correct articles',
    ],
    // The note's way to the standards page, after the AI note on every AI-written article.
    'standards_link' => 'How Industry Updates is made',
    // The newest articles on the home page and the product pages (Psc\News\Latest): their words, and each product's
    // categories, the areas its users work in.
    'latest' => [
        'eyebrow' => 'Industry Updates',
        'home_title' => 'Latest from the news desk',
        'home_lede' => 'The newest from Industry Updates: what changed in AV, live events and streaming, and whether it is worth testing.',
        'product_title' => 'From Industry Updates',
        'product_lede' => 'The newest on :categories, the areas :product works in.',
        'all' => 'All Industry Updates',
        'more' => ':category news',
    ],
    'products' => [
        'patterns' => ['video', 'streaming', 'automation'],
        'stagewatch' => ['showcalling', 'production', 'automation'],
        'agmessage' => ['production', 'legal', 'ai'],
        'playmap' => ['interactive', 'video', 'lighting'],
    ],

    // /news/standards/: how the section is made, who answers for it and how it is corrected (Markdown; :editor is
    // the site's publisher, content/site.php publisher.name; :publishing is one of 'publishing' below). Every claim
    // here is one the code keeps (docs/NEWS.md §2, §6, §7).
    'standards' => [
        'eyebrow' => 'Industry Updates',
        'title' => 'Editorial standards',
        'lede' => 'How Industry Updates is made, who answers for it, and how to tell us when we get something wrong.',
        'sections' => [
            [
                'id' => 'how',
                'title' => 'How Industry Updates is made',
                'body' => "Most articles here are researched and written by our AI desk. Usually twice a day it looks for what changed in AV, live events and streaming, reads the original sources (manufacturers' release notes, standards bodies, regulators and the trade press) and writes up the developments that matter, with what each one means for freelance crew and corporate event teams. We use it because it lets a small, independent show-software maker follow fourteen corners of the industry every day, straight from the sources.\n\n"
                    . "- Every article lists its sources, and each fact under **What's confirmed** and **Still speculation** links to the page it rests on. The desk can only cite pages its research actually found, so it cannot invent a link.\n"
                    . "- Verified facts are kept apart from speculation: anything not yet confirmed is marked unconfirmed, with whose claim it is.\n"
                    . "- A fact under **What's confirmed** cites the original document where there is one (the law, the official guidance, the regulator's notice, the maker's own release). A fact the desk has only from a report says whose report it is; one that does not is listed as unconfirmed.\n"
                    . "- Its rules forbid made-up quotes: it may quote only words it found in a source.\n"
                    . "- It never copies pictures. A picture on an article was added by the editor, with the right to use it.\n"
                    . "- Every article the AI desk wrote, or helped write, carries a note saying so.\n\n"
                    . 'Before an article from the desk goes live, a second, separate check reads all of it. Anything that names a private person or may allege wrongdoing is held back for the editor, and anything on a topic we do not cover is dropped. :publishing',
            ],
            [
                'id' => 'editor',
                'title' => 'What the editor does',
                'body' => "Industry Updates is edited by :editor, who answers for everything published here.\n\n"
                    . "- Reviews every article the checks hold back, and decides whether it is published, changed or dropped.\n"
                    . "- Takes down any article that should not be here, at once.\n"
                    . "- Corrects articles when something is wrong, and says so on the article.\n"
                    . '- Decides what the desk looks into, which websites it may not use and which topics it leaves alone.',
            ],
            [
                'id' => 'errors',
                'title' => 'Spotted a mistake?',
                'body' => 'If something in an article is wrong, out of date or unfair, please [tell us through the contact form](/contact/?topic=other). Name the article (its address helps) and what is wrong, with a source if you have one. Every message reaches the editor, and mistakes get put right.',
            ],
            [
                'id' => 'corrections',
                'title' => 'Corrections',
                'body' => "When a correction changes what an article says, the article shows a dated **Correction** note under its headline saying what changed, and its updated time moves with it. Small fixes that change no facts, such as a typo or a broken link, are made without a note.\n\n"
                    . 'An article we remove for good leaves its address saying that it has gone, so nothing else ever appears there.',
            ],
            [
                'id' => 'publisher',
                'title' => 'Who publishes it',
                'body' => 'Industry Updates is published by Patterns Show Control, which makes show software: [Patterns](/patterns/), [StageWatch](/stagewatch/), [AGMessage](/agmessage/) and [PlayMap](/playmap/). Patterns Show Control is run by :editor in the United Kingdom. The desk names our own products only where they are directly relevant, once and plainly. More about us is on the [about page](/about/).',
            ],
        ],
        // The last words of 'how' (:publishing): as the desk's "Publish articles automatically" setting stands
        // (auto_publish), and, when the settings cannot be read, the words that are true either way.
        'publishing' => [
            'auto' => 'Articles that pass every check are published automatically.',
            'editor' => 'Articles that pass every check then wait for the editor to publish them.',
            'unknown' => 'Articles that pass every check are published automatically, unless the editor has chosen to publish each one by hand.',
        ],
    ],

    // The way across the section, at the foot of the hub and of each category's hub.
    'silo' => [
        'index' => 'Browse by category',
        'category' => 'More Industry Updates',
        'all' => 'Every article',
    ],

    // The verdict callout on an article: its label, its callout variant (empty for the plain callout) and its icon.
    'verdicts' => [
        'adopt' => ['label' => 'Worth adopting', 'callout' => 'c-callout--success', 'icon' => 'ok'],
        'test' => ['label' => 'Worth testing', 'callout' => 'c-callout--info', 'icon' => 'idea'],
        'watch' => ['label' => 'One to watch', 'callout' => 'c-callout--warning', 'icon' => 'eye'],
        'skip' => ['label' => 'Safe to skip for now', 'callout' => '', 'icon' => 'info'],
        'note' => ['label' => 'Good to know', 'callout' => 'c-callout--info', 'icon' => 'info'],
    ],
    'sections' => [
        'confirmed' => "What's confirmed",
        'speculation' => 'Still speculation',
        'impact' => 'What it means for freelancers and corporate events',
        'sources' => 'Sources',
        'later' => 'Later updates',
        'follow_up' => 'Follow-up to',
        // The end of every article: the newest of its primary category, and the way to the rest.
        'more' => 'More in :category',
        'more_all' => 'Every article in :category',
    ],
    'badges' => [
        'confirmed' => 'Confirmed',
        'unconfirmed' => 'Unconfirmed',
    ],

    'categories' => [
        'video' => [
            'name' => 'Video',
            'title' => 'Video news: switching, LED and processing | Industry Updates',
            'description' => 'Production switchers, LED screens and processing, projection and playback: what changed, and whether it is worth testing on your next show.',
            'intro' => 'Switchers, LED walls, processors, projectors and the playback that feeds them. New kit and firmware, explained by what it changes on a real job.',
            'icon' => 'video',
        ],
        'audio' => [
            'name' => 'Audio',
            'title' => 'Audio news: mixing, mics and comms | Industry Updates',
            'description' => 'Consoles, radio mics, comms, audio networking and spectrum: the audio changes that matter for conferences, live events and streamed shows.',
            'intro' => 'Desks, radio mics, intercom and the networks that carry them. Spectrum and licensing changes get a look too, because they land on show day.',
            'icon' => 'speaker',
        ],
        'lighting' => [
            'name' => 'Lighting',
            'title' => 'Lighting news: fixtures and control | Industry Updates',
            'description' => 'Fixtures, lighting desks, control protocols and power: the lighting news worth knowing for corporate stages, conferences and live events.',
            'intro' => 'Fixtures, consoles and the control that ties them together, from DMX and sACN to the desk on the riser. Judged on what it does in a ballroom, not in a brochure.',
            'icon' => 'sun',
        ],
        'staging' => [
            'name' => 'Staging',
            'title' => 'Staging news: sets, rigging and safety | Industry Updates',
            'description' => 'Staging, sets, rigging, truss and venue safety: changes in kit, standards and practice that affect how event stages are built and run.',
            'intro' => 'Stages, sets, truss and rigging, plus the safety rules that sit behind them. What changes for the build, the load-in and the people doing it.',
            'icon' => 'layers',
        ],
        'production' => [
            'name' => 'Production',
            'title' => 'Production news: workflows and crewing | Industry Updates',
            'description' => 'Live event production workflows, crewing, rehearsals, run-of-show and budgets: what is changing in how corporate events are delivered.',
            'intro' => 'How shows get made: run-of-show, rehearsals, crewing, comms and the paperwork. The changes that alter how a corporate event is planned and delivered.',
            'icon' => 'list',
        ],
        'showcalling' => [
            'name' => 'Showcalling',
            'title' => 'Showcalling news: cues, timing and comms | Industry Updates',
            'description' => 'Calling a show: cue lists, stage timers, comms, scripts and the tools a show caller relies on, with what is new and what is worth trying.',
            'intro' => 'The desk at the back of the room: cue lists, stage timers, comms and scripts. New tools and habits for calling a tight show.',
            'icon' => 'cue',
        ],
        'streaming' => [
            'name' => 'Streaming',
            'title' => 'Streaming news: encoders and platforms | Industry Updates',
            'description' => 'Live streaming for events: encoders, platforms, protocols such as SRT and NDI, and the hybrid side of conferences and corporate shows.',
            'intro' => 'Encoders, platforms, protocols and the audience watching from home. What changed in getting a show out of the building reliably.',
            'icon' => 'broadcast',
        ],
        'automation' => [
            'name' => 'Automation',
            'title' => 'Show control and automation news | Industry Updates',
            'description' => 'Show control and automation for live events: timecode, OSC, Bitfocus Companion, Stream Deck and control systems, with what to test first.',
            'intro' => 'Timecode, OSC, Companion, Stream Deck and the control systems that run a show without someone pressing every button. What is new, and what is reliable enough to trust.',
            'icon' => 'deck',
        ],
        'interactive' => [
            'name' => 'Interactive',
            'title' => 'Interactive and immersive events news | Industry Updates',
            'description' => 'Interactive and immersive events: projection mapping, tracking, real-time engines, audience interaction and the venues built around them.',
            'intro' => 'Projection mapping, tracking, real-time graphics and audience interaction. What is shipping, what it costs, and what holds up at a live show.',
            'icon' => 'cube',
        ],
        'ai' => [
            'name' => 'AI',
            'title' => 'AI in events: news and practical tools | Industry Updates',
            'description' => 'AI in live events: tools that change how shows are planned, captioned, translated, filmed and run, and where a person still has to check.',
            'intro' => 'AI tools for planning, captioning, translation, filming and playout. Tried against real shows, with a clear line on where a person still has to check.',
            'icon' => 'sparkles',
        ],
        'technology' => [
            'name' => 'Technology',
            'title' => 'Networks, IT and standards news | Industry Updates',
            'description' => 'Networks, venue IT, AV over IP and the standards behind them: the technology changes that reach show kit, with what they mean in practice.',
            'intro' => 'Show networks, venue IT, AV over IP and the standards that decide whether kit talks to each other. Plain explanations, no acronym soup.',
            'icon' => 'network',
        ],
        'freelance' => [
            'name' => 'Freelance',
            'title' => 'Freelance news: rates, tax and contracts | Industry Updates',
            'description' => 'For freelance crew: rates, tax, IR35 and employment status, contracts, payment terms, insurance and wellbeing, with what to do about them.',
            'intro' => 'Rates, tax, employment status, contracts, late payment, insurance and looking after yourself. News that lands in a freelancer\'s inbox or bank account.',
            'icon' => 'user',
        ],
        'legal' => [
            'name' => 'Legal',
            'title' => 'Legal news for live events and AV | Industry Updates',
            'description' => 'Law and regulation for live events in the UK: safety, security, employment, data protection, accessibility and licensing, explained plainly.',
            'intro' => 'Safety, security, employment, data protection, accessibility and licensing, UK first. What changed, when it applies and who has to act, in plain words rather than legal advice.',
            'icon' => 'shield',
        ],
        'tips' => [
            'name' => 'Tips',
            'title' => 'Advice, tips and tricks for event crew | Industry Updates',
            'description' => 'Practical advice, tips and tricks for AV and live event crew: the small habits and fixes that save a show, learnt the hard way.',
            'intro' => 'Practical advice from the show floor: the checks, shortcuts and fixes worth knowing before the next load-in.',
            'icon' => 'bulb',
        ],
    ],
];
