// Author-time geometry for the artwork: numbers, paths, shapes cut into bars, and the builder that merges shapes of
// one paint into one path. Nothing here runs at request time; build.mjs uses it to write the table
// (app/src/News/art-data.php).
//
// Paint classes (art.css): a fill slot fN and a stroke slot sN/lN follow the article's palette (the bars in their
// order, starting at the bar the palette names); qN and kN are the calm run (the same order without green and red,
// for a lone lamp, a key, a node); cN is a bar fixed in SMPTE order (where the picture is the test signal itself).
// The rest are the screen's greys. A stroke up to 3 units wide without dashes is a line (its class keeps its weight
// on the lead card); a wider or dashed one is a band and scales with the picture.

// A take's signature (FNV-1a of its markup): edges.json records the one it measured, so a redrawn take is measured again.
export const sig = (s) => { let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; } return h.toString(36); };
export const n = (v) => { const r = Math.round(v * 10) / 10; return Object.is(r, -0) ? '0' : String(r); };
export const R = (x, y, w, h) => `M${n(x)} ${n(y)}h${n(w)}v${n(h)}h${n(-w)}z`;
// A rectangle with its corners cut by c: a key, a pad, a node (reads as a small radius, costs no arc).
export const K = (x, y, w, h, c = 2) => `M${n(x + c)} ${n(y)}h${n(w - 2 * c)}l${n(c)} ${n(c)}v${n(h - 2 * c)}l${n(-c)} ${n(c)}h${n(-(w - 2 * c))}l${n(-c)} ${n(-c)}v${n(-(h - 2 * c))}z`;
export const P = (pts, close = true) => {
  const q = pts.map(([x, y]) => [Math.round(x * 10), Math.round(y * 10)]);
  let d = `M${n(q[0][0] / 10)} ${n(q[0][1] / 10)}`;
  if (q.length > 1) d += 'l' + q.slice(1).map(([x, y], i) => `${n((x - q[i][0]) / 10)} ${n((y - q[i][1]) / 10)}`).join(' ');
  return d + (close ? 'z' : '');
};
// A closed polygon wound clockwise on screen, so overlapping shapes merged into one path add up instead of cutting
// holes in each other under the nonzero rule.
export const Pc = (pts) => {
  let a = 0; for (let i = 0; i < pts.length; i++) { const p = pts[i], q = pts[(i + 1) % pts.length]; a += p[0] * q[1] - q[0] * p[1]; }
  return P(a < 0 ? [...pts].reverse() : pts);
};
// Drops separators a path does not need: before a minus, and the zero before a decimal point.
export const tight = (d) => d.replace(/ -/g, '-').replace(/(^|[^\d.])0\./g, '$1.');
export const Ln = (x1, y1, x2, y2) => P([[x1, y1], [x2, y2]], false);
export const H = (x1, x2, y) => `M${n(x1)} ${n(y)}H${n(x2)}`;
export const Vt = (x, y1, y2) => `M${n(x)} ${n(y1)}V${n(y2)}`;
export const O = (cx, cy, r) => `M${n(cx - r)} ${n(cy)}a${n(r)} ${n(r)} 0 1 0 ${n(2 * r)} 0a${n(r)} ${n(r)} 0 1 0 ${n(-2 * r)} 0z`;
// An ellipse, wound the way O is.
export const E = (cx, cy, rx, ry) => `M${n(cx - rx)} ${n(cy)}a${n(rx)} ${n(ry)} 0 1 0 ${n(2 * rx)} 0a${n(rx)} ${n(ry)} 0 1 0 ${n(-2 * rx)} 0z`;
export const rad = (deg) => (deg * Math.PI) / 180;
// Angles in degrees, 0 at twelve o'clock, clockwise.
export const at = (cx, cy, r, deg) => [cx + r * Math.sin(rad(deg)), cy - r * Math.cos(rad(deg))];
export const A = (cx, cy, r, a0, a1) => {
  const [x0, y0] = at(cx, cy, r, a0); const [x1, y1] = at(cx, cy, r, a1);
  return `M${n(x0)} ${n(y0)}A${n(r)} ${n(r)} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${n(x1)} ${n(y1)}`;
};
// A filled wedge (a pie slice) and a filled ring segment.
export const Wedge = (cx, cy, r, a0, a1) => { const [x0, y0] = at(cx, cy, r, a0); const [x1, y1] = at(cx, cy, r, a1); return `M${n(cx)} ${n(cy)}L${n(x0)} ${n(y0)}A${n(r)} ${n(r)} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${n(x1)} ${n(y1)}z`; };
export const Ring = (cx, cy, r0, r1, a0, a1) => {
  const [x0, y0] = at(cx, cy, r1, a0), [x1, y1] = at(cx, cy, r1, a1), [x2, y2] = at(cx, cy, r0, a1), [x3, y3] = at(cx, cy, r0, a0); const big = a1 - a0 > 180 ? 1 : 0;
  return `M${n(x0)} ${n(y0)}A${n(r1)} ${n(r1)} 0 ${big} 1 ${n(x1)} ${n(y1)}L${n(x2)} ${n(y2)}A${n(r0)} ${n(r0)} 0 ${big} 0 ${n(x3)} ${n(y3)}z`;
};
export const rot = (pts, cx, cy, deg) => { const c = Math.cos(rad(deg)), s = Math.sin(rad(deg)); return pts.map(([x, y]) => [cx + (x - cx) * c - (y - cy) * s, cy + (x - cx) * s + (y - cy) * c]); };
export const rectPts = (x, y, w, h) => [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];

// Outlines as points, for slicing a shape into bars.
export function quad(p0, c, p1, steps = 10) { const out = []; for (let i = 0; i <= steps; i++) { const t = i / steps; out.push([(1 - t) * (1 - t) * p0[0] + 2 * (1 - t) * t * c[0] + t * t * p1[0], (1 - t) * (1 - t) * p0[1] + 2 * (1 - t) * t * c[1] + t * t * p1[1]]); } return out; }
export function cubic(p0, c0, c1, p1, steps = 12) { const out = []; for (let i = 0; i <= steps; i++) { const t = i / steps, u = 1 - t; out.push([u * u * u * p0[0] + 3 * u * u * t * c0[0] + 3 * u * t * t * c1[0] + t * t * t * p1[0], u * u * u * p0[1] + 3 * u * u * t * c0[1] + 3 * u * t * t * c1[1] + t * t * t * p1[1]]); } return out; }
export function circlePts(cx, cy, r, steps = 72) { const out = []; for (let i = 0; i < steps; i++) out.push(at(cx, cy, r, (360 * i) / steps)); return out; }
// The 4-point spark: concave sides, k pulls them toward the centre.
export function sparkPts(cx, cy, r, k = 0.16) {
  const t = [cx, cy - r], rr = [cx + r, cy], b = [cx, cy + r], l = [cx - r, cy];
  return [...quad(t, [cx + r * k, cy - r * k], rr), ...quad(rr, [cx + r * k, cy + r * k], b).slice(1), ...quad(b, [cx - r * k, cy + r * k], l).slice(1), ...quad(l, [cx - r * k, cy - r * k], t).slice(1, -1)];
}
export const sparkPath = (cx, cy, r, k = 0.16) => `M${n(cx)} ${n(cy - r)}Q${n(cx + r * k)} ${n(cy - r * k)} ${n(cx + r)} ${n(cy)}Q${n(cx + r * k)} ${n(cy + r * k)} ${n(cx)} ${n(cy + r)}Q${n(cx - r * k)} ${n(cy + r * k)} ${n(cx - r)} ${n(cy)}Q${n(cx - r * k)} ${n(cy - r * k)} ${n(cx)} ${n(cy - r)}z`;
export function shieldPts(x, y, w, h) {
  const cx = x + w / 2, mid = y + h * 0.45;
  return [[x, y], [x + w, y], [x + w, mid], ...cubic([x + w, mid], [x + w, y + h * 0.75], [cx + w * 0.22, y + h * 0.9], [cx, y + h]).slice(1), ...cubic([cx, y + h], [cx - w * 0.22, y + h * 0.9], [x, y + h * 0.75], [x, mid]).slice(1)];
}
export const shieldPath = (x, y, w, h) => { const cx = x + w / 2, mid = y + h * 0.45; return `M${n(x)} ${n(y)}H${n(x + w)}V${n(mid)}C${n(x + w)} ${n(y + h * 0.75)} ${n(cx + w * 0.22)} ${n(y + h * 0.9)} ${n(cx)} ${n(y + h)}C${n(cx - w * 0.22)} ${n(y + h * 0.9)} ${n(x)} ${n(y + h * 0.75)} ${n(x)} ${n(mid)}z`; };
export function bulbPts(cx, cy, r) {
  // A glass: most of a circle, then a neck that narrows to the base.
  const neckY = cy + r * 1.25, neckW = r * 0.46, a = 140;
  const arc = []; for (let d = -a; d <= a; d += 5) arc.push(at(cx, cy, r, d));
  const [rx, ry] = at(cx, cy, r, a), [lx, ly] = at(cx, cy, r, -a);
  return [...arc, ...cubic([rx, ry], [rx - r * 0.12, ry + r * 0.22], [cx + neckW, neckY - r * 0.2], [cx + neckW, neckY]).slice(1), [cx - neckW, neckY], ...cubic([cx - neckW, neckY], [cx - neckW, neckY - r * 0.2], [lx + r * 0.12, ly + r * 0.22], [lx, ly]).slice(1, -1)];
}
export const bulbPath = (cx, cy, r) => {
  const neckY = cy + r * 1.25, neckW = r * 0.46; const [rx, ry] = at(cx, cy, r, 140), [lx, ly] = at(cx, cy, r, -140);
  return `M${n(lx)} ${n(ly)}A${n(r)} ${n(r)} 0 1 1 ${n(rx)} ${n(ry)}C${n(rx - r * 0.12)} ${n(ry + r * 0.22)} ${n(cx + neckW)} ${n(neckY - r * 0.2)} ${n(cx + neckW)} ${n(neckY)}H${n(cx - neckW)}C${n(cx - neckW)} ${n(neckY - r * 0.2)} ${n(lx + r * 0.12)} ${n(ly + r * 0.22)} ${n(lx)} ${n(ly)}z`;
};

// The vertical (or horizontal) extent of a closed outline at x (or y).
function span(pts, v, axis) {
  const a = axis, b = 1 - axis; let lo = Infinity, hi = -Infinity;
  for (let i = 0; i < pts.length; i++) {
    const p = pts[i], q = pts[(i + 1) % pts.length];
    if ((p[a] - v) * (q[a] - v) <= 0 && p[a] !== q[a]) { const t = (v - p[a]) / (q[a] - p[a]); const y = p[b] + t * (q[b] - p[b]); lo = Math.min(lo, y); hi = Math.max(hi, y); }
    else if (p[a] === v) { lo = Math.min(lo, p[b]); hi = Math.max(hi, p[b]); }
  }
  return lo <= hi ? [lo, hi] : null;
}
function dedupe(pts) { const out = []; for (const p of pts) { const q = out[out.length - 1]; if (!q || n(q[0]) !== n(p[0]) || n(q[1]) !== n(p[1])) out.push(p); } return out; }
// Cuts a convex outline into `count` bars across (axis 0: vertical bars, side by side; axis 1: horizontal bands),
// each a polygon (as points) that follows the outline. Each bar overlaps the next by a hair so no seam shows.
export function slicePts(pts, count, axis = 0, step = 2) {
  const a = axis; const lo = Math.min(...pts.map((p) => p[a])), hi = Math.max(...pts.map((p) => p[a]));
  const w = (hi - lo) / count; const out = [];
  for (let i = 0; i < count; i++) {
    const s0 = lo + i * w, s1 = Math.min(hi, lo + (i + 1) * w + (i < count - 1 ? 0.4 : 0));
    const xs = []; for (let v = s0; v < s1; v += step) xs.push(v); xs.push(s1);
    const top = [], bot = [];
    for (const v of xs) { const e = span(pts, Math.min(hi - 0.01, Math.max(lo + 0.01, v)), a); if (!e) continue; top.push(a === 0 ? [v, e[0]] : [e[0], v]); bot.push(a === 0 ? [v, e[1]] : [e[1], v]); }
    out.push(dedupe([...top, ...bot.reverse()]));
  }
  return out;
}
export const slice = (pts, count, axis = 0, step = 2) => slicePts(pts, count, axis, step).map((q) => P(q));
// The same cut at an angle: the outline is turned by deg, cut into upright bars and turned back, so the bars run
// diagonally across it, still in their order from left to right.
export const sliceAt = (pts, count, deg, cx, cy, step = 2) => slicePts(rot(pts, cx, cy, -deg), count, 0, step).map((q) => P(rot(q, cx, cy, deg)));

// ----------------------------------------------------------------------------------------------------- builder
// A stroke's width as an attribute; 1 is the initial value of stroke-width, so it is left out.
const sw = (w) => (Number(w) === 1 ? '' : ` stroke-width="${w}"`);
export class Art {
  constructor() { this.layers = new Map(); }
  // attrs are printed straight after the class or the d attribute, so each one starts with its own space.
  add(layer, cls, d, attrs = '') {
    attrs = attrs.replace(/^\s*(?=\S)/, ' ');
    if (!this.layers.has(layer)) this.layers.set(layer, new Map());
    const m = this.layers.get(layer); const k = cls + '|' + attrs;
    if (!m.has(k)) m.set(k, { cls, attrs, d: [] });
    m.get(k).d.push(d); return this;
  }
  // Fills: the palette's run (f), the calm run (q), a bar fixed in SMPTE order (c), a grey of the screen.
  f(slot, d, layer = 2, attrs = '') { return this.add(layer, 'f' + (((slot % 7) + 7) % 7), d, attrs); }
  q(slot, d, layer = 2, attrs = '') { return this.add(layer, 'q' + (((slot % 5) + 5) % 5), d, attrs); }
  c(bar, d, layer = 2) { return this.add(layer, 'c' + (((bar % 7) + 7) % 7), d); }
  nf(cls, d, layer = 1, attrs = '') { return this.add(layer, cls, d, attrs); }
  // Strokes: a line keeps its weight on the lead card, a band (wide or dashed) scales with the picture.
  static isLine(w, attrs) { return w <= 3 && !/dasharray/.test(attrs); }
  s(slot, d, w, layer = 3, attrs = '') { const sl = ((slot % 7) + 7) % 7; return this.add(layer, (Art.isLine(w, attrs) ? 'l' : 's') + sl, d, `${sw(w)}${attrs}`); }
  // A band whatever its width: a stripe of a ribbon, a column of a waveform, a ray that is one of the bars.
  sb(slot, d, w, layer = 3, attrs = '') { return this.add(layer, 's' + (((slot % 7) + 7) % 7), d, `${sw(w)}${attrs}`); }
  k(slot, d, w, layer = 3, attrs = '') { if (!Art.isLine(w, attrs)) throw new Error('a calm stroke is a line'); return this.add(layer, 'k' + (((slot % 5) + 5) % 5), d, `${sw(w)}${attrs}`); }
  ns(cls, d, w, layer = 4, attrs = '') { return this.add(layer, Art.isLine(w, attrs) ? cls : cls + 'b', d, `${sw(w)}${attrs}`); }
  // A panel and its outline in one path: pw, pm, pd (outlined in white, mid, dim), qw (raised, white).
  both(cls, d, w, layer = 1) { return this.add(layer, cls, d, sw(w)); }
  // Literal markup: each path printed with its class (news-art-<cls>), its geometry and its numeric attributes, in
  // paint order. A run of paths with the same attributes (the seven stripes of a ribbon, the columns of a waveform)
  // shares them through one <g>, which they inherit; paint order is unchanged.
  out() {
    const all = [];
    for (const l of [...this.layers.keys()].sort((a, b) => a - b)) for (const g of this.layers.get(l).values()) all.push(g);
    let s = '';
    for (let i = 0; i < all.length;) {
      let j = i + 1; while (j < all.length && all[j].attrs && all[j].attrs === all[i].attrs) j++;
      const run = all.slice(i, j), shared = run.length > 1 && run[0].attrs.length * (run.length - 1) > 8;
      if (shared) s += `<g${run[0].attrs}>`;
      for (const g of run) s += `<path class="news-art-${g.cls}" d="${tight(g.d.join(''))}"${shared ? '' : g.attrs}/>`;
      if (shared) s += '</g>';
      i = j;
    }
    return s;
  }
}
