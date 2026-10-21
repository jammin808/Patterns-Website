<?php
/**
 * The signal flow (docs/DESIGN.md 8.16): an operator's controllers drive the apps, an AI tool's proposal stops at
 * the amber approval gate, Show Bus joins the apps on the venue network, and each app feeds the room. Every line
 * restates content/ecosystem.php (show_bus, integrations, ai); the apps' rows come from content/products/.
 */
$apps = [
    'patterns' => ['line' => 'The picture: outputs, looks and cues', 'out' => 'LED walls, projectors, displays, NDI receivers'],
    'stagewatch' => ['line' => 'The time: the clock and the running order', 'out' => 'Confidence monitors, vision mixers, phones and TVs'],
    'agmessage' => ['line' => 'The people: the back-room line to the stage', 'out' => 'Directors\' phones and tablets'],
    'playmap' => ['line' => 'Playback and mapping: playlists and surfaces', 'out' => 'Walls, objects, buildings and LED fixtures'],
];
?>
<figure class="c-flow-wrap">
    <div class="c-flow">
        <p class="c-flow-h c-flow-h--in">Inputs</p>
        <p class="c-flow-h c-flow-h--bus" aria-hidden="true">Bus</p>
        <p class="c-flow-h c-flow-h--apps">Apps</p>
        <p class="c-flow-h c-flow-h--room">To the room</p>
        <ul class="c-flow-inputs" role="list">
            <li class="c-flow-node c-flow-node--ctl"><h3><?= icon('deck', 'c-icon--sm') ?>Companion and Stream Deck</h3><p>Keys that label themselves and light from live state</p></li>
            <li class="c-flow-node c-flow-node--ctl"><h3>OSC and MIDI</h3><p>Lighting desks, show controllers and MIDI surfaces</p></li>
            <li class="c-flow-node c-flow-node--ctl"><h3>Phones and tablets</h3><p>Remotes and crew pages in any browser</p></li>
            <li class="c-flow-proposal">
                <div class="c-flow-node c-flow-node--ai"><h3>AI tools over MCP</h3><p>Read the show, propose a change</p></div>
                <span class="c-flow-wire c-flow-wire--proposal">Proposal</span>
                <div class="c-flow-gate"><h3><?= icon('approve', 'c-icon--sm') ?>A person approves</h3><p>Approve or discard each suggestion</p><span class="c-flow-gate-state"><i class="c-lamp c-lamp--standby" aria-hidden="true"></i>Standing by for you</span></div>
            </li>
        </ul>
        <div class="c-flow-bus"><b>Show Bus</b><span>Discovery, live status, receipts and approvals on the venue network. No internet needed.</span></div>
        <ul class="c-flow-apps" role="list">
            <?php foreach ($apps as $key => $a): $p = product($key); ?>
                <li class="c-flow-app" data-product="<?= e($key) ?>"><div class="c-flow-node c-flow-node--app"><h3><span class="c-swatch" aria-hidden="true"></span><?= e($p['name'] ?? $key) ?></h3><p><?= e($a['line']) ?></p></div><p class="c-flow-out"><?= e($a['out']) ?></p></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <figcaption class="c-flow-note u-mt-4">Out of the box, anything an AI tool proposes stops at the approval gate until a person decides. Controllers in an operator's hands drive the apps directly, and every app runs its own checks before anything changes.</figcaption>
</figure>
