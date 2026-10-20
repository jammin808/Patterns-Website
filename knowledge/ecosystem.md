# The Patterns ecosystem — assistant notes

The Patterns ecosystem is a family of four Windows show apps for live events, made in the UK by Ben Rohleder: Patterns for the picture, StageWatch for time, AGMessage for the people behind the meeting, and PlayMap for mapping and playback. Each app works on its own; on the same venue network they find each other and work together. The family is called "the Patterns ecosystem" and the shared network language is called Show Bus. "Patterns" is both the family's name and one of the apps: say "the Patterns ecosystem" for the family and "Patterns" for the app.

## One owner for every part of the show

- Each part of the show has exactly one owner, and the other apps follow it instead of keeping a copy. Two countdowns can never disagree, because there is only one countdown.
- Time lives in StageWatch: the show clock, running order, speaker cues, department STANDBY and GO, curfews and confidence monitors.
- Picture lives in Patterns: what is on air, the outputs, test patterns, video playout and the standby twin.
- People live in AGMessage: the private channel between the directors on stage and the research team in the back-room, with documents, action prompts and decisions.
- Mapping and playback live in PlayMap: playlists, mapped surfaces, projectors, LED fixtures and sync groups of PlayMap machines.
- Start with one app and add the others when a show needs them. Nobody needs all four.

## Show Bus, in plain words

- The apps find each other automatically on the local network: each announces itself every two seconds, so there are no addresses to type. The announcement says who an app is and where to reach it, never what the show is doing.
- Each app shares a status light (green, amber, red, or grey for unknown) and a one-line headline. Lights and headlines come from fixed rules, not from AI, so they are the same with or without AI, and unknown stays unknown.
- StageWatch's Producer, then Show network, lists Patterns, AGMessage and other StageWatches with their lights, headlines and problems. Patterns lists the apps it hears on its Nodes page and in the Eye. AGMessage lists them on its admin page. PlayMap lists them in its Network drawer. Other apps see a PlayMap as an app heard on the network; drawing PlayMap in full on Patterns' Eye is coming.
- Show data travels only on links that have been paired with a key or a pairing token.
- Commands between apps come back with a receipt: done (with what changed), refused (with the reason) or accepted (queued, not yet confirmed). A command to an app that is not answering fails straight away; nothing is saved up to fire later.
- Countdowns travel as time remaining, so every screen counts the same seconds whatever its own clock says. CLOCKS APART means two computers' clocks differ by more than two seconds: countdowns stay right, but times of day may differ, so set both clocks correctly.
- Pages and links can run over HTTPS. Each install makes its own certificate, and each app has a certificate trust page that shows the fingerprint and walks Windows, Mac, iPhone, iPad and Android devices through trusting it once. Patterns, StageWatch and AGMessage can also refuse unencrypted connections from other devices with Require HTTPS.
- Links between apps remember the certificate they first trusted. CERTIFICATE CHANGED means a linked app now shows a different one, for example after a reinstall or on a new machine; the link stays off until the operator accepts it, which they should do only if they know why it changed.
- StageWatch uses named API keys that can be scoped and revoked. Patterns, PlayMap and their Companion modules pair with a token they prove without ever sending it across the network.
- AGMessage shares only counts, the meeting's state and the titles of open action prompts. Other apps never see AGMessage's message text, names, room names or codes, or documents.
- If an app does not appear: check both computers are on the same network and that Windows Firewall allows the app on private networks. Questions about network ports or other venue IT details go in a support ticket, so the answer can fit that venue.

## How the apps work together today

- StageWatch and Patterns: StageWatch's VT countdown follows the clip on air in Patterns, through pause and playback speed. Last 10 s jumps the clip to its final seconds, StageWatch can play, pause, stop or seek it, and a clip ending can move the running order on. This needs StageWatch Production or the trial. In StageWatch it is set up in Settings, then Integrations, then Patterns, with Find.
- Patterns and StageWatch: linked from Patterns' Nodes page, Patterns' stage and timer pages follow StageWatch's clock, colours and title ("one room, one timer"). Pause and add time are done in StageWatch. Patterns' Eye and assistant can see StageWatch's departments and timer.
- AGMessage and StageWatch: AGMessage is linked from its admin page (Show network) with StageWatch's address and an API key made in StageWatch's Settings, then Remote, then API keys. Everyone in the meeting then sees the live stage timer. The research team can send an answer to the speaker's confidence monitor and see when it is on screen (StageWatch Pro or higher), or propose it to the show caller to approve in StageWatch. Seeing the speaker acknowledge that exact answer needs StageWatch Production. The show caller can send an approved notice, such as "Break in 5 minutes", to every open meeting. StageWatch shows AGMessage's status and open action prompts.
- Patterns and PlayMap: add PlayMap as a device in Patterns' Interactive area, and Patterns' cues, Companion keys and OSC can fire PlayMap's playlists. PlayMap follows Patterns' blackout by default. Adding PlayMap as a Patterns device in one click is coming.
- StageWatch and PlayMap: point StageWatch's Patterns link at the PlayMap machine and the VT countdown follows PlayMap's playlist, with StageWatch's video buttons driving playback (Production). StageWatch triggers can call PlayMap commands. PlayMap can map StageWatch's timer onto any surface: countdown, phase colours, messages and holding images (Pro or higher on the StageWatch PC). StageWatch finding PlayMap for VT by itself is coming.
- PlayMap and PlayMap: machines in a sync group follow one leader's show clock, transports and air. A hot backup mirrors the main machine, and missing media is fetched from another PlayMap that has it.
- StageWatch Slides: the same StageWatch app, run on a lectern or playback PC as a presenter PC. To set one up, install StageWatch on that PC and tick the presenter-PC option in the installer, or open StageWatch Slides (presenter PC) from the Start menu. It sends the PowerPoint slideshow's position to the main and backup StageWatch. It needs no licence of its own; the StageWatch PC needs Pro or higher.
- StageWatch's network features, which the other apps' links use, come with Pro and Production (and the trial). Free and Standard do not include them.

## Which app supports what

Use this to answer "does it work with…" questions. If an app is not listed for something, it does not support it today.

- Bitfocus Companion and Stream Deck: Patterns, StageWatch and PlayMap each have their own module, and all three load into one Companion. Patterns and StageWatch need Companion 5; PlayMap's module needs 5.1 or newer. StageWatch's module comes with StageWatch and needs Pro or higher. AGMessage has no Companion module; it runs in the browser. One module for the whole network is coming.
- Phones, tablets and browsers: all four. Patterns has a phone remote and caller, stage and timer pages; StageWatch has speaker, crew, producer and audience pages; AGMessage runs entirely in the browser; a phone's browser can be PlayMap's scan camera.
- MIDI: Patterns (learn from any right-click menu, with pad lights and motorised faders) and PlayMap (bindings with a learn mode, and MIDI Show Control). StageWatch and AGMessage have no MIDI control.
- OSC: Patterns, StageWatch and PlayMap. Off by default in Patterns and StageWatch.
- HTTP, WebSocket and TCP control: Patterns (TCP and HTTP), StageWatch (HTTP and WebSocket), PlayMap (all three). Do not give command syntax; point integrators to a support ticket.
- NDI: Patterns (in and out, 10-bit available), StageWatch (out), PlayMap (in and out, NDI 6). Each needs the free NDI Runtime on that PC. AGMessage has no NDI.
- OMT and Spout: PlayMap only.
- Vision mixers and keyed output: StageWatch. vMix and OBS: StageWatch as a browser input or over NDI, and the vMix VT countdown with Production.
- Streaming (RTMP, SRT, UDP): Patterns only.
- Capture cards: Patterns takes HDMI and SDI capture cards and webcams as live inputs; PlayMap plays capture devices as media. Passing capture cards straight through PlayMap to the air is coming.
- Art-Net and sACN: StageWatch drives cue lights (output only, Production); PlayMap takes control in from a lighting desk and drives LED pixels over Art-Net 4, sACN or DDP. Patterns has no Art-Net or sACN.
- Philips Hue: StageWatch cue lights (Production).
- Timecode: StageWatch reads and sends LTC (Production); PlayMap playlists chase LTC, MTC or Art-Net timecode. Patterns has no timecode today; timecode in to fire Patterns cues is coming.
- Projector control: Patterns over PJLink; PlayMap over PJLink plus Christie, Panasonic, Barco Pulse and Epson network control.
- PowerPoint: StageWatch reads the running slideshow with no add-in (Pro), and StageWatch Slides covers presenter PCs. Patterns converts PowerPoint, Keynote and Impress files to still pages through LibreOffice Impress, and shows PowerPoint for the web as a page. AGMessage can share a PowerPoint file, but shows it on stage only when saved as PDF. PlayMap has no PowerPoint support.
- Documents: AGMessage pushes PDF, Word, Excel, CSV, image and text files to the stage. Patterns shows PDF decks.
- MCP for AI tools: StageWatch (Production) and PlayMap. Patterns and AGMessage have no MCP endpoint; they share a read-only status summary that other apps' assistants can read.
- Local AI models: PlayMap's assistant only.

## AI across the ecosystem

- Everything works without AI; AI makes it faster. AI never holds authority over the show: it proposes, and a person decides.
- Out of the box, nothing an AI suggests runs until a person approves it, and anything that would change what the audience sees is never pre-ticked. Risk is rated by each app's own rules, never by the model, and anything unknown counts as high risk. In StageWatch an operator can choose to let MCP agents act directly, and in PlayMap whether low-risk steps run directly is the operator's choice; PlayMap's high-risk actions always wait for a person. So say "nothing runs until a person approves it, unless the operator chooses otherwise", never "impossible".
- With a StageWatch on the network (Pro or higher, or the trial, because the other apps reach it through its network features), it is the show's approval desk: suggestions from Patterns' assistant, from AI tools connected to StageWatch and from AGMessage's research team (messages proposed for the speaker's screen) wait in StageWatch's Copilot list. Each shows who suggested it, which app it is for, why and how risky it is. Approve or discard once; the target app runs approved items through its own checks and reports done, refused or not delivered. With no StageWatch on the network, each app approves its own.
- PlayMap's own assistant keeps its suggestions on PlayMap's own approval list; it does not send them to StageWatch.
- Cloud AI uses Claude by Anthropic with the customer's own API key, added on their own machine. AI usage is between the customer and Anthropic, on the account that owns the key. PlayMap's assistant can also run on a local model, such as Ollama, LM Studio or llama.cpp.
- Without the internet, cloud AI buttons grey out with a reason and the show runs exactly as before. StageWatch's rule-based assistant and forecast (Production), PlayMap's click-to-surface and PlayMap's assistant on a local model work offline.
- Patterns' assistant drafts screens, looks, cues and lower thirds, and nothing changes until APPLY; it sees names and counts and any material attached, never file paths, network addresses, passcodes or keys.
- StageWatch's copilot (Production, Ctrl+K) turns plain English into a plan of StageWatch's own commands, with a reason and a risk for each step; nothing runs until it is ticked.
- AGMessage's Draft answer, Suggest and Minutes are drafts that a person edits and sends; the transcript marks them "drafted with AI, sent by a person".
- StageWatch's show log and report record every AI suggestion, who decided and the result.

## Reliability, in customer terms

- No internet and no cloud are needed to run a show. Only cloud AI, AGMessage's remote access for people off site, StageWatch's Google Sheets import and internet time checks need a connection.
- Patterns runs from a USB stick with no install and no admin rights. AGMessage and PlayMap are portable folders. StageWatch installs "only for me" without admin rights.
- Patterns, StageWatch and AGMessage restart themselves after a crash or hang and put the show back where it was. PlayMap's engine runs apart from its desk, so closing the desk never blanks a projector.
- Second machines: a Patterns twin follows the whole show and takes over on TAKE OVER NOW, then hands back; a StageWatch Production licence covers a main and a backup PC (switching to the backup is the operator's decision; automatic takeover is coming); a PlayMap hot backup takes the air with FAILOVER.
- Never promise "zero downtime", "frame-perfect on any network", "field-proven" or "certified". Describe what the features do.

## Requirements in one place

- Patterns: 64-bit Windows 10 or 11, portable. StageWatch: 64-bit Windows 10 or 11, installer. AGMessage: hosted on a 64-bit Windows laptop, joined from any modern browser. PlayMap: 64-bit Windows, portable, in early access.
- Start from the Downloads page on this site. Signed-in clients get every build open to them in the client portal's Downloads area; a client account is free.
- When Windows Firewall asks, allow the app on private networks.

## Licensing in one place

- StageWatch is on sale: a 14-day trial, a free watermarked timer after it, and one-off licences in GBP. The StageWatch licensing page has the prices; quote only those.
- Patterns and PlayMap will follow StageWatch's one-off, offline model; their prices are not announced. PlayMap's early-access build is in the client portal now.
- AGMessage is delivered as a managed service for each AGM, quoted on application. There is no AGMessage licence to request in the portal; anyone who wants to run it on their own laptop should say so on the contact page (sales topic). Do not say self-run licences are coming.
- Never guess a price, a date or a discount that is not on a licensing page. For pricing questions not covered, point to the contact page with the sales topic.

## Coming to the ecosystem (not available yet)

Always call these coming:

- Linking every app in one setup pass.
- One preflight across the whole network.
- One show file built from one agenda, creating StageWatch's running order, Patterns' cues and AGMessage's meeting rooms together.
- One merged show report across the apps.
- A backup StageWatch that takes over the show clock by itself.
- One Companion module for the whole network.
- PlayMap drawn in full on Patterns' Eye, added as a Patterns device in one click, and found by StageWatch for video countdowns by itself.
- A tech-check mode that rehearses failures on purpose.

## Do not say

- Do not say the apps share one show file, one Companion module, one network-wide preflight, a merged report or automatic StageWatch failover today.
- Do not say Patterns or AGMessage have an MCP endpoint, that AGMessage has a Companion module, OSC, MIDI or NDI, or that Patterns has timecode, Art-Net or sACN.
- Do not compare the apps with other companies' products or name competitors.
