/*
 * The Ecosystem tagline gathers out of a cloud of particles: hand-written, no dependencies, no build step.
 *   <script type="module" src="/assets/js/particles.js"></script>   (the Ecosystem page only)
 *
 * Hooks (docs/DESIGN.md 8.10): [data-particles] is the paragraph and [data-particles-line] each line; a line's
 * [data-product] child is its full stop, drawn as a square in the app's bar. The paragraph gets
 * data-state="armed|run|done|off", and the CSS hides and shows the words by it: "off" when the script never took the
 * paragraph, so the words simply stay, and "done" once it had, so they fade back in.
 *
 * The real text is the truth: it stays in the page for every reader, is hidden only while the canvas draws over it
 * and fades back in as the canvas fades out; then the canvas goes, and the script keeps nothing of it. Colour is a
 * signal: the cloud is the four app bars, each line gathers from its own app's colour and lands in the text colour,
 * and its full stop keeps the bar. Colours come from the computed styles, so both themes are right, and are read
 * again on a theme change.
 *
 * It starts once the page has loaded and any of the paragraph is in view, stops for good when settled, pauses off
 * screen and in a hidden tab, and ends at once on a resize or when reduced motion or forced colours come on. It does
 * nothing under reduced motion, Save-Data or forced colours, and if it has not started when the CSS hold would have
 * ended (two seconds after the paragraph was first styled), the words fade in without it. A frame is one pass over typed arrays and
 * one whole-pixel fillRect per particle, in 29 fills.
 */

const root = document.querySelector('[data-particles]');
let quit = () => { if (root) root.dataset.state = 'off'; };
const noop = () => {}; // what quit becomes once it has ended: the module then holds nothing of the effect

function guard(name, fn) {
  try { return fn(); } catch (err) { console.warn(`[particles] ${name} skipped:`, err); quit(); return undefined; }
}

const LEAD = 120, LINE = 220, SWEEP = 260, JITTER = 80, TRAVEL = 880; // ms: the gather, line after line
const STEPS = 5; // colour steps from the app's bar to the text colour as a particle lands
const motion = matchMedia('(prefers-reduced-motion: no-preference)');
const forced = matchMedia('(forced-colors: active)');

if (root) guard('start', start);

function start() {
  const lines = [...root.querySelectorAll('[data-particles-line]')];
  const q = (m) => matchMedia(m).matches;
  if (!lines.length || !motion.matches || forced.matches || q('(prefers-reduced-data: reduce)')
    || navigator.connection?.saveData || !('IntersectionObserver' in window) || !('ResizeObserver' in window)
    || !document.fonts || !document.createElement('canvas').getContext) { quit(); return; }

  // Words already on screen stay (the CSS hold ended before the script came); words not yet seen wait unseen.
  const box = root.getBoundingClientRect();
  const fill = getComputedStyle(lines[0]).getPropertyValue('-webkit-text-fill-color');
  if (box.bottom > 0 && box.top < innerHeight && !/(, 0\)|transparent)$/.test(fill)) { quit(); return; }
  // The CSS hold's own clock (it runs from when the paragraph was first styled), read before arming ends it.
  const hold = lines[0].getAnimations?.().find((a) => a.animationName === 'hub-stops-in')?.effect?.getComputedTiming();
  root.dataset.state = 'armed';

  let fx = null, io = null;
  const onMedia = () => { if (!motion.matches || forced.matches) (fx ? fx.end() : quit()); };
  const release = () => { io?.disconnect(); motion.removeEventListener('change', onMedia); forced.removeEventListener('change', onMedia); };
  quit = () => { release(); root.dataset.state = 'done'; quit = noop; }; // taken, so the words fade back in
  // A slow page or slow fonts: the words come back, with no effect, when the CSS hold would have let them in.
  const late = setTimeout(() => quit(), Math.max(0, (hold?.delay ?? 2000) - (hold?.localTime ?? 0)));
  motion.addEventListener('change', onMedia);
  forced.addEventListener('change', onMedia);
  // After the load and the fonts, so the gather never competes with the page for frames.
  const loaded = new Promise((done) => (document.readyState === 'complete' ? done() : addEventListener('load', done, { once: true })));
  Promise.all([loaded, document.fonts.ready]).then(() => guard('watch', () => {
    clearTimeout(late);
    if (root.dataset.state !== 'armed') return;
    // Any of it in view: the words are never left unseen on screen waiting for more of the paragraph to scroll in.
    io = new IntersectionObserver((entries) => guard('view', () => {
      const e = entries[entries.length - 1]; // the newest entry wins when several arrive together
      if (!fx && e.isIntersecting) fx = gather(lines, release);
      else if (fx) fx.view(e.isIntersecting);
    }), { threshold: 0 });
    io.observe(root);
  }));
}

/* A canvas takes font-stretch only as a keyword: the nearest one; the measured width scales it to the page's. */
const stretch = (pct) => (pct >= 137.5 ? 'extra-expanded' : pct >= 118.75 ? 'expanded' : pct >= 106.25 ? 'semi-expanded' : 'normal');

/* A cubic-bezier() as a 256-step table, read from a motion token so the settle is the system's own (--e-cut). */
function easing(token) {
  const v = (/cubic-bezier\(([^)]+)\)/.exec(token)?.[1] ?? '').split(',').map(Number);
  const [a, b, c, d] = v.length === 4 && v.every(Number.isFinite) ? v : [0.2, 0, 0, 1];
  const bz = (t, p, r) => 3 * p * t * (1 - t) * (1 - t) + 3 * r * t * t * (1 - t) + t * t * t;
  const lut = new Float32Array(257);
  for (let i = 0; i <= 256; i++) {
    let lo = 0, hi = 1, t = 0.5;
    for (let k = 0; k < 22; k++) { t = (lo + hi) / 2; if (bz(t, a, c) < i / 256) lo = t; else hi = t; }
    lut[i] = bz(t, b, d);
  }
  return (u) => { const f = u * 256, i = f | 0; return i >= 256 ? 1 : lut[i] + (lut[i + 1] - lut[i]) * (f - i); };
}

/* Two short tasks rather than one long one: the targets now, the particles and the canvas in the next task. */
function gather(lines, release) {
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  const box = root.getBoundingClientRect();
  const bleed = Math.round(Math.min(32, box.height * 0.12)); // the cloud spills a little above and below the lines
  const ox = box.left, oy = box.top - bleed;
  const cw = Math.ceil(box.width * dpr), ch = Math.ceil((box.height + 2 * bleed) * dpr);

  /* Targets: each line's text in its computed font, drawn at the device pixel ratio and read back, one text box at a
     time; the full stops are squares. */
  const g = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
  const texts = [], stops = [];
  let ink = 0;
  lines.forEach((line, li) => {
    const cs = getComputedStyle(line);
    for (const node of line.childNodes) {
      if (node.nodeType === 3 && node.data.trim()) {
        const range = document.createRange();
        range.selectNodeContents(node);
        const r = range.getBoundingClientRect();
        const w = Math.ceil(r.width * dpr) + 2, h = Math.ceil(r.height * dpr) + 2;
        g.canvas.width = w; g.canvas.height = h; // a new size resets the context, so the font is set after it
        g.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
        if ('fontStretch' in g) g.fontStretch = stretch(parseFloat(cs.fontStretch) || 100);
        if ('letterSpacing' in g) g.letterSpacing = cs.letterSpacing;
        const m = g.measureText(node.data);
        g.setTransform(dpr * (m.width ? r.width / m.width : 1), 0, 0, dpr, 1, (m.fontBoundingBoxAscent ?? r.height * 0.8) * dpr + 1);
        g.fillText(node.data, 0, 0);
        const a = g.getImageData(0, 0, w, h).data;
        for (let j = 0; j < h; j += 2) for (let i = 0; i < w; i += 2) if (a[(j * w + i) * 4 + 3] > 127) ink += 4; // one pixel in four counts
        texts.push({ li, a, w, h, x: (r.left - ox) * dpr - 1, y: (r.top - oy) * dpr - 1 });
      } else if (node.nodeType === 1 && node.matches('[data-product]')) {
        const r = node.getBoundingClientRect();
        stops.push({ li, x: (r.left - ox) * dpr, y: (r.top - oy) * dpr, side: r.width * dpr });
      }
    }
  });

  /* How many: about 1,500 on a phone, 3,000 on a desktop, fewer on a weak device or past 250,000 canvas pixels
     (measured at four times CPU throttling, software raster); then the grid step that gives that many. */
  const cores = navigator.hardwareConcurrency || 4, mem = navigator.deviceMemory || 4;
  const weak = cores <= 2 || mem <= 2 ? 0.6 : cores <= 4 && mem <= 4 ? 0.8 : 1;
  const ceiling = 3000 * Math.min(1, (250000 / (cw * ch)) ** 0.25);
  const want = Math.min(ceiling, Math.max(1500, ink / (dpr * dpr) / 4)) * weak;
  const step = Math.max(1, Math.sqrt(ink / want));
  const size = Math.max(1, Math.round(step * 0.8));

  const pts = []; // x, y, size, line, full stop (1) or text (0); grouped by line, each line's text before its stop
  const ranges = lines.map((line, li) => {
    const a = pts.length / 5;
    for (const t of texts) {
      if (t.li !== li) continue;
      for (let j = step / 2; j < t.h; j += step) for (let i = step / 2; i < t.w; i += step) {
        if (t.a[(Math.round(j) * t.w + Math.round(i)) * 4 + 3] > 127) pts.push(Math.round(t.x + i - size / 2), Math.round(t.y + j - size / 2), size, li, 0);
      }
    }
    const b = pts.length / 5;
    for (const s of stops) {
      if (s.li !== li) continue;
      const k = Math.max(2, Math.round(s.side / step)), cell = s.side / k, z = Math.ceil(cell);
      for (let r = 0; r < k; r++) for (let c = 0; c < k; c++) pts.push(Math.round(s.x + c * cell), Math.round(s.y + r * cell), z, li, 1);
    }
    return [a, b, pts.length / 5];
  });
  const n = pts.length / 5;

  let raf = 0, clock = 0, last = -1, onScreen = true, over = false, draw = null, end = 0, canvas = null;
  const kick = () => { if (!raf && !over && draw && onScreen && !document.hidden) raf = requestAnimationFrame(tick); };
  const pause = () => { if (raf) cancelAnimationFrame(raf); raf = 0; last = -1; };
  function tick(now) {
    guard('frame', () => {
      raf = 0;
      clock += last < 0 ? 0 : Math.min(50, now - last); // a stall slows the gather rather than skipping it
      last = now;
      draw(clock);
      if (clock >= end) finish(); else kick();
    });
  }
  const onVisible = () => guard('visibility', () => { if (document.hidden) pause(); else kick(); });
  const scheme = matchMedia('(prefers-color-scheme: light)');
  let colours = () => {};
  const onTheme = () => guard('theme', colours);
  const theme = new MutationObserver(onTheme);
  let seen = null;
  const resize = new ResizeObserver(([entry]) => {
    const r = entry.contentRect, now = `${Math.round(r.width)}x${Math.round(r.height)}`;
    if (seen === null) seen = now; else if (now !== seen) finish(); // targets would be wrong: the words come back now
  });

  /* Settled, or ended early: the words fade back in as the canvas fades out, then the canvas goes. */
  function finish() {
    if (over) return;
    over = true;
    pause();
    release();
    resize.disconnect(); theme.disconnect();
    document.removeEventListener('visibilitychange', onVisible);
    scheme.removeEventListener('change', onTheme);
    root.dataset.state = 'done';
    quit = noop; // the module lets go of finish, and with it of the canvas, the targets and the particles
    if (!canvas) return;
    const remove = () => canvas.remove();
    canvas.addEventListener('transitionend', remove, { once: true });
    setTimeout(remove, 1000); // no transition to wait for (no motion, or the canvas never showed)
  }
  quit = finish;
  document.addEventListener('visibilitychange', onVisible);
  scheme.addEventListener('change', onTheme);
  theme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  resize.observe(root);
  if (!n) finish(); else setTimeout(() => guard('gather', particles), 0);

  function particles() {
    if (over) return;
    /* The cloud: loose, centred, each particle drifting until its turn; seeded, so it is the same on every visit. */
    let seed = 0x5ec7a1;
    const rnd = () => {
      let t = seed = seed + 0x6d2b79f5 | 0;
      t = Math.imul(t ^ t >>> 15, t | 1);
      t ^= t + Math.imul(t ^ t >>> 7, t | 61);
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
    const bell = () => (rnd() + rnd() + rnd()) / 1.5 - 1;
    const F = () => new Float32Array(n);
    const TX = F(), TY = F(), S = F(), SX = F(), SY = F(), VX = F(), VY = F(), PX = F(), PY = F(), DL = F(), DU = F();
    const X = F(), Y = F(), B = new Uint8Array(n);
    const spans = lines.map((l, li) => texts.find((t) => t.li === li) || { x: 0, w: cw });
    for (let i = 0; i < n; i++) {
      const li = pts[i * 5 + 3], span = spans[li];
      TX[i] = pts[i * 5]; TY[i] = pts[i * 5 + 1]; S[i] = pts[i * 5 + 2];
      SX[i] = cw / 2 + bell() * cw * 0.52; SY[i] = ch / 2 + bell() * ch * 0.55;
      VX[i] = (rnd() - 0.5) * 0.024 * dpr; VY[i] = (rnd() - 0.5) * 0.024 * dpr;
      const curl = (rnd() - 0.5) * 0.6;
      PX[i] = -(TY[i] - SY[i]) * curl; PY[i] = (TX[i] - SX[i]) * curl;
      const across = pts[i * 5 + 4] ? 1 : Math.min(1, Math.max(0, (TX[i] - span.x) / span.w));
      DL[i] = LEAD + li * LINE + across * SWEEP + rnd() * JITTER;
      DU[i] = TRAVEL * (0.86 + rnd() * 0.28);
      end = Math.max(end, DL[i] + DU[i]);
    }

    /* Colours, read from the computed styles, as STEPS + 1 fills per line: its bar, then towards the text colour. */
    const probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    const rgb = (css) => {
      probe.clearRect(0, 0, 1, 1); probe.fillStyle = '#000'; probe.fillStyle = css; probe.fillRect(0, 0, 1, 1);
      return probe.getImageData(0, 0, 1, 1).data;
    };
    let pal = [];
    colours = () => {
      const bars = lines.map((l) => { const s = l.querySelector('[data-product]'); return s ? rgb(getComputedStyle(s).backgroundColor) : null; });
      pal = lines.map((l, li) => {
        const text = rgb(getComputedStyle(l).color), bar = bars[li] || bars.find(Boolean) || text;
        return Array.from({ length: STEPS + 1 }, (_, k) => `rgb(${[0, 1, 2].map((c) => Math.round(bar[c] + (text[c] - bar[c]) * k / STEPS)).join(' ')})`);
      });
    };
    colours();

    canvas = document.createElement('canvas');
    canvas.className = 'hub-stops-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    canvas.width = cw; canvas.height = ch;
    canvas.style.top = `${-bleed}px`;
    canvas.style.height = `${box.height + 2 * bleed}px`;
    const ctx = canvas.getContext('2d');
    root.prepend(canvas); // first, so the last line keeps its closing rule
    void getComputedStyle(canvas).opacity; // its hidden style is computed now, so "run" fades it in
    const ease = easing(getComputedStyle(document.documentElement).getPropertyValue('--e-cut'));

    draw = (t) => {
      for (let i = 0; i < n; i++) {
        const u = (t - DL[i]) / DU[i];
        if (u >= 1) { X[i] = TX[i]; Y[i] = TY[i]; B[i] = STEPS; continue; }
        const cx = SX[i] + VX[i] * t, cy = SY[i] + VY[i] * t;
        if (u <= 0) { X[i] = cx + 0.5 | 0; Y[i] = cy + 0.5 | 0; B[i] = 0; continue; }
        const e = ease(u), arc = 4 * e * (1 - e);
        X[i] = cx + (TX[i] - cx) * e + PX[i] * arc + 0.5 | 0; // whole pixels: crisp squares, and the cheapest fill
        Y[i] = cy + (TY[i] - cy) * e + PY[i] * arc + 0.5 | 0;
        B[i] = e < 0.5 ? 0 : Math.min(STEPS, 1 + (((e - 0.5) * 2 * STEPS) | 0));
      }
      ctx.clearRect(0, 0, cw, ch);
      // The cloud first and the landed last, so a line stays legible while later ones fly over it; stops on top.
      for (let k = 0; k <= STEPS; k++) {
        for (let li = 0; li < ranges.length; li++) {
          ctx.fillStyle = pal[li][k];
          for (let i = ranges[li][0], z = ranges[li][1]; i < z; i++) if (B[i] === k) ctx.fillRect(X[i], Y[i], S[i], S[i]);
        }
      }
      for (let li = 0; li < ranges.length; li++) {
        ctx.fillStyle = pal[li][0];
        for (let i = ranges[li][1], z = ranges[li][2]; i < z; i++) ctx.fillRect(X[i], Y[i], S[i], S[i]);
      }
    };
    draw(0); // the first frame is ready before the next paint
    root.dataset.state = 'run';
    kick();
  }
  return { view(on) { onScreen = on; if (on) kick(); else pause(); }, end: finish };
}
