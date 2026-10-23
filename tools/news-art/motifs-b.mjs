// The motifs, part two: Automation, Interactive, AI, Technology, Freelance, Legal, Tips. The same rules as part one.
import { n, R, K, P, Ln, H, Vt, O, at, A, rot, rectPts, circlePts, sparkPts, sparkPath, shieldPts, shieldPath, bulbPts, bulbPath, slice, sliceAt } from './lib.mjs';
import { smpte } from './motifs-a.mjs';

export const M = {};

// ---------------------------------------------------------------------------------------------------- Automation
// A node: its header in the calm run (a lone block of colour is never green or red), its ports, two lines of text.
function node(b, x, y, w, h, slot, ins = 1, outs = 1) {
  b.both('pd', R(x, y, w, h), 1.5, 1); b.q(slot, R(x, y, w, 6), 2);
  b.nf('p2', `${R(x + 6, y + 12, w * 0.5, 3)}${R(x + 6, y + 19, w * 0.32, 3)}`, 3);
  for (let i = 0; i < ins; i++) b.nf('p4', R(x - 3, y + (h * (i + 1)) / (ins + 1) - 2, 5, 4), 6);
  for (let i = 0; i < outs; i++) b.nf('p4', R(x + w - 2, y + (h * (i + 1)) / (outs + 1) - 2, 5, 4), 6);
}
const wire = (x0, y0, x1, y1) => `M${n(x0)} ${n(y0)}C${n((x0 + x1) / 2)} ${n(y0)} ${n((x0 + x1) / 2)} ${n(y1)} ${n(x1)} ${n(y1)}`;
// A graph in columns: each column a list of node centres (y); each node wired to the nodes of the next column it
// meets (all of them across a fan or a join, one to one between columns of the same size). Wires are in the calm run.
function graph(b, W, cols, nw = 52, nh = 28) {
  const gap = (W - 60 - cols.length * nw) / (cols.length - 1), xs = cols.map((_, c) => 30 + c * (nw + gap));
  let s = 0;
  cols.forEach((col, c) => col.forEach((y) => {
    const ins = c === 0 ? 0 : cols[c - 1].length === col.length ? 1 : cols[c - 1].length > 1 && col.length === 1 ? cols[c - 1].length : 1;
    const outs = c === cols.length - 1 ? 0 : cols[c + 1].length === col.length ? 1 : cols[c + 1].length > 1 && col.length === 1 ? cols[c + 1].length : 1;
    node(b, xs[c], y - nh / 2, nw, nh, s++, ins, outs);
  }));
  cols.forEach((col, c) => {
    if (c === cols.length - 1) return;
    const nx = cols[c + 1], x0 = xs[c] + nw + 2, x1 = xs[c + 1] - 2;
    col.forEach((y, i) => {
      const targets = nx.length === col.length ? [nx[i]] : col.length === 1 ? nx : [nx[Math.min(nx.length - 1, Math.floor((i * nx.length) / col.length))]];
      targets.forEach((ty, j) => {
        const y0 = col.length === 1 && nx.length > 1 ? y - nh / 2 + (nh * (j + 1)) / (nx.length + 1) : y;
        const y1 = nx.length === 1 && col.length > 1 ? ty - nh / 2 + (nh * (i + 1)) / (col.length + 1) : ty;
        b.k(c + i + j, wire(x0, y0, x1, y1), 2.5, 0);
      });
    });
  });
}
M.automation = [
  (b, { W, k }) => { // A graph: a source, two branches, a join (two sources joined then split, or one fanned three ways and joined).
    const card = [[[90], [52, 128], [90]], [[54, 126], [90], [54, 126]], [[90], [36, 90, 144], [90]]];
    const band = [[[90], [52, 128], [90], [52, 128], [90]], [[56, 124], [90], [42, 90, 138], [90], [56, 124]], [[90], [36, 90, 144], [90], [36, 90, 144], [90]]];
    graph(b, W, (W > 320 ? band : card)[k], W > 320 ? 60 : 56, k === 2 ? 26 : 30);
  },
  (b, { W, k }) => { // A pipeline that loops back on itself (or a cycle of four, round a square).
    if (k === 2) {
      const nw = 70, nh = 32, cx = W / 2, gx = W > 320 ? 150 : 84, pts = [[cx - gx, 46], [cx + gx, 46], [cx + gx, 128], [cx - gx, 128]];
      pts.forEach(([x, y], i) => node(b, x - nw / 2, y - nh / 2, nw, nh, i, 1, 1));
      b.k(0, H(cx - gx + nw / 2 + 3, cx + gx - nw / 2 - 3, 46), 2.5, 0); b.k(1, Vt(cx + gx, 46 + nh / 2 + 2, 128 - nh / 2 - 2), 2.5, 0);
      b.k(2, H(cx + gx - nw / 2 - 3, cx - gx + nw / 2 + 3, 128), 2.5, 0); b.k(3, Vt(cx - gx, 128 - nh / 2 - 2, 46 + nh / 2 + 2), 2.5, 0);
      b.nf('p4', `${P([[cx, 42], [cx + 8, 46], [cx, 50]])}${P([[cx, 124], [cx - 8, 128], [cx, 132]])}`, 6);
      return;
    }
    // The stages fill the frame's middle, the return runs along the foot.
    const count = (k ? 3 : 4) + (W > 320 ? 2 : 0), nw = k ? 70 : 56, nh = k ? 48 : 42, gap = (W - 64 - count * nw) / (count - 1), y = 44;
    for (let i = 0; i < count; i++) { const x = 32 + i * (nw + gap); node(b, x, y, nw, nh, i, i ? 1 : 0, 1); if (i < count - 1) b.k(i, H(x + nw + 3, x + nw + gap - 3, y + nh / 2), 2.5, 0); }
    const yy = y + nh / 2, r = W - 32 + 2, ly = 142;
    b.ns('m', `M${r} ${yy}H${r + 10}V${ly - 12}C${r + 10} ${ly - 2} ${r + 6} ${ly + 2} ${r - 6} ${ly + 2}H${32 + 6}C26 ${ly + 2} 18 ${ly - 2} 18 ${ly - 12}V${yy}H${32 - 4}`, 2, 0);
    b.nf('p4', P([[r + 10, ly - 26], [r + 6, ly - 34], [r + 14, ly - 34]]), 6);
  },
  (b, { W, k }) => { // A tree: one trigger fans out, and fans out again.
    const sets = W > 320
      ? [[[90], [40, 90, 140], [26, 62, 98, 134]], [[90], [50, 130], [30, 70, 110, 150]], [[90], [60, 120], [36, 90, 144]]]
      : [[[90], [40, 90, 140], [28, 64, 116, 152]], [[90], [52, 128], [30, 70, 110, 150]], [[90], [60, 120], [36, 90, 144]]];
    const cols = sets[k], nw = W > 320 ? 64 : 50, nh = k === 2 ? 28 : 24, gap = (W - 60 - 3 * nw) / 2, xs = [30, 30 + nw + gap, 30 + 2 * (nw + gap)];
    let s = 0;
    cols.forEach((col, c) => col.forEach((y) => node(b, xs[c], y - nh / 2, nw, nh, s++, c ? 1 : 0, c < 2 ? (c ? 2 : cols[1].length) : 0)));
    cols[1].forEach((y, i) => b.k(0, wire(xs[0] + nw + 2, 90 - nh / 2 + (nh * (i + 1)) / (cols[1].length + 1), xs[1] - 2, y), 2.5, 0));
    cols[2].forEach((y, j) => { const i = Math.min(cols[1].length - 1, Math.floor((j * cols[1].length) / cols[2].length)), py = cols[1][i] - nh / 2 + (nh * (1 + (j % 2))) / 3; b.k(i + 1, wire(xs[1] + nw + 2, py, xs[2] - 2, y), 2.5, 0); });
  },
  (b, { W, k }) => { // A merge: seven coloured inputs into one node (dropping into it from above, or three in and four out).
    const nw = 62, nh = 56, ny = 62;
    if (k === 2) {
      const nx = W / 2 - nw / 2; node(b, nx, ny, nw, nh, 2, 3, 4);
      for (let i = 0; i < 3; i++) b.s(i, wire(20, 40 + i * 50, nx - 2, ny + (nh * (i + 1)) / 4), 3, 0);
      for (let i = 0; i < 4; i++) b.s(3 + i, wire(nx + nw + 2, ny + (nh * (i + 1)) / 5, W - 20, 30 + i * 40), 3, 0);
      return;
    }
    if (k === 1) {
      // Seven inputs dropping from along the top into one node low in the middle, its output running on to the right.
      const wide = W > 320, bx = W / 2 - nw / 2, by = 104, span = wide ? 440 : 236;
      node(b, bx, by, nw, 46, 2, 1, 1);
      for (let i = 0; i < 7; i++) { const tx = W / 2 - span / 2 + (span * i) / 6, px = bx + (nw * (i + 1)) / 8; b.s(i, `M${n(tx)} 22C${n(tx)} 70 ${n(px)} 62 ${n(px)} ${by - 2}`, 3, 0); }
      b.nf('p4', `${Array.from({ length: 7 }, (_, i) => R(bx + (nw * (i + 1)) / 8 - 2, by - 3, 4, 4)).join('')}`, 6);
      b.ns('w', wire(bx + nw + 4, by + 23, W - 24, by + 23), 3, 0);
      return;
    }
    const nx = W > 320 ? 300 : 198;
    node(b, nx, ny, nw, nh, 2, 7, 1);
    for (let i = 0; i < 7; i++) {
      const py = ny + (nh * (i + 1)) / 8, fy = 26 + i * 21.5;
      b.s(i, wire(20, fy, nx - 2, py), 3, 0);
    }
    if (W > 320) { b.k(2, wire(nx + nw + 4, 90, 440, 90), 3, 0); node(b, 442, 70, 70, 40, 4, 1, 1); b.ns('w', wire(514, 90, 556, 90), 3, 0); } else b.ns('w', wire(nx + nw + 4, 90, 304, 90), 3, 0);
  },
];

// --------------------------------------------------------------------------------------------------- Interactive
M.interactive = [
  (b, { W, k }) => { // A deck of keys, each with its colour, one pressed (in the calm run).
    const cols = (k === 2 ? 4 : 5) + (W > 320 ? 4 : 0), s = k === 2 ? 40 : 34, g = 8, x0 = (W - (cols * s + (cols - 1) * g)) / 2, y0 = k === 2 ? 22 : 28;
    const pr = [[1, 2], [0, 3], [2, 1]][k], pc = W > 320 ? [pr[0], pr[1] + 2] : pr;
    for (let r = 0; r < 3; r++) for (let c = 0; c < cols; c++) {
      const x = x0 + c * (s + g), y = y0 + r * (s + g), slot = c + [2, 3, 1][k] * r;
      if (r === pc[0] && c === pc[1]) { b.q([2, 3, 4][k], K(x + 2, y + 2, s - 4, s - 4, 3)); continue; }
      b.nf('p1', K(x, y, s, s, 3), 1); b.f(slot, R(x + 8, y + 8, s - 16, s - 16));
    }
    const cx = x0 + pc[1] * (s + g) + s / 2, cy = y0 + pc[0] * (s + g) + s / 2;
    b.ns('w', O(cx, cy, s * 0.8), 1.5, 5); b.ns('m', O(cx, cy, s * 1.03), 1, 5);
  },
  (b, { W, k }) => { // A pad grid lit in a sweep across it (a step sequencer, its playhead on one column; or lit as a meter).
    const cols = W > 320 ? 16 : 8, s = 22, g = 6, x0 = (W - (cols * s + (cols - 1) * g)) / 2, y0 = 34;
    const steps = ['10010010', '00100101', '11000110', '01011001'], head = W > 320 ? 10 : 5;
    for (let r = 0; r < 4; r++) for (let c = 0; c < cols; c++) {
      const x = x0 + c * (s + g), y = y0 + r * (s + g), d = (c % 8) - r;
      const on = [d >= 1 && d <= 5, steps[r][(c + r) % 8] === '1', 3 - r < [2, 3, 4, 2, 3, 1, 4, 2][c % 8]][k];
      if (on) b.f([c + r, r * 2 + (c >= 8 ? 1 : 0), c][k], R(x, y, s, s)); else b.nf('p1', R(x, y, s, s), 1);
    }
    if (k === 1) b.ns('w', R(x0 + head * (s + g) - 3, y0 - 4, s + 6, 4 * s + 3 * g + 8), 1.5, 5);
    b.ns('d', R(x0 - 9, y0 - 9, cols * s + (cols - 1) * g + 18, 4 * s + 3 * g + 18), 1.5, 0);
  },
  (b, { W, k }) => { // A touch surface: a gesture's trail across it, the touch at its head (a pinch, or a tap's ripple).
    const x0 = W > 320 ? 40 : 46, x1 = W - x0;
    b.both('pd', R(x0, 22, x1 - x0, 136), 1.5, 0);
    b.ns('m', `${H(x0, x1, 90)}${Vt(W / 2, 22, 158)}`, 1, 0, ' stroke-dasharray="2 4"');
    const ring = (x, y) => { b.ns('w', O(x, y, 17), 1.5, 5); b.ns('m', O(x, y, 26), 1, 5); };
    if (k === 0) {
      const count = W > 320 ? 14 : 7, sx = x0 + 30, ex = x1 - 50;
      for (let i = 0; i < count; i++) { const t = i / (count - 1), x = sx + (ex - sx) * t, y = 138 - 86 * t + 18 * Math.sin(t * Math.PI * (W > 320 ? 2 : 1)); b.f(i % 7, O(x, y, 3 + 7 * (W > 320 ? (i % 7) / 6 : t))); if (i === count - 1) ring(x, y); }
    } else if (k === 1) {
      const cy = 90, l = W / 2 - (W > 320 ? 150 : 96), r = W / 2 + (W > 320 ? 150 : 96);
      for (let i = 0; i < 4; i++) { const t = i / 3; b.f(i, O(l + (W / 2 - 24 - l) * t, cy + 40 - 34 * t, 3 + 2.5 * i)); b.f(6 - i, O(r - (r - W / 2 - 24) * t, cy - 40 + 34 * t, 3 + 2.5 * i)); }
      ring(W / 2 - 24, cy + 6); ring(W / 2 + 24, cy - 6);
    } else {
      const cx = W / 2 + (W > 320 ? 0 : 20), cy = 90;
      for (let i = 0; i < 7; i++) b.s(i, O(cx, cy, 12 + i * 9), 3.5, 2);
      b.nf('p4', O(cx, cy, 6), 4);
    }
  },
  (b, { W, k }) => { // Close up: large keys, one pressed (in the calm run).
    const big = k === 2, cols = (big ? 2 : 3) + (W > 320 ? 2 : 0), w = big ? 108 : W > 320 ? 92 : 76, h = big ? 60 : 58, g = 9, x0 = (W - (cols * w + (cols - 1) * g)) / 2, y0 = big ? 22 : 26, rowStep = h + g + (big ? 6 : 0);
    // The pressed key takes the calm run's first bar (the palette's first or second); the others take the run from its
    // third bar on, so the pressed key's colour is never one of theirs, and nothing alone is green or red.
    const pr = [[0, 1], [1, cols - 1], [1, 0]][k];
    let j = 0;
    for (let r = 0; r < 2; r++) for (let c = 0; c < cols; c++) {
      const x = x0 + c * (w + g), y = y0 + r * rowStep;
      if (r === pr[0] && c === pr[1]) { b.q(0, K(x + 3, y + 3, w - 6, h - 6, 4)); continue; }
      b.nf('p1', K(x, y, w, h, 4), 1); b.f(2 + (j++ % 5), R(x + 10, y + 10, w - 20, h - 26)); b.nf('p3', R(x + 10, y + h - 12, 26, 3), 2);
    }
    const cx = x0 + pr[1] * (w + g) + w / 2, cy = y0 + pr[0] * rowStep + h / 2;
    b.ns('w', O(cx, cy, 22), 1.5, 5); b.ns('m', O(cx, cy, 32), 1, 5);
  },
];

// ------------------------------------------------------------------------------------------------------------ AI
M.ai = [
  (b, { W, k }) => { // A spark cut into the bars (upright or on the bend), with small ones about it (never green or red).
    const X = (W - 320) / 2, big = [[X + 150, 90, 68], [X + 170, 90, 68], [X + 160, 90, 72]][k];
    (k === 2 ? sliceAt(sparkPts(...big), 7, 30, big[0], big[1], 4) : slice(sparkPts(...big), 7, 0, 4)).forEach((d, i) => b.f(i, d));
    const outline = [[[X + 240, 46, 18]], [[X + 76, 50, 18]], [[X + 252, 136, 16]]][k], calm = [[[X + 86, 138, 11]], [[X + 262, 136, 11]], [[X + 72, 48, 12]]][k];
    if (W > 320) { outline.push([[94, 58, 15], [470, 60, 14], [500, 52, 15]][k]); calm.push([[476, 128, 12], [110, 130, 10], [80, 130, 11]][k]); }
    outline.forEach((s) => b.ns('w', sparkPath(...s), 2, 5)); calm.forEach((s, i) => b.q(i * 2 + k, sparkPath(...s), 3));
  },
  (b, { W, k }) => { // Sparks, each cut a different way (or a constellation of them in a row).
    const X = (W - 320) / 2;
    const sets = [
      [[X + 124, 94, 54, 7, 1], [X + 214, 56, 28, 4, 0], [X + 230, 128, 18, 3, 1]],
      [[X + 196, 92, 54, 7, 1], [X + 104, 54, 28, 4, 0], [X + 88, 128, 18, 3, 1]],
      [[X + 58, 92, 24, 3, 0], [X + 120, 82, 38, 5, 1], [X + 198, 96, 46, 7, 0], [X + 268, 76, 22, 3, 1]],
    ];
    const set = sets[k];
    if (W > 320) set.push(...[[[470, 70, 30, 5, 0], [520, 138, 14, 3, 1]], [[58, 64, 24, 4, 1], [470, 112, 34, 5, 0]], [[28, 140, 12, 3, 1], [500, 116, 28, 4, 0]]][k]);
    let s = 0; set.forEach(([x, y, r, c, ax]) => { slice(sparkPts(x, y, r), c, ax, r > 40 ? 4 : 3).forEach((d, i) => b.f(s + i, d)); s += c; });
  },
  (b, { W, k }) => { // Seven sparks nested, one in each bar (about one centre, as a tunnel, or two sets interlocked).
    const draw = (cx, cy, r0, step, w, s0, dx = 0, dy = 0) => {
      for (let i = 0; i < 7; i++) b.s(s0 + i, sparkPath(cx + dx * i, cy + dy * i, r0 - i * step, 0.16), w, 2);
      b.nf('p4', sparkPath(cx + dx * 7.4, cy + dy * 7.4, r0 * 0.11), 4);
    };
    if (k === 2) { draw(W / 2 - 40, 90, 66, 8.6, 3.5, 0); draw(W / 2 + 40, 90, 66, 8.6, 3.5, 3); }
    else draw(W / 2, 90, 76, 9.6, 3.5, 0, k ? 5.3 : 0, k ? -3.4 : 0);
    if (W > 320 && k !== 2) { draw(96, 90, 50, 6.4, 2.5, 3); draw(480, 90, 50, 6.4, 2.5, 5); }
    if (W > 320 && k === 2) { draw(72, 90, 40, 5.2, 2.5, 2); draw(504, 90, 40, 5.2, 2.5, 5); }
  },
  (b, { W, k }) => { // Close up: a spark too large for the frame, cut in bands or bars, and small ones beside it.
    const X = (W - 320) / 2, cx = [X + 122, X + 196, X + 160][k], cy = [92, 88, 90][k];
    if (k === 0) slice(sparkPts(cx, cy, 96, 0.14), 7, 1, 5).forEach((d, i) => b.f(i, d));
    else if (k === 1) slice(sparkPts(cx, cy, 96, 0.14), 7, 0, 5).forEach((d, i) => b.f(i, d));
    else sliceAt(sparkPts(cx, cy, 104, 0.14), 7, -35, cx, cy, 5).forEach((d, i) => b.f(i, d));
    const sm = [[[X + 256, 52, 20, 'w'], [X + 270, 118, 10, 'm']], [[X + 62, 52, 20, 'w'], [X + 48, 118, 10, 'm']], [[X + 274, 40, 14, 'w'], [X + 42, 142, 12, 'w']]][k];
    if (W > 320) sm.push(...[[[40, 132, 14, 'w'], [60, 52, 9, 'm']], [[520, 60, 16, 'w'], [500, 140, 9, 'm']], [[90, 54, 18, 'w'], [500, 120, 16, 'w']]][k]);
    sm.forEach(([x, y, r, c]) => b.ns(c, sparkPath(x, y, r), c === 'w' ? 2 : 1.5, 5));
  },
];

// ---------------------------------------------------------------------------------------------------- Technology
function pins(b, x, y, w, h, count = 7) {
  const step = w / count, dash = step * 0.45, da = ` stroke-dasharray="${n(dash)} ${n(step - dash)}"`;
  b.ns('m', `M${n(x + (step - dash) / 2)} ${y - 4}H${x + w}M${n(x + (step - dash) / 2)} ${y + h + 4}H${x + w}`, 8, 0, da);
  const vs = h / count, vd = vs * 0.45, va = ` stroke-dasharray="${n(vd)} ${n(vs - vd)}"`;
  b.ns('m', `M${x - 4} ${n(y + (vs - vd) / 2)}V${y + h}M${x + w + 4} ${n(y + (vs - vd) / 2)}V${y + h}`, 8, 0, va);
}
function chip(b, x, y, s, count = 7, h = s) { pins(b, x, y, s, h, count); b.both('pm', R(x, y, s, h), 1.5, 1); }
M.technology = [
  (b, { W, k }) => { // A chip, its die showing the bars, its traces out to pads.
    const X = (W - 320) / 2, L = W > 320 ? 20 : 0;
    if (k === 2) {
      const x = X + 40, y = 54, s = 72; chip(b, x, y, s); for (let i = 0; i < 7; i++) b.f(i, R(x + 16 + i * 5.72, y + 16, 6, 40));
      for (let i = 0; i < 6; i++) { const yy = y + 8 + i * 11.2, py = 30 + i * 24, px = W - 40 - (i % 2) * 24; b.ns('d', `M${x + s + 8} ${n(yy)}H${n(x + s + 30 + i * 8)}L${n(x + s + 30 + i * 8 + Math.abs(py - yy))} ${n(py)}H${n(px)}`, 2, 0); b.f(i, R(px - 4, py - 4, 8, 8), 3); }
      return;
    }
    const x = X + 124, y = 54, s = 72;
    chip(b, x, y, s); for (let i = 0; i < 7; i++) b.f(i, R(x + 16 + i * 5.72, y + 16, 6, 40));
    const tr = !k
      ? [[`M${x - 8} 70H${90 - L / 2}L${70 - L / 2} 50H${40 - L}`, 40 - L, 50], [`M${x - 8} 100H${96 - L / 2}L${80 - L / 2} 116V146`, 80 - L / 2, 146], [`M${x + s + 8} 70H${226 + L / 2}L${244 + L / 2} 52H${280 + L}`, 280 + L, 52], [`M${x + s + 8} 110H${236 + L / 2}V140`, 236 + L / 2, 140], [`M${x + 26} ${y - 8}V34H${120 - L / 2}`, 120 - L / 2, 34], [`M${x + 46} ${y + s + 8}V150H${200 + L / 2}`, 200 + L / 2, 150]]
      : [[`M${x - 8} 64H${84 - L / 2}L${60 - L / 2} 40H${36 - L}`, 36 - L, 40], [`M${x - 8} 92H${44 - L}`, 44 - L, 92], [`M${x - 8} 116H${94 - L / 2}L${74 - L / 2} 136H${48 - L}`, 48 - L, 136], [`M${x + s + 8} 64H${232 + L / 2}L${256 + L / 2} 40H${284 + L}`, 284 + L, 40], [`M${x + s + 8} 92H${276 + L}`, 276 + L, 92], [`M${x + s + 8} 116H${226 + L / 2}L${246 + L / 2} 136H${272 + L}`, 272 + L, 136]];
    tr.forEach(([d]) => b.ns('d', d.replace(/(\d+\.\d+)/g, (m) => n(Number(m))), 2, 0));
    tr.forEach(([, px, py], i) => b.f(i, R(px - 4, py - 4, 8, 8), 3));
  },
  (b, { W, k }) => { // A bus: seven traces leave a chip and fan out, each in a bar (sideways, down, or up).
    if (k === 0) {
      const x = W > 320 ? 60 : 40, y = 54, s = 72, end = W - 34;
      chip(b, x, y, s); b.nf('p2', R(x + 14, y + 14, s - 28, s - 28), 2);
      for (let i = 0; i < 7; i++) { const yy = y + 6 + i * 10, ty = 24 + i * 22, bx = x + s + 38 + i * 6; b.s(i, `M${x + s + 8} ${yy}H${bx}L${n(bx + Math.abs(ty - yy) * 0.8)} ${ty}H${end - 4}`, 3, 2); b.f(i, O(end, ty, 4), 3); }
    } else {
      const up = k === 2, s = 64, x = W / 2 - s / 2, y = up ? 94 : 22, spread = W > 320 ? 66 : 38;
      chip(b, x, y, s); b.nf('p2', R(x + 12, y + 12, s - 24, s - 24), 2);
      for (let i = 0; i < 7; i++) {
        const xx = x + 6 + i * 8.6, tx = W / 2 + (i - 3) * spread;
        if (!up) { const y1 = 104 - Math.abs(i - 3) * 4; b.s(i, `M${n(xx)} ${y + s + 8}V${n(y1)}L${n(tx)} ${n(y1 + Math.min(34, Math.abs(tx - xx) * 0.5))}V150`, 3, 2); b.f(i, O(tx, 154, 4), 3); }
        else { const y1 = 76 + Math.abs(i - 3) * 4; b.s(i, `M${n(xx)} ${y - 8}V${n(y1)}L${n(tx)} ${n(y1 - Math.min(34, Math.abs(tx - xx) * 0.5))}V30`, 3, 2); b.f(i, O(tx, 26, 4), 3); }
      }
    }
  },
  (b, { W, k }) => { // A processor's die: cores among the bars (and memory beside it on the band); or the wafer the dies are cut from.
    if (k === 1) {
      // The wafer: a disc ruled into dies, those whole on it lit in the bars in diagonal runs, the flat at its foot.
      const wide = W > 320, cx = W / 2, cy = 88, r = 70, d = 17, cols = 9;
      b.both('pm', `M${n(cx - 26)} ${n(cy + r - 5.1)}A${r} ${r} 0 1 1 ${n(cx + 26)} ${n(cy + r - 5.1)}z`, 1.5, 0);
      let dim = '';
      for (let rr = 0; rr < cols; rr++) for (let c = 0; c < cols; c++) {
        const x = cx - (cols * d) / 2 + c * d, y = cy - (cols * d) / 2 + rr * d, far = Math.max(...[[x, y], [x + d, y], [x, y + d], [x + d, y + d]].map(([px, py]) => Math.hypot(px - cx, py - cy)));
        if (far > r - 3 || y + d > cy + r - 8) continue;
        if ((c * 3 + rr * 5) % 11 === 4) dim += R(x + 1.5, y + 1.5, d - 3, d - 3); else b.f(c + rr, R(x + 1.5, y + 1.5, d - 3, d - 3), 2);
      }
      if (dim) b.nf('p3', dim, 2);
      if (wide) [[96, 0], [480, 3]].forEach(([mx, s0]) => { chip(b, mx - 32, 58, 64); b.nf('p2', R(mx - 20, 70, 40, 40), 2); b.f(s0, R(mx - 8, 82, 16, 16), 3); });
      return;
    }
    const sw = k === 2 ? 150 : 100, s = k === 2 ? 76 : 100, x = W / 2 - sw / 2, y = k === 2 ? 52 : 40, cols = [3, 4, 4][k], rows = [3, 4, 2][k];
    const cs = (sw - 10 - (cols - 1) * 3) / cols, chh = (s - 10 - (rows - 1) * 3) / rows;
    chip(b, x, y, sw, k === 2 ? 12 : 9, s);
    const order = [[0, 1, 2, 3, -1, 4, 5, 6, -2], [0, -1, 1, 2, -2, 3, -1, 4, 5, -1, 6, -2, 0, 3, -1, 6], [0, 1, -1, 2, 3, -2, 4, 5]][k];
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) { const xx = x + 5 + c * (cs + 3), yy = y + 5 + r * (chh + 3), o = order[r * cols + c]; if (o === -1) b.nf('p3', R(xx, yy, cs, chh), 2); else if (o === -2) b.nf('p2', R(xx, yy, cs, chh), 2); else b.f(k === 2 && o >= 5 ? 6 : o, R(xx, yy, cs, chh)); }
    if (W > 320) [[52, 0], [452, 3]].forEach(([mx, s0]) => { pins(b, mx, 58, 72, 64, 6); b.both('pm', R(mx, 58, 72, 64), 1.5, 1); for (let i = 0; i < 4; i++) b.f(s0 + i, R(mx + 8, 66 + i * 13, 56, 9)); b.ns('d', mx < 200 ? `M${mx + 80} 90H${x - 8}` : `M${x + sw + 8} 90H${mx - 8}`, 2, 0); });
  },
  (b, { W, k }) => { // Chips joined by a bus of seven lanes (level, stepping down, or one above the other).
    const ch = (x, y, s = 64) => { chip(b, x, y, s); b.nf('p2', R(x + 12, y + 12, s - 24, s - 24), 2); };
    const bus = (x0, y0, x1, y1) => { for (let i = 0; i < 7; i++) { const a = y0 + 6 + i * 8.6, c = y1 + 6 + i * 8.6, mx = (x0 + x1) / 2; b.s(i, `M${n(x0)} ${n(a)}H${n(mx - 18 + i * 1.5)}L${n(mx + i * 1.5)} ${n(c)}H${n(x1)}`, 3, 2); } };
    const vbus = (x, y0, y1) => { for (let i = 0; i < 7; i++) b.s(i, Vt(x + 6 + i * 8.6, y0, y1), 3, 2); };
    if (k === 2) {
      (W > 320 ? [116, 404] : [132]).forEach((x) => { ch(x, 20, 56); ch(x, 106, 56); vbus(x - 2, 82, 98); });
      if (W > 320) bus(180, 20, 396, 106);
      return;
    }
    if (W === 320) { if (k === 0) { ch(36, 58); ch(220, 58); bus(108, 58, 212, 58); } else { ch(36, 30); ch(220, 86); bus(108, 30, 212, 86); } }
    else if (k === 0) { ch(36, 58); ch(256, 58); ch(476, 58); bus(108, 58, 248, 58); bus(328, 58, 468, 58); }
    else { ch(36, 30); ch(256, 86); ch(476, 30); bus(108, 30, 248, 86); bus(328, 86, 468, 30); }
  },
];

// ----------------------------------------------------------------------------------------------------- Freelance
// A person: head and shoulders, their body running down to foot (the frame's foot, so they stand in the picture
// rather than float in it); layer sets who stands in front where a crew overlaps.
function bust(b, cx, top, s = 1, foot = 186, layer = 1) {
  const hr = 17 * s, hy = top + hr, sy = hy + hr + 6 * s, w = 46 * s, bot = Math.max(foot, sy + 46 * s);
  b.both('pw', `${O(cx, hy, hr)}M${n(cx - w)} ${n(bot)}C${n(cx - w)} ${n(sy + 8 * s)} ${n(cx - w * 0.55)} ${n(sy)} ${n(cx)} ${n(sy)}C${n(cx + w * 0.55)} ${n(sy)} ${n(cx + w)} ${n(sy + 8 * s)} ${n(cx + w)} ${n(bot)}`, 2, layer);
  return { sy, bot, w, hy, hr };
}
// A road case, the freelancer's kit: its lid's seam, corner brackets, a handle and its castors on the floor.
function roadCase(b, x, y, w, h, floor = 158) {
  b.both('pm', R(x, y, w, h), 2, 1); b.ns('m', H(x, x + w, y + h * 0.32), 1.5, 2);
  b.nf('p3', `${[[x, y], [x + w - 8, y], [x, y + h - 8], [x + w - 8, y + h - 8]].map(([cx, cy]) => R(cx, cy, 8, 8)).join('')}${R(x + w / 2 - 12, y + h * 0.32 + 7, 24, 9)}`, 3);
  b.nf('p5', R(x + w / 2 - 8, y + h * 0.32 + 10, 16, 3), 4);
  b.both('pd', `${O(x + 12, floor - 5, 5)}${O(x + w - 12, floor - 5, 5)}`, 1.5, 2);
}
// A pass: its header is the SMPTE bars in their order, a photo and lines of text below; turned by deg about its clip
// (the middle of its top edge), as a pass swings on its lanyard.
function pass(b, x, y, w, h, layer = 6, deg = 0) {
  const T = (px, py, pw, ph) => (deg ? P(rot(rectPts(px, py, pw, ph), x + w / 2, y, deg)) : R(px, py, pw, ph));
  b.both('qw', T(x, y, w, h), 1.5, layer); for (let i = 0; i < 7; i++) b.c(i, T(x + (w * i) / 7, y, w / 7 + 0.3, h * 0.22), layer);
  b.nf('p3', `${T(x + w * 0.14, y + h * 0.34, w * 0.3, h * 0.34)}${T(x + w * 0.52, y + h * 0.4, w * 0.34, h * 0.08)}${T(x + w * 0.14, y + h * 0.78, w * 0.72, h * 0.07)}`, layer);
}
// A lanyard: two ribbons from the neck down to the clip, each ribbon the seven bars side by side.
function ribbon(b, x0, y0, x1, y1, width, layer = 5) {
  const dx = x1 - x0, dy = y1 - y0, len = Math.hypot(dx, dy), nx = -dy / len, ny = dx / len, w = width / 7;
  for (let i = 0; i < 7; i++) { const o = (i - 3) * w; b.sb(i, Ln(x0 + nx * o, y0 + ny * o, x1 + nx * o, y1 + ny * o), n(w + 0.15), layer); }
}
function lanyard(b, cx, sy, py, spread, width = 7, layer = 5, clip = 1) {
  ribbon(b, cx - spread, sy + 1, cx - 3 * clip, py, width, layer); ribbon(b, cx + 3 * clip, py, cx + spread, sy + 1, width, layer);
  b.nf('p4', R(cx - 5 * clip, py - 2 * clip, 10 * clip, 7 * clip), layer + 0.5);
}
// A pass hung on its own lanyard, the two ribbons of bars rising out of the top of the frame (turned by deg).
function hung(b, cx, top, w, h, wd = 18, deg = 0) {
  ribbon(b, cx - 10, top - 14, cx - 10 - wd * 3, -6, wd, 1); ribbon(b, cx + 10 + wd * 3, -6, cx + 10, top - 14, wd, 1); b.nf('p3', R(cx - 10, top - 18, 20, 12), 4); b.ns('w', R(cx - 4, top - 8, 8, 10), 2, 5);
  pass(b, cx - w / 2, top, w, h, 6, deg);
}
function diary(b, x, y, cw, ch, from, head = true, weeks = 4) {
  // A month: seven days across, its weeks down, cut into days by the screen's black; a booking of seven days runs
  // through the bars, day by day.
  const w = 7 * cw + 12, h = weeks * ch + (weeks - 1) * 2;
  b.nf('p1', R(x, y, w, h), 1);
  let cuts = ''; for (let c = 1; c < 7; c++) cuts += Vt(x + c * (cw + 2) - 1, y, y + h); for (let r = 1; r < weeks; r++) cuts += H(x, x + w, y + r * (ch + 2) - 1);
  for (let d = 0; d < 7; d++) { const cell = d + from, c = cell % 7, r = Math.floor(cell / 7); b.f(d, R(x + c * (cw + 2), y + r * (ch + 2), cw, ch), 2); }
  b.ns('x', cuts, 2, 3); if (head) b.ns('m', H(x, x + w, y - 6), 2, 0);
}
M.freelance = [
  (b, { W, k }) => { // A person, their lanyard in the bars, their pass (in the third take, beside their road case, the pass turned on its clip); on the band, their kit and diary.
    const X = (W - 320) / 2, cx = k === 2 ? X + 112 : W / 2, { sy } = bust(b, cx, 16, 1.28), py = 106;
    lanyard(b, cx, sy, py, 18, k === 1 ? 9 : 7); pass(b, cx - 26, py + 4, 52, 60, 6, k === 2 ? 8 : 0);
    if (k === 2) roadCase(b, X + 186, 92, 92, 56);
    if (W > 320) {
      const lx = 40, ly = 66, lw = 110, lh = 64; smpte(b, lx, ly, lw, lh); b.ns('w', R(lx - 3, ly - 3, lw + 6, lh + 6), 2, 5); b.nf('p3', P([[lx - 12, ly + lh + 4], [lx + lw + 12, ly + lh + 4], [lx + lw + 22, 148], [lx - 22, 148]]), 4);
      if (k === 2) diary(b, 432, 56, 14, 18, 15); else diary(b, 404, 56, 16, 18, [4, 10][k]);
    }
  },
  (b, { W, k }) => { // The pass close up: its ribbon is the bars (two passes, or one turned on its clip; on the band, three or four).
    const one = hung.bind(null, b);
    if (W === 320) { if (k === 0) one(160, 74, 96, 94); else if (k === 1) { one(118, 82, 80, 80, 14); one(212, 64, 84, 98, 14); } else one(160, 70, 92, 92, 16, -12); }
    else ([[[150, 84, 0.86], [288, 70, 1], [426, 84, 0.86]], [[96, 92, 0.72], [224, 76, 0.86], [352, 92, 0.72], [480, 76, 0.86]], [[180, 76, 0.9], [396, 76, 0.9]]][k]).forEach(([cx, top, s], i) => one(cx, top, 88 * s, 90 * s, 13 * s, k === 2 ? (i ? 10 : -10) : 0));
  },
  (b, { W, k }) => { // A crew, each with their pass: the colour of their access, as on a real show (three, four, or five).
    // They stand in the frame, their bodies running off its foot; the middle one a step forward, in front.
    const count = [3, 4, 5][k] + (W > 320 ? 2 : 0), pitch = W > 320 ? [100, 84, 70][k] : [92, 70, 56][k], x0 = W / 2 - ((count - 1) * pitch) / 2;
    for (let i = 0; i < count; i++) {
      const cx = x0 + i * pitch, mid = Math.abs(i - (count - 1) / 2) < 0.6, s = (mid ? 1.15 : 1) * [1, 0.85, 0.74][k];
      const top = 160 - 103 * s - (mid ? 8 : 0), { sy } = bust(b, cx, top, s, 186, mid ? 1.5 : 1); const py = sy + 24 * s;
      b.s(i, `M${n(cx - 10 * s)} ${n(sy + 1)}L${n(cx - 2)} ${n(py)}M${n(cx + 10 * s)} ${n(sy + 1)}L${n(cx + 2)} ${n(py)}`, 3.5, 5); b.nf('p4', R(cx - 4, py - 1, 8, 5), 5.5);
      const x = cx - 15 * s, y = py + 3, w = 30 * s, h = 36 * s;
      b.both('qw', R(x, y, w, h), 1.5, 6); b.f(i, R(x, y, w, h * 0.24), 6.5); if (k < 2) b.nf('p3', R(x + w * 0.16, y + h * 0.38, w * 0.36, h * 0.4), 6.5);
    }
  },
  (b, { W, k }) => { // Close up: the person fills the frame, their lanyard in the bars, the pass large on their chest (to one side, the pass turned on its clip, their kit beside them; or beside their diary, the booking in the bars).
    const X = (W - 320) / 2, wide = W > 320;
    if (k === 2) {
      // Beside their diary: the person large and running off the foot, the lanyard in the bars, the booking beside.
      const cx = X + 88, s = 1.45, { sy } = bust(b, cx, 22, s), py = sy + 38;
      lanyard(b, cx, sy, py, 20, 11, 5, 1.2); pass(b, cx - 26, py + 4, 52, 58);
      diary(b, X + 166, 56, 17, 24, 9, false, 3);
      if (W > 320) diary(b, 30, 46, 10, 22, 2, false);
      return;
    }
    // The head and shoulders run off the foot of the frame; on the band, the diary and another pass either side.
    const cx = k === 1 ? X + 112 : W / 2, s = 2, { sy } = bust(b, cx, 14, s), py = 128;
    lanyard(b, cx, sy, py, 28, 16, 5, 1.6);
    pass(b, cx - 35, py + 5, 70, 76, 6, k === 1 ? -7 : 0);
    if (k === 1) roadCase(b, X + 198, 98, 88, 54, 160);
    if (wide) { if (k) diary(b, 438, 56, 14, 20, 2); else diary(b, 36, 56, 18, 20, 9); hung(b, k ? 100 : 476, 74, 68, 72, 10); }
  },
];

// --------------------------------------------------------------------------------------------------------- Legal
function gavel(b, x, y) {
  b.nf('p3', P(rot(rectPts(x - 22, y - 12, 44, 24), x, y, -30)), 4); b.nf('p1', P(rot(rectPts(x - 26, y - 14, 4, 28), x, y, -30)), 5); b.nf('p1', P(rot(rectPts(x + 22, y - 14, 4, 28), x, y, -30)), 5);
  b.ns('w', Ln(...rot([[x, y + 12]], x, y, -30)[0], ...rot([[x, y + 62]], x, y, -30)[0]), 3, 5);
  b.nf('p2', R(x - 6, y + 52, 64, 10), 1); b.nf('p1', R(x, y + 46, 52, 6), 1);
}
function page(b, x, y, w, h, lines = 6) {
  b.both('pm', P([[x, y], [x + w - 20, y], [x + w, y + 20], [x + w, y + h], [x, y + h]]), 1.5, 0); b.ns('m', `M${x + w - 20} ${y}V${y + 20}H${x + w}`, 1.5, 0);
  b.nf('p2', Array.from({ length: lines }, (_, i) => R(x + 14, y + 18 + i * 12, (w - 28) * [0.72, 0.9, 0.82, 0.92, 0.66, 0.54, 0.8, 0.7][i % 8], 4)).join(''), 1);
}
// A seal: a disc of the bars inside a notched ring, its ribbon's tails below.
function seal(b, cx, cy, r) {
  b.nf('p3', `${P([[cx - r * 0.5, cy + r * 0.6], [cx - r * 0.1, cy + r * 0.7], [cx - r * 0.28, cy + r * 1.75], [cx - r * 0.5, cy + r * 1.5], [cx - r * 0.72, cy + r * 1.75]])}${P([[cx + r * 0.1, cy + r * 0.7], [cx + r * 0.5, cy + r * 0.6], [cx + r * 0.72, cy + r * 1.75], [cx + r * 0.5, cy + r * 1.5], [cx + r * 0.28, cy + r * 1.75]])}`, 1);
  slice(circlePts(cx, cy, r * 0.78, 48), 7, 0, 3).forEach((d, i) => b.f(i, d, 2));
  b.ns('w', O(cx, cy, r * 0.84), 2, 5); b.ns('m', O(cx, cy, r), 4, 4, ` stroke-dasharray="2 ${n((2 * Math.PI * r) / 28 - 2)}"`);
}
M.legal = [
  (b, { W, k }) => { // A shield of the bars (upright or on the bend), or a seal; on the band a gavel and the page beside it.
    const X = (W - 320) / 2;
    if (k === 2) seal(b, X + 160, 76, 52);
    else {
      const x = X + 108, y = 26, w = 104, h = 130;
      (k ? sliceAt(shieldPts(x, y, w, h), 7, 28, x + w / 2, y + h / 2, 4) : slice(shieldPts(x, y, w, h), 7, 0, 5)).forEach((d, i) => b.f(i, d));
      b.ns('w', shieldPath(x - 6, y - 6, w + 12, h + 14), 2, 5);
    }
    if (W > 320) { gavel(b, 96, 70); page(b, 428, 34, 100, 118, 7); }
  },
  (b, { W, k }) => { // A shield in bands inside its own outline (level, or tilted one way or the other).
    const draw = (cx, w, h, s0, deg) => { const x = cx - w / 2, y = 90 - h / 2; (deg ? sliceAt(shieldPts(x, y, w, h), 7, 90 - deg, cx, 90, 4) : slice(shieldPts(x, y, w, h), 7, 1, 5)).forEach((d, i) => b.f(s0 + i, d)); b.ns('m', shieldPath(x - 9, y - 9, w + 18, h + 18), 1.5, 5); };
    const deg = [0, 16, -16][k];
    draw(W / 2, 88, 118, 0, deg);
    if (W > 320) { draw(W / 2 - 156, 58, 78, 3, deg ? 0 : 16); draw(W / 2 + 156, 58, 78, 5, deg ? 0 : -16); }
  },
  (b, { W, k }) => { // The scales: the pans hold the bars (level, or weighed down one side or the other).
    const cx = W / 2, arm = W > 320 ? 150 : 84, tilt = [0, 9, -9][k], beamY = 52;
    const [lx, ly] = at(cx, beamY, arm, 270 - tilt), [rx, ry] = at(cx, beamY, arm, 90 - tilt);
    b.ns('w', `M${cx} 30V150M${cx - 34} 154H${cx + 34}${Ln(lx, ly, rx, ry)}`, 3, 5); b.nf('p4', O(cx, 30, 5), 6); b.nf('p3', P([[cx - 22, 150], [cx + 22, 150], [cx + 14, 142], [cx - 14, 142]]), 4);
    const counts = k === 2 ? [3, 4] : [4, 3];
    [[lx, ly, 0, counts[0]], [rx, ry, counts[0], counts[1]]].forEach(([px, py, from, count]) => {
      const pr = W > 320 ? 38 : 30, top = py + 50;
      b.ns('m', `M${n(px)} ${n(py)}L${n(px - pr + 4)} ${n(top)}M${n(px)} ${n(py)}L${n(px + pr - 4)} ${n(top)}`, 1.5, 4);
      slice([[px - pr, top], [px + pr, top], ...circlePts(px, top, pr, 36).filter(([, y]) => y > top + 0.01).sort((p, q) => q[0] - p[0])], count, 0, 4).forEach((d, i) => b.f(from + i, d));
    });
  },
  (b, { W, k }) => { // A page under seal: lines of text, a shield of the bars at its corner (two pages fanned, or a seal at its foot).
    const X = (W - 320) / 2;
    if (k === 1) b.both('pm', P(rot([[X + 80, 30], [X + 176, 30], [X + 176, 150], [X + 80, 150]], X + 128, 150, -8)), 1.5, 0);
    page(b, X + 92, 24, 116, 134, k === 2 ? 6 : 8);
    if (W > 320) { page(b, 40, 40, 96, 112, 7); page(b, 440, 40, 96, 112, 7); }
    if (k === 2) { b.ns('w', `M${X + 106} 126c6-8 10 4 16-2s8-6 14 0`, 1.5, 5); b.ns('m', H(X + 104, X + 150, 132), 1, 5); seal(b, X + 184, 112, 26); return; }
    const sx = X + (k ? 70 : 180), sy = 92;
    slice(shieldPts(sx + 4, sy + 4, 60, 72), 7, 0, 4).forEach((d, i) => b.f(i, d, 3));
    b.ns('w', shieldPath(sx, sy, 68, 80), 2, 5);
  },
];

// ---------------------------------------------------------------------------------------------------------- Tips
function rays(b, cx, cy, r0, r1, w, slots = null, from = -90, to = 90, count = 7) {
  for (let i = 0; i < count; i++) { const a = from + ((to - from) * i) / (count - 1); const d = Ln(...at(cx, cy, r0, a), ...at(cx, cy, r1, a)); if (slots !== null) b.s(slots + i, d, w, 5); else b.ns('w', d, w, 5); }
}
function base(b, cx, top, r, lit = true) {
  const w = r * 0.46;
  b.ns('m', `${H(cx - w, cx + w, top + 6)}${H(cx - w, cx + w, top + 12)}${lit ? H(cx - w, cx + w, top + 18) : ''}`, 3, 5);
  b.nf('p3', P([[cx - w * 0.7, top + 22], [cx + w * 0.7, top + 22], [cx + w * 0.3, top + 28], [cx - w * 0.3, top + 28]]), 5);
}
// The filament: two supports and a coil between them.
function filament(b, cx, cy, r) {
  const y = cy + r * 0.15, x0 = cx - r * 0.32, step = (r * 0.64) / 5;
  let d = `M${n(cx - r * 0.2)} ${n(cy + r * 1.25)}L${n(x0)} ${n(y)}`;
  for (let i = 0; i < 5; i++) d += `a${n(step / 2)} ${n(step / 2)} 0 0 1 ${n(step)} 0`;
  d += `L${n(cx + r * 0.2)} ${n(cy + r * 1.25)}`;
  b.ns('m', d, 1.5, 4);
}
function lit(b, cx, cy, r) { slice(bulbPts(cx, cy, r), 7, 0, 4).forEach((d, j) => b.f(j, d)); rays(b, cx, cy, r + 8, r + 20, 2.5, null, -75, 75, 5); b.ns('w', bulbPath(cx, cy, r), 2, 5); }
M.tips = [
  (b, { W, k }) => { // A bulb whose glass is the bars (upright, or on the bend one way or the other), its light around it.
    const X = (W - 320) / 2, cx = X + 160, cy = 70, r = 38;
    [slice(bulbPts(cx, cy, r), 7, 0, 4), sliceAt(bulbPts(cx, cy, r), 7, 24, cx, cy + 6, 4), sliceAt(bulbPts(cx, cy, r), 7, -24, cx, cy + 6, 4)][k].forEach((d, i) => b.f(i, d));
    b.ns('w', bulbPath(cx, cy, r), 2, 5); base(b, cx, cy + r * 1.25, r);
    rays(b, cx, cy, r + 10, r + 24, 2.5, null, k === 2 ? -100 : -80, k === 2 ? 100 : 80, k === 2 ? 7 : 5);
    if (W > 320) [[70, 0.62], [506, 0.62]].forEach(([x, s]) => { b.both('pm', bulbPath(x, 80, r * s), 2, 1); base(b, x, 80 + r * s * 1.25, r * s); });
  },
  (b, { W, k }) => { // A bulb drawn in light, its rays in the bars.
    const cx = W / 2, cy = 80, r = 36;
    b.both('pw', bulbPath(cx, cy, r), 2.5, 1); base(b, cx, cy + r * 1.25, r); filament(b, cx, cy, r);
    if (W === 320) { if (k === 2) { rays(b, cx, cy, r + 12, r + 26, 5, 0, -126, -54, 4); rays(b, cx, cy, r + 12, r + 26, 5, 4, 66, 114, 3); } else rays(b, cx, cy, r + 12, r + 32, 5, 0, k ? -110 : -90, k ? 110 : 90, 7); }
    else if (k === 0) { rays(b, cx, cy, r + 14, r + 60, 6, 0, -112, -36, 7); rays(b, cx, cy, r + 14, r + 60, 6, 0, 36, 112, 7); }
    else if (k === 1) { rays(b, cx, cy, r + 18, r + 110, 4, 0, -100, -62, 7); rays(b, cx, cy, r + 18, r + 110, 4, 0, 62, 100, 7); }
    else rays(b, cx, cy, r + 14, r + 44, 6, 0, -150, 150, 7);
  },
  (b, { W, k }) => { // A row of bulbs, the middle one lit (or a stair of them, the top one lit).
    if (k === 2) {
      const count = W > 320 ? 5 : 3, pitch = W > 320 ? 100 : 82, x0 = W / 2 - ((count - 1) * pitch) / 2;
      for (let i = 0; i < count; i++) {
        const cx = x0 + i * pitch, on = i === count - 1, r = on ? 30 : 20, cy = 112 - i * (W > 320 ? 14 : 26);
        if (on) lit(b, cx, cy, r); else b.both('pm', bulbPath(cx, cy, r), 2, 1);
        base(b, cx, cy + r * 1.25, r);
      }
      return;
    }
    const count = (k ? 5 : 3) + (W > 320 ? 2 : 0), pitch = W > 320 ? (k ? 72 : 96) : k ? 58 : 76, x0 = W / 2 - ((count - 1) * pitch) / 2, mid = (count - 1) / 2;
    for (let i = 0; i < count; i++) {
      const cx = x0 + i * pitch, on = i === mid, r = on ? 32 : (k ? 17 : 22), cy = 78;
      if (on) lit(b, cx, cy, r); else b.both('pm', bulbPath(cx, cy, r), 2, 1);
      base(b, cx, cy + r * 1.25, r, on || !k);
    }
  },
  (b, { W, k }) => { // Close up: a large bulb in bands, bars or on the bend, rays at its crown.
    const X = (W - 320) / 2, cx = X + [150, 172, 160][k], cy = 82, r = 56;
    [slice(bulbPts(cx, cy, r), 7, 1, 5), slice(bulbPts(cx, cy, r), 7, 0, 5), sliceAt(bulbPts(cx, cy, r), 7, 40, cx, cy, 5)][k].forEach((d, i) => b.f(i, d));
    b.ns('w', bulbPath(cx, cy, r), 2.5, 5);
    rays(b, cx, cy, r + 10, r + 24, 3, null, [40, -140, -50][k], [140, -40, 50][k], [4, 4, 5][k]);
    if (W > 320) rays(b, cx, cy, r + 40, r + 92, 4, 0, [-120, 60, 70][k], [-60, 120, 130][k], 7);
  },
];
