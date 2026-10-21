<?php
/**
 * AGMessage's hero visual: the meeting as the research team and the board see it, drawn in HTML so its words stay
 * readable at every width. Left, the research team's conversation with the chair, an action prompt the board
 * answers with a tap and an AI draft standing by for a person; right, the directors' On stage panel with the
 * document the team pushed. It is a picture (role="img"); the labels are a sample meeting.
 */
?>
<div class="c-hero-visual">
    <div class="c-pd-agm c-screen" role="img" aria-label="AGMessage during an AGM: the research team tells the chair who takes the pension question, a Yes, No or Defer prompt waits for the board's tap, an AI draft waits for a person to send it, and the pension scheme document is open on the directors' On stage panel">
        <div class="c-pd-agm-top">
            <span class="c-pd-agm-name"><span class="c-swatch"></span>AGMessage</span>
            <span class="c-pd-agm-room">Annual general meeting</span>
            <span class="c-pd-agm-code">K7P2QX</span>
        </div>
        <div class="c-pd-agm-panes">
            <div class="c-pd-agm-chat">
                <p class="c-pd-agm-pane">Research team</p>
                <div class="c-pd-agm-msg"><b>Chair</b>Who takes the pension question?</div>
                <div class="c-pd-agm-msg c-pd-agm-msg--out"><b>Research</b>The finance director. The figures are on your On stage panel, page 14.</div>
                <div class="c-pd-agm-prompt">
                    <p><b>Board</b>Take questions on the scheme now?</p>
                    <div class="c-pd-agm-keys"><span class="is-picked">Yes</span><span>No</span><span>Defer</span></div>
                    <p class="c-pd-agm-seen">Chair chose Yes</p>
                </div>
                <div class="c-pd-agm-draft"><i class="c-lamp c-lamp--standby"></i>AI draft waiting for a person to send</div>
            </div>
            <div class="c-pd-agm-stage">
                <p class="c-pd-agm-pane">On stage</p>
                <div class="c-pd-agm-doc">
                    <span class="c-pd-agm-doc-h"></span>
                    <span></span><span></span><span class="is-short"></span>
                    <span class="c-pd-agm-doc-fig"></span>
                    <span></span><span class="is-short"></span>
                </div>
                <p class="c-pd-agm-cap">Pension scheme, page 14</p>
                <p class="c-pd-agm-meta">Pushed 14:31</p>
            </div>
        </div>
    </div>
</div>
