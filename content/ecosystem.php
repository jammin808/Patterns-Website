<?php

declare(strict_types=1);

/* The ecosystem pages (/ecosystem/, /ecosystem/integrations/, /ecosystem/ai/): keys definition, tagline, h1, pitch, principle, pillars[], show_bus, connections[], integrations_intro, integration_categories[], integrations[] (category = a category title), ai, reliability, architecture[], glossary[], roadmap, faqs[]; plain text, product keys as in content/products/. */

return [
    'definition' => 'The Patterns ecosystem is a family of four Windows show apps for live events: Patterns for the picture, StageWatch for time, AGMessage for the people behind the meeting and PlayMap for mapping and playback, each working on its own or together on the venue\'s own network.',

    'tagline' => 'Four show apps. One network. Every decision stays yours.',

    'h1' => 'Four apps. One show network.',

    'pitch' => 'Every part of the show has one owner, and everything else in the room follows it. Patterns runs the screens, StageWatch runs the clock, AGMessage links the stage with the back-room, and PlayMap plays your media and maps it onto walls, objects and buildings. Start with one app and add the others when the show needs them: on the same network they find each other, share live status and confirm every command, with no internet and no cloud.',

    'principle' => [
        'title' => 'One owner for every part of the show',
        'text' => 'Two countdowns can never disagree, because there is only one countdown. StageWatch owns the time, Patterns owns the picture, AGMessage owns the conversation between the stage and the back-room, and PlayMap owns its playlists and mapped surfaces. The other apps show and follow that owner instead of keeping a copy of their own.',
    ],

    'pillars' => [
        [
            'role' => 'Picture',
            'title' => 'Picture',
            'product' => 'patterns',
            'line' => 'Patterns runs the picture',
            'text' => 'Pixel-exact test patterns for every LED wall, projector and NDI feed, then the slides, video, graphics, sound and audience polls on them, run from a safe preview and cues.',
            'href' => '/patterns/',
        ],
        [
            'role' => 'Time',
            'title' => 'Time',
            'product' => 'stagewatch',
            'line' => 'StageWatch runs the time',
            'text' => 'One countdown on every screen in the room, a running order that wins back time before the curfew, and cues that speakers and crew confirm with a tap.',
            'href' => '/stagewatch/',
        ],
        [
            'role' => 'People',
            'title' => 'People',
            'product' => 'agmessage',
            'line' => 'AGMessage connects the people',
            'text' => 'A private, live line between the directors on stage and the research team in the back-room: answers, board-pack documents and tap-to-answer questions in front of the chair.',
            'href' => '/agmessage/',
        ],
        [
            'role' => 'Mapping',
            'title' => 'Mapping and playback',
            'product' => 'playmap',
            'line' => 'PlayMap plays and maps the media',
            'text' => 'Playlists on cue, and media mapped onto walls, objects, rooms and buildings, with camera scanning, blending, LED fixtures and several machines on one show clock.',
            'href' => '/playmap/',
        ],
    ],

    'show_bus' => [
        'title' => 'Show Bus: the language the apps share',
        'intro' => 'Show Bus is the small shared language every app in the ecosystem speaks on the venue\'s local network. It needs no internet, no server and no account, and it is the reason the apps can work as one show.',
        'points' => [
            [
                'title' => 'The apps find each other',
                'text' => 'Each app announces itself on the local network every two seconds, so the others list it with no addresses to type. The announcement says who an app is and where to reach it, never what the show is doing.',
                'icon' => 'radar',
            ],
            [
                'title' => 'Live status at a glance',
                'text' => 'Every app shares a status light (green, amber, red, or grey for unknown) and a one-line headline such as "Keynote · 12:30 left · 2:10 behind". StageWatch\'s Show network view lists Patterns, AGMessage and other StageWatches with their lights, headlines and any problems they report, and Patterns shows every app it hears, PlayMap included, on its Nodes page and in the Eye.',
                'icon' => 'pulse',
            ],
            [
                'title' => 'Show data only on paired links',
                'text' => 'Live show data travels only on links you have paired with a key or a pairing token. Lights and headlines are worked out by fixed rules, not by AI, so they read the same with or without it, and unknown stays unknown.',
                'icon' => 'link',
            ],
            [
                'title' => 'Commands that come back with a receipt',
                'text' => 'A command from one app to another comes back done (with what changed), refused (with the reason) or accepted (queued, not yet confirmed). A command to an app that is not answering fails straight away with a clear message, and nothing is quietly saved up to fire later.',
                'icon' => 'check',
            ],
            [
                'title' => 'Clocks that agree',
                'text' => 'Countdowns travel as time remaining, not as clock times, so every screen counts the same seconds whatever its own computer\'s clock says. If two computers\' clocks drift more than two seconds apart, the apps warn CLOCKS APART.',
                'icon' => 'clock',
            ],
            [
                'title' => 'Approvals in one place',
                'text' => 'With StageWatch Pro or higher on the network, suggestions from Patterns\' assistant, from AI tools connected to StageWatch and from AGMessage\'s research team wait in one list for the show caller. Each app runs what was approved through its own checks and reports back done, refused or not delivered.',
                'icon' => 'approve',
            ],
            [
                'title' => 'Encrypted, pinned links',
                'text' => 'Every app\'s pages and links can run over HTTPS, with a certificate each install makes for itself and a trust page that walks each phone, tablet or laptop through accepting it once. Links between apps remember the certificate they first trusted; if it ever changes, the link stops and says CERTIFICATE CHANGED until you accept it.',
                'icon' => 'lock',
            ],
            [
                'title' => 'Keys that stay secret',
                'text' => 'Control needs a key or a pairing token. StageWatch uses named API keys that you can scope and revoke; Patterns\' and PlayMap\'s Companion modules prove they know the pairing token without ever sending it across the network, and every app can serve its pages over HTTPS. AGMessage shares only counts, the room\'s state and the titles of open action prompts, never message text, names, room codes or documents.',
                'icon' => 'key',
            ],
        ],
        'offline' => [
            'title' => 'What works with no internet',
            'works' => [
                'Finding each other, links, commands and receipts',
                'Live status lights, headlines and clock checks',
                'Rule-based advice, problem lists and triggers',
                'Automation between apps, such as a clip ending moving StageWatch to the next item, or an answer reaching the speaker\'s screen',
                'StageWatch\'s approval list, where the show caller decides on suggestions',
            ],
            'needs_internet' => [
                'Cloud AI features, using your own Anthropic API key',
                'AGMessage\'s remote-access link for people off site',
                'StageWatch\'s Google Sheets import and its internet time-server check',
                'Online content you choose to show, such as web pages and streams',
            ],
        ],
    ],

    'connections' => [
        [
            'from' => 'stagewatch',
            'to' => 'patterns',
            'title' => 'Video countdowns from Patterns',
            'text' => 'StageWatch\'s VT countdown follows the clip on air in Patterns, through pause and playback speed. Last 10 s jumps the clip to its final seconds, StageWatch can play, pause or seek it, and when the clip ends the running order can move on by itself.',
            'needs' => 'StageWatch Production or the trial',
        ],
        [
            'from' => 'patterns',
            'to' => 'stagewatch',
            'title' => 'One room, one timer',
            'text' => 'Link Patterns to a StageWatch from Patterns\' Nodes page, and Patterns\' stage and timer pages, a caller\'s station and a stage timer machine all follow StageWatch\'s clock, colours and title. Patterns\' Eye and its assistant also see StageWatch\'s departments and timer.',
            'needs' => 'StageWatch Pro or higher',
        ],
        [
            'from' => 'patterns',
            'to' => 'stagewatch',
            'title' => 'Patterns\' assistant, approved in StageWatch',
            'text' => 'With a StageWatch on the network, the Patterns assistant\'s proposals wait in StageWatch\'s Copilot list and reach Patterns only once the show caller approves them.',
            'needs' => 'StageWatch Pro or higher',
        ],
        [
            'from' => 'agmessage',
            'to' => 'stagewatch',
            'title' => 'Answers on the speaker\'s screen',
            'text' => 'The research team sends a short answer to the speaker\'s confidence monitor and sees on the message when it is on screen; with StageWatch Production they also see the speaker acknowledge that exact answer. Or they propose it to the show caller, who approves it in StageWatch.',
            'needs' => 'StageWatch Pro or higher; Production for the speaker\'s acknowledgement',
        ],
        [
            'from' => 'stagewatch',
            'to' => 'agmessage',
            'title' => 'The stage timer for the whole meeting',
            'text' => 'Linked to StageWatch with a key made in StageWatch, AGMessage shows the live stage timer in every participant\'s header, and the show caller can send an approved notice such as "Break in 5 minutes" to every open meeting.',
            'needs' => 'StageWatch Pro or higher',
        ],
        [
            'from' => 'agmessage',
            'to' => 'stagewatch',
            'title' => 'The back-room at a glance',
            'text' => 'StageWatch\'s Show network shows AGMessage\'s status and its open action prompts, so the show caller knows when the board has a question waiting. Message text, names, room codes and documents stay in AGMessage; only an answer you choose to send to the speaker\'s screen goes to StageWatch.',
            'needs' => 'StageWatch Pro or higher',
        ],
        [
            'from' => 'patterns',
            'to' => 'playmap',
            'title' => 'PlayMap under Patterns\' cues',
            'text' => 'Add PlayMap as a device in Patterns\' Interactive area, and every Patterns cue, Companion key and OSC message can send it commands, such as firing a playlist. PlayMap can link to Patterns and follow its blackout and the clip it is playing, and Patterns shows PlayMap on its Nodes page and in the Eye.',
            'needs' => null,
        ],
        [
            'from' => 'stagewatch',
            'to' => 'playmap',
            'title' => 'Video countdowns from PlayMap',
            'text' => 'Point StageWatch\'s Patterns link at a PlayMap machine and its VT countdown follows PlayMap\'s playlist, while StageWatch\'s video buttons drive the playback. StageWatch triggers can fire PlayMap commands too.',
            'needs' => 'StageWatch Production or the trial',
        ],
        [
            'from' => 'stagewatch',
            'to' => 'playmap',
            'title' => 'The countdown, mapped onto anything',
            'text' => 'Link PlayMap to StageWatch and the timer becomes a source you can map onto any surface: the countdown, colours by phase, messages and holding images. If the link goes quiet, it shows "?" rather than a wrong time.',
            'needs' => 'StageWatch Pro or higher',
        ],
        [
            'from' => 'playmap',
            'to' => 'playmap',
            'title' => 'Many machines, one show clock',
            'text' => 'PlayMap machines in a sync group follow one leader\'s show clock, transports and air, so each draws the frame for the same moment. A hot backup mirrors the main machine, and media a machine is missing is fetched from another that has it.',
            'needs' => null,
        ],
        [
            'from' => 'stagewatch',
            'to' => 'stagewatch',
            'title' => 'Presenter PCs that feed the timer',
            'text' => 'StageWatch Slides runs on a lectern or playback PC and sends the PowerPoint slideshow\'s position to the main and backup StageWatch, so the timer knows where the talk is. It needs no licence of its own.',
            'needs' => 'StageWatch Pro or higher on the show PC',
        ],
    ],

    'integrations_intro' => 'The apps work with the kit already in your rack. Each entry says which apps support it, and the product pages have the detail.',

    'integration_categories' => [
        ['id' => 'control-surfaces', 'title' => 'Control surfaces', 'text' => 'Stream Decks, phones, tablets, MIDI controllers and buttons in the room.'],
        ['id' => 'video', 'title' => 'Video', 'text' => 'NDI, vision mixers, streaming, capture and network video.'],
        ['id' => 'show-control', 'title' => 'Show control', 'text' => 'The show network, OSC, network control, projectors and running orders.'],
        ['id' => 'lighting-timecode', 'title' => 'Lighting & timecode', 'text' => 'Art-Net, sACN, cue lights, LED pixels, timecode and time servers.'],
        ['id' => 'presentation', 'title' => 'Presentation', 'text' => 'Slides, documents and web pages.'],
        ['id' => 'ai', 'title' => 'AI', 'text' => 'Cloud and local AI models, and AI tools that propose through MCP.'],
    ],

    'integrations' => [
        // Control surfaces
        [
            'name' => 'Bitfocus Companion and Stream Deck',
            'category' => 'Control surfaces',
            'text' => 'Patterns, StageWatch and PlayMap each have their own Companion module, and all three load into one Companion, so one Stream Deck can carry the picture, the clock and the playback. Keys label themselves from your show and light from each app\'s live state. The modules need Companion 5, and 5.1 or newer for PlayMap\'s.',
            'products' => ['patterns', 'stagewatch', 'playmap'],
        ],
        [
            'name' => 'Phones, tablets and web browsers',
            'category' => 'Control surfaces',
            'text' => 'Nothing to install on the device. Patterns has a phone remote, caller, stage and timer pages, and polls, quizzes and games on the audience\'s own phones; StageWatch puts speaker views, crew pages, the Showcaller page and audience Q&A in any browser; everyone in an AGMessage meeting joins in a browser; and a phone\'s browser can be PlayMap\'s scan camera.',
            'products' => ['patterns', 'stagewatch', 'agmessage', 'playmap'],
        ],
        [
            'name' => 'MIDI controllers',
            'category' => 'Control surfaces',
            'text' => 'Patterns learns any MIDI control from a right-click menu, and lights pads and moves motorised faders in return. PlayMap binds faders, knobs, pads and MIDI Show Control to any action, with a learn mode that binds the next control you move.',
            'products' => ['patterns', 'playmap'],
        ],
        [
            'name' => 'Arduino, Raspberry Pi and ESP32',
            'category' => 'Control surfaces',
            'text' => 'Buttons, sensors and lamps in the room join the show through Patterns\' Interactive area, over USB or the network, using short text lines.',
            'products' => ['patterns'],
        ],

        // Video
        [
            'name' => 'NDI®',
            'category' => 'Video',
            'text' => 'Patterns receives NDI feeds and sends as many NDI outputs as you need, 10-bit included. StageWatch appears as an NDI source for vMix, OBS and NDI mixers. PlayMap receives and sends NDI 6. Each needs the free NDI Runtime on that PC.',
            'products' => ['patterns', 'stagewatch', 'playmap'],
        ],
        [
            'name' => 'Vision mixers',
            'category' => 'Video',
            'text' => 'StageWatch\'s keyed full-screen output sits on blue, green, magenta or black, ready for a mixer\'s chroma or luma key. NDI-capable mixers can take StageWatch over NDI instead.',
            'products' => ['stagewatch'],
        ],
        [
            'name' => 'vMix and OBS Studio',
            'category' => 'Video',
            'text' => 'Bring StageWatch in as a browser input or over NDI. With Production, StageWatch also counts down the clip playing in vMix\'s programme.',
            'products' => ['stagewatch'],
        ],
        [
            'name' => 'Streaming over RTMP and SRT',
            'category' => 'Video',
            'text' => 'Patterns streams a screen to two destinations from one encode: YouTube, Twitch, Restream and more over RTMP, plus SRT and UDP.',
            'products' => ['patterns'],
        ],
        [
            'name' => 'Capture cards and cameras',
            'category' => 'Video',
            'text' => 'Patterns takes HDMI and SDI capture cards and webcams as live inputs, with crop, mirror and rotate to tidy them up. PlayMap plays capture devices as media.',
            'products' => ['patterns', 'playmap'],
        ],
        [
            'name' => 'OMT and Spout',
            'category' => 'Video',
            'text' => 'PlayMap sends and receives Open Media Transport video on every machine with nothing extra to install, and shares video with other apps on the same PC through Spout.',
            'products' => ['playmap'],
        ],
        [
            'name' => 'Zoom and Microsoft Teams',
            'category' => 'Video',
            'text' => 'Remote speakers see StageWatch\'s timer as a camera: bring StageWatch into OBS Studio and pick OBS Virtual Camera in Zoom or Teams.',
            'products' => ['stagewatch'],
        ],

        // Show control
        [
            'name' => 'The show network (Show Bus)',
            'category' => 'Show control',
            'text' => 'The four apps find each other on the venue network, share a status light and a headline, confirm every command with a receipt and route suggestions to one approval list, all without the internet.',
            'products' => ['patterns', 'stagewatch', 'agmessage', 'playmap'],
        ],
        [
            'name' => 'OSC',
            'category' => 'Show control',
            'text' => 'Drive the apps from lighting desks, show controllers and TouchOSC, with feedback sent back when things change. PlayMap binds any OSC address to any action; in Patterns and StageWatch, OSC is off until you turn it on.',
            'products' => ['patterns', 'stagewatch', 'playmap'],
        ],
        [
            'name' => 'HTTP, WebSocket and TCP control',
            'category' => 'Show control',
            'text' => 'For integrators and custom controllers: Patterns takes commands over TCP and HTTP, StageWatch over HTTP and WebSocket, and PlayMap over all three. Every command comes back with a receipt, and an unknown command is refused rather than guessed.',
            'products' => ['patterns', 'stagewatch', 'playmap'],
        ],
        [
            'name' => 'Projectors',
            'category' => 'Show control',
            'text' => 'Patterns switches projector power, input and shutter over PJLink from a cue or a key. PlayMap controls PJLink projectors, plus Christie, Panasonic, Barco Pulse and Epson over their own network control, with status.',
            'products' => ['patterns', 'playmap'],
        ],
        [
            'name' => 'Media servers and room control',
            'category' => 'Show control',
            'text' => 'Patterns cues and keys can send OSC, JSON-RPC and HTTP commands to media servers, show controllers and any room-control processor with a web API, such as a Q-SYS core or a Crestron or Extron processor, and Patterns shows what each box answered.',
            'products' => ['patterns'],
        ],
        [
            'name' => 'Excel, CSV and Google Sheets',
            'category' => 'Show control',
            'text' => 'Paste the running order into StageWatch from Excel, import a CSV, or pull it from a shared Google Sheet. Patterns brings in running orders and speaker lists for its lower thirds from Excel or CSV.',
            'products' => ['stagewatch', 'patterns'],
        ],

        // Lighting & timecode
        [
            'name' => 'Art-Net and sACN',
            'category' => 'Lighting & timecode',
            'text' => 'StageWatch drives cue lights over Art-Net or sACN that follow the speaker\'s cue ladder (Production). PlayMap takes control from a lighting desk over Art-Net and sACN, with a ready-made channel layout, and sends its pictures to LED pixel fixtures over Art-Net 4, sACN or DDP.',
            'products' => ['stagewatch', 'playmap'],
        ],
        [
            'name' => 'Philips Hue',
            'category' => 'Lighting & timecode',
            'text' => 'A lectern or stage-edge Hue light follows StageWatch\'s cue ladder through the local Hue Bridge (Production).',
            'products' => ['stagewatch'],
        ],
        [
            'name' => 'Timecode',
            'category' => 'Lighting & timecode',
            'text' => 'StageWatch reads LTC to chase it, count down to an end timecode and fire triggers, and sends LTC out (Production). PlayMap\'s playlists chase LTC, MTC or Art-Net timecode, or an internal generator.',
            'products' => ['stagewatch', 'playmap'],
        ],
        [
            'name' => 'Time servers',
            'category' => 'Lighting & timecode',
            'text' => 'StageWatch checks its PC\'s clock against a time server and warns when it is more than a second out (Production).',
            'products' => ['stagewatch'],
        ],

        // Presentation
        [
            'name' => 'Microsoft PowerPoint',
            'category' => 'Presentation',
            'text' => 'StageWatch reads the running slideshow on the same PC with no add-in: slide numbers, the next slide\'s title and pace against your rehearsal, and StageWatch Slides does the same from a presenter PC. Patterns opens PowerPoint, Keynote and Impress files as still pages through LibreOffice Impress, and shows PowerPoint for the web as a live page.',
            'products' => ['stagewatch', 'patterns'],
        ],
        [
            'name' => 'PDF, Word and Excel documents',
            'category' => 'Presentation',
            'text' => 'AGMessage pushes PDF, Word, Excel, CSV and image files straight onto the directors\' On stage panel. Patterns shows PDF decks full frame, with instant page turns from the presenter\'s clicker.',
            'products' => ['agmessage', 'patterns'],
        ],
        [
            'name' => 'Google Slides, YouTube, Vimeo and web pages',
            'category' => 'Presentation',
            'text' => 'Patterns shows web pages inside its engine, with next, previous and present controls from cues, a phone or a Stream Deck.',
            'products' => ['patterns'],
        ],

        // AI
        [
            'name' => 'Claude by Anthropic',
            'category' => 'AI',
            'text' => 'The cloud AI features use Claude with your own Anthropic API key: Patterns\' assistant, StageWatch\'s copilot (Production), AGMessage\'s drafts and PlayMap\'s assistant. Every one proposes, and a person decides.',
            'products' => ['patterns', 'stagewatch', 'agmessage', 'playmap'],
        ],
        [
            'name' => 'Local AI models',
            'category' => 'AI',
            'text' => 'PlayMap\'s assistant can run on a model on your own computer, through a local model server such as Ollama, LM Studio or llama.cpp, so it needs no internet at all.',
            'products' => ['playmap'],
        ],
        [
            'name' => 'MCP (Model Context Protocol)',
            'category' => 'AI',
            'text' => 'AI tools can read the show and propose commands. In StageWatch (Production) an agent\'s command waits in the Copilot list for approval by default. In PlayMap, every high-risk command waits for a person, and Show Mode is checked first.',
            'products' => ['stagewatch', 'playmap'],
        ],
    ],

    'ai' => [
        'title' => 'AI that proposes. A person decides.',
        'intro' => 'Everything in the ecosystem works without AI, and AI makes it faster. Where an app offers AI, it drafts, suggests and explains; a person decides what happens, and the app\'s own safety checks still apply after approval.',
        'principles' => [
            [
                'title' => 'People stay in charge',
                'text' => 'AI features propose. Out of the box, Patterns, StageWatch and AGMessage run nothing an AI suggests until a person says yes, and anything that would change what the audience sees is never pre-ticked. In PlayMap, everyday steps run straight away through PlayMap\'s own checks, and anything high-risk waits for a person whatever the settings.',
            ],
            [
                'title' => 'One place to decide',
                'text' => 'With StageWatch Pro or higher on the network, suggestions from Patterns\' assistant, AI tools connected to StageWatch and AGMessage\'s research team wait in one list for the show caller. Each shows who suggested it, which app it is for, why, and how risky it is.',
            ],
            [
                'title' => 'Risk rated by the app, not the model',
                'text' => 'Each app rates the risk of its own commands by fixed rules. Anything it does not recognise counts as high risk and is never pre-ticked.',
            ],
            [
                'title' => 'Offline first',
                'text' => 'Without the internet, cloud AI buttons grey out with a reason and the show runs exactly as before. StageWatch\'s rule-based assistant and forecast, PlayMap\'s click-to-surface and PlayMap\'s assistant on a local model all work with no internet.',
            ],
            [
                'title' => 'Your own key, on your machine',
                'text' => 'The cloud AI features use Claude by Anthropic with your own API key, which you add on your own show machine. Usage is between you and Anthropic, on the account that owns the key.',
            ],
            [
                'title' => 'Nothing in charge',
                'text' => 'AI never holds authority over the show. Status lights and headlines come from rules, not from a model, so they read the same with or without AI, and what is unknown stays unknown rather than being guessed.',
            ],
            [
                'title' => 'Every decision on the record',
                'text' => 'StageWatch\'s show log and report record every AI suggestion, who decided and what happened. AGMessage\'s transcript marks AI-assisted messages "drafted with AI, sent by a person".',
            ],
        ],
        'items' => [
            [
                'product' => 'patterns',
                'title' => 'The Patterns assistant drafts the show',
                'text' => 'Describe the day, or attach the brief and the running order, and it proposes planned screens, looks, a timed cue stack and lower thirds in the brand\'s colours. Nothing changes until you press APPLY, and what you apply lands in the preview, never on air. It can also explain why a cue ran late from the desk\'s own measurements.',
            ],
            [
                'product' => 'stagewatch',
                'title' => 'StageWatch\'s assistant and copilot keep the day on time',
                'text' => 'Offline, the producer assistant proposes trims when a curfew is at risk, and the forecast learns when your shows really end. With your own key, the copilot (Ctrl+K) turns plain English into a plan of StageWatch\'s own commands, each step with its reason and its risk, and nothing runs until you tick it. Both come with Production.',
            ],
            [
                'product' => 'agmessage',
                'title' => 'AGMessage drafts answers for the back-room',
                'text' => 'Draft answer writes a reply to the board\'s latest question from the thread and the document on stage, Suggest offers tap-to-answer buttons, and Minutes lists decisions, open questions and actions. Each is a draft that a person edits and sends.',
            ],
            [
                'product' => 'playmap',
                'title' => 'PlayMap\'s assistant turns words into actions',
                'text' => 'Type plain words and the optional assistant turns them into PlayMap actions, on Claude with your own key or on a local model. High-risk actions wait on PlayMap\'s own approval list, and while the show is armed it only proposes. After a scan, PlayMap suggests surfaces, and each becomes one only when you approve it.',
            ],
        ],
        'agents' => [
            'title' => 'AI agents, through MCP',
            'text' => 'StageWatch (Production) and PlayMap let AI tools read the show and propose commands through MCP, the Model Context Protocol. StageWatch puts an agent\'s commands in the Copilot list for approval by default, and PlayMap holds every high-risk command for a person. Patterns and AGMessage share a read-only summary of their status that other apps\' assistants can read.',
        ],
        'privacy' => [
            [
                'product' => 'patterns',
                'text' => 'The assistant sees the names and counts of your show\'s parts and any material you attach, never file paths, network addresses, passcodes or keys. Nothing is sent until you press ASK.',
            ],
            [
                'product' => 'stagewatch',
                'text' => 'The copilot is off until you add your key. When you use it, it sends the running order, timings, messages, audience questions, screen names, link status and overrun history. Spoken requests are recognised on the PC, and only the words are sent.',
            ],
            [
                'product' => 'agmessage',
                'text' => 'A draft sends the conversation and the text of the document open on stage, and only when someone asks for a draft.',
            ],
            [
                'product' => 'playmap',
                'text' => 'With a local model, the assistant stays on your own machine. PlayMap works fully with the assistant switched off.',
            ],
        ],
    ],

    'reliability' => [
        'title' => 'Built for show day',
        'intro' => 'The apps are made for rooms where the show cannot stop: they run without the internet and come back by themselves, and Patterns, StageWatch and PlayMap can keep a second machine ready.',
        'points' => [
            [
                'title' => 'Nothing to install, nothing in the cloud',
                'text' => 'Patterns runs from a USB stick with no install and no admin rights. AGMessage and PlayMap are portable folders you unzip and open, and StageWatch installs just for you without admin rights. Every app runs on the venue\'s own network with no internet.',
                'products' => ['patterns', 'stagewatch', 'agmessage', 'playmap'],
            ],
            [
                'title' => 'Back on its feet by itself',
                'text' => 'Patterns, StageWatch and AGMessage each run under a watchdog that restarts them after a crash or a hang and puts the show back where it was. Patterns comes back showing what the audience was watching, not the half-built edit.',
                'products' => ['patterns', 'stagewatch', 'agmessage'],
            ],
            [
                'title' => 'Nothing lost in a power cut',
                'text' => 'StageWatch saves the live show every two seconds and offers it back after a crash or power cut. AGMessage saves every change as it happens, so relaunching brings every meeting back with its history. Every Patterns save keeps the previous show file and timed backups you can restore.',
                'products' => ['stagewatch', 'agmessage', 'patterns'],
            ],
            [
                'title' => 'The projectors keep playing',
                'text' => 'PlayMap\'s engine runs apart from its desk, so closing or restarting the desk never blanks a projector.',
                'products' => ['playmap'],
            ],
            [
                'title' => 'A second machine, ready',
                'text' => 'A Patterns twin follows the whole show and every edit, takes over on TAKE OVER NOW and hands back afterwards. A StageWatch Production licence covers a main and a backup PC running the same show. A PlayMap hot backup mirrors the show, media, clock and air, and FAILOVER hands it the air with no jump in show time.',
                'products' => ['patterns', 'stagewatch', 'playmap'],
            ],
            [
                'title' => 'Nothing interrupts the show',
                'text' => 'Patterns\' show lock holds off Windows notifications, other apps\' sounds, sleep and the Windows key while the show runs, and puts them back afterwards. StageWatch keeps Windows and the displays awake while a show is live and asks before closing mid-show.',
                'products' => ['patterns', 'stagewatch'],
            ],
            [
                'title' => 'Safe changes, even mid-show',
                'text' => 'Patterns builds every edit in a preview, and nothing reaches the audience until you TAKE or CUT. PlayMap\'s blind edit stages changes off air and commits them now or at the next item, its Show Mode locks the mapping, and PANIC takes every output to a planned safe look.',
                'products' => ['patterns', 'playmap'],
            ],
            [
                'title' => 'Screens that tell the truth',
                'text' => 'A screen whose link goes quiet greys out, shows NOT CONNECTED or shows "?" instead of a confident wrong time, and a command that could not be confirmed says so rather than reporting done.',
                'products' => ['patterns', 'stagewatch', 'agmessage', 'playmap'],
            ],
            [
                'title' => 'Wi-Fi drops forgiven',
                'text' => 'A phone that drops off AGMessage\'s Wi-Fi catches up with exactly what it missed, and StageWatch\'s browser screens reconnect by themselves.',
                'products' => ['agmessage', 'stagewatch'],
            ],
        ],
    ],

    'architecture' => [
        [
            'title' => 'One owner per job',
            'text' => 'Each part of the show lives in one app and the others follow it, so two screens can never disagree about the time or the picture.',
        ],
        [
            'title' => 'The venue network is enough',
            'text' => 'The apps talk directly to each other on the local network, with no server, cloud service or account in between, so a lost internet connection never stops the show.',
        ],
        [
            'title' => 'Built like real-time games',
            'text' => 'The apps use techniques that keep online games smooth: one writer for the show, the newest state always wins, and numbered changes let a device that dropped off catch up with just what it missed.',
        ],
        [
            'title' => 'Confirmed, not hopeful',
            'text' => 'Every command between apps comes back with a receipt, and silence always reads as "not connected", never as "fine".',
        ],
        [
            'title' => 'Every app checks for itself',
            'text' => 'Whoever sends a command, whether a person, a Stream Deck, another app or an AI, it passes through the receiving app\'s own checks before anything changes.',
        ],
    ],

    'glossary' => [
        ['term' => 'Show Bus', 'definition' => 'The shared language the apps speak on the venue network to find each other, share status and confirm commands.'],
        ['term' => 'Show network', 'definition' => 'All the apps currently heard on the venue network.'],
        ['term' => 'Node', 'definition' => 'Any app or helper on the show network: a Patterns desk, caller\'s station or stage timer machine, a StageWatch, an AGMessage, a PlayMap or a presenter PC.'],
        ['term' => 'Status light and headline', 'definition' => 'An app\'s one-glance health (green, amber, red, or grey for unknown) and its one-line summary, worked out by rules.'],
        ['term' => 'Receipt', 'definition' => 'The answer to a command between apps: done, refused with the reason, or accepted and not yet confirmed.'],
        ['term' => 'Approval list', 'definition' => 'The list in StageWatch, called the Copilot list, where the show caller approves or discards suggestions from every app.'],
        ['term' => 'Pairing token and API key', 'definition' => 'The secret that lets one app or controller drive another.'],
        ['term' => 'Pinned certificate', 'definition' => 'The encryption identity an app remembers for another app. If it changes, the link stops and says CERTIFICATE CHANGED.'],
        ['term' => 'CLOCKS APART', 'definition' => 'A warning that two computers\' clocks differ by more than two seconds.'],
        ['term' => 'VT countdown', 'definition' => 'StageWatch\'s countdown of the video clip playing in Patterns, PlayMap or vMix.'],
    ],

    'roadmap' => [
        'title' => 'Coming to the ecosystem',
        'intro' => 'The apps already work together today. These are the next steps for the show network; each one is coming, not available yet.',
        'items' => [
            ['label' => 'Coming soon', 'title' => 'Link everything in one pass', 'text' => 'Coming: one setup step that links every app it hears, instead of linking each pair in turn.'],
            ['label' => 'Coming soon', 'title' => 'One preflight for the whole network', 'text' => 'Coming: a single check before doors that covers every app on the show network.'],
            ['label' => 'Coming soon', 'title' => 'One show file from one agenda', 'text' => 'Coming: one agenda that builds StageWatch\'s running order, Patterns\' cues and AGMessage\'s meeting rooms together.'],
            ['label' => 'Coming soon', 'title' => 'One report for the client', 'text' => 'Coming: a merged show report across the apps, with the timings, what played and every AI decision.'],
            ['label' => 'Coming soon', 'title' => 'A backup clock that takes over', 'text' => 'Coming: a backup StageWatch that takes over the show clock by itself when the main one goes silent.'],
            ['label' => 'Coming soon', 'title' => 'One Companion module for the network', 'text' => 'Coming: a single Bitfocus Companion module and Stream Deck profile that reaches every app.'],
            ['label' => 'Coming soon', 'title' => 'StageWatch finds PlayMap by itself', 'text' => 'Coming: StageWatch finding a PlayMap on the network for video countdowns by itself, with no address to type.'],
            ['label' => 'Coming soon', 'title' => 'A rehearsal for failure', 'text' => 'Coming: a tech-check mode that drops links and skews clocks on purpose, so the crew can rehearse what happens.'],
        ],
    ],

    'faqs' => [
        [
            'q' => 'Do I need all four apps?',
            'a' => 'No. Each app runs on its own and does its own job. When two or more are on the same network they find each other and link up, so you can add the others when a show needs them.',
        ],
        [
            'q' => 'Do the four apps work together?',
            'a' => 'Yes. StageWatch counts down the video playing in Patterns or PlayMap, Patterns\' stage pages follow StageWatch\'s timer, AGMessage puts answers on the speaker\'s screen through StageWatch, Patterns\' cues can fire PlayMap\'s playlists, and PlayMap follows Patterns\' blackout. Patterns also shows AGMessage and PlayMap on its Nodes page and in the Eye.',
        ],
        [
            'q' => 'Does any of it need the internet?',
            'a' => 'No. Finding each other, links, commands, status lights and the approval list all work on the venue\'s own network. Only optional extras need a connection: the cloud AI features, AGMessage\'s remote-access link, StageWatch\'s Google Sheets import and internet time check, and online content you choose to show.',
        ],
        [
            'q' => 'What does the show network do?',
            'a' => 'It lets the apps find each other on the venue network, show each other\'s live status, confirm every command with a receipt and send their AI suggestions to one approval list. It runs on Show Bus, the shared language every app speaks.',
        ],
        [
            'q' => 'How do the apps find each other?',
            'a' => 'Automatically: each one announces itself on the local network every two seconds. If an app does not appear, check that both computers are on the same network and that Windows Firewall allows the app on private networks.',
        ],
        [
            'q' => 'Is it safe on a shared venue network?',
            'a' => 'The announcements carry no show data and no secrets, and control needs a key or a pairing token. Every app can serve its pages over HTTPS, and links between apps remember the certificate they trusted and warn if it changes.',
        ],
        [
            'q' => 'Will the AI change my show by itself?',
            'a' => 'In Patterns, StageWatch and AGMessage, not unless you choose to let it: out of the box, AI features only propose and a person decides, and anything that would change what the audience sees is never pre-ticked. In PlayMap, everyday steps run straight away through PlayMap\'s own checks, and high-risk actions always wait for a person. With StageWatch Pro or higher on the network, suggestions from Patterns\' assistant, AI tools connected to StageWatch and AGMessage\'s research team wait in one list for the show caller.',
        ],
        [
            'q' => 'Can I run everything from one Stream Deck?',
            'a' => 'Yes. Patterns, StageWatch and PlayMap each have a Bitfocus Companion module. Load all three into one Companion and put keys from each app on your pages.',
        ],
        [
            'q' => 'What happens if a computer crashes mid-show?',
            'a' => 'Patterns, StageWatch and AGMessage restart themselves and put the show back where it was, and closing PlayMap\'s desk never blanks a projector. For a second machine, use a Patterns twin, a StageWatch Production backup PC or a PlayMap hot backup.',
        ],
        [
            'q' => 'What do CLOCKS APART and CERTIFICATE CHANGED mean?',
            'a' => 'CLOCKS APART means two computers\' clocks differ by more than two seconds; countdowns stay right, but set both clocks to the correct time. CERTIFICATE CHANGED means a linked app now shows a different certificate, for example after a reinstall; accept it only if you know why it changed.',
        ],
    ],
];
