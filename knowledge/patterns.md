# Patterns: assistant notes

Patterns is a portable Windows app that runs the picture at live events: it lines up every LED wall, projector and NDI feed with pixel-exact test patterns, then plays, switches and cues the show on them, from slides to lower thirds. Use that sentence when someone asks what Patterns is.

Patterns runs the picture for the whole show, from the first test card to the last cue: a desk that switches every screen from a safe preview, the decks, video and web pages, the show graphics and their sound, a cue stack, a speaker countdown with messages to the stage, and polls, quizzes and games on the audience's own phones. It is the picture app of the Patterns ecosystem, beside StageWatch (the stage timer and show-calling desk), AGMessage (private back-room messaging for AGMs and board meetings) and PlayMap (media playback and projection mapping). Its screens include LED walls, video walls, projectors, TVs, NDI receivers and live streams.

When someone asks "is it just a test pattern generator?", the answer is no, though it still draws a rather good test card.

## What it is

- One portable Windows app for Windows 10 and 11 (64-bit). There is no installer and no runtime to install, and it needs no admin rights to run.
- Run it from any folder you can write to, including a USB stick or the desktop. Settings, presets, brand kits and show media live beside it, so copying the folder moves the whole show.
- A whole rig and show saves as one show file that travels with you.
- There are two downloads: the standard one, and a full download that includes the video engine. Choose the full download if the show plays or streams video; with the standard download, install 64-bit VLC media player instead. Images work with either.
- NDI needs the free NDI Runtime from the NDI website. Without it, Patterns runs normally with NDI switched off.
- PowerPoint, Keynote and Impress files need the free LibreOffice, installed or as a portable copy beside Patterns. PDF decks need nothing extra. Web pages use Microsoft Edge WebView2, which comes with Windows 10 and 11.
- Downloads come from the client portal; a client account is free.

## Where things are on the desk

The rail down the side groups the pages: SHOW (the Panel, Run and the Eye), PLAN (Cues, Looks and Install), BUILD (Pattern, Media, Overlays, Lower thirds, Countdown, Particles, Fractals, Reactive, Branding, Layers, Library and the Assistant), SETUP (Screens, Multiview, Audio, NDI, Stream, Remote, Interactive, Arcade and Nodes) and ADMIN (Machine and Help).

## Key ideas to explain

- The desk works like a vision mixer: PROGRAM (what the audience sees) above PREVIEW (what you are building), with a live tile for every screen and canvas.
- EDIT SAFE is on from the start, so edits stay in the preview until you press TAKE (with a transition) or CUT (instant). Things you fire, such as looks, cues, stingers and remote commands, go straight to air.
- A take can go to every armed screen, one tile, the ticked tiles or a group of screens such as every confidence monitor. A line under the picker says what the press will do, locked screens are never taken, and a take that would change nothing is refused with the reason.
- Any screen can have a picture of its own (OWN) while the rest follow the show. SEND puts the preview on one screen's tile, so that screen's own CUT or TAKE puts it up there alone.
- A look is a saved picture you can recall on F1 to F12. LOOK BACK returns to the previous look.
- The cue stack is the caller's running order, run from the RUN layout with ARM, GO, HOLD and STOP ALL. GO checks a cue first and says why if it will not fire.
- The header mode PREP keeps the outputs closed while you prepare on planned screens; SHOW lets them open; RUN is the caller's layout. At the venue, Adopt maps each planned screen onto its real display.

## Main features, in brief

- The desk: preview and programme, takes to all, one, the ticked tiles or a group, a picture of its own for any screen, a transition or video sting chosen for one take from a right-click on TAKE, looks and presets, LOOK BACK, FREEZE, fades to black on one screen, a group or the whole rig, up to two multiview monitor walls with tally and REVIEW, and right-click menus in step with the buttons.
- Test patterns and line-up: alignment grids, SMPTE RP 219-style and EBU colour bars, 75% and 100% bars in legal or full range, ramps, banding steps, focus charts, geometry, safe areas, checkerboards, motion and dropped-frame checks, and the Patterns test card, which says which screen it is on, whether pixels arrive one to one, where the edges went and what happened to black, white and grey. Drawn at each screen's own size or at any resolution up to 4K DCI and beyond. IDENTIFY flashes screen numbers, and a soundcheck tone has a left and right ident.
- Signal checks and commissioning: each screen's signal contract (what its link should carry, down to the exact rate, so 59.94 is not 60) reads MATCH, MISMATCH, PARTIAL or UNVERIFIED against what the display advertises, what Windows sends and what the far end reports or the engineer reads off its panel. Patterns builds the matching EDID file for a processor input or a PC's port, to load with that device's own tools. A seven-stage commissioning checklist and SAVE KNOWN GOOD record the working rig, and the next start says if a driver, a mode or a display has drifted.
- Slides, video and web pages: PDF decks full frame with instant page turns (after the last page, the next click can fire the next cue), PowerPoint, Keynote and Impress files converted in the background through LibreOffice, web pages such as YouTube, Vimeo, Google Slides, PowerPoint for the web and live dashboards as the picture or a layer, FULL FRAME and CLEAN to tidy a site's buttons away, a web video cued in the preview and armed to play from its mark when it goes to air, playlists with show parts such as Walk-in, Main and Break, NDI feeds and HDMI or SDI capture cards, and crop, mirror and rotate on any input.
- Show graphics: a lower-thirds designer with ten starting styles and keyframed motion, a people library imported from the Excel speaker list or a CSV, lower thirds signed off in the preview and leaving by themselves after their hold, countdowns, a clock, messages, tickers from RSS, CSV or a calendar, the logo, a live weather chip, brand kits, particle packs, a fractal studio, six sound-reactive walk-in scenes, six transitions, twelve effect stings that need no file, video stingers, two picture layers on every screen dragged into place on the preview, and a picture-in-picture over the programme that, like the clock, the logo and the countdown, can also be typed to the pixel. Whole-screen flashes are limited to three a second on every screen.
- Cues and Run mode: each cue runs a list of show actions with timed steps inside it, cues can follow on by themselves, ask for a confirm and carry the caller's notes, and the stack flags any cue that cannot run before the show. Run mode has a LIVE strip, standby and next, a GO that ignores a double press, HOLD, ARM and a guarded STOP ALL, and the Run window pops out onto the caller's monitor or opens on a tablet. Running orders come in from Excel or CSV with ON TIME or 3 MIN LATE against the plan, and the rest of the day can be pushed back, resumed or caught up. The clip on air has one clock with OUT IN 7 over its last ten seconds, the standby cue's clip is opened in advance, and a presenter's USB clicker steps a list of its own.
- The stage and the caller: a speaker countdown, a crew view and messages to the stage marked as seen, a caller's station and a stage timer machine. See "The stage timer, messages and second computers" below.
- Control: a Bitfocus Companion 5 module for Stream Deck, a phone and tablet remote, MIDI LEARN, OSC, Arduino, Raspberry Pi and ESP32 devices, PJLink projectors, media servers and any box with a web API, and TCP and HTTP control for integrators. See "Control" below.
- Audience play and the arcade: a room code and QR on the wall, polls, word clouds, timed quizzes, a leaderboard, messages back to the room, two room games, and Pong, Snake and Breakout. Off until switched on. See "Audience play and the arcade" below.
- LED walls and video walls: LED wall mode with the panel size, partial edge panels and serpentine, linear or row-column numbering, an irregular LED map editor, video wall mode with bezel-loss hatching, screens dragged flush into one canvas that is pixel-exact across the seam, bezels and LED pillar gaps laid out across the real wall, screen roles (Main, Confidence, Info and Repeater; confidence and info screens keep their picture through looks and cues), every output paced to its own display with the show's frame rate following the displays, and per-screen rotation, brightness, gamma and RGB trims.
- Projection: edge blending for rows, stacks and grids with each output's own curve, gamma and widths, a one-press blend grid, a blend audit that names the fix, black-level matching, 4-corner keystone, edge bends, a mesh warp of 3×3 to 17×17 points shown live, optional camera calibration with APPLY and UNDO (the camera arrives over NDI, so a phone running an NDI camera app works), the Projection blend pattern for 2 to 12 projectors, and an optional alignment game for the final touches at the edges.
- Sound: walk-in and break music as a playlist to any set of Windows outputs (an interface, HDMI screens, Dante or USB devices), Voice-of-God announcements that duck the music, a live DUCK, a routing matrix (off until switched on) with a level per crosspoint and a delay, trim and mute per destination, sound that follows what the audience sees and never the preview, a separate MONITOR output for the operator, one master clock with SYNC CHECK, Spotify break music played by the Spotify app (needs Spotify Premium and the customer's own free Client ID), and a fade to black that can take the sound down too.
- Outputs, NDI and streaming: as many NDI senders as needed, each with its own name, size, frame rate and picture (10-bit available), streaming to two destinations at once (RTMP for YouTube, Twitch or Restream, SRT or UDP) from one encode, with the encoder running apart from the show and a SLOW light, a display-mode picker with a 15-second safety revert, direct output, a low-latency mode for a capture card feeding IMAG, and the strongest graphics card picked by default.
- Permanent installs: for lobbies, shop windows and museums, a clock-run schedule (PLAN → Install) plays programmes on a rota by time, weekday and season, adverts on chosen screens and announcements with a voice-over, with a TODAY view of what the clock will do. Announcements beat adverts, and while the cue stack is armed the clock leaves the screens alone. An admin passcode opens an admin page on the web remote to look after the site from elsewhere, an optional check-in to the customer's own server needs no open inbound port, and updates the watchdog applies are rolled back by themselves if the new version does not stay up. Off until switched on. For reaching a site over the internet, suggest a VPN rather than opening the admin page to the internet.

## The stage timer, messages and second computers

StageWatch is the ecosystem's timing product. Patterns also has timing and messaging of its own, enough for a show without StageWatch:

- A speaker countdown: green, amber and red at the times set on BUILD → Countdown (amber at two minutes and red at one by default), then red and counting how far over. It shows on a confidence monitor or in any browser on the show network, so a tablet on a stand, a phone on the lectern or a spare laptop can be the speaker's display with nothing to install.
- A crew view for the stage manager, and a timer page for a phone or tablet to start, pause, resume and add or take away time.
- Messages to the speaker or the crew, typed or from a preset. Each flashes on its display until it is acknowledged on the stage page, and the desk then shows when it was seen. A cue can send a message on its GO, such as "Mic 2 is live" to the crew.
- A show caller's station: a second computer running Patterns as the caller's own desk, with no outputs. The caller plans the cues at home on their own laptop, then links to the desk at the venue from the Nodes page; the desk shows what differs and takes the plan in with one press, and the caller calls GO, STANDBY and HOLD from there.
- A stage timer machine: a second computer running Patterns whose own screen is the stage display. Linked to the desk it shows the desk's countdown and messages and sends the acknowledgements back; on its own it is a stage timer by itself.
- One clock across the machines: the stations read the countdown and the messages on the desk's clock, so the speaker's timer and the desk's agree to the second. If two machines' own clocks are more than two seconds apart, the health line says CLOCKS APART.
- How to start Patterns as a caller's station or a stage timer machine is in Patterns' built-in Help (the Nodes topic). Do not give a command line.
- Linked to StageWatch, the stage pages, the caller's station and the stage timer machine all follow StageWatch's clock, colours and title, so the room keeps one timer. Pause and add time are then done in StageWatch, and Patterns' messages to the stage stay Patterns' own.
- When someone needs a full show-calling desk (cue ladders, department STANDBY and GO that the crew confirm, curfews and show reports), point them to StageWatch.

## Control

- A web remote for phones and tablets with nothing to install, with tabs for SHOW, CUES, LOOKS, SCREENS, AUDIO, LOWER THIRDS, OVERLAYS and SETUP, a header that always says what is on air, and pages for the caller, the stage, the timer and the multiview. Switch it on in SETUP → Remote, which shows the address to open and the pairing token. A new desk is paired with a token from its first start, and the web remote can run over HTTPS.
- A Bitfocus Companion 5 module for Stream Deck. Download the module, import it into Companion, pick the desk under "Desk on the network" and drag the presets onto the keys. Keys label themselves from the show's looks, cues, people and stingers and show live tally, so a new look or speaker appears on the next spare key. The desk's own menus can be stepped through on 24 keys to build the show from the deck, desk presses can be turned into buttons with Companion's recorder, Stream Deck+ knobs step levels, and a cue can turn the deck to another page.
- MIDI LEARN from any right-click menu: choose it, touch a pad or fader on any MIDI control surface, and that control is mapped. Pads light and motorised faders follow the show.
- OSC in and out for lighting desks, tablet control apps and show controllers, with feedback. OSC is off until switched on.
- Arduino, Raspberry Pi and ESP32 devices over USB, TCP or UDP, added in SETUP → Interactive: a lectern button fires GO, a sensor reading becomes on-screen text, and a lamp follows the cue stack.
- Cues and keys talk to the room's other kit: PJLink projectors (power, input and shutter), media servers over OSC or their own API, and any room-control processor or box with a web API, and Patterns shows what each box answered, not just that the command went.
- A one-line-per-command TCP protocol and an HTTP API for integrators. A misspelt command is refused rather than guessed. Do not give command syntax; point integrators to a support ticket.
- Every screen tile, look, cue, lower third, layer and overlay has a right-click menu with the desk's own commands, in step with the buttons, and each line shows the command a Stream Deck key would send for it.

## Seeing the show

- The God's Eye (SHOW → Eye) draws the whole show as one zoomable map: every display, screen, source, device, Stream Deck, audio path, the stream, the cue stack and the other show apps it hears, with a status light on each thing and each link. The rail names the worst problem first in plain words, and NEXT PROBLEM steps through everything red or amber.
- REPLAY plays the show log back over the map, to see what was done, refused or failed at any moment.
- Super Check on ADMIN → Machine checks the machine, the graphics, the displays, NDI, the stream and the audio in one press and rates the hardware from rehearsal to big show. The Machine page is a health dashboard with warnings worst first and the last three minutes graphed beside the day so far, and it lists the whole computer: graphics cards and drivers, displays and their modes, audio devices and the power plan.
- A slow frame names what took the time, with advice to match, and every GO is timed from the press to the first frame on each output.

## Audience play and the arcade

- Audience play is off until it is switched on: in SETUP → Remote, turn on remote control and the audience listener. The room is hosted by the desk itself, or by a second computer running Patterns out front.
- Put the room code and its QR on the wall, and the audience joins from their phones' browsers with a nickname and a table. There is no app to install.
- Polls with one choice, several or a scale, word clouds, and timed quizzes where the first right answers score most. Results grow on the wall as bars or a word cloud, with a quiz leaderboard.
- Questions are added on the desk's Arcade page (SETUP → Arcade), on the host page (behind the admin passcode set on PLAN → Install), or by a cue step that adds a question. Questions typed on the Arcade page or the host page last until Patterns restarts, so a cue step is the way to keep them with the show. Questions are opened and closed from the host page, the Arcade page, a cue or a Stream Deck key.
- Messages go back to the whole room, a table or one phone.
- Two games for the room: draughts on the wall, played from two phones, one for each side, and a story where the room votes at every fork.
- Everything the audience writes meets the room's word list first, then waits for the host's approval, one by one or all at once. With the assistant set up, it takes the first look: plainly fine words go through, plainly unfit ones are turned away, and doubtful ones wait for the host. The host can also let words straight through for a quiz night. The word list matches whole words, so Scunthorpe gets in.
- Phones join on a separate port that serves only the play pages and never show control, so that port, and only that port, can be opened to the audience Wi-Fi. On a machine with two networks, the Bind to boxes on SETUP → Remote put remote control on the show network's address and the audience port on the audience Wi-Fi's.
- The arcade: Pong, Snake and Breakout on the big screen, with phones, game pads, the keyboard or a Stream Deck as controllers and the computer playing any empty seat. The game can be a picture on the show's own screens, a window of its own or an NDI source.

## Reliability

- A watchdog restarts Patterns within seconds after a crash or a hang and puts back what the audience was watching, from the picture and the lower third to locked and faded screens, with the outputs and the audio. A restart asked for while the outputs are live becomes a handover: the new copy takes the screens once its picture is over them, so the room does not see the desktop.
- An optional standby twin (ADMIN → Machine → TWIN): a second Patterns, as a second copy on the same machine or on a second computer running Patterns, keeps in step with every edit and can take over, then hand back. Between two computers, an automatic takeover waits for a video switcher to confirm the switch.
- Every screen remembers its display: pull a cable and that screen waits with everything programmed for it, the other outputs stay lit, and the same display is switched back on when it returns.
- The show lock holds off notifications, other apps' sounds, Sticky Keys pop-ups, sleep, the screensaver and the Windows key during the show, and puts them back afterwards, even after a crash.
- The show log records every change to what the audience sees, with the time and who or what caused it.
- Particle and fractal effects step their detail down before a smaller machine would stutter, and step back up when there is room.
- Every save keeps the previous show file and up to 20 timed copies: ADMIN → Machine → Earlier versions of the show → RESTORE.

## Common fixes

- Nothing on screen: check the header says SHOW, not PREP, then press OUTPUTS ON (Shift+F5), and check the screen is switched on in SETUP → Screens.
- An edit did not reach the room: press TAKE or CUT.
- A display is missing: check Windows shows it as a display of its own rather than a copy of another. One that was unplugged is recognised when it returns, and a new one is offered on SETUP → Screens.
- Video will not play or the stream will not start: use the full download, or install 64-bit VLC media player and restart Patterns.
- NDI missing: install the free NDI Runtime and restart Patterns.
- A phone or Stream Deck cannot connect: same network as the show PC, remote control on, the address and pairing token from SETUP → Remote, and Windows Firewall allowing Patterns on that network.
- Audience phones cannot join: remote control and the audience listener both on in SETUP → Remote, and the phones on a Wi-Fi network that can reach the machine hosting the room. If the venue's Wi-Fi puts every phone behind one address, set the audience Network to Venue NAT on the same page; the room's status says when joins were refused from one address.
- A PowerPoint deck looks different: slides become still pages through LibreOffice, so animations do not play and a missing font is replaced. Install the deck's fonts and check it once; for animations, show PowerPoint for the web as a web page.
- A web video is not as smooth as expected: the page controls show the frame rate the room is actually getting. Set the page's Frames choice to Smooth and plug a laptop's charger in. If it stays low, suggest a support ticket with Copy support info.
- The picture stutters on a laptop: plug in the charger and run Super Check on the Machine page, which gives advice.
- Panic keys: Shift+F8 or Space is blackout. In Run mode, Esc twice stops all sounds and clips.

## Keys

F1 to F12 recall looks. Shift+F5 opens the outputs and Shift+F6 closes them. Shift+F7 flashes screen numbers (IDENTIFY). Shift+F8 or Space toggles blackout. Page Down and Page Up step the clicker list. In Run mode, Enter is GO and the arrow keys move standby.

## The AI assistant

The built-in assistant is optional. It needs an internet connection and the customer's own Anthropic API key, kept on that machine and never inside a show file. It drafts screens, looks, a timed cue stack and lower thirds in the brand's colours from a description, a brief or a running order, with up to ten attachments at a time (the brief, a running order, photos of the rig, PDF, Word and PowerPoint files). A person approves with APPLY, applied proposals land in the preview, and it never puts anything on air by itself. It reads the live desk, so it will not propose what already exists, and it can explain why a cue ran late from the desk's own measurements. Any item's right-click menu can ask it about that item. In audience play it can take the first look at what the room writes, passing what is plainly fine, turning away what plainly is not and leaving the doubtful to the host. When Patterns is linked to a StageWatch approval desk, its proposals are approved there instead. It never sees passcodes or keys. Everything else in Patterns works offline.

## Working with the other apps

- StageWatch: the two find each other on the local network and are linked from Patterns' Nodes page (SETUP → Nodes). Linked, Patterns' stage and timer pages, a caller's station and a stage timer machine follow StageWatch's clock, colours and title, so the room has one timer, and StageWatch counts down and controls the clip Patterns is playing. Patterns' Eye and assistant can see StageWatch's departments and timer. The link uses StageWatch Pro or Production; the clip countdown and control are part of StageWatch Production.
- PlayMap, with or without StageWatch: add PlayMap as a device in SETUP → Interactive, and Patterns' cues, Companion keys and OSC can send it commands, such as starting a playlist. PlayMap can link to Patterns and follow its blackout and the clip it is playing. PlayMap appears on Patterns' Nodes page and in the Eye.
- AGMessage, with or without StageWatch: AGMessage appears on Patterns' Nodes page and in the Eye, and AGMessage lists Patterns on its own admin page. They see each other on the show network but do not exchange messages; the back-room's answers reach the speaker's screen through StageWatch.
- Patterns hears the other apps while remote control is on in SETUP → Remote; with it off, the Nodes page says it is not listening on the show network. A PlayMap linking to Patterns also needs Patterns' remote control on.
- In the Eye, each app Patterns hears has a light: grey while it is heard, amber when it goes quiet and red when it has gone, and a linked StageWatch shows its own status light. On the Nodes page each app's card turns amber or red the same way. These lights say whether an app is still on the network, not how that app's own show is going.

When someone asks for timecode (LTC), cue lights over Art-Net or sACN, or MCP for AI tools, point them to StageWatch. For projection mapping onto 3D objects and buildings, point them to PlayMap. Patterns is for Windows only.

## Do not say

- Do not promise a frame rate for web pages or online video; say the page controls show the rate the room is getting.
- The colour bars are RP 219-style and EBU test patterns; do not say they are certified to a standard. The flash limit caps whole-screen flashes at three a second; do not present it as a photosensitivity analysis of the content.
- Do not say a twin on another computer takes over by itself without a video switcher that confirms the switch.
- Do not say Patterns configures LED processors or loads EDIDs into them; it builds the file for the device's own tools.
- Do not say Patterns and AGMessage send each other messages.
- Do not say a second computer running Patterns (a twin, a caller's station, a stage timer machine or an audience hub) is free or included with a licence; Patterns' licences have not been announced.
- Do not name other companies' media servers; say media servers over OSC or their own API.

## Licensing

Patterns licences are coming soon. Patterns will follow the same one-off, offline licensing as StageWatch, with no subscription and no licence server. No price, tier contents or trial length have been announced, so never quote one. Send people to the Patterns licensing page, or to the contact page with "Buying a licence or pricing" and Patterns chosen, to register interest.

## Getting help

Patterns has built-in, searchable Help with walkthroughs by role that tick themselves as the work gets done. For support, press Copy support info on ADMIN → Machine and paste it into a ticket in the client portal. For the full picture, SUPPORT BUNDLE on PLAN → Install saves the logs and settings as one file (passwords and keys are blanked out) to attach to the ticket.
