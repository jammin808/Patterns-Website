<?php

declare(strict_types=1);

/* The nine show workflows, keyed by slug (/workflows/<slug>/): each step names the product that does it; plain text, only what the apps do today. */

return [
    'led-wall-line-up' => [
        'title' => 'Line up and test an LED wall',
        'h1' => 'Line up an LED wall at load-in',
        'summary' => 'A load-in checklist for LED walls with Patterns: number the panels the way the LED crew does, check the raster pixel for pixel, test colour, low grey and motion, hold every output to its signal contract, then hand the checked canvas to the show.',
        'audience' => 'LED and video technicians at load-in, and the show operator who takes the wall over',
        'products' => ['patterns'],
        'duration' => 'Part of load-in, once the wall and its processor are powered and fed',
        'you_need' => [
            'Patterns on the show laptop: one portable app, run from any folder or a USB stick',
            'The LED processor\'s inputs connected to the laptop\'s outputs',
            'The panel size in pixels, and the wall\'s columns and rows',
            'Optional: the free NDI Runtime, to send the same charts to NDI receivers',
        ],
        'steps' => [
            [
                'title' => 'See every output',
                'text' => 'Connect the processor feeds and open Patterns; there is nothing to install. Every display Windows can see appears as a live tile on SETUP → Screens. Check the header reads SHOW rather than PREP, so the outputs can open.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Identify the feeds and join the canvas',
                'text' => 'Press IDENTIFY (Shift+F7) to flash each screen\'s number on the wall. If several outputs feed one wall, drag their tiles together to join them into one canvas; drag them apart to split it again.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Describe the wall the way the LED crew does',
                'text' => 'Choose LED wall mode and enter the panel size in pixels, then the columns and rows or a target canvas. Pick serpentine, linear or row-column numbering so the panel numbers follow the data runs. For an irregular wall, switch to the map editor and drag mixed-size panels, offsets and gaps into place.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Check the raster pixel for pixel',
                'text' => 'Put up the Patterns test card. Its 1:1 pixel fields, overscan ticks, 11-step staircase and clipping and gamma patches show at a glance whether the wall is receiving the picture untouched.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Test colour, uniformity and low grey',
                'text' => 'Step through flat fields, RGB and grey ramps, banding steps and 1-pixel checkerboards to find a panel that is off colour or crushing the low greys. Per-screen brightness, gamma and RGB trims bring a mismatched house display into line.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Check motion and frame rate',
                'text' => 'Run the judder bar, the bouncing frame-rate box and the dropped-frame flash. The master frame rate follows the displays, so a 50 Hz room runs at 50.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Hold each output to its signal contract',
                'text' => 'Give each screen its signal contract (resolution, rate and colour) and read MATCH or MISMATCH against what Windows sends and what the display reports. Then press SAVE KNOWN GOOD: from then on, Patterns tells you if a driver, mode or display drifts from the rig you checked.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Hand the canvas to the show',
                'text' => 'Save the wall in the show file, run Super Check on the Machine page before doors, and build the show\'s looks on the canvas you have just checked.',
                'product' => 'patterns',
            ],
        ],
        'outcome' => 'A wall numbered the way the LED crew sees it, checked pixel for pixel, with a record of the working rig that flags anything that changes.',
        'tips' => [
            'Press IDENTIFY whenever a cable moves: the numbers on the wall settle any doubt faster than a diagram.',
            'Plan at home in PREP mode: add planned screens at the wall\'s size, build the looks, then adopt the real outputs at the venue.',
            'An unplugged display keeps its screen and everything programmed for it, and Patterns takes it back when the same display returns.',
            'Patterns draws its charts at any resolution, so the same test card fits a small side wall and a 4K main screen.',
            'Send a pattern to an NDI output to check an NDI receiver, such as a vision mixer\'s NDI input, with the same charts.',
        ],
        'faqs' => [
            [
                'q' => 'Do I need to install anything on the show laptop?',
                'a' => 'No. Patterns is one portable app for 64-bit Windows 10 and 11. It runs from any folder you can write to, a USB stick included, with no admin rights.',
            ],
            [
                'q' => 'Can Patterns number an irregular LED wall?',
                'a' => 'Yes. The map editor takes mixed panel sizes, offsets and gaps, or you can start from the regular grid and edit the exceptions.',
            ],
            [
                'q' => 'What resolutions can the test patterns cover?',
                'a' => 'Any. The patterns are drawn for the canvas you give them, up to 4K DCI and beyond, and you can save your own presets.',
            ],
        ],
        'related' => ['projector-edge-blending', 'stream-deck-show-control'],
    ],

    'projector-edge-blending' => [
        'title' => 'Blend projectors into one picture',
        'h1' => 'Blend projectors into one widescreen',
        'summary' => 'Turn two, three or more overlapping projectors into one even picture with Patterns: lay out the blend in one press, line up the geometry, match the joins\' gamma and black level, and save it all with the show.',
        'audience' => 'Video technicians and AV crews setting up widescreen and multi-projector screens',
        'products' => ['patterns', 'playmap'],
        'duration' => 'Part of the projection line-up, before rehearsals start',
        'you_need' => [
            'Patterns on the show PC, with one output for each projector',
            'Projectors whose pictures overlap on the screen',
            'Optional: a camera the PC can see over NDI for camera calibration (a phone with an NDI camera app works)',
        ],
        'steps' => [
            [
                'title' => 'Lay out the blend grid',
                'text' => 'Add the projectors on SETUP → Screens, or planned screens before they arrive, and press Arrange as a blend grid with your overlap. Rows, stacks and grids such as 2×2 and 3×2 are laid out in one press.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Join the overlaps into one canvas',
                'text' => 'Tick Automatic blend. The overlapping outputs become one canvas with soft edges, and your content spans it as a single picture.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Line up the geometry',
                'text' => 'Put up the Projection blend pattern, which shows each projector\'s zones, ramps and alignment grid. Bring the grids into line with four-corner keystone, edge bends for a curved screen, or a mesh warp of 3×3 up to 17×17 points.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Match the joins',
                'text' => 'On the pattern\'s 50% grey check, turn each projector\'s blend gamma until the joins read flat, and choose the blend curve that suits the screen.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Match the black level',
                'text' => 'Put up a black picture and slide each projector\'s Black level until the brighter bands in the overlaps disappear, so dark scenes look even across the screen.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Or let a camera do the measuring',
                'text' => 'With Camera calibration, one camera that sees the screen watches each projector show patterns in turn. Patterns places, warps and blends every projector, shows a report of each fit, and you choose APPLY or UNDO.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Audit the joins and save',
                'text' => 'The blend audit on Screens → Edge blend checks every join and says how to fix one that is out. Then save the show: the whole rig, blends and warps included, travels in the show file.',
                'product' => 'patterns',
            ],
        ],
        'outcome' => 'One even picture across every projector, with each join checked and the whole rig saved with the show.',
        'tips' => [
            'Build the blend before the projectors arrive: planned screens at the projectors\' native resolution let you lay out the grid and the content at home.',
            'Tune gamma and black level with the room lit as it will be for the show.',
            'The Projection blend pattern lines up two to twelve projectors in a row or a column, and grids such as 2×2 and 3×2.',
            'Mapping and playing media with PlayMap as well? PlayMap sets a blend grid for side-by-side outputs in its Outputs drawer, and can measure each projector\'s warp, blend and white with a camera on a flat screen.',
        ],
        'faqs' => [
            [
                'q' => 'How many projectors can Patterns blend?',
                'a' => 'Rows, stacks and grids such as 2×2 and 3×2. Each projector fades its own edges from its own overlaps, so a middle projector blends on both sides, and one press of Arrange as a blend grid lays them out with your overlap.',
            ],
            [
                'q' => 'Do I need a camera to blend projectors?',
                'a' => 'No. Every step can be done by eye with the Projection blend pattern. Camera calibration is optional, and places, warps and blends the projectors for you.',
            ],
            [
                'q' => 'Can I warp the picture onto a curved screen?',
                'a' => 'Yes. Edge bends bow each edge for a curved screen or a lens, and the mesh warp of up to 17×17 points fits odd shapes.',
            ],
        ],
        'related' => ['led-wall-line-up', 'projection-mapping-an-object'],
    ],

    'conference-screens' => [
        'title' => 'Run a conference\'s screens from one desk',
        'h1' => 'Run a conference\'s screens, slides and graphics from one desk',
        'summary' => 'Plan the screens before you travel, turn the speaker list into lower thirds and the running order into cues, load the decks and web videos, then run the day from a safe preview with a Stream Deck, a phone and the presenter\'s clicker.',
        'audience' => 'Show operators, producers and corporate event teams running the screens at a conference, a launch or an awards night',
        'products' => ['patterns', 'stagewatch'],
        'duration' => 'A prep session before the event, then the show day',
        'you_need' => [
            'Patterns on the show laptop: one portable app, run from any folder or a USB stick, as the full download if the show plays video files',
            'The speaker list and the running order as Excel or CSV files',
            'The client\'s decks as PDF or PowerPoint files (PowerPoint needs the free LibreOffice on the show laptop), and any web pages or videos you will show',
            'Optional: a Stream Deck with Bitfocus Companion 5, a presenter\'s USB clicker, and StageWatch for cue ladders, department cues the crew confirm and a show report',
        ],
        'steps' => [
            [
                'title' => 'Plan the screens before you travel',
                'text' => 'Switch to PREP and add planned screens at the venue\'s sizes: the main wall, the confidence monitors and a foyer screen. Give each its role. Confidence and info screens keep their own picture through every look and cue until you send them something on purpose.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Bring in the people and the running order',
                'text' => 'Import the speaker list into the lower-thirds library and the running order into the cue stack, from Excel or CSV. Each row brings its planned start and length, and the break, lunch and end marks come in with it.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Dress it in the client\'s colours',
                'text' => 'Make a brand kit from the client\'s colours and logo, pick a lower-thirds style and adjust it, and build a walk-in look with a particle or sound-reactive scene and a countdown to the start.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Load the decks, clips and web pages',
                'text' => 'Add the PDF and PowerPoint decks, the clips and the web pages. Cue a web video in the preview, find where it should start and ARM it, so it plays from that mark the moment it goes to air.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Build the looks and let the stack check itself',
                'text' => 'Save the looks on F1 to F12, give one screen a sponsor picture of its own where the client wants one, and time the steps inside each cue. Any cue that cannot run is flagged with the reason as you build.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Adopt the screens and line them up',
                'text' => 'At the venue, switch to SHOW, adopt each planned screen onto its real display and put up the test card. Plug in the charger, run Super Check on the Machine page and turn on the show lock.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Run the day from the preview',
                'text' => 'Build each change in the preview and TAKE it when it is ready. Put lower thirds up in the preview for sign-off first, hand the presenter the clicker for their deck, and put looks, people and cues on Stream Deck keys that label themselves.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Give the speakers their countdown',
                'text' => 'With remote control on, open the speaker view of Patterns\' stage page on the confidence monitor or a tablet at the lectern: green, then amber at two minutes and red at one unless you set other times. Messages to the speaker or the crew come back marked as seen, and a cue can send one. For cue ladders, department cues the crew confirm and a show report, link StageWatch from the Nodes page, and the stage pages follow its clock.',
                'product' => 'patterns',
            ],
        ],
        'outcome' => 'One laptop runs every screen in the room: decks, videos, web pages, lower thirds and walk-in graphics, built in a preview the audience never sees and taken to air when they are ready.',
        'tips' => [
            'Put lower thirds through the preview first, so the director approves the name before the room reads it.',
            'A lower third leaves by itself after its hold, so a busy operator never leaves a speaker\'s name up through the next session.',
            'Check converted PowerPoint decks once before doors: animations become still pages and a missing font is replaced. For a deck that needs its animations, show PowerPoint for the web as a web page.',
            'The God\'s Eye shows the whole rig on one map, worst problem first, which is quicker than walking the room.',
            'If a confidence monitor must show something else for one session, send it a picture of its own. It keeps it until you send it the programme again.',
        ],
        'faqs' => [
            [
                'q' => 'Can Patterns run the slides as well as the screens?',
                'a' => 'Yes. PDF decks play full frame with instant page turns, PowerPoint, Keynote and Impress files are converted through the free LibreOffice, and web decks such as Google Slides play as pages. The presenter\'s clicker turns the pages.',
            ],
            [
                'q' => 'Can I get the speaker names from a spreadsheet?',
                'a' => 'Yes. Import the speaker list from Excel or CSV into the lower-thirds library, and any name goes into any design in one press. A cue that names someone who is not in the list is flagged before the show.',
            ],
            [
                'q' => 'What does the audience see while I build?',
                'a' => 'Only what is already on air. EDIT SAFE keeps every edit in the preview until you press TAKE or CUT. Looks, cues and remote commands you fire still go straight to air, so fire them when you mean it.',
            ],
        ],
        'related' => ['conference-timing', 'stream-deck-show-control'],
    ],

    'conference-timing' => [
        'title' => 'Run a conference day on time',
        'h1' => 'Run a conference day on time',
        'summary' => 'Paste the agenda into StageWatch, put one countdown on every screen in the room, track ahead or behind as the day runs, win back time before the curfew and hand the client a planned-against-actual report.',
        'audience' => 'Show callers, producers and AV operators running conference days',
        'products' => ['stagewatch', 'patterns'],
        'duration' => 'Set up the day before; StageWatch then runs the whole day',
        'you_need' => [
            'StageWatch on a 64-bit Windows 10 or 11 PC (the 14-day trial includes every feature)',
            'StageWatch Pro for the running order and browser screens, or Production for the forecast, the assistant, the printable report and a backup PC',
            'The agenda in a spreadsheet, a CSV file or a Google Sheet',
            'A confidence monitor for the speakers, plus any phones, tablets or TVs you want as extra screens',
            'Optional: Patterns for the room\'s screens and video playback',
        ],
        'steps' => [
            [
                'title' => 'Paste the agenda',
                'text' => 'The day before, open Running order → Edit… and paste the agenda from your spreadsheet: title, speaker and duration. Set item kinds, fixed starts after lunch and a curfew on the last item, or import a CSV or a shared Google Sheet instead.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Set the start and save the show',
                'text' => 'Set Starts, for example 09:00, and save the show file. With Production, open the same show on the backup PC too: one licence covers a main and a backup machine.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Put the countdown on every screen',
                'text' => 'Send Output → Full screen to the speaker\'s confidence monitor, a keyed full screen to the vision mixer and NDI to vMix, OBS or any NDI mixer. Tick Allow other devices in Settings → Remote, and phones, tablets and TVs open StageWatch\'s pages in a browser; name and place each one from Screens.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Brief the speakers',
                'text' => 'Run the Briefing preview with each presenter. It plays their whole cue ladder at ten times speed, so they know what 5 MIN and WRAP UP look like before they walk on.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Bring the room\'s screens into step',
                'text' => 'If Patterns runs the screens, link it to StageWatch from Patterns\' Nodes page. Its stage and timer pages follow StageWatch\'s clock, and with StageWatch Production, StageWatch counts down the clip on air in Patterns.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Run the day',
                'text' => 'Press N for each next item; auto-advance items roll on by themselves. The running order shows ahead or behind live, the curfew chip shows the margin left, and with Production the forecast gives the likely end.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Win back time',
                'text' => 'Announce Delay +1m or +5m and it ripples on until a gap absorbs it. Recover time… wins back minutes from the rest of the show, previewing every change and the new end first; with Production, the assistant proposes trims, Q&A first and never below an item\'s minimum. Ctrl+Z undoes a whole recovery in one step.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Hand over the report',
                'text' => 'After the last session, open Producer → Show report for planned, adjusted and actual times on every item. Print it to PDF for the client with Production, or export it as CSV with Pro.',
                'product' => 'stagewatch',
            ],
        ],
        'outcome' => 'The speakers, the crew and every screen count the same seconds, the curfew holds, and the client gets planned-against-actual timings for every session.',
        'tips' => [
            'Trims are kept apart from the plan, so the report shows what was planned, what you changed and what really happened.',
            'Use cue labels and the colour-blind-safe palette, so a cue never relies on colour alone.',
            'Several days, or a rehearsal? Keep each as its own running order in the same show file.',
            'A screen that loses its link dims under NOT CONNECTED after a few seconds instead of showing a stale time, and reconnects by itself.',
            'With your own Anthropic API key, StageWatch\'s copilot (Production) can draft the running order from agenda text in an email or PDF; you check every row before keeping it.',
        ],
        'faqs' => [
            [
                'q' => 'Does StageWatch need the internet to run the day?',
                'a' => 'No. Everything runs on the venue\'s own network. The internet is only for optional extras: the cloud copilot, Google Sheets import and the time-server clock check.',
            ],
            [
                'q' => 'Can speakers confirm they have seen a cue?',
                'a' => 'Yes, with Production. Ask to acknowledge puts a Got it button on the speaker\'s screen, and you see each cue go from waiting to acknowledged. If nobody answers within 20 seconds (you can change the time), the cue flashes so you know to follow it up.',
            ],
            [
                'q' => 'Which StageWatch licence do I need for a conference?',
                'a' => 'Pro covers the running order, browser screens, curfews and recovering time. Production adds the forecast, the assistant, acknowledged cues, video countdowns from Patterns, the printable report and a backup PC. The 14-day trial includes everything.',
            ],
        ],
        'related' => ['agm-shareholder-questions', 'stream-deck-show-control'],
    ],

    'audience-polls' => [
        'title' => 'Run audience polls and quizzes on the big screen',
        'h1' => 'Run audience polls, word clouds and quizzes on the big screen',
        'summary' => 'Switch on audience play, put a room code and a QR on the wall, and the audience joins from their phones. Run polls, word clouds and timed quizzes with the results growing on screen, while a word list, the host and an optional AI check stand between what the room writes and the wall.',
        'audience' => 'Producers, hosts and show operators at conferences, awards nights, away days and parties',
        'products' => ['patterns'],
        'duration' => 'Set up in the prep session, then run whenever the agenda allows',
        'you_need' => [
            'Patterns on the machine that drives the wall: the show laptop itself, or a second computer running Patterns out front',
            'A Wi-Fi network for the audience\'s phones that can reach that machine, ideally kept apart from the show network',
            'An admin passcode set on Patterns\' Install page, which opens the host page',
            'Optional: the AI assistant, to take the first look at what the room writes. It needs the internet and your own Anthropic API key',
        ],
        'steps' => [
            [
                'title' => 'Switch on audience play',
                'text' => 'On SETUP → Remote, turn on remote control and then the audience listener. The phones join on a separate port that serves only the play pages and never show control, so you can open that port, and only that port, to the audience Wi-Fi.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Prepare the questions',
                'text' => 'Before the day, put each question in a cue as an add-a-question step, so it is saved with the show: polls with one choice, several or a scale, word clouds, and quizzes with a time limit and the right answer marked. On the day you can also add questions on the desk\'s Arcade page or the host page.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Put the room code on the wall',
                'text' => 'Show the join screen with the room code and its QR. People scan it, pick a nickname and a table, and they are in, with nothing to install.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Open a question',
                'text' => 'Open the next poll or quiz from the desk, the host page, a cue or a Stream Deck key, and close it when the time is up. The answers arrive as people tap.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Reveal the results',
                'text' => 'Put the results on the wall as bars that grow or a word cloud that blooms, and bring up the leaderboard after a quiz. The fastest right answers score the most.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Keep the wall clean',
                'text' => 'Everything the room writes meets the room\'s word list first, then waits for your approval on the host page, one by one or all at once. With the AI assistant set up, it takes the first look: plainly fine words go through, plainly unfit ones are turned away, and doubtful ones wait for you. The list matches whole words, so Scunthorpe is welcome.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Talk back to the room',
                'text' => 'Send a message to the whole room, one table or one phone, from "The poll closes in 30 seconds" to "Table 4, you won".',
                'product' => 'patterns',
            ],
            [
                'title' => 'Play something in the interval',
                'text' => 'Put draughts on the wall for two phones to play, one for each side, tell a story the room votes on at every fork, or run Pong, Snake or Breakout on the big screen with game pads or phones as the controllers and the computer taking any empty seat.',
                'product' => 'patterns',
            ],
        ],
        'outcome' => 'The room takes part from its own phones, the results land on the wall as they come in, and nothing the room writes reaches the wall without passing the word list and then the host or the AI check.',
        'tips' => [
            'If the venue Wi-Fi puts every phone behind one address, set the audience network to Venue NAT on SETUP → Remote. The room\'s status line says when joins were refused and what to change.',
            'On a machine with two networks, use the Bind to boxes on SETUP → Remote: remote control on the show network\'s address and the audience port on the audience Wi-Fi\'s, so the phones only ever see the play pages.',
            'A cue can open a question or put the leaderboard up, so the reveal lands on its moment in the running order.',
            'Run a warm-up poll during the walk-in, so the first real question is not also the first time anyone looks for the QR.',
        ],
        'faqs' => [
            [
                'q' => 'Can the audience vote from their phones?',
                'a' => 'Yes, once you switch audience play on. People join from a room code or QR on the wall, then answer polls, word clouds and timed quizzes, with the results and a leaderboard on screen. What the room writes meets a word list, then waits for the host, or for the AI check when the assistant is set up.',
            ],
            [
                'q' => 'Do people need an app to join?',
                'a' => 'No. They scan the QR or type the address and the room code into their phone\'s browser, then pick a nickname.',
            ],
            [
                'q' => 'Can someone in the audience reach the show controls?',
                'a' => 'No. Audience phones join on a port of their own that serves only the play pages and never takes a show command.',
            ],
            [
                'q' => 'Does audience play need the internet?',
                'a' => 'No. The phones join Patterns over the venue\'s own Wi-Fi. Only the optional AI check needs a connection.',
            ],
        ],
        'related' => ['conference-screens', 'stream-deck-show-control'],
    ],

    'agm-shareholder-questions' => [
        'title' => 'Answer AGM shareholder questions from the back-room',
        'h1' => 'Answer a shareholder question while the meeting carries on',
        'summary' => 'When a shareholder asks a hard question, the back-room team finds the answer in AGMessage, pushes the document onto the chair\'s screen and confirms the wording with a tap-to-answer prompt, in silence, while the meeting carries on.',
        'audience' => 'Company secretaries, legal, investor relations and finance teams, and the agencies and technicians who run AGMs',
        'products' => ['agmessage', 'stagewatch'],
        'duration' => 'Set up on the venue laptop before the meeting, then live for the whole meeting',
        'you_need' => [
            'AGMessage on a 64-bit Windows laptop on the venue network, or delivered with a technician as a managed service',
            'A phone, tablet or laptop with a web browser for each director and each member of the research team',
            'The board pack and briefing documents as PDF, Word or Excel files',
            'Optional: StageWatch Pro or higher, to put answers on the speaker\'s confidence monitor',
        ],
        'steps' => [
            [
                'title' => 'Start the back-room on the venue laptop',
                'text' => 'Unzip the AGMessage folder onto the meeting laptop and start it; nothing else needs installing. Open the admin page on that laptop and start a session named after the meeting.',
                'product' => 'agmessage',
            ],
            [
                'title' => 'Invite the roles',
                'text' => 'Share the network address and the six-character meeting code. Directors join On stage, the back-room joins as the Research team, and anyone else can follow as an Observer. Lock the board and research seats to invite links when you want to be sure who sits where.',
                'product' => 'agmessage',
            ],
            [
                'title' => 'Push the document to the chair',
                'text' => 'When the question comes, the research team drops the policy PDF into File / image, adds a caption and sends it to the stage. It opens on the directors\' On stage panel, with no download and no new tab.',
                'product' => 'agmessage',
            ],
            [
                'title' => 'Confirm the wording',
                'text' => 'Legal confirms the exact wording in a short message marked high priority, and Seen by under the message shows that the board has read it.',
                'product' => 'agmessage',
            ],
            [
                'title' => 'Ask the chair with one tap',
                'text' => 'Research sends an action prompt, such as "Use the exact policy wording?" with Yes, No and Defer. The chair taps an answer and the whole back-room sees it, without a word spoken on stage.',
                'product' => 'agmessage',
            ],
            [
                'title' => 'Put a line on the lectern screen',
                'text' => 'Linked to StageWatch, research can send a short answer to the speaker\'s confidence monitor and see when it is on screen, or propose it to the show caller to approve first. With StageWatch Production they also see the speaker acknowledge that exact answer, and everyone in the meeting sees the live stage timer.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Keep the record',
                'text' => 'Afterwards, export the printable transcript from the admin page and save it as PDF for the client. Then close the meeting and, if you choose, delete the files shared only in it.',
                'product' => 'agmessage',
            ],
        ],
        'outcome' => 'The right paragraph is in front of the chair with no microphone break, the board\'s decision is captured with a tap, and every step is on the record.',
        'tips' => [
            'Pin the documents you expect to need, such as the remuneration policy, so they stay one tap away.',
            'Long board packs render page by page as you scroll, with a page counter, so a big PDF is easy to move through on a director\'s tablet.',
            'Add an Anthropic API key on the admin page and Draft answer suggests a reply from the thread and the document on stage. A person always edits and sends it, and the transcript marks it "drafted with AI, sent by a person".',
            'A phone that drops off the Wi-Fi catches up with exactly what it missed, and relaunching AGMessage brings every meeting back with its history.',
            'An off-site adviser can join through AGMessage\'s built-in remote access, a secure public link that exists only while you switch it on. It needs the internet, and the admin page is never reachable through it.',
        ],
        'faqs' => [
            [
                'q' => 'Does AGMessage replace our AGM voting or audience platform?',
                'a' => 'No. AGMessage runs the back-room alongside whichever audience-facing AGM platform you use. It connects the directors on stage with the team behind them.',
            ],
            [
                'q' => 'Does AGMessage need the internet?',
                'a' => 'No. It runs on the venue network from one laptop. Only the optional AI drafts and remote access for off-site people need a connection.',
            ],
            [
                'q' => 'Who sees which messages?',
                'a' => 'Everyone in a meeting sees its conversation through their role: directors get large cards and answer buttons, the research team sends messages, prompts and files, and observers can read but not post. Other show apps only ever see counts and the titles of open action prompts.',
            ],
            [
                'q' => 'Which files can go to the stage?',
                'a' => 'PDF, Word, Excel, CSV, image and text files show directly on the On stage panel, up to 25 MB each. Save a PowerPoint file as PDF to show it there.',
            ],
        ],
        'related' => ['conference-timing', 'stream-deck-show-control'],
    ],

    'projection-mapping-an-object' => [
        'title' => 'Projection map an object',
        'h1' => 'Map an object with one projector and a camera',
        'summary' => 'Map a sculpture, set piece or product with PlayMap: scan it with a webcam, a phone or a tethered camera, click each face into a surface, refine the projector\'s position with pins, then give every surface its content and mask.',
        'audience' => 'Projection-mapping artists, set designers and AV technicians',
        'products' => ['playmap'],
        'duration' => 'One scan session in a darkened room, then content and masks at your own pace',
        'you_need' => [
            'PlayMap (early access, from the client portal) on a 64-bit Windows PC, with the projector connected as an extended display',
            'A camera: a USB or capture camera (manual exposure helps), a phone with a modern browser on the show Wi-Fi, or a tethered camera shooting into a folder',
            'A room you can darken for the scan',
            'Your content: clips, stills, or PlayMap\'s built-in generators and test patterns',
        ],
        'steps' => [
            [
                'title' => 'Set up the camera',
                'text' => 'In SCAN, step 1 is Camera: pick or add the camera and stand it near the projector\'s lens. Lock exposure, white balance and focus so every pattern is seen the same way. A phone joins with a one-time invite link or QR code and stays on your show network.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Choose the projector and the scan mode',
                'text' => 'In step 2, tick the projector to scan and pick a mode: Fast for matte objects in the dark, Robust for most jobs, or Difficult for shiny materials.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Scan',
                'text' => 'In step 3, the projector shows its patterns for a short time while the camera watches, and the desk tells you roughly how long it will take. Cancel gives the output back at once.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Click the faces into surfaces',
                'text' => 'In step 4, the smart scan shows what the camera saw from the projector\'s point of view. Click each part of the object, such as a face, a box or a column, and choose Make surface. PlayMap also suggests surfaces from what it measured, and each becomes a surface only when you approve it.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Apply the scan',
                'text' => 'In step 5, Apply turns the scan into the stage mesh, the projector\'s position and your surfaces, ready to work on in MAP.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Refine the projector with pins',
                'text' => 'For a tighter fit, press P in MAP and click features on the object to pin them. PlayMap solves the projector\'s position and lens and shows each pin\'s error, so you can see which point to adjust.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Give each surface its content and mask',
                'text' => 'In MAP or PLAY, give each surface a clip, a playlist or a generator. Trim the edges with the Bézier pen mask (B); masks and content stay with their surfaces if you scan again.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Arm the show',
                'text' => 'Press the Ready light to open Preflight, run it and press Fix on anything red or amber, then ARM the show (Ctrl+Shift+A) to lock the mapping. If anything goes wrong, PANIC (F12) takes the output to its planned safe look.',
                'product' => 'playmap',
            ],
        ],
        'outcome' => 'Content sits on every face of the object, the masks are clean, and the whole map survives a rescan.',
        'tips' => [
            'Choose Difficult mode for shiny or dark materials; Robust suits most jobs.',
            'Scanning from more than one camera position? PlayMap solves every view together into one mesh.',
            'If the projector is knocked, re-solve that projector alone from its pins or a camera; masks, surfaces and content stay as they were.',
            'No camera to hand? Draw a four-click quad on each flat face (Q in MAP), or import a 3D model of the object and solve the projector from pins.',
            'Every calibration is scored and kept in a history, so you can always go back to an earlier result.',
        ],
        'faqs' => [
            [
                'q' => 'Do I need a camera to map an object?',
                'a' => 'No, but it saves time. Without one, draw four-click quads on each face, or import a 3D model and solve the projector from pins. With one, PlayMap scans the object and you click its faces into surfaces.',
            ],
            [
                'q' => 'Which cameras work for scanning?',
                'a' => 'A USB or capture camera (manual exposure is best), a phone\'s browser on the show Wi-Fi, or a tethered camera shooting into a folder.',
            ],
            [
                'q' => 'Can I use two projectors on the object?',
                'a' => 'Yes. Tick both projectors in the scan\'s second step, and PlayMap blends their overlap on the object automatically.',
            ],
            [
                'q' => 'Does closing PlayMap\'s desk stop the projector?',
                'a' => 'No. PlayMap\'s engine runs separately from the desk, so closing or restarting the desk never blanks a projector.',
            ],
        ],
        'related' => ['projector-edge-blending', 'stream-deck-show-control'],
    ],

    'stream-deck-show-control' => [
        'title' => 'Run the show from a Stream Deck',
        'h1' => 'Run the whole show from one Stream Deck',
        'summary' => 'Load the Bitfocus Companion modules for Patterns, StageWatch and PlayMap into one Companion and lay out test patterns, looks, cues, timers and playback on a single Stream Deck, with keys that label themselves and light from each app\'s live state.',
        'audience' => 'Show operators and callers who want every app under their fingers',
        'products' => ['patterns', 'stagewatch', 'playmap'],
        'duration' => 'Set up once in rehearsal, then run the show from the deck',
        'you_need' => [
            'Bitfocus Companion 5 (5.1 or newer for the PlayMap module) and a Stream Deck',
            'The Companion module for each app you use: StageWatch\'s comes with StageWatch, and the others are on the Downloads page',
            'StageWatch Pro or higher, or the trial, for Companion control of StageWatch',
            'Every computer on the same show network',
        ],
        'steps' => [
            [
                'title' => 'Connect Patterns',
                'text' => 'In Companion, import the Patterns module package from the Modules page, and check that remote control is on in Patterns (SETUP → Remote). Add a Patterns connection and pick the desk from Desk on the network; if the desk uses a pairing token, enter it once.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Connect StageWatch',
                'text' => 'Import the StageWatch module that comes with StageWatch and add a StageWatch connection with the StageWatch PC\'s address, then check that Settings → Companion → Enabled is on in StageWatch. For a Companion on another PC, also tick Bind all interfaces and allow StageWatch through Windows Firewall on private networks.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Connect PlayMap',
                'text' => 'Import the PlayMap module, pick the PlayMap machine from Companion\'s list and enter the pairing token from PlayMap\'s Network drawer. The module proves it knows the token without ever sending it across the network.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Lay out the picture',
                'text' => 'Drag Patterns\' bank presets onto the deck: looks, cues, screens and lower thirds label themselves from the show and light with live tally. Stream Deck+ knobs step audio levels, and risky keys such as the twin\'s take-over need two presses.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Add the clock',
                'text' => 'Drop in StageWatch\'s 32-key Showcaller page preset, or pick from the presets made for every StageWatch preset and running-order item. The timer ring drains as time runs down, and the Timer key turns the same colour as StageWatch.',
                'product' => 'stagewatch',
            ],
            [
                'title' => 'Add the playback',
                'text' => 'PlayMap builds its keys from your show: playlists, items, outputs, projectors and PANIC, with live pictures and lights. Risky keys are held or pressed twice, and a key lights because PlayMap says so, not just because it was pressed.',
                'product' => 'playmap',
            ],
            [
                'title' => 'Let the show drive the deck',
                'text' => 'A Patterns cue can turn the Stream Deck to another page, such as the Q&A page, at the right moment. Companion\'s action recorder captures what you do on the Patterns desk into a button.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Rehearse, then run',
                'text' => 'Walk every page in rehearsal. During the show the keys follow each app\'s live state; if StageWatch\'s link drops, its keys read OFFLINE, and presses made while offline are dropped, never replayed later.',
                'product' => 'stagewatch',
            ],
        ],
        'outcome' => 'One Stream Deck carries the picture, the clock and the playback, and every key shows what the app it drives is really doing.',
        'tips' => [
            'Keys for StageWatch features your licence does not include turn grey, so the deck always shows what is available.',
            'Keep GO, Blackout and PANIC on the same keys on every page, so they are where your hand expects them under pressure.',
            'Patterns\' Navigator turns the desk\'s own menus into deck pages, and the desk and the deck can follow each other.',
            'Companion finds Patterns desks and PlayMap machines on the network by itself, so setup is usually a pick from a list.',
        ],
        'faqs' => [
            [
                'q' => 'Is there one module for the whole network?',
                'a' => 'Each app has its own Companion module, and all three load into one Companion, so one Stream Deck carries keys from every app.',
            ],
            [
                'q' => 'Which version of Companion do I need?',
                'a' => 'Companion 5 for the Patterns and StageWatch modules, and 5.1 or newer for PlayMap\'s.',
            ],
            [
                'q' => 'What happens to a key press if the network drops?',
                'a' => 'Keys light from each app\'s live state, so the deck shows what the app has really done. If StageWatch\'s link drops, its keys read OFFLINE, and presses made in the meantime are never replayed.',
            ],
        ],
        'related' => ['conference-timing', 'led-wall-line-up'],
    ],

    'lobby-screens' => [
        'title' => 'Run a lobby screen by the clock',
        'h1' => 'Run a lobby, shop window or museum screen by the clock',
        'summary' => 'Set up a screen nobody sits at: programmes on a rota by time, weekday and season, adverts and announcements at their times, a TODAY view of what the clock will do, and care from elsewhere with updates that roll back by themselves.',
        'audience' => 'Integrators and AV teams looking after hotel lobbies, shop windows, museums, receptions and other permanent screens',
        'products' => ['patterns'],
        'duration' => 'An afternoon to set up, then it runs by itself',
        'you_need' => [
            'Patterns on the machine that drives the screens, run from any folder you can write to',
            'The looks the site will show, built in Patterns',
            'Optional: a VPN for reaching the site over the internet, and a web service of your own if you want the site to check in',
        ],
        'steps' => [
            [
                'title' => 'Build the looks',
                'text' => 'Build each picture the site will show as a look: the morning programme, the evening one, each advert and an idle look for outside opening hours.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Put the programmes on a rota',
                'text' => 'On the Install page, give each programme its start and end, its days and any season\'s dates. A window can run past midnight, and a dated row, such as the Christmas rota, beats the everyday one.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Add the adverts and announcements',
                'text' => 'An advert takes over for a few seconds at its times, on every screen or only the ones it names, then the programme comes back. An announcement puts words on screen with a voice-over and a look of its own, by the clock or by hand. Announcements beat adverts.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Read TODAY before you leave',
                'text' => 'TODAY lists the day exactly as the clock will run it and flags any row that cannot work, so a missing look turns up now rather than at opening time.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Make the machine look after itself',
                'text' => 'Turn on the show lock so notifications and sleep stay away, run Super Check, and press SAVE KNOWN GOOD so any later change in the displays or drivers is reported. The watchdog restarts Patterns if it ever falls over.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Look after the site from elsewhere',
                'text' => 'Set an admin passcode and the web remote\'s ADMIN page gives you the health line, the schedule, a button for every announcement and advert, RESTART, the log and a support bundle with every secret blanked. Add a check-in to your own server and the site needs no open inbound port.',
                'product' => 'patterns',
            ],
            [
                'title' => 'Let updates install themselves',
                'text' => 'With the watchdog running, an update waiting on the machine goes in as Patterns restarts, from APPLY on the admin page or at a set time each day in the update window. The files it replaces are kept, an update your server delivers is used only when its checksum matches, and if the new version does not stay up, the old one comes back by itself.',
                'product' => 'patterns',
            ],
        ],
        'outcome' => 'A screen that runs the day by itself, says what it will do before it does it, and can be looked after and updated without a visit.',
        'tips' => [
            'The passcode protects the local network only. Use a VPN to reach the admin page over the internet.',
            'No management server comes with Patterns: any web service you run, from a short script to a signage system\'s webhook, can be one.',
            'Every scheduled change goes into the show log, so "why was that advert on at nine?" has an answer.',
            'A person at the desk still wins: while the cue stack is armed, the clock leaves the screens alone.',
            'Announcements also fire by hand from a cue, a Stream Deck key, OSC or the admin page, with the schedule on or off.',
        ],
        'faqs' => [
            [
                'q' => 'Can Patterns run a screen with nobody there?',
                'a' => 'Yes. A clock-run schedule plays programmes, adverts and announcements by time, weekday and season, with an idle look or black outside opening hours. It is off until you switch it on, so a show machine never changes its picture by the clock.',
            ],
            [
                'q' => 'Can I update a site without visiting it?',
                'a' => 'Yes. With the watchdog running, an update your own server delivers, or one copied into the site\'s updates folder, is put in place as Patterns restarts, and the old version comes back by itself if the new one does not stay up.',
            ],
            [
                'q' => 'Does the site need an open port on the internet?',
                'a' => 'No. It can check in with a server you run instead, which needs no inbound port. For the admin page itself, use a VPN rather than opening it to the internet.',
            ],
        ],
        'related' => ['led-wall-line-up', 'stream-deck-show-control'],
    ],
];
