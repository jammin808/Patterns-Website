<?php /* The drawn patterns screen for a multiview tile (docs/DESIGN.md 8.12), copied from design/kitchen-sink.html. Decorative: the tile's text says what it shows. */ ?>
<svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice">
<defs><pattern id="hub-hatch" width="15" height="15" patternUnits="userSpaceOnUse" x="40" y="35"><path d="M15 0V15M0 15H15" stroke="#E8ECF1" stroke-opacity=".55" stroke-width=".8" fill="none"/></pattern></defs>
<rect width="320" height="200" fill="#05070A"/>
<g transform="translate(40 35)"><rect width="240" height="120" fill="#0A0D12"/><rect width="240" height="120" fill="url(#hub-hatch)"/><path d="M0 0H240V120H0Z M80 0V120 M160 0V120 M0 60H240" stroke="#05070A" stroke-width="2.5" fill="none"/><path d="M120 0V120M0 60H240" stroke="#FFF" stroke-width="1.2"/><circle cx="120" cy="60" r="54" stroke="#FFF" stroke-width="1.1" fill="none"/><circle cx="30" cy="30" r="15" stroke="#FFF" stroke-opacity=".8" fill="none"/><circle cx="210" cy="30" r="15" stroke="#FFF" stroke-opacity=".8" fill="none"/><circle cx="30" cy="90" r="15" stroke="#FFF" stroke-opacity=".8" fill="none"/><circle cx="210" cy="90" r="15" stroke="#FFF" stroke-opacity=".8" fill="none"/></g>
<rect x="38" y="33" width="244" height="124" fill="none" stroke="#18C8D8" stroke-width="1.2" stroke-dasharray="4 3"/>
<g fill="#18C8D8"><rect x="35" y="30" width="6" height="6"/><rect x="279" y="30" width="6" height="6"/><rect x="35" y="154" width="6" height="6"/><rect x="279" y="154" width="6" height="6"/></g>
<g font-family="JetBrains Mono, monospace" font-size="8.5" fill="#A7B0BC"><text x="40" y="172">CANVAS 5760×2400</text><text x="280" y="172" text-anchor="end">6 OUTPUTS</text></g>
<rect x="40" y="16" width="26" height="11" rx="1" fill="#FF4040"/><text x="53" y="24.5" font-family="JetBrains Mono, monospace" font-size="7.5" font-weight="700" text-anchor="middle" fill="#090C10">PGM</text>
</svg>
