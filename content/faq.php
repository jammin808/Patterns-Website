<?php

declare(strict_types=1);

/* The cross-product FAQ for /support/faq/, in groups (id, title, items of q and a); plain-text answers of one to three sentences, matching the product and licensing files. */

return [
    [
        'id' => 'general',
        'title' => 'General',
        'items' => [
            [
                'q' => 'What is Patterns Show Control?',
                'a' => 'It is the home of four Windows apps for live events, made in the UK by Ben Rohleder: Patterns for the picture, StageWatch for the show clock, AGMessage for the back-room at AGMs and board meetings, and PlayMap for media playback and projection mapping. Each works on its own, and together they share one show network.',
            ],
            [
                'q' => 'Which app do I need?',
                'a' => 'Patterns if you put pictures on LED walls, projectors and screens; StageWatch if you keep speakers and a running order to time; AGMessage if you run the back-room at an AGM or board meeting; PlayMap if you play media and map it onto walls, objects or buildings. Many shows use two or more together.',
            ],
            [
                'q' => 'Do I need all four apps?',
                'a' => 'No. Each app runs on its own. On the same network they find each other and link up, so you can add the others when a show needs them.',
            ],
            [
                'q' => 'Is there a Mac or Linux version?',
                'a' => 'The apps run on Windows. Macs, iPhones, iPads, Android devices and smart TVs take part through a web browser, as screens, remotes or meeting devices, with nothing to install.',
            ],
            [
                'q' => 'Does any of it need the internet?',
                'a' => 'No. All four apps run on the venue\'s own network. The internet is only for optional extras, such as cloud AI features, AGMessage\'s remote access for people off site, StageWatch\'s Google Sheets import and online content you choose to show.',
            ],
        ],
    ],
    [
        'id' => 'setup',
        'title' => 'Requirements and setup',
        'items' => [
            [
                'q' => 'Which versions of Windows do the apps need?',
                'a' => 'Patterns and StageWatch run on 64-bit Windows 10 and 11. AGMessage is hosted on a 64-bit Windows laptop and joined from any browser, and PlayMap runs on 64-bit Windows.',
            ],
            [
                'q' => 'Do I need to install anything?',
                'a' => 'Patterns, AGMessage and PlayMap are portable: put them in a folder, unzipping first where needed, and open them. StageWatch has an installer; choose "only for me" and it needs no admin rights.',
            ],
            [
                'q' => 'Do I need administrator rights?',
                'a' => 'Not to run Patterns, AGMessage or PlayMap, and not to install StageWatch "only for me". A StageWatch install for all users needs admin rights, and also lets StageWatch through Windows Firewall for you.',
            ],
            [
                'q' => 'What do I need for NDI?',
                'a' => 'Install the free NDI Runtime on the PC that sends or receives NDI; PlayMap uses the NDI 6 Runtime. Without it, NDI is simply unavailable and everything else works as normal.',
            ],
            [
                'q' => 'Windows Firewall asked about an app. What should I choose?',
                'a' => 'Allow it on private networks. The apps talk to each other and to phones, tablets and Companion over the venue network, and other devices cannot connect until Windows lets them.',
            ],
            [
                'q' => 'Windows warned me the first time I opened an app. What do I do?',
                'a' => 'Windows SmartScreen can ask you to confirm the first run of an app downloaded from the internet. If you downloaded it from this site\'s client portal, choose More info, then Run anyway.',
            ],
            [
                'q' => 'Do phones and tablets need an app?',
                'a' => 'No. They use an ordinary web browser on the same network: StageWatch\'s speaker and crew pages, Patterns\' remote, AGMessage\'s meeting and PlayMap\'s phone scan camera all open in the browser.',
            ],
        ],
    ],
    [
        'id' => 'licensing',
        'title' => 'Licences and buying',
        'items' => [
            [
                'q' => 'How does licensing work?',
                'a' => 'Licences are one-off and work offline: you buy once and install a licence file on your show PC, with no licence server and no internet needed at the venue. StageWatch is on sale now, Patterns and PlayMap will follow the same model with prices still to be announced, and AGMessage is delivered as a managed service for each AGM.',
            ],
            [
                'q' => 'Is it a subscription?',
                'a' => 'No. A StageWatch licence is bought once and keeps working for ever on the PC it was issued for. Standard and Pro include updates for life; Production includes lifetime updates for early buyers, or a year of updates and then £20 a year for newer versions, and nothing you already have stops working.',
            ],
            [
                'q' => 'How do I buy a StageWatch licence?',
                'a' => 'Copy your hardware ID in StageWatch (Help → License → Plans), create a free client account here and request the licence in the portal. Payment details follow by email, and once paid, the licence file appears under Licences in the portal.',
            ],
            [
                'q' => 'Does activation need the internet?',
                'a' => 'No. Install the licence file in the app and restart it. The licence works offline, and a running show is never interrupted by a licence check.',
            ],
            [
                'q' => 'What happens if I change PC?',
                'a' => 'A licence follows the PC\'s hardware ID, which changes only with a Windows reinstall or a new motherboard. Send the new hardware ID through the client portal and a fresh licence file is issued; changing networks, docks or Wi-Fi never affects a licence.',
            ],
            [
                'q' => 'Is there a free trial?',
                'a' => 'StageWatch has a 14-day trial of every feature, after which it stays free as a basic, watermarked timer. PlayMap\'s early-access build is in the client portal now, and trials for Patterns and PlayMap will be announced with their licences.',
            ],
            [
                'q' => 'Is there a nonprofit discount?',
                'a' => 'Yes. Charities, churches, schools and universities get 30% off every StageWatch licence: Standard £6, Pro £20, Production £55 and the event pass £11. Name your organisation when you request the licence.',
            ],
            [
                'q' => 'Can a rental house buy in bulk?',
                'a' => 'Yes. Rental and production companies can buy StageWatch licences in 5-packs at 20% off; ask for a 5-pack when you request. For other arrangements, choose the rental, reseller or partnership topic on the contact form.',
            ],
            [
                'q' => 'Does the event pass count towards a full licence?',
                'a' => 'Yes. The £15 StageWatch Production event pass counts towards a full Production licence. It covers 30 days on one PC and keeps working for two days of grace after it ends.',
            ],
            [
                'q' => 'How is AGMessage priced?',
                'a' => 'AGMessage is delivered as a managed service for each AGM: a venue laptop, the software set up for your meeting and a technician through setup, rehearsal and the meeting day, quoted on application. Agencies with five or more AGMs a year get a partnership rate. To run AGMessage on your own laptop, tell us on the contact page.',
            ],
        ],
    ],
    [
        'id' => 'together',
        'title' => 'Working together',
        'items' => [
            [
                'q' => 'Do the four apps work together?',
                'a' => 'Yes. StageWatch counts down the video playing in Patterns or PlayMap, Patterns\' stage pages follow StageWatch\'s timer, AGMessage puts answers on the speaker\'s screen through StageWatch, and Patterns\' cues fire PlayMap\'s playlists.',
            ],
            [
                'q' => 'How do the apps find each other?',
                'a' => 'Automatically: each app announces itself on the local network every two seconds, so there are no addresses to type. If one does not appear, check that both computers are on the same network and that Windows Firewall allows the app on private networks.',
            ],
            [
                'q' => 'Can StageWatch count down a video playing in Patterns or PlayMap?',
                'a' => 'Yes, with StageWatch Production or the trial. The VT countdown follows the clip on air through pause and playback speed, and Last 10 s jumps the clip to its final seconds.',
            ],
            [
                'q' => 'Can AGMessage put a message on the speaker\'s screen?',
                'a' => 'Yes, through a linked StageWatch with Pro or higher, and the message shows when it is on screen. With StageWatch Production the research team also sees the speaker acknowledge it, and a message can go to the show caller to approve first.',
            ],
            [
                'q' => 'Do the other apps see our AGMessage conversations?',
                'a' => 'No. Other apps see only counts, the meeting\'s state and the titles of open action prompts, never message text, names, room codes or documents.',
            ],
            [
                'q' => 'Can I run everything from one Stream Deck?',
                'a' => 'Yes. Patterns, StageWatch and PlayMap each have a Bitfocus Companion module; load all three into one Companion and mix their keys on your pages. AGMessage is used from a browser, so keep its laptop or tablet beside the deck.',
            ],
            [
                'q' => 'Will an AI change my show by itself?',
                'a' => 'In Patterns, StageWatch and AGMessage, not unless you choose to let it: out of the box, AI features only propose and a person decides, and anything that would change what the audience sees is never pre-ticked. In PlayMap, everyday steps run straight away through PlayMap\'s own checks, and high-risk actions always wait for a person. With StageWatch Pro or higher on the network, suggestions from Patterns\' assistant, AI tools connected to StageWatch and AGMessage\'s research team wait in one list for the show caller.',
            ],
            [
                'q' => 'What happens if a computer crashes mid-show?',
                'a' => 'Patterns, StageWatch and AGMessage restart themselves and put the show back where it was, and closing PlayMap\'s desk never blanks a projector. For a second machine, use a Patterns twin, a StageWatch Production backup PC or a PlayMap hot backup.',
            ],
        ],
    ],
    [
        'id' => 'portal',
        'title' => 'The client portal and support',
        'items' => [
            [
                'q' => 'Do I need an account?',
                'a' => 'Not to read this site, browse the FAQ or ask the support assistant. A free client account lets you download builds, request licences and collect licence files, report bugs, ask questions and vote on feature requests.',
            ],
            [
                'q' => 'How do I create an account?',
                'a' => 'Choose Create an account, enter your name, email address and a password, and accept the terms of use. We email you a link to confirm your address: open it and press Confirm my email address on the page it opens, then sign in. If new accounts are being checked by a person first, the page tells you and we email you when yours is ready; if accounts are by invitation, the page says so and links to the contact form.',
            ],
            [
                'q' => 'The confirmation email has not arrived. What now?',
                'a' => 'Check your spam or junk folder first. Each link works for 48 hours, and if yours has expired, sign in with your email address and password and the page offers to send a new one.',
            ],
            [
                'q' => 'Where do I download the apps?',
                'a' => 'Start from the Downloads page, then sign in to the client portal\'s Downloads for every build open to you, with release notes. A free account is all you need, and a few builds are for licence holders of that product.',
            ],
            [
                'q' => 'Where does my licence file appear?',
                'a' => 'Under Licences in the client portal. A request moves from Requested to Awaiting payment to Issued, we email you as it moves on, and the licence file is ready to download once it is issued.',
            ],
            [
                'q' => 'How do I report a bug?',
                'a' => 'Sign in, choose New ticket, then Report a bug. Choose the product and how serious it is, from Low to Show-stopper, describe what happened and attach up to five files, such as screenshots or the app\'s support information; bug reports stay private between you and the developer.',
            ],
            [
                'q' => 'How do feature requests and votes work?',
                'a' => 'Feature requests are public on the portal\'s Feature requests board, so every signed-in client can read them, comment and vote. Each one shows its status, such as Planned, In progress or Done, so you can see where an idea has got to.',
            ],
            [
                'q' => 'Can I get in touch without an account?',
                'a' => 'Yes. Use the contact form and choose a topic, such as buying a licence, help with the software, or rental, reseller and partnership enquiries.',
            ],
            [
                'q' => 'How do I delete my account or get a copy of my data?',
                'a' => 'Signed in, open Account: you can download your data or delete your account there. Licence records are kept for as long as the licence may be used and for the period accounting law requires.',
            ],
        ],
    ],
    [
        'id' => 'assistant',
        'title' => 'The support assistant',
        'items' => [
            [
                'q' => 'What can the support assistant help with?',
                'a' => 'It answers questions about the four apps: what they do, how they work together, licensing and prices, downloads, requirements, getting started and basic troubleshooting. It also helps with this website and the client portal, and links you to the right page.',
            ],
            [
                'q' => 'What can\'t the assistant do?',
                'a' => 'It cannot see your account, licences or tickets, issue a licence, take a payment or change anything for you. It does not share code, commands or details of how the software is built, and it sticks to Patterns Show Control software.',
            ],
            [
                'q' => 'Should I share licence files, hardware IDs or passwords with it?',
                'a' => 'No. Never paste a password, licence file, hardware ID or personal details into the chat; the developer asks for what is needed through the client portal.',
            ],
            [
                'q' => 'Are my conversations with the assistant kept?',
                'a' => 'Yes, for 30 days, to answer you and to improve the assistant; the privacy policy explains the details. What you type is sent to Anthropic, whose AI model writes the answers.',
            ],
            [
                'q' => 'When should I ask a person instead?',
                'a' => 'For refunds, a licence file that is wrong or missing, account deletion, legal questions, security reports, or a fault that is stopping a show right now. Open a support ticket or use the contact form, and the assistant will point you there too.',
            ],
        ],
    ],
];
