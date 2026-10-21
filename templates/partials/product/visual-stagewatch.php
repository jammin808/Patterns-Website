<?php
/**
 * StageWatch's hero visual: the confidence monitor (docs/DESIGN.md 8.18), driven by countdown.js. The page pushes the
 * script; without it the monitor shows its starting values and reads as a still. The labels are a sample show.
 */
?>
<div class="c-hero-visual" data-countdown-scope>
    <div class="c-cm" data-countdown="75" data-warn="60" data-overrun="12" data-state="go" role="img" aria-label="StageWatch confidence monitor: a keynote countdown, turning amber to wrap up and red when it runs over">
        <div class="c-cm-top"><span class="c-cm-room">Main stage</span><span class="c-cm-onair">ON AIR</span><span class="c-cm-clock" data-countdown-clock>14:32:07</span></div>
        <div class="c-cm-main">
            <p class="c-cm-seg">Keynote <span>Opening address</span></p>
            <p class="c-cm-time" data-countdown-time>01:15</p>
            <p class="c-cm-msg" data-countdown-msg></p>
            <div class="c-cm-bar"><span class="c-cm-fill" data-countdown-fill></span></div>
        </div>
        <div class="c-cm-foot"><span>Next</span><b>Panel: the hybrid AGM</b><span class="c-cm-clock">14:45</span></div>
    </div>
    <div class="c-depts" role="group" aria-label="Department cue lights">
        <div class="c-dept" data-dept data-standby-at="15" data-go-at="0"><b>LX</b><span><i class="c-lamp" aria-hidden="true"></i><span data-dept-state>Clear</span></span></div>
        <div class="c-dept" data-dept data-standby-at="45" data-go-at="0"><b>SND</b><span><i class="c-lamp" aria-hidden="true"></i><span data-dept-state>Clear</span></span></div>
        <div class="c-dept" data-dept data-standby-at="30" data-go-at="0"><b>VT</b><span><i class="c-lamp" aria-hidden="true"></i><span data-dept-state>Clear</span></span></div>
        <div class="c-dept" data-dept data-go-at="9999" data-cue="go"><b>CAM</b><span><i class="c-lamp" aria-hidden="true"></i><span data-dept-state>Go</span></span></div>
    </div>
    <div class="c-cm-controls">
        <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="pause" aria-pressed="false"><?= icon('pause', 'c-icon--sm') ?><span data-label>Pause</span></button>
        <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="minus" aria-label="Take a minute off">−1:00</button>
        <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="plus" aria-label="Add a minute">+1:00</button>
        <button class="c-btn c-btn--ghost c-btn--sm" type="button" data-countdown-action="reset"><?= icon('reset', 'c-icon--sm') ?>Reset</button>
        <ul class="c-cm-legend" role="list">
            <li><i class="c-lamp c-lamp--go" aria-hidden="true"></i>On time</li>
            <li><i class="c-lamp c-lamp--standby" aria-hidden="true"></i>Wrap up at 1:00</li>
            <li><i class="c-lamp c-lamp--pgm" aria-hidden="true"></i>Over time</li>
        </ul>
    </div>
</div>
