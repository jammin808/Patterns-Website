# Patterns — assistant notes

Patterns is a portable Windows app that puts pixel-exact test patterns and show content on every LED wall, projector, display and NDI receiver in the room, with a vision-mixer style desk to run the show. Use that sentence when someone asks what Patterns is.

Patterns is the picture app of the Patterns ecosystem, beside StageWatch (the stage timer and show-calling desk), AGMessage (private back-room messaging for AGMs and board meetings) and PlayMap (media playback and projection mapping). Its screens include LED walls, video walls, projectors, TVs, NDI receivers and live streams.

## What it is

- One portable Windows app for Windows 10 and 11 (64-bit). There is no installer and no runtime to install, and it needs no admin rights to run.
- Run it from any folder you can write to, including a USB stick or the desktop. Settings, presets, brand kits and show media live beside it, so copying the folder moves the whole show.
- A whole rig and show saves as one show file that travels with you.
- There are two downloads: the standard one, and a full download that includes the video engine. With the standard download, install 64-bit VLC media player to play video. Images work with either.
- NDI needs the free NDI Runtime from the NDI website. Without it, Patterns runs normally with NDI switched off.
- Downloads come from the client portal; a client account is free.

## Key ideas to explain

- The desk shows PROGRAM (what the audience sees) above PREVIEW (what you are building), with a tile for every screen.
- EDIT SAFE is on from the start, so edits stay in the preview until you press TAKE (with a transition) or CUT (instant). Things you fire, such as looks, cues, stingers and remote commands, go straight to air.
- A look is a saved picture you can recall on F1 to F12. LOOK BACK returns to the previous look.
- The cue stack is the caller's running order, run from the RUN layout with ARM, GO, HOLD and STOP ALL. GO checks a cue first and says why if it will not fire.
- The header mode PREP keeps the outputs closed while you prepare on planned screens; SHOW lets them open; RUN is the caller's layout. At the venue, Adopt maps each planned screen onto its real display.

## Main features, in brief

- Test patterns: grids, colour bars (SMPTE RP 219-style, EBU, 75% and 100%), ramps, checkerboards, focus and motion charts, and the Patterns test card, at any resolution.
- LED walls and video walls: panel sizes, serpentine, linear or row-column numbering, irregular LED maps, bezels and gaps, joined canvases, per-screen trims and screen roles.
- Signal checks: each screen can say what its cable should carry and shows MATCH or MISMATCH; SAVE KNOWN GOOD records the working rig.
- Projection: automatic edge blend, blend grids, a blend audit, black-level matching, keystone, edge bends, a mesh warp and optional camera calibration. The Projection blend pattern lines up 2 to 12 projectors.
- Show graphics: countdowns, clocks, messages, tickers, weather, brand kits, a lower-thirds designer with a people library, particle scenes, fractals, sound-reactive scenes, six transitions, stingers and effect stings.
- Media: images, video and audio playlists with show parts, PDF decks, PowerPoint, Keynote and Impress files through LibreOffice, web pages such as YouTube and Google Slides, NDI feeds and capture cards.
- Sound: an audio playlist to any Windows outputs, VOGs that duck the music, a live DUCK, Spotify break music (needs Spotify Premium), routing with lip-sync delay.
- Outputs: as many NDI senders as needed (10-bit available), streaming to two destinations (RTMP, SRT or UDP), a display-mode picker with a safety revert, multiview monitor walls with tally.
- Permanent installs: a clock-run schedule of programmes, adverts and announcements for lobbies, shop windows and museums.

## Control

- A web remote for phones and tablets, with pages for the caller, the stage, the timer and the multiview. Switch it on in SETUP → Remote, which shows the address to open and the pairing token. Remote control is protected by that pairing token.
- A Bitfocus Companion 5 module for Stream Deck. Download the module, import it into Companion, pick the desk under "Desk on the network" and drag the presets onto the keys. Keys label themselves and show live tally.
- OSC, MIDI LEARN from any right-click menu, Arduino and Raspberry Pi devices, projector control over PJLink, and a one-line-per-command TCP protocol and an HTTP API for integrators.
- Every screen tile, look, cue, lower third, layer and overlay has a right-click menu with the desk's own commands, in step with the buttons.

## Reliability

- A watchdog restarts Patterns within seconds after a crash or a hang and puts back what the audience was watching, with the outputs and audio.
- An optional standby twin: a second Patterns keeps in step with the show and can take over, then hand back.
- The show lock holds off notifications, other apps' sounds, sleep and pop-ups during the show.
- Super Check on ADMIN → Machine checks the machine and the show and rates it from rehearsal to big show.
- Every save keeps the previous show file and up to 20 timed copies: ADMIN → Machine → Earlier versions of the show → RESTORE.

## Common fixes

- Nothing on screen: check the header says SHOW, not PREP, then press OUTPUTS ON (Shift+F5), and check the screen is switched on in SETUP → Screens.
- An edit did not reach the room: press TAKE or CUT.
- Video will not play or the stream will not start: use the full download, or install 64-bit VLC media player.
- NDI missing: install the free NDI Runtime and restart Patterns.
- A phone or Stream Deck cannot connect: same network as the show PC, remote control on, the address and pairing token from SETUP → Remote, and Windows Firewall allowing Patterns on that network.
- A PowerPoint deck looks different: slides become still pages through LibreOffice, so install the deck's fonts and check it once; for animations, show PowerPoint for the web as a web page.
- Panic keys: Shift+F8 or Space is blackout. In Run mode, Esc twice stops all sounds and clips.

## Keys

F1 to F12 recall looks. Shift+F5 opens the outputs and Shift+F6 closes them. Shift+F7 flashes screen numbers (IDENTIFY). Shift+F8 or Space toggles blackout. Page Down and Page Up step the clicker list. In Run mode, Enter is GO and the arrow keys move standby.

## The AI assistant

The built-in assistant is optional. It needs an internet connection and the customer's own Anthropic API key. It drafts screens, looks, cues and lower thirds from a description, a brief or a running order. A person approves with APPLY, applied proposals land in the preview, and it never puts anything on air by itself. When Patterns is linked to a StageWatch approval desk, its proposals are approved there instead. Everything else in Patterns works offline.

## Working with the other apps

Patterns and StageWatch find each other on the local network. Linked, Patterns' stage and timer pages follow StageWatch's clock, and StageWatch counts down and controls the clip Patterns is playing. The link uses StageWatch Pro or Production; the clip countdown and control are part of StageWatch Production. Patterns' cues can send commands to PlayMap, and PlayMap follows Patterns' blackout. AGMessage appears on Patterns' show-network list.

When someone asks for timecode (LTC), cue lights over Art-Net or sACN, or MCP for AI tools, point them to StageWatch. For projection mapping onto 3D objects and buildings, point them to PlayMap. Patterns is for Windows only.

## Licensing

Patterns licences are coming soon. Patterns will follow the same one-off, offline licensing as StageWatch, with no subscription and no licence server. No price, tier contents or trial length have been announced, so never quote one. Send people to the Patterns licensing page, or to the contact page with "Buying a licence or pricing" and Patterns chosen, to register interest.

## Getting help

Patterns has built-in, searchable Help with walkthroughs by role. For support, press Copy support info on ADMIN → Machine and paste it into a ticket in the client portal. For the full picture, SUPPORT BUNDLE on PLAN → Install saves the logs and settings as one file (passwords and keys are blanked out) to attach to the ticket.
