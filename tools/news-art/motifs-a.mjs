// The motifs, part one: Video, Audio, Lighting, Staging, Production, Showcalling, Streaming. Each category has four
// compositions; each composition draws itself on a card (W 320) or the article band (W 576) and in three takes (k 0
// to 2), which change its data or its arrangement (levels, times, which cue is next, the layout of a wall) and never
// reverse a run of bars or a pointer. Coordinates: y runs 0 to 180; the motif keeps to x 18 to W - 18 so the share
// card's crop loses nothing; the test card's furniture (pages/news.css) sits in the corners and the right-hand margin.
import { n, R, K, P, Pc, Ln, H, Vt, O, E, at, A, Wedge, Ring, rot, rectPts, slice } from './lib.mjs';

// ------------------------------------------------------------------------------------------------- shared pieces
// The SMPTE picture in a rectangle: the seven bars, the castellations under them, the PLUGE row. Fixed in its
// order: where the bars are the signal they never rotate.
export function smpte(b, x, y, w, h, layer = 2) {
  const bw = w / 7, h1 = h * 0.67, h2 = h * 0.08, y3 = y + h1 + h2, h3 = h - h1 - h2;
  for (let i = 0; i < 7; i++) b.c(i, R(x + bw * i, y, bw + 0.3, h1), layer);
  [6, -1, 4, -1, 2, -1, 0].forEach((s, i) => { if (s >= 0) b.c(s, R(x + bw * i, y + h1, bw + 0.3, h2), layer); });
  b.nf('p4', R(x + bw * 1.15, y3, bw * 1.5, h3), layer); b.nf('p2', R(x + bw * 3, y3, bw, h3), layer); b.nf('p1', R(x + bw * 5, y3, bw * 2, h3), layer);
}
// A wall of screens showing one picture across all of them, the bezels cut through it.
function wall(b, x, y, cols, rows, sw, sh) {
  const w = cols * sw, h = rows * sh;
  smpte(b, x, y, w, h);
  let cuts = ''; for (let c = 1; c < cols; c++) cuts += Vt(x + c * sw, y, y + h); for (let r = 1; r < rows; r++) cuts += H(x, x + w, y + r * sh);
  b.ns('x', cuts, 3, 5); b.ns('m', R(x - 3, y - 3, w + 6, h + 6), 2, 5);
  return { w, h };
}
function stand(b, x, top, foot = 150, half = 16) { b.ns('m', `${Vt(x, top, foot)}${H(x - half, x + half, foot)}`, 2, 5); }
// The SMPTE picture across a wall seen from one side: the near edge (left) runs from near[0] to near[1], the far edge
// from far[0] to far[1]. Upright lines stay upright; along the wall, equal steps shorten with distance (sx(u) is the
// screen's x a fraction u along it), so the bars, the rows under them and the bezels all keep the perspective.
function wallAngle(b, x0, x1, near, far, cols, rows) {
  const hL = near[1] - near[0], hR = far[1] - far[0];
  const sx = (u) => x0 + ((x1 - x0) * u * hL) / ((1 - u) * hR + u * hL);
  const top = (x) => near[0] + ((far[0] - near[0]) * (x - x0)) / (x1 - x0), bot = (x) => near[1] + ((far[1] - near[1]) * (x - x0)) / (x1 - x0);
  const yv = (x, v) => top(x) + (bot(x) - top(x)) * v;
  const quad = (u0, u1, v0, v1) => { const a = sx(u0), c = sx(Math.min(1, u1)); return P([[a, yv(a, v0)], [c, yv(c, v0)], [c, yv(c, v1)], [a, yv(a, v1)]]); };
  for (let i = 0; i < 7; i++) b.c(i, quad(i / 7, (i + 1) / 7 + 0.004, 0, 0.67));
  [6, -1, 4, -1, 2, -1, 0].forEach((c, i) => { if (c >= 0) b.c(c, quad(i / 7, (i + 1) / 7 + 0.004, 0.67, 0.75)); });
  b.nf('p4', quad(1.15 / 7, 2.65 / 7, 0.75, 1), 2); b.nf('p2', quad(3 / 7, 4 / 7, 0.75, 1), 2); b.nf('p1', quad(5 / 7, 1, 0.75, 1), 2);
  let cuts = ''; for (let c = 1; c < cols; c++) { const x = sx(c / cols); cuts += Ln(x, top(x), x, bot(x)); }
  for (let r = 1; r < rows; r++) cuts += Ln(x0, yv(x0, r / rows), x1, yv(x1, r / rows));
  b.ns('x', cuts, 3, 5); b.ns('m', P([[x0 - 3, near[0] - 3], [x1 + 3, far[0] - 2], [x1 + 3, far[1] + 2], [x0 - 3, near[1] + 3]]), 2, 5);
  return { sx, bot };
}
// A studio camera on its tripod, (x, y) the body's top left: the body, the lens, the viewfinder, the pan bar.
function camera(b, x, y) {
  b.both('pm', R(x, y, 60, 30), 2, 3); b.both('pm', P([[x + 60, y + 5], [x + 80, y + 1], [x + 80, y + 29], [x + 60, y + 25]]), 2, 3);
  b.nf('p3', R(x + 8, y - 11, 26, 11), 3); b.nf('p2', `${R(x + 8, y + 8, 22, 4)}${R(x + 8, y + 16, 14, 4)}`, 4);
  const hx = x + 30, hy = y + 30, leg = (dx, t) => [hx + dx * t, hy + (156 - hy) * t];
  b.ns('w', `${Ln(hx, hy, ...leg(-30, 1))}${Ln(hx, hy, ...leg(0, 1))}${Ln(hx, hy, ...leg(30, 1))}${Ln(...leg(-30, 0.62), ...leg(30, 0.62))}`, 2, 5);
  b.ns('m', Ln(x - 2, y + 24, x - 24, y + 36), 2.5, 4);
}
// A truss: two chords and the zig-zag between them.
export function lattice(b, x0, y0, x1, y1, step = 10, horizontal = true, cls = 'w', w = 1.5) {
  let d = horizontal ? `${H(x0, x1, y0)}${H(x0, x1, y1)}` : `${Vt(x0, y0, y1)}${Vt(x1, y0, y1)}`;
  const pts = [];
  if (horizontal) { const c = Math.max(1, Math.round((x1 - x0) / step)); for (let i = 0; i <= c; i++) pts.push([x0 + ((x1 - x0) * i) / c, i % 2 ? y1 : y0]); }
  else { const c = Math.max(1, Math.round((y1 - y0) / step)); for (let i = 0; i <= c; i++) pts.push([i % 2 ? x1 : x0, y0 + ((y1 - y0) * i) / c]); }
  d += P(pts, false);
  b.ns(cls, d, w, 5);
}
// A source on a multiviewer: its picture, then its name strip.
function source(b, x, y, w, h, kind, slot) {
  const ph = h - 6;
  if (kind === 'bands') for (let i = 0; i < 7; i++) b.f(slot + i, R(x, y + (i * ph) / 7, w, ph / 7 + 0.3));
  else if (kind === 'circle') { b.nf('p1', R(x, y, w, ph), 1); b.q(slot, O(x + w / 2, y + ph / 2, Math.min(w, ph) * 0.3)); b.ns('d', `${H(x, x + w, y + ph / 2)}${Vt(x + w / 2, y, y + ph)}`, 1, 4); }
  else if (kind === 'scene') { b.f(slot, R(x, y, w, ph * 0.62)); b.f(slot + 3, R(x, y + ph * 0.62, w, ph * 0.38 + 0.3)); b.q(slot + 1, O(x + w * 0.7, y + ph * 0.32, ph * 0.16), 3); }
  else if (kind === 'smpte') smpte(b, x, y, w, ph);
  else b.f(slot, R(x, y, w, ph));
  b.nf('p1', R(x, y + ph, w, 6), 2); b.nf('p3', R(x + 3, y + ph + 2, Math.min(16, w * 0.3), 2), 3);
}
function screenFrame(b, x, y, w, h, cls = 'm') { b.ns(cls, R(x - 3, y - 3, w + 6, h + 6), 2, 5); }

export const M = {};

// --------------------------------------------------------------------------------------------------------- Video
M.video = [
  (b, { W, k }) => { // A wall showing one SMPTE picture across its screens: on stands, seen from one side, or beside the camera that shoots it.
    const wide = W > 320;
    if (k === 0) {
      const cols = wide ? 5 : 3, sw = 66, sh = 42, w = cols * sw, h = 2 * sh, x = (W - w) / 2, y = 26;
      wall(b, x, y, cols, 2, sw, sh);
      (wide ? [0.18, 0.5, 0.82] : [0.25, 0.75]).forEach((t) => stand(b, x + w * t, y + h + 3));
    } else if (k === 1) {
      // Seen from its left: the near edge tall, the far edge short; two stands, the floor running away with it.
      const x0 = wide ? 64 : 44, x1 = W - (wide ? 84 : 54), near = [22, 132], far = [48, 110];
      const { sx, bot } = wallAngle(b, x0, x1, near, far, wide ? 6 : 4, 2);
      [[0.16, 14], [0.84, 9]].forEach(([u, half]) => { const x = sx(u); stand(b, x, bot(x) + 3, 150 - (x - x0) / (x1 - x0) * 12, half); });
      b.ns('d', Ln(x0 - 20, 152, x1 + 26, 138), 1.5, 0);
    } else {
      // The camera on its tripod, its view across to the wall it shoots.
      const cols = wide ? 4 : 2, sw = 56, sh = 36, w = cols * sw, h = 2 * sh, x = W - (wide ? 60 : 36) - w, y = 34;
      b.nf('h', Pc([[wide ? 168 : 112, 81], [x - 6, y + 4], [x - 6, y + h - 4], [wide ? 168 : 112, 93]]), 0);
      wall(b, x, y, cols, 2, sw, sh);
      (wide ? [0.2, 0.8] : [0.5]).forEach((t) => stand(b, x + w * t, y + h + 3, 150, 18));
      camera(b, wide ? 86 : 30, 70);
      b.ns('d', H(26, W - 26, 156), 1.5, 0);
    }
  },
  (b, { W, k }) => { // A wall of sources, each screen its own picture, the colours running in diagonals.
    const wide = W > 320;
    if (k === 0) {
      const cols = wide ? 8 : 4, s = 44, h = 26, g = 4, w = cols * s + (cols - 1) * g, x = (W - w) / 2, y = 24;
      // Mostly flat sources, with a camera's scene and a test circle among them, so it reads as a wall of feeds.
      for (let r = 0; r < 3; r++) for (let c = 0; c < cols; c++) { const d = (c + 2 * r) % 5; source(b, x + c * (s + g), y + r * (h + g), s, h, d === 1 ? 'scene' : d === 4 ? 'circle' : 'solid', c + 3 * r); }
      b.ns('m', R(x - 4, y - 4, w + 8, 3 * h + 2 * g + 8), 2, 5);
      (wide ? [0.25, 0.75] : [0.5]).forEach((t) => stand(b, x + w * t, y + 3 * h + 2 * g + 4, 150, 24));
    } else if (k === 1) {
      const cols = wide ? 7 : 3, s = 56, h = 32, g = 5, w = cols * s + (cols - 1) * g, x = (W - w) / 2, y = 22, mid = (cols - 1) / 2;
      let i = 0;
      for (let r = 0; r < 3; r++) for (let c = 0; c < cols; c++) {
        const sx = x + c * (s + g), sy = y + r * (h + g);
        if (r === 1 && c === mid) source(b, sx, sy, s, h, 'smpte', 0);
        else source(b, sx, sy, s, h, (c + r) % 4 === 1 ? 'bands' : 'solid', i++ * 3);
      }
      b.ns('m', R(x - 4, y - 4, w + 8, 3 * h + 2 * g + 8), 2, 5);
      (wide ? [0.3, 0.7] : [0.5]).forEach((t) => stand(b, x + w * t, y + 3 * h + 2 * g + 4, 152, 24));
    } else {
      // A long, low wall: two rows of sources of every kind, on two stands.
      const cols = wide ? 10 : 6, s = 41, h = 34, g = 4, w = cols * s + (cols - 1) * g, x = (W - w) / 2, y = 40, kinds = ['solid', 'bands', 'scene', 'solid', 'circle', 'solid', 'bands', 'scene', 'solid', 'circle'];
      for (let r = 0; r < 2; r++) for (let c = 0; c < cols; c++) source(b, x + c * (s + g), y + r * (h + g), s, h, kinds[(c + r * 3) % kinds.length], c * 2 + r * 3);
      b.ns('m', R(x - 4, y - 4, w + 8, 2 * h + g + 8), 2, 5);
      (wide ? [0.2, 0.5, 0.8] : [0.25, 0.75]).forEach((t) => stand(b, x + w * t, y + 2 * h + g + 4, 150, 18));
    }
  },
  (b, { W, k }) => { // A multiviewer: the programme large (the SMPTE picture), the sources around it.
    const wide = W > 320;
    if (k === 0 && !wide) {
      source(b, 30, 40, 150, 92, 'smpte', 0);
      [[188, 40, 'solid', 2], [244, 40, 'bands', 3], [188, 88, 'circle', 1], [244, 88, 'solid', 5]].forEach(([x, y, kind, s]) => source(b, x, y, 50, 42, kind, s));
      b.ns('m', R(26, 36, 272, 100), 2, 5); b.ns('m', Vt(184, 36, 136), 2, 5);
    } else if (k === 0) {
      source(b, 196, 34, 184, 104, 'smpte', 0);
      [[30, 34, 'solid', 2], [114, 34, 'bands', 3], [30, 88, 'circle', 1], [114, 88, 'solid', 5], [394, 34, 'scene', 4], [478, 34, 'solid', 6], [394, 88, 'bands', 0], [478, 88, 'circle', 3]]
        .forEach(([x, y, kind, s]) => source(b, x, y, 70, 50, kind, s));
      b.ns('m', R(26, 30, 524, 112), 2, 5); b.ns('m', `${Vt(190, 30, 142)}${Vt(386, 30, 142)}`, 2, 5);
    } else if (k === 1 && !wide) {
      source(b, 34, 28, 124, 72, 'scene', 2); source(b, 162, 28, 124, 72, 'smpte', 0);
      b.ns('w', R(162, 28, 124, 66), 2, 5);
      ['solid', 'bands', 'circle', 'solid'].forEach((kind, i) => source(b, 34 + i * 64, 108, 59, 38, kind, [4, 5, 3, 0][i]));
      b.ns('m', R(30, 24, 260, 126), 2, 5);
    } else if (k === 1) {
      source(b, 122, 24, 162, 88, 'scene', 2); source(b, 292, 24, 162, 88, 'smpte', 0);
      b.ns('w', R(292, 24, 162, 82), 2, 5);
      ['solid', 'bands', 'circle', 'solid', 'scene', 'bands'].forEach((kind, i) => source(b, 30 + i * 87, 120, 82, 34, kind, [4, 5, 3, 0, 6, 1][i]));
      b.ns('m', R(26, 20, 524, 138), 2, 5);
    } else {
      // A three by three grid: the programme across four of its cells, the sources in the rest.
      // On the card the grid keeps clear of the safe-area corners; the band has room for full-size cells.
      const cw = wide ? 82 : 76, ch = wide ? 44 : 40, g = 5, cols = wide ? 6 : 3, x0 = (W - (cols * cw + (cols - 1) * g)) / 2, y0 = wide ? 22 : 26, pc = wide ? 2 : 0;
      source(b, x0 + pc * (cw + g), y0, 2 * cw + g, 2 * ch + g, 'smpte', 0);
      const kinds = ['bands', 'solid', 'circle', 'scene', 'solid', 'bands', 'solid', 'circle', 'scene', 'solid', 'bands', 'solid', 'solid', 'circle'];
      let i = 0;
      for (let r = 0; r < 3; r++) for (let c = 0; c < cols; c++) { if (r < 2 && (c === pc || c === pc + 1)) continue; source(b, x0 + c * (cw + g), y0 + r * (ch + g), cw, ch, kinds[i % kinds.length], i * 2 + 1); i++; }
      b.ns('w', R(x0 + pc * (cw + g), y0, 2 * cw + g, 2 * ch + g - 6), 2, 5);
      b.ns('m', R(x0 - 4, y0 - 4, cols * cw + (cols - 1) * g + 8, 3 * ch + 2 * g + 8), 2, 5);
    }
  },
  (b, { W, k }) => { // Monitors on a desk, each showing the bars a different way.
    const desk = 138, X = (W - 320) / 2, wide = W > 320;
    const mon = (x, y, w, h, kind, slot, base = true) => {
      if (kind === 'bands') for (let i = 0; i < 7; i++) b.f(slot + i, R(x, y + (i * h) / 7, w, h / 7 + 0.3));
      else if (kind === 'bars') smpte(b, x, y, w, h);
      else if (kind === 'chart') { const cw = w / 4, ch = h / 2; for (let i = 0; i < 8; i++) { const c = i % 4, r = Math.floor(i / 4); if (i < 7) b.f(slot + i, R(x + c * cw, y + r * ch, cw + 0.3, ch + 0.3)); else b.nf('p4', R(x + c * cw, y + r * ch, cw, ch)); } }
      else { b.nf('p1', R(x, y, w, h), 1); for (let i = 0; i < 7; i++) b.f(slot + i, R(x + w / 2 - 21 + i * 6, y + h / 2 - 11, 6.3, 22)); b.ns('w', O(x + w / 2, y + h / 2, Math.min(w, h) * 0.36), 1.5, 4); b.ns('d', `${H(x, x + w, y + h / 2)}${Vt(x + w / 2, y, y + h)}`, 1, 4); }
      screenFrame(b, x, y, w, h);
      if (base) b.ns('m', `${Vt(x + w / 2, y + h + 3, desk)}${H(x + w / 2 - 14, x + w / 2 + 14, desk)}`, 2, 5);
    };
    if (k === 0) {
      if (wide) { mon(30, 66, 76, 44, 'chart', 1); mon(118, 62, 80, 48, 'bands', 3); mon(248, 38, 80, 72, 'bars', 0); mon(378, 62, 80, 48, 'circle', 5); mon(470, 66, 76, 44, 'bands', 6); }
      else { mon(30, 62, 80, 48, 'bands', 3); mon(120, 38, 80, 72, 'bars', 0); mon(210, 62, 80, 48, 'circle', 5); }
    } else if (k === 1) {
      // A laptop between a monitor and a tablet.
      const lx = X + 108, ly = 44, lw = 104, lh = 64;
      smpte(b, lx, ly, lw, lh); screenFrame(b, lx, ly, lw, lh, 'w');
      b.nf('p3', P([[lx - 10, ly + lh + 4], [lx + lw + 10, ly + lh + 4], [lx + lw + 20, desk], [lx - 20, desk]]), 4);
      b.nf('p2', `${R(lx + 6, ly + lh + 9, lw - 12, 3)}${R(lx + 2, ly + lh + 15, lw - 4, 3)}${R(lx + lw / 2 - 14, ly + lh + 22, 28, 4)}`, 5);
      mon(X + 22, 60, 72, 46, 'chart', 2);
      const tx = X + 232, ty = 52; for (let i = 0; i < 7; i++) b.f(i + 4, R(tx, ty + i * 11, 46, 11.3)); b.both('pw', R(tx - 4, ty - 6, 54, 89), 2, 0); b.ns('w', R(tx - 4, ty - 6, 54, 89), 2, 5); b.nf('p4', R(tx + 19, ty + 79, 8, 2), 6);
      if (wide) { mon(30, 60, 74, 46, 'bands', 0); mon(472, 60, 74, 46, 'circle', 3); }
    } else {
      // The grading monitor: one large screen with its safe areas marked over the picture.
      const mw = 176, mh = 99, mx = X + 72, my = 22;
      smpte(b, mx, my, mw, mh); screenFrame(b, mx, my, mw, mh, 'w');
      b.ns('w', `${R(mx + mw * 0.05, my + mh * 0.05, mw * 0.9, mh * 0.9)}${R(mx + mw * 0.1, my + mh * 0.1, mw * 0.8, mh * 0.8)}`, 1, 5);
      b.ns('w', `${H(mx + mw / 2 - 6, mx + mw / 2 + 6, my + mh / 2)}${Vt(mx + mw / 2, my + mh / 2 - 6, my + mh / 2 + 6)}`, 1.5, 5);
      b.ns('m', `${Vt(mx + mw / 2, my + mh + 3, desk)}${H(mx + mw / 2 - 30, mx + mw / 2 + 30, desk)}`, 2.5, 5);
      if (wide) { mon(36, 56, 80, 50, 'bands', 2); mon(460, 56, 80, 50, 'chart', 4); }
    }
    b.ns('d', H(wide ? 24 : 26, wide ? W - 24 : 294, desk + 6), 1.5, 5);
  },
];

// --------------------------------------------------------------------------------------------------------- Audio
// A level for column i, between about a third and the whole height, shaped by an envelope.
const jit = (i, a, c) => 0.5 + 0.5 * Math.abs(Math.sin(i * a + c) * Math.cos(i * (a * 0.38) + c * 0.7));
const env1 = (t) => Math.sin(Math.PI * t) ** 0.9;
const env2 = (t) => 0.25 + 0.75 * Math.max(Math.sin(Math.PI * Math.min(1, t * 1.7)) ** 1.2, Math.sin(Math.PI * Math.max(0, t * 1.7 - 0.7)) ** 1.4);
// An analogue VU meter: its scale an arc in seven segments of the bars, its needle at a level.
function vu(b, cx, cy, r, level) {
  b.both('pd', R(cx - r - 14, cy - r - 14, 2 * r + 28, r + 34), 1.5, 0);
  for (let i = 0; i < 7; i++) b.s(i, A(cx, cy + 10, r, -58 + i * (116 / 7) + 1, -58 + (i + 1) * (116 / 7) - 1), 7, 2);
  b.ns('m', A(cx, cy + 10, r - 9, -58, 58), 1.5, 3);
  b.ns('w', Ln(cx, cy + 10, ...at(cx, cy + 10, r + 4, -58 + 116 * level)), 2, 5); b.nf('p4', O(cx, cy + 10, 4), 6);
}
M.audio = [
  (b, { W, k }) => { // A waveform about its centre line, its columns in seven runs of colour (or two channels, one above the other).
    const count = W > 320 ? 78 : 42, x0 = (W - (count * 6 - 2)) / 2;
    const lanes = k === 2 ? [[54, 60], [126, 60]] : [[90, 122]];
    lanes.forEach(([cy, amp], lane) => {
      b.ns('d', H(24, W - 24, cy), 1, 0);
      for (let i = 0; i < count; i++) {
        const t = (i + 0.5) / count, e = k === 1 ? env2(t) : env1(t), j = k === 1 ? jit(i, 1.37, 2.1) : jit(i + lane * 5, 2.17, 0.6 + lane);
        const h = Math.max(3, Math.round(e * (0.35 + 0.65 * j) * amp));
        b.sb(Math.floor((i * 7) / count), Vt(x0 + i * 6 + 2, cy - h / 2, cy + h / 2), 4, 2);
      }
    });
  },
  (b, { W, k }) => { // A meter bridge: segmented meters with their peaks held (upright, laid along), or two VU meters.
    const lv = k ? [0.74, 0.58, 0.9, 0.66, 0.81, 0.52, 0.7] : [0.62, 0.84, 0.71, 0.93, 0.55, 0.77, 0.66];
    if (k === 0) {
      const pairs = W > 320, count = pairs ? 14 : 7, pitch = pairs ? 32 : 28, x0 = W / 2 - ((count - 1) * pitch) / 2;
      for (let i = 0; i < count; i++) {
        const s = pairs ? Math.floor(i / 2) : i, x = x0 + i * pitch - (pairs ? (i % 2 ? 6 : -6) : 0), top = 26, bot = 152, len = bot - top;
        const l = pairs ? Math.min(0.97, lv[s] + (i % 2 ? -0.06 : 0.03)) : lv[s], lit = Math.round((len * l) / 4) * 4;
        b.ns('u', Vt(x, bot, top), 14, 1, ' stroke-dasharray="3 1"');
        b.s(s, Vt(x, bot, bot - lit), 14, 2, ' stroke-dasharray="3 1"');
        b.s(s, H(x - 7, x + 7, bot - lit - 10 + 1.5), 3, 3);
      }
      b.ns('d', H(x0 - 20, x0 + (count - 1) * pitch + 20, 156), 1.5, 0);
      for (let i = 0; i <= 6; i++) b.ns('d', H(x0 - 24, x0 - 16, 152 - i * 21), 1, 0);
    } else if (k === 1) {
      const left = W > 320 ? 96 : 60, right = W - (W > 320 ? 60 : 44), len = right - left;
      for (let i = 0; i < 7; i++) {
        const y = 32 + i * 18, lit = Math.round((len * lv[i]) / 5) * 5;
        b.nf('p3', R(left - 22, y - 3, 12, 6), 1);
        b.ns('u', H(left, right, y), 12, 1, ' stroke-dasharray="4 1"');
        b.s(i, H(left, left + lit, y), 12, 2, ' stroke-dasharray="4 1"');
        b.s(i, Vt(left + lit + 8, y - 6, y + 6), 3, 3);
      }
      b.ns('d', `${Vt(left - 4, 20, 152)}${H(left - 4, right, 152)}`, 1.5, 0);
    } else {
      const pair = W > 320 ? [[124, 0.62], [288, 0.78], [452, 0.55]] : [[86, 0.62], [234, 0.78]];
      pair.forEach(([cx, l]) => vu(b, cx, 104, 58, l));
    }
  },
  (b, { W, k }) => { // A radial visualiser: rays about a ring (or a dome of them rising from the floor); on the band, a spectrum either side.
    const cx = W / 2, cy = k === 2 ? 132 : 90, r0 = k === 2 ? 26 : 30, count = [42, 56, 21][k], wd = k === 1 ? 3 : 4;
    b.ns('d', k === 2 ? A(cx, cy, r0 - 6, -90, 90) : O(cx, cy, r0 - 6), 1.5, 0);
    for (let i = 0; i < count; i++) {
      const a = k === 2 ? -90 + (180 * (i + 0.5)) / count : (360 * i) / count, e = 0.3 + 0.7 * jit((i * 5) % count, [2.17, 1.71, 1.21][k], [0.6, 1.3, 2.4][k]), l = 8 + Math.round(e * (k === 2 ? 70 : 38));
      const [x0, y0] = at(cx, cy, r0, a), [x1, y1] = at(cx, cy, r0 + l, a); b.sb(Math.floor((i * 7) / count), Ln(x0, y0, x1, y1), k === 2 ? 6 : wd, 2);
    }
    b.nf('p4', k === 2 ? `M${n(cx - 6)} ${cy}a6 6 0 0 1 12 0z` : O(cx, cy, 5), 3);
    if (k === 1) b.ns('m', O(cx, cy, r0 - 14), 1, 0);
    if (k === 2) b.ns('m', H(cx - 150, cx + 150, cy), 2, 5);
    if (W > 320 && k !== 2) {
      for (const side of [0, 1]) for (let i = 0; i < 20; i++) {
        const x = side ? 360 + i * 9 : 36 + i * 9, t = side ? i / 19 : 1 - i / 19, h = Math.max(4, Math.round((0.2 + 0.8 * jit(i + side * 7, 1.9, 0.4)) * (90 - 50 * t)));
        b.sb(Math.floor((i * 7) / 20), Vt(x + 3, 146 - h, 146), 6, 2);
      }
      b.ns('d', `${H(30, 220, 150)}${H(356, 546, 150)}`, 1.5, 0);
    }
  },
  (b, { W, k }) => { // A channel strip: faders, each with its gain knob and its level (or the EQ: rows of knobs).
    const count = W > 320 ? 14 : 7, pitch = W > 320 ? 34 : 32, x0 = W / 2 - ((count - 1) * pitch) / 2 - (W > 320 ? 6 : 0);
    if (k === 2) {
      // The EQ: two rows of knobs over each channel, each lit round to its value in the channel's bar.
      const vals = [0.7, 0.35, 0.55, 0.85, 0.25, 0.6, 0.45, 0.9, 0.3, 0.65, 0.5, 0.75, 0.4, 0.8];
      for (let i = 0; i < count; i++) for (let r = 0; r < 2; r++) {
        const x = x0 + i * pitch + (W > 320 && i >= 7 ? 12 : 0), y = 50 + r * 54, v = vals[(i * 3 + r * 5) % 14];
        b.s(i % 7, A(x, y, 13, -135, -135 + 270 * v), 5, 2); b.nf('p3', O(x, y, 7), 3); b.ns('x', Ln(x, y, ...at(x, y, 7, -135 + 270 * v)), 2, 5);
      }
      b.ns('u', H(x0 - 14, x0 + (count - 1) * pitch + (W > 320 ? 26 : 14), 142), 6, 1, ' stroke-dasharray="3 2"');
      return;
    }
    const caps = k ? [70, 104, 86, 112, 66, 94, 78] : [96, 74, 108, 64, 88, 116, 80];
    for (let i = 0; i < count; i++) {
      const s = i % 7, x = x0 + i * pitch + (W > 320 && i >= 7 ? 12 : 0), cap = caps[(i + (i >= 7 ? 3 : 0)) % 7];
      b.ns('d', O(x, 36, 8), 2, 0); b.s(s, Ln(x, 36, ...at(x, 36, 8, -120 + ((i * 37 + (k ? 60 : 0)) % 240))), 3, 2);
      b.ns('u', Vt(x, 56, 152), 3, 0);
      b.s(s, Vt(x, 152, cap + 6), 3, 2);
      if (k) b.s(s, Vt(x + 9, 152, cap + 26), 3, 2, ' stroke-dasharray="2 1"');
      b.nf('p3', R(x - 9, cap, 18, 11), 4); b.ns('x', H(x - 9, x + 9, cap + 5.5), 1.5, 5);
    }
  },
];

// ------------------------------------------------------------------------------------------------------ Lighting
// The beams are haze, in the screen's greys, edged in their bar's colour; the colour is where the light lands, at
// full strength: so a crossing never mixes two bars into a colour that is not one of them.
function beam(b, pts, slot, core = true) {
  const [a0, a1, b1, b0] = pts, at2 = (p, q, t) => [p[0] + (q[0] - p[0]) * t, p[1] + (q[1] - p[1]) * t];
  b.nf('h', Pc(pts), 1); if (core) b.nf('h', Pc([at2(a0, a1, 0.28), at2(a0, a1, 0.72), at2(b0, b1, 0.7), at2(b0, b1, 0.3)]), 1.5);
  b.s(slot, `${Ln(...a0, ...b0)}${Ln(...a1, ...b1)}`, 1, 1.6);
}
function fixture(b, cx, y, slot, w = 14, h = 11) { b.nf('p3', R(cx - w / 2, y, w, h), 4); b.f(slot, R(cx - w / 2 + 3, y + h, w - 6, 2.5), 2); }
// Moving heads cross in pairs: each lands where its neighbour hangs, the last one straight down.
const crossTo = (i, count) => (i === count - 1 && count % 2 ? i : i % 2 ? i - 1 : i + 1);
M.lighting = [
  (b, { W, k }) => { // Fixtures on a bar; their beams land side by side as the bars (or meet in the middle, or spread from it).
    const count = 7, span = k === 2 ? (W > 320 ? 200 : 128) : W > 320 ? 420 : 252, x0 = (W - span) / 2;
    b.ns('w', H(x0 - 6, x0 + span + 6, 24), 3, 5);
    const pw = [span / count, W > 320 ? 34 : 22, W > 320 ? 70 : 38][k], p0 = W / 2 - (pw * count) / 2;
    for (let i = 0; i < count; i++) {
      const cx = x0 + (span * (i + 0.5)) / count, lx = p0 + pw * i;
      beam(b, [[cx - 4, 40], [cx + 4, 40], [lx + pw, 144], [lx, 144]], i, k !== 2);
      b.f(i, R(lx, 144, pw + 0.3, 11), 2);
      fixture(b, cx, 28, i, k === 2 ? 12 : 14); b.ns('w', Vt(cx, 24, 28), 2, 5);
    }
    b.ns('d', H(W > 320 ? 40 : 30, W - (W > 320 ? 40 : 30), 158), 1, 0);
  },
  (b, { W, k }) => { // Moving heads, their beams crossing: hung from a truss onto the floor, on the floor onto the cyc, or all swept one way.
    const count = W > 320 ? 11 : 7, pitch = W > 320 ? 46 : 40, x0 = W / 2 - ((count - 1) * pitch) / 2, xe = x0 + (count - 1) * pitch;
    if (k !== 1) {
      lattice(b, x0 - 22, 16, xe + 22, 26);
      for (let i = 0; i < count; i++) {
        const x = x0 + i * pitch - (k === 2 ? 14 : 0), tx = k === 2 ? x + pitch * 0.7 - 8 : x0 + crossTo(i, count) * pitch;
        beam(b, [[x - 4, 38], [x + 4, 38], [tx + 16, 146], [tx - 16, 146]], i, false);
        b.f(i, E(tx, 147, 16, 5), 2);
        b.nf('p3', R(x - 7, 26, 14, 10), 4); b.f(i, R(x - 3, 36, 6, 2.5), 2);
      }
      b.ns('d', H(x0 - 26, xe + 26 + (k === 2 ? 20 : 0), 156), 1, 0);
    } else {
      b.nf('p0', R(x0 - 26, 14, xe - x0 + 52, 26), 0); b.ns('d', H(x0 - 26, xe + 26, 40), 1, 0);
      for (let i = 0; i < count; i++) {
        const x = x0 + i * pitch, tx = x0 + crossTo(i, count) * pitch;
        beam(b, [[x - 4, 140], [x + 4, 140], [tx + 15, 30], [tx - 15, 30]], i, false);
        b.f(i, E(tx, 28, 15, 7), 2);
        b.nf('p3', R(x - 8, 142, 16, 9), 4); b.f(i, R(x - 3, 139.5, 6, 2.5), 2);
      }
      b.ns('m', H(x0 - 26, xe + 26, 152), 2, 5);
    }
  },
  (b, { W, k }) => { // One fixture's fan: seven beams from a point, each landing as a bar (from the middle, the left or the right).
    const wide = W > 320, cx = [W / 2, wide ? 120 : 70, wide ? W - 120 : W - 70][k], span = wide ? 476 : 252, x0 = k === 1 ? W - 36 - span : k === 2 ? 36 : (W - span) / 2, pw = span / 7;
    for (let i = 0; i < 7; i++) {
      const lx = x0 + i * pw;
      beam(b, [[cx - 3, 40], [cx + 3, 40], [lx + pw - 2, 144], [lx + 2, 144]], i);
      b.f(i, R(lx, 144, pw + 0.3, 11), 2);
    }
    b.nf('p3', R(cx - 11, 22, 22, 17), 4); b.nf('p4', R(cx - 7, 38, 14, 3), 5); b.ns('w', `${H(cx - 40, cx + 40, 16)}${Vt(cx, 16, 22)}`, 2.5, 5);
  },
  (b, { W, k }) => { // A boom at the side: seven fixtures throw their beams across onto the wall (or down onto the floor), landing as the bars; or a boom each side, their beams crossing onto the floor.
    if (k === 1) {
      // A boom each side, as for dance: four fixtures on the left, three on the right, each throwing across the
      // stage to land on the far side of the floor; the floor reads in the bars' order.
      const wide = W > 320, lp = wide ? 40 : 34, rp = W - lp, pw = wide ? 54 : 30, x0 = W / 2 - 3.5 * pw;
      b.ns('w', `${Vt(lp, 40, 162)}${H(lp - 10, lp + 10, 162)}${Vt(rp, 52, 162)}${H(rp - 10, rp + 10, 162)}`, 3, 5);
      for (let i = 0; i < 7; i++) {
        const left = i >= 3, j = left ? i - 3 : i, cy = left ? 56 + j * 24 : 70 + j * 24, pipe = left ? lp : rp, dir = left ? 1 : -1, sx = pipe + dir * 14, lx = x0 + i * pw;
        beam(b, left ? [[sx, cy - 4], [sx, cy + 4], [lx + pw, 146], [lx, 146]] : [[sx, cy - 4], [sx, cy + 4], [lx, 146], [lx + pw, 146]], i, false);
        b.f(i, R(lx, 146, pw + 0.3, 10), 2);
        b.nf('p3', R(left ? pipe + 2 : pipe - 14, cy - 6, 12, 12), 4); b.f(i, R(left ? sx : sx - 3, cy - 3, 3, 6), 2);
      }
      b.ns('d', H(lp + 20, rp - 20, 158), 1, 0);
      return;
    }
    const left = true, pipe = 34, dir = 1, ww = 14;
    b.ns('w', `${Vt(pipe, 18, 162)}${H(pipe - 10, pipe + 10, 162)}`, 3, 5);
    for (let i = 0; i < 7; i++) {
      const cy = 30 + i * 18, sx = pipe + dir * 14;
      if (k === 2) {
        // The top fixture throws furthest, so the beams nest and never cross; the floor reads in the bars' order.
        const j = 6 - i, pw = W > 320 ? 46 : 24, lx = (W > 320 ? 220 : 120) + j * pw;
        beam(b, [[sx, cy - 4], [sx, cy + 4], [lx, 146], [lx + pw, 146]], j, false); b.f(j, R(lx, 146, pw + 0.3, 10), 2);
      } else {
        const wall = W - 40, ty = 28 + i * 20.6;
        beam(b, [[sx, cy - 4], [sx, cy + 4], [wall, ty + 10.3], [wall, ty - 10.3]], i);
        b.f(i, R(wall, ty - 10.3, ww, 20.9), 2);
      }
      b.nf('p3', R(pipe + 2, cy - 6, 12, 12), 4); b.f(k === 2 ? 6 - i : i, R(sx, cy - 3, 3, 6), 2);
    }
    if (k === 2) b.ns('d', H(60, W - 26, 158), 1, 0);
  },
];

// ------------------------------------------------------------------------------------------------------- Staging
// An LED wall: cols by rows of tiles showing the stage's picture in the palette's run (seven bands across it, or the
// bars running in diagonals tile by tile), the seams in the screen's black. A show's screen, never the test signal.
function led(b, x, y, w, h, cols, rows, mode, s0 = 0) {
  const tw = w / cols, th = h / rows;
  if (mode === 'bands') for (let i = 0; i < 7; i++) b.f(s0 + i, R(x, y + (i * h) / 7, w, h / 7 + 0.3));
  else for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) b.f(s0 + c + 2 * r, R(x + c * tw, y + r * th, tw + 0.3, th + 0.3));
  let cuts = ''; for (let c = 1; c < cols; c++) cuts += Vt(x + c * tw, y, y + h); for (let r = 1; r < rows; r++) cuts += H(x, x + w, y + r * th);
  if (cuts) b.ns('x', cuts, 1.2, 5);
  b.ns('m', R(x - 2, y - 2, w + 4, h + 4), 1.5, 5);
}
function lineArray(b, x, y, count = 6) { for (let i = 0; i < count; i++) b.nf('p3', P(rot(rectPts(x - 9, y + i * 9, 18, 8), x, y, i * 2.5)), 4); b.ns('m', Vt(x, y - 12, y), 1.5, 4); }
M.staging = [
  (b, { W, k }) => { // Ground support: two towers and the span, the deck between them (an LED wall flown on the span, two portrait screens either side of the deck, or PA hung beside a riser with an LED header above).
    const wide = W > 320, l = wide ? 56 : 40, r = W - l;
    lattice(b, l, 28, r, 40); lattice(b, l, 40, l + 14, 146, 10, false); lattice(b, r - 14, 40, r, 146, 10, false);
    b.nf('p2', R(l - 6, 146, r - l + 12, 10), 1); b.ns('m', H(l - 6, r + 6, 146), 2, 5);
    if (k === 0) {
      const ww = wide ? 300 : 164, wx = (W - ww) / 2;
      led(b, wx, 54, ww, 68, wide ? 10 : 6, 3, 'bands'); b.ns('m', `${Vt(wx + ww * 0.25, 40, 52)}${Vt(wx + ww * 0.75, 40, 52)}`, 1.5, 5);
    } else if (k === 1) {
      // Two portrait screens hung either side, the treads up to the deck at its centre, a microphone on its stand.
      const sw = wide ? 60 : 44, sh = 84, y = 50;
      [[l + 22, 0], [r - 22 - sw, 3]].forEach(([x, s0]) => { led(b, x, y, sw, sh, 2, 4, 'diag', s0); b.ns('m', Vt(x + sw / 2, 40, y - 2), 1.5, 5); });
      b.nf('p1', `${R(W / 2 - 30, 156, 60, 4)}${R(W / 2 - 24, 160, 48, 4)}`, 1);
      b.ns('w', `${Vt(W / 2, 104, 146)}${H(W / 2 - 9, W / 2 + 9, 146)}M${W / 2} 104l10-8`, 1.5, 5); b.nf('p4', R(W / 2 + 8, 92, 5, 6), 5);
    } else {
      // A riser on the deck, its fascia lit tile by tile; an LED header under the span; PA hung either side.
      lineArray(b, l + 34, 52, 4); lineArray(b, r - 34, 52, 4);
      const hw = wide ? 300 : 120, hx = (W - hw) / 2, rw = wide ? 200 : 112, rx = (W - rw) / 2;
      led(b, hx, 50, hw, 18, wide ? 12 : 6, 1, 'diag', 2); b.ns('m', `${Vt(hx + 10, 40, 48)}${Vt(hx + hw - 10, 40, 48)}`, 1.5, 5);
      b.nf('p1', R(rx, 112, rw, 4), 2); led(b, rx, 116, rw, 30, 7, 1, 'diag');
    }
  },
  (b, { W, k }) => { // Tiered risers, their fronts in the bars, under a truss (flat, or curved as a choir stand); or a stage on braced legs, its fascia the bars, treads up at one end and PA hung either side.
    const tw = W > 320 ? 1.6 : 1, wide = W > 320;
    lattice(b, wide ? 30 : 40, 22, W - (wide ? 30 : 40), 34);
    if (k === 2) {
      const x0 = wide ? 112 : 78, x1 = W - (wide ? 52 : 34), top = 98, fh = 20, foot = 156, fw = (x1 - x0) / 7, tread = wide ? 15 : 12;
      b.nf('p4', R(x0, top - 3, x1 - x0, 3), 3);
      for (let i = 0; i < 7; i++) b.f(i, R(x0 + i * fw, top, fw + 0.3, fh));
      // The legs, every deck's width, braced corner to corner; the treads, four steps up to the deck.
      const legs = wide ? 8 : 5, lp = (x1 - x0 - 8) / (legs - 1); let lg = '', br = '';
      for (let i = 0; i < legs; i++) { const x = x0 + 4 + i * lp; lg += Vt(x, top + fh, foot); if (i < legs - 1) br += i % 2 ? Ln(x, foot - 4, x + lp, top + fh + 4) : Ln(x, top + fh + 4, x + lp, foot - 4); }
      b.ns('m', lg, 2.5, 4); b.ns('d', br, 1.5, 3);
      let st = ''; for (let j = 0; j < 4; j++) { const h = ((j + 1) * (foot - top)) / 4; st += R(x0 - (4 - j) * tread, foot - h, tread + 0.3, h); }
      b.nf('p2', st, 3); b.ns('x', `${[1, 2, 3].map((j) => Vt(x0 - (4 - j) * tread, foot - ((j + 1) * (foot - top)) / 4, foot)).join('')}`, 1, 4);
      lineArray(b, x0 + 14, 46, 5); lineArray(b, x1 - 14, 46, 5);
      b.ns('m', H(26, W - 26, foot), 2, 5);
      return;
    }
    [[210, 0], [166, 2], [122, 4], [78, 6]].forEach(([w0, s], i) => {
      const w = w0 * tw, x = (W - w) / 2, y = 140 - i * 22;
      if (!k) { b.nf('p1', R(x, y - 4, w, 4), 1); b.f(s, R(x, y, w, 18)); }
      else { const c = 10 + i * 2; b.nf('p1', `M${n(x)} ${n(y - 4)}Q${n(W / 2)} ${n(y - 4 - c)} ${n(x + w)} ${n(y - 4)}v4Q${n(W / 2)} ${n(y - c)} ${n(x)} ${n(y)}z`, 1); b.f(s, `M${n(x)} ${n(y)}Q${n(W / 2)} ${n(y - c)} ${n(x + w)} ${n(y)}v18Q${n(W / 2)} ${n(y + 18 - c)} ${n(x)} ${n(y + 18)}z`); }
    });
    b.ns('m', `${Vt(W / 2, 34, 50)}${H(W / 2 - 14, W / 2 + 14, 50)}`, 1.5, 5); b.q(1, R(W / 2 - 9, 50, 18, 4), 3);
    b.ns('m', H(30, W - 30, 160), 2, 5);
  },
  (b, { W, k }) => { // A proscenium: the house curtains are the bars, the border across the top in the house's grey (a lectern in its pool; or seen from the house, a thrust running out from it).
    if (k === 1) {
      // From the house: the arch further back, its curtains drawn, the thrust running out towards us, its fascia in the bars.
      const wide = W > 320, l = wide ? 170 : 96, r = W - l, top = 26, fl = 100, cw = wide ? 17 : 13, run = wide ? 70 : 52;
      b.ns('w', `M${n(l)} ${fl}V${top}H${n(r)}V${fl}`, 2.5, 5);
      for (let i = 0; i < 3; i++) {
        const x = l + 4 + i * cw * 0.8, rx = r - 4 - (i + 1) * cw * 0.8, inn = (i + 1) * 3;
        b.f(i, P([[x, top + 8], [x + cw, top + 8], [x + cw - inn, fl - 26], [x + cw - inn / 2, fl], [x, fl]]));
        b.f(4 + i, P([[rx, top + 8], [rx + cw, top + 8], [rx + cw, fl], [rx + inn / 2, fl], [rx + inn, fl - 26]]));
      }
      const sc = Math.round((r - l) / 14);
      b.nf('p3', P([[l, top], [r, top], [r, top + 8], ...Array.from({ length: sc }, (_, i) => [r - ((i + 0.5) * (r - l)) / sc, i % 2 ? top + 8 : top + 13]), [l, top + 8]]), 3);
      b.nf('p2', P([[l, fl], [r, fl], [r + run, 142], [l - run, 142]]), 1); b.ns('m', H(l, r, fl), 1.5, 5);
      const fw = (r - l + 2 * run) / 7; for (let i = 0; i < 7; i++) b.f(i, R(l - run + i * fw, 142, fw + 0.3, 12), 2);
      b.nf('h', Pc([[W / 2 - 5, top + 14], [W / 2 + 5, top + 14], [W / 2 + 34, 124], [W / 2 - 34, 124]]), 1); b.q(1, E(W / 2, 124, 34, 6), 2);
      b.ns('d', H(26, W - 26, 160), 1.5, 0);
      return;
    }
    const l = W > 320 ? 60 : 52, r = W - l, cw = 20 * (W > 320 ? 1.4 : 1) * (k === 2 ? 0.7 : 1), step = k === 1 ? 0.8 : k === 2 ? 0.9 : 1, open = k ? 1.25 : 1;
    b.ns('w', `M${n(l)} 158V28H${n(r)}V158`, 3, 5);
    for (let i = 0; i < 3; i++) {
      const x = l + 6 + i * cw * step, rx = r - 6 - (i + 1) * cw * step, inn = (i + 1) * 4 * open;
      b.f(i, P([[x, 40], [x + cw, 40], [x + cw - inn, 108], [x + cw - inn / 2, 150], [x, 150]]));
      b.f(4 + i, P([[rx, 40], [rx + cw, 40], [rx + cw, 150], [rx + inn / 2, 150], [rx + inn, 108]]));
    }
    const sc = Math.round((r - l) / 16);
    b.nf('p3', P([[l, 28], [r, 28], [r, 40], ...Array.from({ length: sc }, (_, i) => [r - ((i + 0.5) * (r - l)) / sc, i % 2 ? 40 : 46]), [l, 40]]), 3);
    if (k === 1) return;
    if (k === 2) {
      // A lectern at centre, its microphone, in the spotlight's pool.
      b.q(3, E(W / 2 + 30, 148, 34, 5), 2); b.nf('p3', P([[W / 2 + 14, 112], [W / 2 + 46, 112], [W / 2 + 42, 148], [W / 2 + 18, 148]]), 4); b.nf('p1', R(W / 2 + 12, 108, 36, 5), 4);
      b.ns('w', `M${n(W / 2 + 22)} 108l-6-14`, 1.5, 5); b.nf('p4', R(W / 2 + 13, 90, 5, 6), 5);
    } else b.f(3, R(W / 2 - 18, 140, 36, 6), 2);
    b.nf('p2', R(l - 8, 150, r - l + 16, 8), 1); b.ns('m', H(l - 16, r + 16, 150), 2, 5);
  },
  (b, { W, k }) => { // Rigging: chain hoists on a truss holding an LED wall (a second truss flown on the chains with tiles hung at their own trims, or a truss flown on a rake with a banner of tiles under it).
    const wide = W > 320;
    lattice(b, wide ? 30 : 36, 22, W - (wide ? 30 : 36), 34);
    const hoist = (x, to) => { b.ns('m', Vt(x, 34, 46), 1.5, 4); b.nf('p3', R(x - 7, 46, 14, 12), 4); b.ns('m', Vt(x, 58, to), 2, 4, ' stroke-dasharray="2 1.5"'); };
    if (k === 2) {
      // Two hoists at their own drops hold a truss on a rake; the banner's seven tiles hang from it, each a step lower.
      const x0 = wide ? 130 : 70, x1 = W - (wide ? 130 : 70), y0 = 64, y1 = 92, d = 9, c = Math.round((x1 - x0) / 9);
      hoist(x0 + 16, y0 + (y1 - y0) * (16 / (x1 - x0))); hoist(x1 - 16, y1 - (y1 - y0) * (16 / (x1 - x0)));
      const at2 = (t, dy = 0) => [x0 + (x1 - x0) * t, y0 + (y1 - y0) * t + dy];
      b.ns('w', `${Ln(...at2(0), ...at2(1))}${Ln(...at2(0, d), ...at2(1, d))}${P(Array.from({ length: c + 1 }, (_, i) => at2(i / c, i % 2 ? d : 0)), false)}`, 1.5, 5);
      const tw = (x1 - x0) / 7 - 4, th = 30; let cab = '', seam = '';
      for (let i = 0; i < 7; i++) {
        const [cx, cy] = at2((i + 0.5) / 7, d), y = cy + 8;
        cab += `${Vt(cx - tw / 4, cy, y)}${Vt(cx + tw / 4, cy, y)}`; b.f(i, R(cx - tw / 2, y, tw, th)); seam += H(cx - tw / 2, cx + tw / 2, y + th / 2);
      }
      b.ns('m', cab, 1, 4, ' stroke-dasharray="2 1.5"'); b.ns('x', seam, 1, 4);
      lineArray(b, x0 - 30, 52, 5); lineArray(b, x1 + 30, 52, 5); b.ns('m', `${Vt(x0 - 30, 34, 40)}${Vt(x1 + 30, 34, 40)}`, 1.5, 4);
      b.ns('d', H(26, W - 26, 158), 1.5, 0);
      return;
    }
    const sw = wide ? 300 : [168, 224][k], sx = (W - sw) / 2, hoists = wide ? [0.08, 0.36, 0.64, 0.92] : [0.12, 0.5, 0.88];
    hoists.forEach((t) => hoist(sx + sw * t, k === 1 ? 82 : 78));
    if (k === 0) led(b, sx, 78, sw, 60, wide ? 10 : 6, 3, 'diag');
    else {
      // A second truss flown on the chains, seven LED tiles hung from it on their cables at their own trims, each a
      // bar in order: a hung set piece, not a light (no beams).
      lattice(b, sx, 82, sx + sw, 92, 8, true, 'w', 1.2);
      const tw = wide ? 30 : 24, th = wide ? 38 : 36, pitch = (sw - 24 - tw) / 6, drop = [6, 16, 2, 12, 20, 4, 14];
      let cab = '', seam = '';
      for (let i = 0; i < 7; i++) {
        const x = sx + 12 + i * pitch, y = 98 + drop[i];
        cab += `${Vt(x + tw * 0.25, 92, y)}${Vt(x + tw * 0.75, 92, y)}`; b.f(i, R(x, y, tw, th)); seam += H(x, x + tw, y + th / 2) + Vt(x + tw / 2, y, y + th);
      }
      b.ns('m', cab, 1, 4, ' stroke-dasharray="2 1.5"'); b.ns('x', seam, 1, 4);
    }
    b.ns('d', H(26, W - 26, 158), 1.5, 0);
  },
];

// ---------------------------------------------------------------------------------------------------- Production
M.production = [
  (b, { W, k }) => { // A cue list: seven cues, each with its colour, its name and its time, the next one framed (beside the next cue's own panel; or four large, the running one showing how far it has run).
    const wide = W > 320;
    if (k === 2) {
      const x = wide ? 70 : 34, w = W - 2 * x, rh = 28, gap = 6, y0 = 26, lens = [92, 128, 70, 110], next = 2;
      for (let i = 0; i < 4; i++) {
        const y = y0 + i * (rh + gap);
        b.nf('p0', R(x, y, w, rh), 0); b.nf('p2', R(x + 7, y + 10, 18, 8), 1); b.f(i + 1, R(x + 32, y, 14, rh));
        b.nf('p3', `${R(x + 56, y + 8, lens[i] * (wide ? 1.9 : 1), 5)}${R(x + 56, y + 17, lens[i] * (wide ? 1.1 : 0.6), 4)}`, 1);
        b.f(i + 1, R(x + w - 14 - (wide ? 70 : 44), y + 9, wide ? 70 : 44, 10));
      }
      b.f(next, R(x + 46, y0 + (next - 1) * (rh + gap) + rh - 4, (w - 60) * 0.58, 4), 3);
      const ny = y0 + next * (rh + gap);
      b.ns('w', R(x - 1.5, ny - 1.5, w + 3, rh + 3), 1.5, 5); b.nf('p4', P([[x - 16, ny + 6], [x - 5, ny + rh / 2], [x - 16, ny + rh - 6]]), 5);
      return;
    }
    const lens = [[96, 70, 120, 84, 60, 104, 78], [70, 112, 84, 60, 98, 76, 120]][k], next = [2, 4][k];
    const x = wide ? 60 : 36, w = k === 1 ? (wide ? 300 : 150) : W - 2 * x, rh = 16, gap = 3, y0 = 24, ls = k === 1 ? (wide ? 1.3 : 0.5) : wide ? 1.3 : 0.72;
    for (let i = 0; i < 7; i++) {
      const y = y0 + i * (rh + gap);
      b.nf('p0', R(x, y, w, rh), 0); b.nf('p2', R(x + 5, y + 5, 14, 6), 1);
      b.f(i, R(x + 24, y, 11, rh)); b.nf('p3', R(x + 42, y + 6, lens[i] * ls, 4), 1);
      b.f(i, R(x + w - 12 - lens[i] * (k === 1 ? 0.24 : 0.42), y + 4, lens[i] * (k === 1 ? 0.24 : 0.42), 8));
      if (wide && k === 0) b.nf('p3', R(x + w - 120, y + 5, 30, 6), 1);
    }
    const ny = y0 + next * (rh + gap);
    b.ns('w', R(x - 1.5, ny - 1.5, w + 3, rh + 3), 1.5, 5); b.nf('p4', P([[x - 16, ny + 2], [x - 6, ny + rh / 2], [x - 16, ny + rh - 2]]), 5);
    if (k === 1) {
      // The next cue's panel: its colour across the top, its lines, the follow-on beneath.
      // A panel of colour this large is lone, so it takes the calm run (never green or red), as do its marks.
      const px = x + w + 14, pw = W - (wide ? 60 : 30) - px, py = y0, ph = 7 * (rh + gap) - gap;
      b.both('pd', R(px, py, pw, ph), 1.5, 0); b.q(3, R(px, py, pw, 34), 1);
      b.nf('p3', `${R(px + 10, py + 46, pw * 0.7, 6)}${R(px + 10, py + 60, pw * 0.5, 4)}${R(px + 10, py + 70, pw * 0.58, 4)}`, 2);
      b.nf('p2', `${R(px + 10, py + 92, pw - 20, 18)}${R(px + 10, py + 114, pw - 20, 12)}`, 2); b.q(1, R(px + 10, py + 92, 6, 18), 3);
      b.ns('m', `M${n(x + w + 3)} ${ny + rh / 2}H${n(px - 3)}`, 1.5, 4);
    }
  },
  (b, { W, k }) => { // A show timeline: tracks of clips, a ruler, the playhead (four tracks; running down the page in columns; or close in, two long tracks and the sound under them).
    const right = W - 30, wide = W > 320;
    if (k === 1) {
      // The tracks as columns, time running down: the ruler at the left, the playhead across.
      const cols = wide ? 7 : 4, cw = wide ? 58 : 50, g = 8, x0 = (W - (cols * cw + (cols - 1) * g)) / 2 + 10, top = 38, foot = 158;
      b.ns('d', `M${n(x0 - 20)} ${top}V${foot}`, 6, 0, ' stroke-dasharray="1 9"');
      const clips = [[[0, 30, 0], [36, 46, 3], [88, 28, 5]], [[12, 54, 1], [72, 44, 4]], [[0, 22, 2], [28, 38, 6], [72, 46, 0]], [[20, 40, 5], [66, 30, 2], [100, 18, 4]]];
      for (let c = 0; c < cols; c++) {
        const x = x0 + c * (cw + g); b.nf('p1', R(x, top - 18, cw, 12), 0); b.nf('p0', R(x, top, cw, foot - top), 0);
        clips[c % 4].forEach(([y, h, s0]) => { const yy = top + 2 + y, slot = s0 + c * 3; b.f(slot, R(x + 3, yy, cw - 6, h - 3)); b.nf('p5', R(x + 3 + (cw - 6) * 0.68, yy, (cw - 6) * 0.32, h - 3), 3, 'fill-opacity=".45"'); });
      }
      const ph = 104; b.ns('w', H(x0 - 14, x0 + cols * (cw + g) - g + 4, ph), 2, 5); b.nf('p4', P([[x0 - 28, ph - 7], [x0 - 22, ph - 7], [x0 - 16, ph], [x0 - 22, ph + 7], [x0 - 28, ph + 7]]), 5);
      return;
    }
    if (k === 2) {
      // Close in: the ruler's ticks large, two long tracks of clips and the sound's waveform under them.
      b.ns('d', H(40, right, 30), 8, 0, ' stroke-dasharray="1.5 23.5"'); b.ns('d', H(40, right, 32), 4, 0, ' stroke-dasharray="1 4"');
      const scale = wide ? 1.9 : 1, clips = [[0, 64, 96, 2], [0, 166, 112, 5], [1, 64, 60, 0], [1, 130, 140, 3]];
      for (let t = 0; t < 2; t++) { b.nf('p1', R(30, 44 + t * 40, 26, 34), 0); b.nf('p0', R(60, 44 + t * 40, right - 60, 34), 0); }
      clips.forEach(([t, x, w, s0]) => { const xx = 60 + (x - 64) * scale + 4, ww = Math.min(w * scale - 2, right - xx - 2), y = 44 + t * 40; b.f(s0, R(xx, y, ww, 34)); b.nf('p5', R(xx, y + 23, ww, 11), 3, 'fill-opacity=".45"'); });
      b.nf('p1', R(30, 128, 26, 30), 0); b.nf('p0', R(60, 128, right - 60, 30), 0);
      let wave = ''; for (let x = 66; x < right - 4; x += 5) { const a = 3 + 11 * Math.abs(Math.sin(x * 0.09) * Math.cos(x * 0.031)); wave += Vt(x, 143 - a, 143 + a); }
      b.ns('m', wave, 2.4, 2);
      const ph = wide ? 300 : 170;
      b.ns('w', Vt(ph, 36, 162), 2, 5); b.nf('p4', P([[ph - 8, 22], [ph + 8, 22], [ph + 8, 30], [ph, 38], [ph - 8, 30]]), 5);
      return;
    }
    b.ns('d', H(40, right, 32), 6, 0, ' stroke-dasharray="1 9"');
    const set = [[0, 64, 52, 0], [0, 122, 40, 1], [0, 168, 88, 2], [1, 74, 84, 3], [1, 164, 52, 4], [1, 222, 50, 5], [2, 64, 36, 6], [2, 106, 112, 0], [2, 224, 44, 2], [3, 88, 60, 4], [3, 154, 86, 6]];
    const scale = wide ? 2.06 : 1, th = 22, tp = 27;
    for (let t = 0; t < 4; t++) { b.nf('p1', R(30, 44 + t * tp, 26, th), 0); b.nf('p0', R(60, 44 + t * tp, right - 60, th), 0); }
    set.forEach(([t, x, w, s0]) => { const xx = 60 + (x - 64) * scale + 4, ww = w * scale - 2, y = 44 + t * tp; b.f(s0, R(xx, y, ww, th)); b.nf('p5', R(xx, y + th * 0.68, ww, th * 0.32), 3, 'fill-opacity=".45"'); });
    const ph = wide ? 352 : 196;
    b.ns('w', Vt(ph, 36, 156), 2, 5); b.nf('p4', P([[ph - 7, 28], [ph + 7, 28], [ph + 7, 34], [ph, 40], [ph - 7, 34]]), 5);
  },
  (b, { W, k }) => { // Follow-ons: each cue starts where the last one ends, down the stack, up it, or in two lanes.
    const wide = W > 320;
    if (k === 2) {
      const lanes = [[44, [0, 2, 4, 6]], [104, [1, 3, 5]]], cw = wide ? 112 : 58, gap = wide ? 18 : 8;
      lanes.forEach(([y, slots], l) => {
        const x0 = (wide ? 44 : 30) + l * (cw / 2 + gap / 2);
        slots.forEach((s, i) => { const x = x0 + i * (cw + gap); b.f(s, R(x, y, cw, 22)); b.nf('p5', R(x + 5, y + 8, 22, 4), 3, 'fill-opacity=".5"'); if (i < slots.length - 1) b.ns('m', H(x + cw, x + cw + gap, y + 11), 1.5, 4); });
      });
      b.ns('m', `M${n((wide ? 44 : 30) + cw / 2)} 66V104M${n((wide ? 44 : 30) + 2 * (cw + gap) + cw / 2)} 66V104`, 1.5, 4, ' stroke-dasharray="2 2"');
      b.ns('d', `${H(26, W - 26, 140)}`, 1, 0);
      return;
    }
    // The stair and its axes keep clear of the safe-area corners.
    const steps = 7, cw = wide ? 120 : 62, pitch = wide ? 66 : 31, x0 = (W - ((steps - 1) * pitch + cw)) / 2;
    const lens = k ? [0.7, 1, 0.82, 1.12, 0.64, 0.94, 0.86] : [1, 1, 1, 1, 1, 1, 1];
    for (let i = 0; i < steps; i++) {
      const x = x0 + i * pitch, y = k ? 136 - i * 18 : 26 + i * 18, w = cw * lens[i];
      b.f(i, R(x, y, w, 12)); b.nf('p5', R(x + 4, y + 4.5, 22, 3), 3, 'fill-opacity=".5"');
      if (i < steps - 1) b.ns('m', k ? `M${n(x + pitch * 0.6)} ${y}V${y - 6}` : `M${n(x + w)} ${y + 6}H${n(x + Math.max(w, pitch) + 4)}V${y + 18}`, 1.5, 4);
    }
    b.ns('d', `${Vt(x0 - 6, 30, 158)}${H(x0 - 6, W - 30, 158)}`, 1, 0);
  },
  (b, { W, k }) => { // The stack itself: cue cards, the next in front with its colour band (fanned in a hand, or laid out in a row).
    const card = (cx, cy, w, h, deg, front, s0) => {
      const pts = rot(rectPts(cx - w / 2, cy - h / 2, w, h), cx, cy + h, deg);
      if (front) {
        b.both('qw', Pc(pts), 2, 3);
        const top = rot(rectPts(cx - w / 2, cy - h / 2, w, h * 0.2), cx, cy + h, deg);
        for (let i = 0; i < 7; i++) { const t0 = i / 7, t1 = (i + 1) / 7 + 0.003; const L = (p, q, t) => [p[0] + (q[0] - p[0]) * t, p[1] + (q[1] - p[1]) * t]; b.f(s0 + i, P([L(top[0], top[1], t0), L(top[0], top[1], t1), L(top[3], top[2], t1), L(top[3], top[2], t0)]), 4); }
        [[0.12, 0.36, 0.5], [0.12, 0.5, 0.76], [0.12, 0.62, 0.66], [0.12, 0.74, 0.7]].forEach(([lx, ly, lw]) => b.nf('p3', P(rot(rectPts(cx - w / 2 + w * lx, cy - h / 2 + h * ly, w * lw, h * 0.05), cx, cy + h, deg)), 4));
      } else { b.both('pm', Pc(pts), 1.5, 1); b.f(s0, P(rot(rectPts(cx - w / 2, cy - h / 2, w, 5), cx, cy + h, deg)), 1.2); }
    };
    const X = (W - 320) / 2, wide = W > 320;
    const lists = (pairs) => { for (const [x, s] of pairs) for (let i = 0; i < 6; i++) { b.nf('p0', R(x, 36 + i * 18, 114, 14), 0); b.f(s + i, R(x + 6, 36 + i * 18, 5, 14)); b.nf('p2', R(x + 16, 41 + i * 18, 34 + ((i * 29) % 50), 4), 1); } };
    if (k === 0) {
      for (let j = 3; j >= 1; j--) card(X + 160 + j * 12, 92 - j * 10, 132, 92, 0, false, j + 3);
      card(X + 160, 98, 132, 96, 0, true, 0);
      if (wide) lists([[24, 1], [438, 5]]);
    } else if (k === 1) {
      [-16, -8].forEach((deg, j) => card(X + 160, 74, 120, 92, deg, false, 4 + j));
      card(X + 160, 74, 120, 92, 7, true, 0);
      if (wide) lists([[24, 2], [438, 4]]);
    } else {
      // The cue board: cards laid out in a row, the next one raised and banded.
      const count = wide ? 7 : 4, cw = 62, g = 10, x0 = (W - (count * cw + (count - 1) * g)) / 2, nx = wide ? 3 : 1;
      for (let i = 0; i < count; i++) if (i !== nx) { const x = x0 + i * (cw + g); b.both('pm', R(x, 64, cw, 78), 1.5, 1); b.f(i + 1, R(x, 64, cw, 6), 1.2); b.nf('p2', `${R(x + 8, 82, cw - 22, 4)}${R(x + 8, 92, cw - 30, 4)}`, 1.3); }
      card(x0 + nx * (cw + g) + cw / 2, 80, cw + 18, 96, 0, true, 0);
      b.ns('d', H(26, W - 26, 150), 1, 0);
    }
  },
];

// --------------------------------------------------------------------------------------------------- Showcalling
function hands(b, cx, cy, r, hour = -50, minute = 60) {
  b.ns('w', Ln(cx, cy, ...at(cx, cy, r * 0.55, hour)), 3, 6); b.ns('w', Ln(cx, cy, ...at(cx, cy, r * 0.85, minute)), 2, 6); b.nf('p4', O(cx, cy, 3.5), 6);
}
// A digit on a seven-segment display: the segments lit for it in the calm run's slot (a time is never green or red,
// which would read as on time or over), the others unlit.
const SEG = { 0: 'abcdef', 1: 'bc', 2: 'abdeg', 3: 'abcdg', 4: 'bcfg', 5: 'acdfg', 6: 'acdefg', 7: 'abc', 8: 'abcdefg', 9: 'abcdfg' };
function digit(b, x, y, w, h, d, slot) {
  // Each segment a bar with a gap at the corners, as a display's segments are drawn.
  const t = Math.max(3, w * 0.16), g = t * 0.35, m = y + h / 2 - t / 2;
  const segs = { a: [x + t, y, w - 2 * t, t], g: [x + t, m, w - 2 * t, t], d: [x + t, y + h - t, w - 2 * t, t], f: [x, y + t - g, t, h / 2 - t], b: [x + w - t, y + t - g, t, h / 2 - t], e: [x, m + t + g, t, h / 2 - t], c: [x + w - t, m + t + g, t, h / 2 - t] };
  let lit = '', unlit = ''; for (const [key, r] of Object.entries(segs)) { if (SEG[d].includes(key)) lit += R(...r); else unlit += R(...r); }
  if (lit) b.q(slot, lit, 3); if (unlit) b.nf('p0', unlit, 1);
}
function colon(b, x, y, h) { b.nf('p3', `${R(x - 2.5, y + h * 0.3, 5, 5)}${R(x - 2.5, y + h * 0.65, 5, 5)}`, 3); }
function countdown(b, cx, cy, r, lit, hour, minute, layer = 2) {
  const pitch = (2 * Math.PI * r) / 60, dash = 2.4, half = (dash / 2 / (2 * Math.PI * r)) * 360, per = lit / 7;
  for (let i = 0; i < 7; i++) b.s(i, A(cx, cy, r, i * per * 6 - half, (i + 1) * per * 6 - half), 12, layer, ` stroke-dasharray="${n(dash)} ${n(pitch - dash)}"`);
  b.ns('u', A(cx, cy, r, lit * 6 - half, 360 - half - 0.01), 12, 1, ` stroke-dasharray="${n(dash)} ${n(pitch - dash)}"`);
  b.ns('d', O(cx, cy, r - 16), 1.5, 0); hands(b, cx, cy, r - 16, hour, minute);
}
M.showcalling = [
  (b, { W, k }) => { // A countdown clock: sixty ticks, those still to run lit in runs of colour (on the band, timecode beside it).
    const lit = [42, 35, 21][k], hh = [-30, 120, 200][k], mm = [252, 210, 126][k];
    if (W === 320) { countdown(b, 160, 90, 62, lit, hh, mm); return; }
    countdown(b, 112, 90, 62, lit, hh, mm);
    const digits = [[0, 0, 4, 2], [0, 0, 3, 5], [0, 0, 2, 1]][k], dw = 46, dh = 82, y = 49;
    [[214, 0], [270, 1], [346, 2], [402, 3]].forEach(([x, i]) => digit(b, x, y, dw, dh, digits[i], i * 2));
    colon(b, 331, y, dh); b.nf('p2', `${R(466, y + 50, 26, 32)}${R(500, y + 50, 26, 32)}`, 1);
    b.q(3, R(466, y + 50, 26, 32 * [0.7, 0.4, 0.9][k]), 2); b.q(4, R(500, y + 50, 26, 32 * [0.3, 0.8, 0.5][k]), 2);
  },
  (b, { W, k }) => { // A slate beside a clock: the clapper's stripes are the bars; the dial's ring is timecode.
    const X = (W - 320) / 2, swap = k === 2;
    const dial = (cx, cy, r, s0, hh, mm) => { for (let i = 0; i < 7; i++) b.s(s0 + i, A(cx, cy, r + 8, i * (360 / 7) + 3, (i + 1) * (360 / 7) - 3), 7, 2); b.ns('d', O(cx, cy, r - 4), 1.5, 0); b.ns('m', O(cx, cy, r - 9), 3, 0, ' stroke-dasharray="1.5 10.15"'); hands(b, cx, cy, r - 4, hh, mm); };
    dial(X + (swap ? 222 : 98), 92, 48, [0, 3, 5][k], [40, 150, 260][k], [160, 300, 20][k]);
    const x = X + (swap ? 34 : 182), y = 70, w = 104;
    b.both('pm', R(x, y, w, 74), 2, 0);
    b.ns('d', `${H(x, x + w, y + 25)}${H(x, x + w, y + 50)}${Vt(x + w / 2, y + 25, y + 74)}`, 1.5, 4);
    b.nf('p2', `${R(x + 8, y + 8, 40, 8)}${R(x + 8, y + 33, 30, 8)}${R(x + w / 2 + 8, y + 33, 34, 8)}${R(x + 8, y + 58, 26, 8)}${R(x + w / 2 + 8, y + 58, 22, 8)}`, 3);
    // The clapper: shut along the slate's top, or raised.
    const sticks = (deg) => { const pivot = [x, y - 4]; for (let i = 0; i < 7; i++) b.c(i, P(rot([[x + i * 15, y - 4], [x + i * 15 + 15, y - 4], [x + i * 15 + 9, y - 16], [x + i * 15 - 6, y - 16]], ...pivot, deg)), 3); b.ns('m', Ln(...pivot, ...rot([[x + w, y - 4]], ...pivot, deg)[0]), 1.5, 5); };
    if (k === 1) { sticks(-16); b.ns('m', H(x, x + w, y - 1), 1.5, 5); } else sticks(0);
    if (W > 320) dial(swap ? 470 : 470, 92, 40, [3, 0, 2][k], [100, -60, 30][k], [230, 90, 300][k]);
  },
  (b, { W, k }) => { // Timers, each ring at its own count (or one large, two small).
    const counts = [[7, 5, 3, 6, 2], [3, 6, 2, 7, 4], [6, 4, 2, 5, 7]][k];
    const ring = (cx, cy, r, lit, j, wd) => { for (let i = 0; i < 7; i++) { const seg = A(cx, cy, r, i * (360 / 7) + 3, (i + 1) * (360 / 7) - 3); if (i < lit) b.s(i + j * 2, seg, wd, 2); else b.ns('u', seg, wd, 1); } b.ns('d', O(cx, cy, r - wd - 2), 1.5, 0); hands(b, cx, cy, r - wd - 2, 90 + j * 70 + k * 40, 300 - j * 40); };
    if (k === 1) {
      // One ring large at the left, and three timers laid along beside it as bars that run down segment by segment.
      const wide = W > 320, cx = wide ? 120 : 82; ring(cx, 90, 56, counts[0], 0, 11);
      const x = cx + 76, w = W - (wide ? 60 : 34) - x, sg = (w - 12) / 7, rows = wide ? [44, 72, 100, 128] : [52, 84, 116];
      rows.forEach((y, j) => { for (let i = 0; i < 7; i++) { const seg = R(x + i * (sg + 2), y, sg, 14); if (i < counts[j + 1]) b.f(i + j * 2, seg, 2); else b.nf('p1', seg, 1); } b.nf('p2', R(x, y + 18, w * 0.3, 3), 1); });
      return;
    }
    if (k === 2) {
      ring(W / 2 - (W > 320 ? 0 : 36), 90, 58, counts[0], 0, 11);
      (W > 320 ? [[110, 62, 1], [110, 122, 2], [466, 62, 3], [466, 122, 4]] : [[244, 56, 1], [244, 124, 2]]).forEach(([cx, cy, j]) => ring(cx, cy, 26, counts[j], j, 6));
      return;
    }
    const r = W > 320 ? 38 : 40, count = W > 320 ? 5 : 3, pitch = W > 320 ? 106 : 100, x0 = W / 2 - ((count - 1) * pitch) / 2;
    for (let j = 0; j < count; j++) ring(x0 + j * pitch, 90, r, counts[j], j, 8);
  },
  (b, { W, k }) => { // A clock whose face is the bars, seven sectors (a ring of them, or the hour so far), the hands over them.
    const draw = (cx, r, s0, hh, mm, layer = 2) => {
      if (k === 1) for (let i = 0; i < 7; i++) b.f(s0 + i, Ring(cx, 90, r * 0.72, r, (i * 360) / 7 + 1.5, ((i + 1) * 360) / 7 - 1.5), layer);
      else { const upto = k === 2 ? 5 : 7; for (let i = 0; i < 7; i++) { const d = Wedge(cx, 90, r, (i * 360) / 7, ((i + 1) * 360) / 7 + (i < 6 ? 0.6 : 0)); if (i < upto) b.f(s0 + i, d, layer); else b.nf('p1', d, layer); } b.nf('p5', O(cx, 90, r * 0.69), layer + 1); }
      b.ns('m', O(cx, 90, r * 0.59), 3, 4, ` stroke-dasharray="1.5 ${n((2 * Math.PI * r * 0.59) / 12 - 1.5)}"`);
      b.ns('d', O(cx, 90, r + 6), 1.5, 0); hands(b, cx, 90, r * 0.62, hh, mm);
    };
    if (k === 1) {
      // The clock at the left, and the time it shows beside it on a seven-segment display.
      // One display, so one colour: the calm run's, as every time is.
      const wide = W > 320, cx = wide ? 110 : 88; draw(cx, 56, 0, 110, 330);
      const dw = wide ? 56 : 36, dh = wide ? 84 : 62, y = 90 - dh / 2, x0 = cx + (wide ? 90 : 82), gap = wide ? 14 : 9;
      const xs = [x0, x0 + dw + 2 * gap + 6, x0 + 2 * dw + 3 * gap + 6].concat(wide ? [x0 + 3 * dw + 5 * gap + 12] : []), ds = [3, 5, 5, 0];
      xs.forEach((x, i) => digit(b, x, y, dw, dh, ds[i], 1));
      colon(b, x0 + dw + gap + 3, y, dh); if (wide) colon(b, x0 + 3 * dw + 4 * gap + 9, y, dh);
      return;
    }
    draw(W / 2, 62, 0, [-60, 110, 30][k], [70, 330, 257][k]);
    if (W > 320) { draw(110, 40, 3, [20, 200, 140][k], [150, 300, 40][k]); draw(466, 40, 5, [-80, 60, 250][k], [40, 190, 120][k]); }
  },
];

// ----------------------------------------------------------------------------------------------------- Streaming
function mast(b, cx, cy, foot = 156) {
  b.ns('w', `M${cx} ${cy + 10}L${cx - 22} ${foot}M${cx} ${cy + 10}L${cx + 22} ${foot}M${cx - 13} ${cy + 46}H${cx + 13}M${cx - 18} ${cy + 66}H${cx + 18}M${cx - 11} ${cy + 46}L${cx + 18} ${cy + 66}M${cx + 11} ${cy + 46}L${cx - 18} ${cy + 66}`, 2, 5);
}
function playScreen(b, x, y, w, h, s0, frame = 'w') {
  const pts = [[x + w * 0.36, y + h * 0.18], [x + w * 0.36, y + h * 0.82], [x + w * 0.7, y + h * 0.5]];
  slice(pts, 7, 1, 3).forEach((d, i) => b.f(s0 + i, d)); b.ns(frame, R(x, y, w, h), 2, 5);
}
function dish(b, cx, cy, r, deg, ground = 156) {
  // A parabolic dish: its aperture faces deg (0 is up), the bowl behind it, the feed in front; on a post and feet.
  const rim = []; for (let t = -1; t <= 1.001; t += 0.125) rim.push([cx + r * t, cy + r * 0.45 * (1 - t * t)]);
  const pts = rot([[cx - r, cy], ...rim.slice(1, -1), [cx + r, cy]], cx, cy, deg);
  const [bx, by] = rot([[cx, cy + r * 0.45]], cx, cy, deg)[0];
  if (ground) b.ns('w', `M${n(bx)} ${n(by)}V${n(ground)}M${n(bx - 16)} ${n(ground)}H${n(bx + 16)}`, 2.5, 3);
  b.both('pw', P(pts), 2, 4);
  const [fx, fy] = rot([[cx, cy - r * 0.75]], cx, cy, deg)[0], [ax, ay] = rot([[cx - r * 0.7, cy - r * 0.02]], cx, cy, deg)[0], [cx2, cy2] = rot([[cx + r * 0.7, cy - r * 0.02]], cx, cy, deg)[0];
  b.ns('w', `${Ln(ax, ay, fx, fy)}${Ln(cx2, cy2, fx, fy)}`, 1.5, 5); b.nf('p4', O(fx, fy, 3), 6);
  return [fx, fy];
}
// A satellite: its body and two panels.
function satellite(b, x, y) { b.nf('p3', R(x - 11, y - 8, 22, 16), 4); b.ns('w', `${R(x - 46, y - 6, 30, 12)}${R(x + 16, y - 6, 30, 12)}${H(x - 16, x - 11, y)}${H(x + 11, x + 16, y)}${Vt(x - 31, y - 6, y + 6)}${Vt(x + 31, y - 6, y + 6)}`, 1.5, 5); }
// Wavefronts travelling from (x0, y0) toward (x1, y1): seven short arcs in the bars, each a step further along.
function wavefronts(b, x0, y0, x1, y1, w = 6, spread = 16, from = 0.18, to = 0.86) {
  const dist = Math.hypot(x1 - x0, y1 - y0), dir = (Math.atan2(x1 - x0, -(y1 - y0)) * 180) / Math.PI;
  for (let i = 0; i < 7; i++) { const r = dist * (from + ((to - from) * i) / 6); b.s(i, A(x0, y0, r, dir - spread, dir + spread), w, 2); }
}
// An outside-broadcast truck: its box, its cab, its wheels, a dish on its roof.
function truck(b, x, y) {
  b.both('pm', `M${x} ${y}h104v40h-104zM${x + 104} ${y + 12}h22l12 14v14h-34z`, 2, 3);
  b.nf('p1', R(x + 110, y + 16, 14, 9), 4); b.nf('p2', `${R(x + 10, y + 10, 40, 4)}${R(x + 10, y + 20, 26, 4)}`, 4);
  b.both('pd', `${O(x + 24, y + 42, 8)}${O(x + 82, y + 42, 8)}${O(x + 120, y + 42, 8)}`, 2, 5);
  return dish(b, x + 72, y - 14, 18, 30, 0);
}
M.streaming = [
  (b, { W, k }) => { // A mast broadcasting: arcs either side in the bars (all to one side, or relayed between two masts).
    if (k === 0) {
      const cx = W / 2, cy = 72, radii = W > 320 ? [22, 38, 54, 70] : [22, 38, 54];
      radii.forEach((r, i) => { b.s(i, A(cx, cy, r, 48, 132), 7, 2); b.s(6 - i, A(cx, cy, r, 228, 312), 7, 2); });
      b.q(2, O(cx, cy, 8), 3); mast(b, cx, cy);
      if (W > 320) [[60, 0], [516, 3]].forEach(([x, s]) => { b.ns('w', R(x - 24, 104, 48, 30), 2, 5); for (let i = 0; i < 7; i++) b.f(s + i, R(x - 21 + i * 6, 107, 6.3, 24)); b.ns('m', `${Vt(x, 134, 146)}${H(x - 10, x + 10, 146)}`, 2, 5); });
    } else if (k === 1) {
      const cx = W > 320 ? 96 : 64, cy = 76;
      for (let i = 0; i < 7; i++) b.s(i, A(cx, cy, 22 + i * (W > 320 ? 26 : 17), 50, 130), 7, 2);
      b.q(1, O(cx, cy, 8), 3); mast(b, cx, cy);
    } else {
      const a = W > 320 ? 90 : 62, c = W - a, cy = 72;
      mast(b, a, cy); mast(b, c, cy); b.q(0, O(a, cy, 7), 3); b.q(3, O(c, cy, 7), 3);
      wavefronts(b, a, cy, c, cy, 6, 22, 0.14, 0.86);
    }
  },
  (b, { W, k }) => { // A play button made of the bars, on a screen (on a laptop and a phone, or across three screens), with its signal.
    const X = (W - 320) / 2;
    if (k === 0) {
      playScreen(b, X + 84, 40, 152, 100, 0);
      [14, 26, 38].forEach((r) => b.ns('m', A(X + 236, 40, r, 190, 260), 3, 5));
      if (W > 320) { playScreen(b, 30, 62, 72, 46, 3, 'm'); playScreen(b, 474, 62, 72, 46, 5, 'm'); b.ns('m', `${Vt(66, 111, 124)}${H(52, 80, 124)}${Vt(510, 111, 124)}${H(496, 524, 124)}`, 2, 5); }
    } else if (k === 1) {
      playScreen(b, X + 52, 40, 140, 86, 0); b.nf('p3', P([[X + 40, 130], [X + 204, 130], [X + 214, 140], [X + 30, 140]]), 4);
      playScreen(b, X + 224, 50, 50, 86, 3); b.nf('p4', R(X + 245, 130, 8, 2), 6);
      [12, 22, 32].forEach((r) => b.ns('m', A(X + 274, 50, r, 10, 80), 3, 5));
      if (W > 320) { playScreen(b, 30, 52, 70, 50, 5, 'm'); b.ns('m', `${Vt(65, 105, 140)}${H(50, 80, 140)}`, 2, 5); playScreen(b, 486, 64, 58, 40, 1, 'm'); }
    } else {
      // The same stream on a television, a tablet and a phone, the signal arcing over them.
      const sets = W > 320 ? [[40, 60, 150, 86, 0], [214, 70, 110, 70, 2], [348, 64, 52, 82, 4], [424, 76, 110, 64, 6]] : [[30, 66, 124, 74, 0], [166, 76, 80, 54, 2], [256, 70, 38, 62, 4]];
      sets.forEach(([x, y, w, h, s]) => playScreen(b, x, y, w, h, s));
      b.ns('m', `${Vt(92 + (W > 320 ? 23 : 0), 140 + (W > 320 ? 6 : 3), 152)}${H(70, 140, 152)}`, 2, 5);
      for (let i = 0; i < 3; i++) b.ns('m', A(W / 2, 100, 70 + i * 12, -38, 38), 2, 5);
    }
  },
  (b, { W, k }) => { // A carrier: one wave across the screen, each half in a bar, from its source (with its pair, or swelling).
    const halves = W > 320 ? 14 : 7, x0 = 52, w = (W - 30 - x0) / halves, amp = k === 1 ? 26 : 34;
    for (let i = 0; i < halves; i++) { const a = k === 2 ? amp * (0.3 + (0.7 * (i + 1)) / halves) : amp; b.s(i % 7, `M${n(x0 + i * w)} 90q${n(w / 2)} ${n(i % 2 ? 2 * a : -2 * a)} ${n(w)} 0`, 6, 2); }
    if (k === 1) for (let i = 0; i < halves; i++) b.ns('m', `M${n(x0 + i * w)} 90q${n(w / 2)} ${i % 2 ? -amp : amp} ${n(w)} 0`, 1.5, 1);
    b.ns('d', H(30, W - 26, 90), 1, 0, ' stroke-dasharray="3 3"');
    b.nf('p4', O(x0, 90, 6), 4); b.ns('m', `${A(x0, 90, 14, 200, 340)}${A(x0, 90, 22, 210, 330)}`, 2, 4);
  },
  (b, { W, k }) => { // An uplink: a dish beams its signal up, seven wavefronts in the bars (to a satellite, or from a broadcast truck).
    const X = (W - 320) / 2;
    if (k === 0) {
      const [fx, fy] = dish(b, X + 104, 118, 34, 40);
      for (let i = 0; i < 7; i++) b.s(i, A(fx, fy, 22 + i * (W > 320 ? 20 : 15), 12, 68), 6, 2);
      if (W > 320) { const [gx, gy] = dish(b, 486, 118, 28, -30); wavefronts(b, gx, gy, 380, 30, 4, 22, 0.3, 0.9); }
    } else if (k === 1) {
      const [fx, fy] = dish(b, X + 78, 124, 32, 44), sx = X + 240, sy = 46;
      satellite(b, sx, sy); wavefronts(b, fx, fy, sx, sy + 8);
      if (W > 320) { const [gx, gy] = dish(b, 500, 124, 32, -44); wavefronts(b, sx, sy + 8, gx, gy, 4, 14, 0.14, 0.82); }
    } else {
      const [fx, fy] = truck(b, X + 40, 104), sx = X + 250, sy = 40;
      satellite(b, sx, sy); wavefronts(b, fx, fy, sx, sy + 8, 6, 14, 0.16, 0.84);
      if (W > 320) { const [gx, gy] = dish(b, 500, 120, 30, -44, 154); wavefronts(b, sx, sy + 8, gx, gy, 4, 14, 0.14, 0.82); }
      b.ns('d', H(26, W - 26, 154), 1.5, 0);
    }
  },
];
