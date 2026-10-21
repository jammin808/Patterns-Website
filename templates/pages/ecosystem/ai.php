<?php
/**
 * AI with approval (/ecosystem/ai/): the principles, how a suggestion reaches the room (the amber gate as a static
 * cue ladder), what each app's AI does and sends, AI agents over MCP, and what this site's own assistant can and
 * cannot do. Facts: content/ecosystem.php 'ai' and content/faq.php (the assistant group, shown here as prose).
 *
 * @var \Psc\View\Seo $seo
 */
$eco = content('ecosystem');
$ai = $eco['ai'];
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/hub.css')) . '">');

$name = static fn (string $key): string => (string) (product($key)['name'] ?? ucfirst($key));
$icons = ['A person always decides' => 'approve', 'One place to decide' => 'ticket', 'Risk rated by the app, not the model' => 'alert',
    'Offline first' => 'bus', 'Your own key, on your machine' => 'key', 'Nothing in charge' => 'system', 'Every decision on the record' => 'doc'];
$privacy = [];
foreach ($ai['privacy'] as $pv) {
    $privacy[$pv['product']] = $pv['text'];
}
$assistant = [];
foreach (content('faq') as $g) {
    if ($g['id'] === 'assistant') {
        foreach ($g['items'] as $f) {
            $assistant[$f['q']] = $f['a'];
        }
    }
}

// How a suggestion reaches the room, restated from the principles and Show Bus' approvals point.
$steps = [
    ['title' => 'A suggestion arrives', 'text' => 'Patterns\' assistant, an AI tool over MCP or AGMessage\'s research team proposes a change. Nothing has happened yet.', 'where' => null, 'state' => 'done'],
    ['title' => 'The app rates the risk', 'text' => 'Each app rates its own commands by fixed rules. Anything it does not recognise counts as high risk and is never pre-ticked.', 'where' => null, 'state' => 'done'],
    ['title' => 'It waits for a person', 'text' => 'With StageWatch Pro or higher on the network, suggestions wait in one list for the show caller, with who suggested each one, which app it is for, why, and how risky it is.', 'where' => 'stagewatch', 'state' => 'standby'],
    ['title' => 'A person decides', 'text' => 'The show caller approves or discards each suggestion. Anything that would change what the audience sees is never pre-ticked.', 'where' => 'person', 'state' => 'waiting'],
    ['title' => 'The app checks and reports back', 'text' => 'The app runs what was approved through its own checks and reports back done, refused or not delivered.', 'where' => null, 'state' => 'waiting'],
    ['title' => 'It goes on the record', 'text' => 'StageWatch\'s show log and report record every AI suggestion, who decided and what happened.', 'where' => 'stagewatch', 'state' => 'waiting'],
];
?>
<section class="c-hero c-hero--page" aria-labelledby="ai-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <p class="c-eyebrow">Ecosystem</p>
            <h1 class="c-hero-title" id="ai-title">AI in show control, with a person always approving</h1>
            <p class="c-hero-lede"><?= e($ai['intro']) ?></p>
        </div>
    </div>
</section>

<section class="l-section l-section--flush" id="gate" aria-labelledby="gate-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">The approval gate</p><h2 id="gate-title"><?= e($ai['title']) ?></h2></div>
            <p class="c-lede">An AI's suggestion stops at an amber gate. It reaches the room only after a person says yes, and the app still runs its own checks.</p>
        </div>
        <figure class="hub-gate">
            <div class="c-flow-node c-flow-node--ai"><h3>AI tools and assistants</h3><p>Read the show, propose a change</p></div>
            <span class="c-flow-wire c-flow-wire--proposal hub-gate-wire">Proposal</span>
            <div class="c-flow-gate"><h3><?= icon('approve', 'c-icon--sm') ?>A person approves</h3><p>Approve or discard, in one list</p><span class="c-flow-gate-state"><i class="c-lamp c-lamp--standby" aria-hidden="true"></i>Standing by for you</span></div>
            <span class="c-flow-wire hub-gate-wire">Approved</span>
            <div class="c-flow-node hub-gate-app"><h3>The app's own checks</h3><p>Then done, refused or not delivered</p></div>
            <figcaption class="c-flow-note">Letting an AI act directly is only ever a choice an operator makes, and PlayMap's high-risk actions wait for a person whatever the settings.</figcaption>
        </figure>
        <div class="c-ladder c-ladder--static hub-ladder-untimed u-mt-8">
            <div class="c-ladder-head" aria-hidden="true"><span>Step</span><span>What happens</span><span>Where</span></div>
            <ol class="c-ladder-list" role="list">
                <?php foreach ($steps as $i => $st): ?>
                    <li class="c-cue"<?= $st['state'] === 'standby' ? ' data-state="standby"' : '' ?>>
                        <span class="c-cue-q"><?= e($i + 1) ?></span>
                        <div class="c-cue-body"><h3><?= e($st['title']) ?></h3><p><?= e($st['text']) ?></p></div>
                        <div class="c-cue-meta"><?php if ($st['where'] === 'person'): ?><span class="c-app" data-product="ecosystem"><?= icon('approve', 'c-icon--sm') ?>The show caller</span><?php elseif ($st['where'] !== null): ?><span class="c-app" data-product="<?= e($st['where']) ?>"><i class="c-swatch" aria-hidden="true"></i><?= e($name($st['where'])) ?></span><?php else: ?><span class="c-app" data-product="ecosystem"><i class="c-swatch" aria-hidden="true"></i>Every app</span><?php endif; ?></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" id="principles" aria-labelledby="principles-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">Principles</p><h2 id="principles-title">The rules the AI works within</h2></div>
            <p class="c-lede">The same principles run through all four apps, so the show never depends on a model.</p>
        </div>
        <div class="c-features">
            <?php foreach ($ai['principles'] as $pr): ?>
                <article class="c-feature"><?= icon($icons[$pr['title']] ?? 'approve') ?><h3><?= e($pr['title']) ?></h3><p><?= e($pr['text']) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section" id="apps" aria-labelledby="apps-title">
    <div class="l-wrap">
        <div class="c-section-head">
            <div><p class="c-eyebrow">In each app</p><h2 id="apps-title">What the AI does, and what it sends</h2></div>
            <p class="c-lede">Each app's AI is optional. Here is what it does, and what leaves the machine when you use it.</p>
        </div>
        <div class="l-grid l-grid--2">
            <?php foreach ($ai['items'] as $it): $k = (string) $it['product']; ?>
                <article class="c-card" data-product="<?= e($k) ?>">
                    <span class="c-card-meta"><span class="c-app"><i class="c-swatch" aria-hidden="true"></i><?= e($name($k)) ?></span></span>
                    <h3 class="c-card-title"><?= e($it['title']) ?></h3>
                    <p class="c-card-body"><?= e($it['text']) ?></p>
                    <?php if (isset($privacy[$k])): ?>
                        <div class="c-card-footer hub-sends"><?= icon('lock', 'c-icon--sm') ?><p><b>What it sends.</b> <?= e($privacy[$k]) ?></p></div>
                    <?php endif; ?>
                    <p class="c-links"><a href="/<?= e($k) ?>/features/"><?= e($name($k)) ?> features</a></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="l-section l-section--tint" id="mcp" aria-labelledby="mcp-title">
    <div class="l-wrap l-split">
        <div>
            <p class="c-eyebrow">MCP</p>
            <h2 id="mcp-title"><?= e($ai['agents']['title']) ?></h2>
        </div>
        <div class="l-stack">
            <p class="c-lede"><?= e($ai['agents']['text']) ?></p>
            <ul class="c-chips" role="list">
                <li class="c-chip"><span class="c-chip-mark" aria-hidden="true">MCP</span><span>StageWatch<small>Production; agents propose by default</small></span></li>
                <li class="c-chip"><span class="c-chip-mark" aria-hidden="true">MCP</span><span>PlayMap<small>High-risk commands wait for a person</small></span></li>
            </ul>
            <p class="hub-more u-mt-0"><a href="/ecosystem/integrations/#cat-ai">AI integrations, app by app</a></p>
        </div>
    </div>
</section>

<section class="l-section" id="assistant" aria-labelledby="assistant-title">
    <div class="l-wrap l-split l-split--even">
        <div>
            <p class="c-eyebrow">This website</p>
            <h2 id="assistant-title">The assistant on this site</h2>
            <p class="c-lede u-mt-4">The support assistant follows the same idea: it answers and points the way, and a person handles anything that matters.</p>
            <a class="c-launcher u-mt-6" href="/contact/" role="button" data-chat-open aria-controls="chat-panel" aria-expanded="false"><span class="c-launcher-icon"><?= icon('chat') ?></span><span><b>Ask the assistant</b><small>What the apps do, licences, downloads and setup</small></span></a>
        </div>
        <dl class="c-specs c-specs--rows hub-rates">
            <?php foreach (['What it helps with' => 'What can the support assistant help with?', 'What it can\'t do' => 'What can\'t the assistant do?', 'What not to share' => 'Should I share licence files, hardware IDs or passwords with it?', 'How long chats are kept' => 'Are my conversations with the assistant kept?', 'When to ask a person' => 'When should I ask a person instead?'] as $label => $q): if (!isset($assistant[$q])) { continue; } ?>
                <div><dt><?= e($label) ?></dt><dd><p><?= e(ucfirst((string) preg_replace('/^(Yes|No)[,.]\s*/', '', (string) $assistant[$q]))) ?></p></dd></div>
            <?php endforeach; ?>
            <div><dt>Your data</dt><dd><p><a href="/legal/privacy/">Read how the assistant handles your data</a></p></dd></div>
        </dl>
    </div>
</section>

<?= $this->partial('hub/cta', [
    'id' => 'cta-title',
    'title' => 'Faster with AI. Never run by it.',
    'text' => 'Everything in the ecosystem works without AI. Turn it on where it saves you time, and keep every decision.',
    'actions' => [
        ['label' => 'Explore the ecosystem', 'href' => '/ecosystem/', 'style' => 'primary'],
        ['label' => 'Ask the assistant', 'href' => '/contact/', 'style' => 'secondary', 'chat' => true],
    ],
]) ?>
