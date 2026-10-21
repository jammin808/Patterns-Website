<?php
/**
 * The cookie choice (docs/DESIGN.md 8.35): asks once whether Google Analytics may set its statistics cookies, before
 * any of them is set. Rendered hidden on every page that carries the Google tag; site.js (initConsent) shows it while
 * the visitor has no answer from the last year, records the answer, and reopens it from any [data-consent-open]
 * ("Cookie settings" in the footer, the cookie notice). Accept and reject are the same size, style and weight, and
 * saying no changes nothing else on the site.
 *
 * @var string $gaId the measurement ID whose gtag.js loads after acceptance
 */
?>
<section class="c-consent" data-consent data-ga="<?= e($gaId) ?>" aria-labelledby="consent-title" hidden>
    <div class="c-consent-card">
        <div class="c-consent-text">
            <h2 class="c-consent-title" id="consent-title">Can we count your visit?</h2>
            <p>With your permission, Google Analytics sets cookies that show us which pages help people. It never tells us who you are, and the site works the same if you say no. <a href="/legal/cookies/">Read the cookie notice</a>.</p>
        </div>
        <div class="c-consent-actions">
            <button type="button" class="c-btn c-btn--secondary" data-consent-choice="granted">Accept statistics cookies</button>
            <button type="button" class="c-btn c-btn--secondary" data-consent-choice="denied">Reject statistics cookies</button>
        </div>
    </div>
</section>
