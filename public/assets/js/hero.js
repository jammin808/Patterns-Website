/*
 * The home hero: a vision mixer that takes real test patterns from preview to programme.
 *   <script type="module" src="/assets/js/hero.js"></script>   (home page only)
 *
 * Markup hooks (see docs/DESIGN.md, "Hero mixer"):
 *   [data-hero]                      the mixer root
 *   canvas[data-hero-pgm]            programme monitor (16:10; its CSS background is an SMPTE bars poster)
 *   canvas[data-hero-pvw]            preview thumbnail
 *   [data-hero-pgm-name] [data-hero-pvw-name] [data-hero-tc]   UMD texts (server renders sensible defaults)
 *   input[data-hero-trans] (radios: dissolve | dip | wipe | push, Patterns' own transitions)  [data-hero-take]
 *   [data-hero-auto][aria-pressed]   the demo's pause and play for its automatic takes (WCAG 2.2.2)
 *
 * Budget: each pattern is drawn once per size into an offscreen canvas; a frame is at most two drawImage calls and
 * one fill, so a frame costs well under 2 ms.
 * The loop runs only while the mixer is on screen and the tab is visible, and between takes it only updates the
 * timecode. Reduced motion: no auto takes, TAKE is a cut, the timecode shows whole seconds.
 */

const root = document.querySelector('[data-hero]');
if (root) {
  try { start(root); } catch (err) { console.warn('[hero] not started:', err); }
}

function start(el) {
  const pgm = el.querySelector('canvas[data-hero-pgm]');
  if (!pgm || !pgm.getContext) return;
  const pvw = el.querySelector('canvas[data-hero-pvw]');
  const gP = pgm.getContext('2d', { alpha: false });
  const gV = pvw ? pvw.getContext('2d') : null;
  const $ = (s) => el.querySelector(s);
  const pgmName = $('[data-hero-pgm-name]');
  const pvwName = $('[data-hero-pvw-name]');
  const tcEl = $('[data-hero-tc]');
  const takeBtn = $('[data-hero-take]');
  const autoBtn = $('[data-hero-auto]');
  const reducedQ = matchMedia('(prefers-reduced-motion: reduce)');
  const pad = (n) => String(n).padStart(2, '0');

  let W = 0, H = 0, dpr = 1, cache = [];
  let cur = 0, nxt = 1, trans = null, lastTake = 0;
  let auto = !reducedQ.matches && (autoBtn ? autoBtn.getAttribute('aria-pressed') !== 'false' : true);
  let onScreen = true, raf = 0, timer = 0, lastTc = '';

  /* ------------------------------------------------------------------- the patterns */
  function bars(g, w, h) {
    const bw = w / 7, y1 = Math.round(h * 0.67), y2 = Math.round(h * 0.75);
    const top = ['#bfbfbf', '#bfbf00', '#00bfbf', '#00bf00', '#bf00bf', '#bf0000', '#0000bf'];
    const mid = ['#0000bf', '#131313', '#bf00bf', '#131313', '#00bfbf', '#131313', '#bfbfbf'];
    for (let i = 0; i < 7; i++) {
      const x = Math.round(i * bw), x2 = Math.round((i + 1) * bw);
      g.fillStyle = top[i]; g.fillRect(x, 0, x2 - x, y1);
      g.fillStyle = mid[i]; g.fillRect(x, y1, x2 - x, y2 - y1);
    }
    const bot = ['#00214c', '#ffffff', '#32006a', '#131313'], b4 = bw * 5 / 4;
    for (let i = 0; i < 4; i++) { const x = Math.round(i * b4), x2 = Math.round((i + 1) * b4); g.fillStyle = bot[i]; g.fillRect(x, y2, x2 - x, h - y2); }
    const pluge = ['#090909', '#131313', '#1d1d1d'];
    for (let i = 0; i < 3; i++) { const x = Math.round(5 * bw + i * bw / 3), x2 = Math.round(5 * bw + (i + 1) * bw / 3); g.fillStyle = pluge[i]; g.fillRect(x, y2, x2 - x, h - y2); }
    g.fillStyle = '#131313'; g.fillRect(Math.round(6 * bw), y2, w - Math.round(6 * bw), h - y2);
  }
  function hatch(g, w, h) {
    g.fillStyle = '#000'; g.fillRect(0, 0, w, h);
    const lw = Math.max(1, Math.round(dpr)), cols = 16, rows = 10;
    g.fillStyle = '#e6e6e6';
    for (let i = 0; i <= cols; i++) g.fillRect(Math.min(w - lw, Math.max(0, Math.round(i * w / cols - lw / 2))), 0, lw, h);
    for (let j = 0; j <= rows; j++) g.fillRect(0, Math.min(h - lw, Math.max(0, Math.round(j * h / rows - lw / 2))), w, lw);
    g.fillRect(Math.round(w / 2 - lw), 0, lw * 2, h); g.fillRect(0, Math.round(h / 2 - lw), w, lw * 2);
    g.strokeStyle = '#e6e6e6'; g.lineWidth = lw * 1.5;
    g.beginPath(); g.arc(w / 2, h / 2, h * 0.45, 0, Math.PI * 2); g.stroke();
    g.lineWidth = lw;
    for (const [cx, cy] of [[2, 2], [14, 2], [2, 8], [14, 8]]) { g.beginPath(); g.arc(cx * w / cols, cy * h / rows, h / rows, 0, Math.PI * 2); g.stroke(); }
    const d = Math.max(2, Math.round(2 * dpr));
    for (let i = 1; i < cols; i++) for (let j = 1; j < rows; j++) g.fillRect(Math.round((i + 0.5) * w / cols) - d / 2, Math.round((j + 0.5) * h / rows) - d / 2, d, d);
  }
  function cabinets(g, w, h) {
    const cols = 8, rows = 5, cw = w / cols, ch = h / rows, lw = Math.max(1, Math.round(dpr));
    g.textAlign = 'center'; g.textBaseline = 'middle';
    g.font = `700 ${Math.round(ch * 0.24)}px "JetBrains Mono", ui-monospace, monospace`;
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) {
      const x = Math.round(c * cw), y = Math.round(r * ch), x2 = Math.round((c + 1) * cw), y2 = Math.round((r + 1) * ch);
      g.fillStyle = `hsl(${(c * 45 + r * 22) % 360} 62% 30%)`; g.fillRect(x, y, x2 - x, y2 - y);
      g.strokeStyle = 'rgba(255,255,255,.25)'; g.lineWidth = lw;
      g.beginPath(); g.moveTo(x, y); g.lineTo(x2, y2); g.moveTo(x2, y); g.lineTo(x, y2); g.stroke();
      g.strokeStyle = '#fff'; g.strokeRect(x + lw / 2, y + lw / 2, x2 - x - lw, y2 - y - lw);
      g.fillStyle = '#fff'; g.fillText(`${String.fromCharCode(65 + r)}${c + 1}`, (x + x2) / 2, (y + y2) / 2);
    }
  }
  function zone(g, w, h) {
    // Rendered at a quarter of the pixels and scaled up: the rings stay smooth and the one-off cost stays small.
    const s = 2, zw = Math.ceil(w / s), zh = Math.ceil(h / s);
    const img = g.createImageData(zw, zh), d = img.data, cx = zw / 2, cy = zh / 2, k = 0.9 * Math.PI / zw;
    for (let y = 0; y < zh; y++) {
      const dy2 = (y - cy) * (y - cy);
      for (let x = 0; x < zw; x++) {
        const dx = x - cx, v = (128 + 127 * Math.cos(k * (dx * dx + dy2))) | 0, i = (y * zw + x) * 4;
        d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255;
      }
    }
    const tmp = document.createElement('canvas'); tmp.width = zw; tmp.height = zh;
    tmp.getContext('2d').putImageData(img, 0, 0);
    g.imageSmoothingQuality = 'high'; g.drawImage(tmp, 0, 0, w, h);
  }
  function ramps(g, w, h) {
    const bands = ['255,255,255', '255,0,0', '0,255,0', '0,0,255'], bh = Math.round(h * 0.14);
    bands.forEach((c, i) => { const gr = g.createLinearGradient(0, 0, w, 0); gr.addColorStop(0, '#000'); gr.addColorStop(1, `rgb(${c})`); g.fillStyle = gr; g.fillRect(0, i * bh, w, bh); });
    const y = 4 * bh, sh = Math.round((h - y) * 0.6), steps = 11;
    for (let s = 0; s < steps; s++) { const v = Math.round(255 * s / (steps - 1)), x = Math.round(s * w / steps), x2 = Math.round((s + 1) * w / steps); g.fillStyle = `rgb(${v},${v},${v})`; g.fillRect(x, y, x2 - x, sh); }
    const gr = g.createLinearGradient(0, 0, w, 0); gr.addColorStop(0, '#fff'); gr.addColorStop(1, '#000'); g.fillStyle = gr; g.fillRect(0, y + sh, w, h - y - sh);
    g.fillStyle = '#000'; for (let i = 1; i < 4; i++) g.fillRect(0, i * bh - Math.round(dpr / 2), w, Math.max(1, Math.round(dpr)));
  }
  function checker(g, w, h) {
    const n = 16, m = 10;
    for (let j = 0; j < m; j++) for (let i = 0; i < n; i++) {
      const x = Math.round(i * w / n), y = Math.round(j * h / m);
      g.fillStyle = (i + j) % 2 ? '#ebebeb' : '#000'; g.fillRect(x, y, Math.round((i + 1) * w / n) - x, Math.round((j + 1) * h / m) - y);
    }
  }
  const P = [
    { name: 'SMPTE bars 75%', draw: bars },
    { name: 'Crosshatch 16×10', draw: hatch },
    { name: 'LED cabinet map 8×5', draw: cabinets },
    { name: 'Zone plate', draw: zone },
    { name: 'Ramps and steps', draw: ramps },
    { name: 'Checkerboard 16×10', draw: checker },
  ];

  function frame(i) {
    if (!cache[i]) {
      const c = document.createElement('canvas'); c.width = W; c.height = H;
      P[i].draw(c.getContext('2d'), W, H);
      cache[i] = c;
    }
    return cache[i];
  }
  function size() {
    const r = pgm.getBoundingClientRect();
    if (!r.width || !r.height) return;
    dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    const w = Math.round(r.width * dpr), h = Math.round(r.height * dpr);
    if (w === W && h === H) return;
    W = w; H = h; pgm.width = W; pgm.height = H; cache = [];
    if (pvw) { pvw.width = Math.round(pvw.clientWidth * dpr) || 192; pvw.height = Math.round(pvw.width * 10 / 16); }
    if (trans) { trans = null; takeBtn?.removeAttribute('data-busy'); }
    gP.drawImage(frame(cur), 0, 0);
    drawPvw();
  }
  function drawPvw() {
    if (gV) { gV.imageSmoothingQuality = 'high'; gV.drawImage(frame(nxt), 0, 0, pvw.width, pvw.height); }
    if (pvwName) pvwName.textContent = P[nxt].name;
  }

  /* -------------------------------------------------------------------- transitions */
  const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

  function composite(t) {
    const a = frame(trans.from), b = frame(trans.to), kind = trans.kind;
    gP.globalAlpha = 1;
    if (kind === 'push') {
      // The new pattern pushes the old one off to the left.
      const x = Math.round(W * t);
      gP.drawImage(a, -x, 0);
      gP.drawImage(b, W - x, 0);
      return;
    }
    if (kind === 'dip') {
      // Through black: the old pattern fades down, the new one fades up.
      gP.drawImage(t < 0.5 ? a : b, 0, 0);
      gP.fillStyle = '#000'; gP.globalAlpha = t < 0.5 ? t * 2 : (1 - t) * 2; gP.fillRect(0, 0, W, H); gP.globalAlpha = 1;
      return;
    }
    gP.drawImage(a, 0, 0);
    if (kind === 'wipe') {
      const x = Math.round(W * t);
      if (x > 0) gP.drawImage(b, 0, 0, x, H, 0, 0, x, H);
      gP.fillStyle = '#fff'; gP.fillRect(x - Math.round(dpr), 0, Math.max(2, Math.round(2 * dpr)), H);
      return;
    }
    gP.globalAlpha = t; gP.drawImage(b, 0, 0); gP.globalAlpha = 1;
  }
  function kindNow() {
    const checked = el.querySelector('input[data-hero-trans]:checked');
    return checked ? checked.value : 'dissolve';
  }
  function take() {
    if (trans || !W) return;
    takeBtn?.setAttribute('data-busy', '');
    if (reducedQ.matches) { finish(nxt); return; }
    trans = { from: cur, to: nxt, kind: kindNow(), t0: performance.now() };
    run();
  }
  function finish(to) {
    cur = to; nxt = (to + 1) % P.length; trans = null;
    gP.globalAlpha = 1; gP.drawImage(frame(cur), 0, 0);
    if (pgmName) pgmName.textContent = P[cur].name;
    pgm.setAttribute('aria-label', `Programme monitor showing ${P[cur].name}`);
    drawPvw();
    lastTake = performance.now();
    takeBtn?.removeAttribute('data-busy');
  }

  /* ------------------------------------------------------------------------ the loop */
  function timecode() {
    if (!tcEl) return;
    const d = new Date();
    const ff = reducedQ.matches ? '00' : pad(Math.floor(d.getMilliseconds() / 40));
    const tc = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}:${ff}`;
    if (tc !== lastTc) { tcEl.textContent = tc; lastTc = tc; }
  }
  function tick(now) {
    raf = 0;
    if (!onScreen || document.hidden) return;
    if (trans) {
      const t = Math.min(1, (now - trans.t0) / 900);
      composite(ease(t));
      if (t >= 1) finish(trans.to);
    } else if (auto && now - lastTake > 4200) {
      take();
    }
    timecode();
    // Reduced motion: the clock still ticks (it is information), once a second.
    if (reducedQ.matches && !trans) { clearTimeout(timer); timer = setTimeout(run, 1000 - new Date().getMilliseconds()); return; }
    run();
  }
  function run() { if (!raf && onScreen && !document.hidden) raf = requestAnimationFrame(tick); }
  function stop() { if (raf) cancelAnimationFrame(raf); raf = 0; clearTimeout(timer); }

  takeBtn?.addEventListener('click', take);
  if (autoBtn) {
    const setAuto = (v) => { auto = v; autoBtn.setAttribute('aria-pressed', String(v)); lastTake = performance.now(); };
    autoBtn.addEventListener('click', () => setAuto(!auto));
    setAuto(auto);
    reducedQ.addEventListener('change', () => { if (reducedQ.matches) setAuto(false); });
  }
  if ('ResizeObserver' in window) new ResizeObserver(() => size()).observe(pgm);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver((entries) => {
      onScreen = entries[entries.length - 1].isIntersecting; // the newest entry wins when several arrive together
      if (onScreen) { lastTake = performance.now(); run(); } else stop();
    }).observe(pgm);
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); else { lastTake = performance.now(); run(); } });
  if (document.fonts?.ready) document.fonts.ready.then(() => { cache[2] = null; if (cur === 2) gP.drawImage(frame(2), 0, 0); if (nxt === 2) drawPvw(); });

  size();
  lastTake = performance.now();
  pgm.setAttribute('aria-label', `Programme monitor showing ${P[cur].name}`);
  el.classList.add('is-live');
  run();
}
