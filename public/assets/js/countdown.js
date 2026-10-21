/*
 * A decorative StageWatch confidence monitor: a countdown that is green, turns amber ("wrap up") near the end and
 * red when it runs over, with department cue lights that stand by and go on cue.
 *   <script type="module" src="/assets/js/countdown.js"></script>   (pages with a [data-countdown])
 *
 * Markup hooks (see docs/DESIGN.md, "Confidence monitor"):
 *   [data-countdown="75"]          the root; the value is the duration in seconds. Optional:
 *        data-warn="60"            seconds left when it turns amber (default 60)
 *        data-overrun="12"         seconds over time before it starts again (default 12; 0 = never)
 *        data-msg-warn / data-msg-over   the words under the clock (default WRAP UP / OVER TIME)
 *   [data-countdown-time]          mm:ss (the server renders the starting value, so the page reads well without JS)
 *   [data-countdown-fill]          progress fill (its width is set in %)
 *   [data-countdown-msg]           the wrap-up / over-time words
 *   [data-countdown-clock]         time of day, hh:mm:ss
 *   [data-dept][data-standby-at="30"][data-go-at="0"]   cue lights; inside, [data-dept-state] holds the word
 *   [data-countdown-action="pause|reset|minus|plus"]    controls; "minus"/"plus" move the clock by data-step (60 s)
 *
 * The root gets data-state="go|standby|over"; the CSS colours follow it. Runs only while on screen and the tab is
 * visible. The numbers keep ticking under reduced motion (they are information); only the colour fades stop.
 */

const pad = (n) => String(Math.abs(Math.trunc(n))).padStart(2, '0');
const fmt = (s) => {
  const over = s < 0;
  const a = Math.abs(Math.round(s));
  const h = Math.floor(a / 3600);
  const body = h ? `${h}:${pad(Math.floor((a % 3600) / 60))}:${pad(a % 60)}` : `${pad(Math.floor(a / 60))}:${pad(a % 60)}`;
  return `${over ? '+' : ''}${body}`;
};

for (const el of document.querySelectorAll('[data-countdown]')) {
  try { mount(el); } catch (err) { console.warn('[countdown] not started:', err); }
}

function mount(el) {
  const q = (s) => el.querySelector(s);
  const num = (v, d) => (Number.isFinite(Number(v)) && v !== '' && v != null ? Number(v) : d);
  const duration = Math.max(1, num(el.dataset.countdown, 300));
  const warn = num(el.dataset.warn, 60);
  const overrun = num(el.dataset.overrun, 12);
  const msgWarn = el.dataset.msgWarn ?? 'WRAP UP';
  const msgOver = el.dataset.msgOver ?? 'OVER TIME';
  const timeEl = q('[data-countdown-time]');
  const fillEl = q('[data-countdown-fill]');
  const msgEl = q('[data-countdown-msg]');
  const clockEl = q('[data-countdown-clock]');
  const depts = [...el.querySelectorAll('[data-dept]')];
  // Controls may sit just outside the monitor: look in the closest [data-countdown-scope] too.
  const scope = el.closest('[data-countdown-scope]') || el;
  const actions = [...scope.querySelectorAll('[data-countdown-action]')];

  let elapsed = 0, running = true, last = performance.now(), timer = 0, visible = true;
  let lastText = '';

  const setDept = (d, state) => {
    if (d.dataset.cue === state) return;
    d.dataset.cue = state;
    const word = d.querySelector('[data-dept-state]');
    if (word) word.textContent = state === 'standby' ? 'Standby' : state === 'go' ? 'Go' : 'Clear';
  };

  function render() {
    const left = duration - elapsed;
    const shown = left > 0 ? Math.ceil(left - 0.001) : Math.floor(left);
    const text = fmt(shown);
    const state = shown <= 0 ? 'over' : shown <= warn ? 'standby' : 'go';
    if (text !== lastText && timeEl) { timeEl.textContent = text; lastText = text; }
    if (el.dataset.state !== state) el.dataset.state = state;
    if (msgEl) msgEl.textContent = state === 'over' ? msgOver : state === 'standby' ? msgWarn : '';
    if (fillEl) fillEl.style.width = `${Math.max(0, Math.min(100, (elapsed / duration) * 100)).toFixed(2)}%`;
    for (const d of depts) {
      const sb = num(d.dataset.standbyAt, NaN), go = num(d.dataset.goAt, NaN);
      if (!Number.isFinite(sb) && !Number.isFinite(go)) continue;
      setDept(d, Number.isFinite(go) && shown <= go ? 'go' : Number.isFinite(sb) && shown <= sb ? 'standby' : 'clear');
    }
    if (clockEl) {
      const n = new Date();
      clockEl.textContent = `${pad(n.getHours())}:${pad(n.getMinutes())}:${pad(n.getSeconds())}`;
    }
  }
  function step() {
    const now = performance.now();
    if (running) {
      const before = elapsed;
      elapsed += (now - last) / 1000;
      // Loop only when the clock runs past the overrun by itself; a clock pushed past it by hand keeps counting.
      if (overrun > 0 && before <= duration + overrun && elapsed > duration + overrun) elapsed = 0;
    }
    last = now;
    render();
  }
  const loop = () => { clearInterval(timer); timer = visible && !document.hidden ? setInterval(step, 250) : 0; last = performance.now(); };

  for (const b of actions) {
    b.addEventListener('click', () => {
      const act = b.dataset.countdownAction;
      const stepBy = num(b.dataset.step, 60);
      if (act === 'pause') {
        running = !running;
        b.setAttribute('aria-pressed', String(!running));
        const label = b.querySelector('[data-label]') || b;
        label.textContent = running ? 'Pause' : 'Resume';
      } else if (act === 'reset') {
        elapsed = 0;
      } else if (act === 'minus') {
        elapsed = Math.min(duration + Math.max(overrun, 0) + 3600, elapsed + stepBy);
      } else if (act === 'plus') {
        elapsed = Math.max(-duration, elapsed - stepBy);
      }
      last = performance.now();
      render();
    });
  }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver((entries) => { visible = entries[entries.length - 1].isIntersecting; loop(); }).observe(el);
  }
  document.addEventListener('visibilitychange', loop);
  render();
  loop();
}
