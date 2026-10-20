/*
 * Patterns Show Control — site behaviour. An ES module with no dependencies, loaded on every page:
 *   <script type="module" src="/assets/js/site.js"></script>
 *
 * Every feature finds its elements by data-* attributes (classes are for styling only), does nothing when they
 * are missing, and runs inside its own guard so one failure never stops the others. Nothing here is needed to
 * read a page or follow a link: without JavaScript the navigation, FAQ, forms and tables all still work.
 *
 * Theme before first paint, without an inline script: the toggle stores the choice in localStorage AND in a
 * first-party cookie `psc_theme=light|dark` (no cookie = follow the system). The PHP layout reads that cookie and
 * renders <html data-theme="…"> on the server, so the first paint is already right. With no attribute the CSS
 * follows prefers-color-scheme. If the server does not render it, this module applies the stored choice as soon
 * as it runs (one frame later), which is the only case that can flash.
 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const motionOK = () => !matchMedia('(prefers-reduced-motion: reduce)').matches;

const store = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) {
    try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch { /* private mode: the choice lasts this visit */ }
  },
};
const cookie = {
  get(name) {
    const hit = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
    return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
  },
  set(name, value, days = 365) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = value === null
      ? `${name}=; Path=/; Max-Age=0; SameSite=Lax${secure}`
      : `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${days * 86400}; SameSite=Lax${secure}`;
  },
};

function guard(name, fn) {
  try { fn(); } catch (err) { console.warn(`[site] ${name} skipped:`, err); }
}

/* A polite live region shared by features that announce something (copy, theme). */
let liveRegion = null;
function announce(text) {
  if (!liveRegion) {
    liveRegion = document.createElement('p');
    liveRegion.className = 'u-visually-hidden';
    liveRegion.setAttribute('role', 'status');
    document.body.append(liveRegion);
  }
  liveRegion.textContent = '';
  requestAnimationFrame(() => { liveRegion.textContent = text; });
}

/* ------------------------------------------------------------------------------------------- theme */
/*
 * [data-theme-toggle] is either a group of buttons with [data-theme-set="system|light|dark"] (aria-pressed shows
 * the choice) or a single button, which switches between light and dark.
 */
function initTheme() {
  const root = document.documentElement;
  const KEY = 'psc-theme';
  const system = matchMedia('(prefers-color-scheme: light)');
  const valid = (v) => v === 'light' || v === 'dark';
  const choice = () => (valid(root.dataset.theme) ? root.dataset.theme : 'system');
  const effective = () => (valid(root.dataset.theme) ? root.dataset.theme : system.matches ? 'light' : 'dark');

  if (!valid(root.dataset.theme)) {
    const saved = store.get(KEY) || cookie.get('psc_theme');
    if (valid(saved)) root.dataset.theme = saved;
  }

  const controls = $$('[data-theme-toggle]');
  const meta = $('meta[name="theme-color"]');
  const sync = () => {
    const current = choice();
    for (const control of controls) {
      const options = $$('[data-theme-set]', control);
      if (options.length) {
        for (const b of options) b.setAttribute('aria-pressed', String(b.dataset.themeSet === current));
      } else {
        const next = effective() === 'dark' ? 'light' : 'dark';
        control.setAttribute('aria-label', `Switch to the ${next} theme`);
        control.dataset.next = next;
      }
    }
    if (meta) meta.setAttribute('content', effective() === 'dark' ? '#090C10' : '#F3F4F6');
  };

  const apply = (value) => {
    const commit = () => {
      if (valid(value)) root.dataset.theme = value; else delete root.dataset.theme;
      store.set(KEY, valid(value) ? value : null);
      cookie.set('psc_theme', valid(value) ? value : null);
      sync();
    };
    if (document.startViewTransition && motionOK()) {
      root.setAttribute('data-theme-switch', '');
      const t = document.startViewTransition(commit);
      t.finished.finally(() => root.removeAttribute('data-theme-switch'));
    } else {
      commit();
    }
    announce(valid(value) ? `${value[0].toUpperCase()}${value.slice(1)} theme` : 'Theme follows your system');
  };

  for (const control of controls) {
    control.addEventListener('click', (e) => {
      const option = e.target.closest('[data-theme-set]');
      if (option && control.contains(option)) { apply(option.dataset.themeSet); return; }
      if (!$('[data-theme-set]', control)) apply(effective() === 'dark' ? 'light' : 'dark');
    });
  }
  system.addEventListener('change', sync);
  sync();
}

/* ---------------------------------------------------------------------------------- mobile drawer */
/* [data-nav-toggle] (a <button aria-expanded aria-controls>) opens the drawer; the header [data-header] gets .is-nav-open. */
function initNav() {
  const toggle = $('[data-nav-toggle]');
  const header = toggle?.closest('[data-header]') || $('[data-header]');
  const nav = toggle && document.getElementById(toggle.getAttribute('aria-controls') || '');
  if (!toggle || !header || !nav) return;
  const desktop = matchMedia('(width >= 72rem)');

  const set = (open, focusBack = false) => {
    toggle.setAttribute('aria-expanded', String(open));
    header.classList.toggle('is-nav-open', open);
    const label = $('[data-nav-label]', toggle);
    if (label) label.textContent = open ? 'Close' : 'Menu';
    if (!open && focusBack) toggle.focus();
  };
  toggle.addEventListener('click', () => set(toggle.getAttribute('aria-expanded') !== 'true'));
  nav.addEventListener('click', (e) => { if (e.target.closest('a[href]')) set(false); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && header.classList.contains('is-nav-open')) set(false, true);
  });
  document.addEventListener('click', (e) => {
    if (header.classList.contains('is-nav-open') && !header.contains(e.target)) set(false);
  });
  desktop.addEventListener('change', () => set(false));
}

/* -------------------------------------------------------------------------------- the mega-menu */
/*
 * [data-mega] holds a button[aria-expanded][aria-controls] and its panel. Enter/Space/click toggles, ArrowDown
 * opens and moves into the panel, ArrowUp/ArrowDown/Home/End move between its links, Escape closes and returns
 * focus, and leaving the menu (focus or pointer elsewhere) closes it.
 */
function initMega() {
  for (const mega of $$('[data-mega]')) {
    const button = $('button[aria-controls]', mega);
    const panel = button && document.getElementById(button.getAttribute('aria-controls') || '');
    if (!button || !panel) continue;
    const links = () => $$('a[href]', panel).filter((a) => a.offsetParent !== null);
    const isOpen = () => button.getAttribute('aria-expanded') === 'true';
    const set = (open) => {
      if (open) {
        for (const other of $$('[data-mega] button[aria-expanded="true"]')) if (other !== button) other.setAttribute('aria-expanded', 'false');
      }
      button.setAttribute('aria-expanded', String(open));
    };
    const focusLink = (i) => { const l = links(); if (l.length) l[(i + l.length) % l.length].focus(); };

    button.addEventListener('click', () => set(!isOpen()));
    button.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); set(true); requestAnimationFrame(() => focusLink(0)); }
      if (e.key === 'Escape' && isOpen()) { e.preventDefault(); e.stopPropagation(); set(false); }
    });
    panel.addEventListener('keydown', (e) => {
      const l = links();
      const i = l.indexOf(document.activeElement);
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); set(false); button.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); focusLink(i + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (i <= 0) button.focus(); else focusLink(i - 1); }
      else if (e.key === 'Home') { e.preventDefault(); focusLink(0); }
      else if (e.key === 'End') { e.preventDefault(); focusLink(-1); }
    });
    mega.addEventListener('focusout', (e) => {
      if (isOpen() && e.relatedTarget && !mega.contains(e.relatedTarget)) set(false);
    });
    document.addEventListener('click', (e) => { if (isOpen() && !mega.contains(e.target)) set(false); });
  }
}

/* ------------------------------------------------------------------------------ header on scroll */
function initHeaderScroll() {
  const header = $('[data-header]');
  if (!header) return;
  let ticking = false;
  const update = () => { header.classList.toggle('is-scrolled', window.scrollY > 4); ticking = false; };
  window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
  update();
}

/* ---------------------------------------------------------------------------- reveal on scroll */
/* [data-reveal] fades up as it enters. Only elements below the fold are armed, so nothing visible ever hides. */
function initReveal() {
  const items = $$('[data-reveal]');
  if (!items.length || !motionOK() || !('IntersectionObserver' in window)) return;
  const io = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      entry.target.classList.add('is-revealed');
      entry.target.classList.remove('is-reveal-armed');
      io.unobserve(entry.target);
    }
  }, { rootMargin: '0px 0px -8% 0px' });
  for (const el of items) {
    if (el.getBoundingClientRect().top < window.innerHeight) continue;
    const group = el.parentElement ? $$(':scope > [data-reveal]', el.parentElement) : [];
    el.style.setProperty('--reveal-i', String(Math.max(0, group.indexOf(el)) % 6));
    el.classList.add('is-reveal-armed');
    io.observe(el);
  }
}

/* ---------------------------------------------------------- comparison table scroll affordance */
/*
 * [data-compare] wraps a [data-compare-scroll] region. data-overflow on the wrapper says none|start|middle|end:
 * the CSS shows the "scroll for more" hint and the edge fade, and with "none" lets the header row stick under
 * the site header (a horizontal scroller would trap it).
 */
function initCompare() {
  for (const root of $$('[data-compare]')) {
    const scroller = $('[data-compare-scroll]', root);
    if (!scroller) continue;
    const update = () => {
      const max = scroller.scrollWidth - scroller.clientWidth;
      let state = 'none';
      if (max > 2) state = scroller.scrollLeft <= 2 ? 'start' : scroller.scrollLeft >= max - 2 ? 'end' : 'middle';
      if (root.dataset.overflow !== state) root.dataset.overflow = state;
    };
    scroller.addEventListener('scroll', update, { passive: true });
    if ('ResizeObserver' in window) {
      // Measure with the scroller in its scrolling state, so a fitting table is detected correctly.
      new ResizeObserver(() => { delete root.dataset.overflow; update(); }).observe(root);
    }
    update();
  }
}

/* ------------------------------------------------------------------- keyboard-reachable scrollers */
/*
 * A box that scrolls sideways must be reachable by keyboard (WCAG 2.1.1). When a data table wrapper, the
 * screenshot strip or any [data-scroll-region] overflows and holds nothing focusable, it becomes a focusable,
 * labelled region. (The one place this file looks at classes: the portal's table wrappers carry no data hook.)
 */
function initScrollRegions() {
  const boxes = $$('[data-scroll-region], .c-table-wrap, .pt-table-wrap, .c-shots');
  if (!boxes.length) return;
  const focusable = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
  const check = (box) => {
    const scrolls = box.scrollWidth - box.clientWidth > 2;
    if (scrolls && !box.hasAttribute('tabindex') && !box.querySelector(focusable)) {
      box.tabIndex = 0;
      if (!box.hasAttribute('role')) box.setAttribute('role', 'region');
      if (!box.hasAttribute('aria-label') && !box.hasAttribute('aria-labelledby')) {
        const caption = box.querySelector('caption');
        box.setAttribute('aria-label', box.dataset.scrollRegion || (caption ? caption.textContent.trim() : 'Scrollable content'));
      }
      box.dataset.scrollFocus = '';
    } else if (!scrolls && box.hasAttribute('data-scroll-focus')) {
      box.removeAttribute('tabindex');
      box.removeAttribute('data-scroll-focus');
    }
  };
  boxes.forEach(check);
  if ('ResizeObserver' in window) {
    const ro = new ResizeObserver((entries) => entries.forEach((e) => check(e.target)));
    boxes.forEach((b) => ro.observe(b));
  }
}

/* ------------------------------------------------------------------------- copy to clipboard */
/* button[data-copy="text"] or button[data-copy-target="#id"]; an optional [data-copy-label] child says "Copied". */
function initCopy() {
  document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-copy], [data-copy-target]');
    if (!button) return;
    const target = button.dataset.copyTarget ? $(button.dataset.copyTarget) : null;
    const text = button.dataset.copy || (target ? ('value' in target ? target.value : target.textContent) : '') || '';
    if (!text.trim()) return;
    let ok = false;
    try {
      await navigator.clipboard.writeText(text.trim());
      ok = true;
    } catch {
      const area = document.createElement('textarea');
      area.value = text.trim();
      area.setAttribute('readonly', '');
      area.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
      document.body.append(area);
      area.select();
      try { ok = document.execCommand('copy'); } catch { ok = false; }
      area.remove();
    }
    const label = $('[data-copy-label]', button);
    const before = label ? label.textContent : '';
    button.classList.toggle('is-copied', ok);
    if (label) label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
    announce(ok ? 'Copied to the clipboard' : 'Copy failed; select the text and press Ctrl+C');
    clearTimeout(Number(button.dataset.copyTimer || 0));
    button.dataset.copyTimer = String(setTimeout(() => {
      button.classList.remove('is-copied');
      if (label) label.textContent = before;
    }, 2000));
  });
}

/* --------------------------------------------------------------------- Client login ↔ My account */
/*
 * The portal sets a non-HttpOnly cookie psc_li=1 while someone is signed in (it carries no identity; the session
 * cookie stays HttpOnly). Cached public pages then show "My account" without asking the server.
 * Markup: <a data-account-link data-account-href="/portal/" data-account-label="My account" href="/portal/login/">…
 * with an optional [data-account-text] child holding the words (so an icon beside them survives).
 */
function initAccount() {
  if (cookie.get('psc_li') !== '1') return;
  for (const link of $$('[data-account-link]')) {
    const words = $('[data-account-text]', link) || link;
    words.textContent = link.dataset.accountLabel || 'My account';
    link.setAttribute('href', link.dataset.accountHref || '/portal/');
  }
}

/* ------------------------------------------------------------------------------- dismissibles */
/*
 * [data-dismissible="id"] with a [data-dismiss] button inside. The id is remembered (localStorage and the cookie
 * psc_dismissed, a comma list) so the server can leave it out next time and nothing shifts on load.
 */
function initDismiss() {
  const read = () => (store.get('psc-dismissed') || cookie.get('psc_dismissed') || '').split(',').filter(Boolean);
  const seen = read();
  for (const box of $$('[data-dismissible]')) {
    if (box.dataset.dismissible && seen.includes(box.dataset.dismissible)) box.hidden = true;
  }
  document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-dismiss]');
    const box = button?.closest('[data-dismissible]');
    if (!box) return;
    box.hidden = true;
    const id = box.dataset.dismissible;
    if (id) {
      const list = [...new Set([...read(), id])].slice(-12).join(',');
      store.set('psc-dismissed', list);
      cookie.set('psc_dismissed', list, 120);
    }
  });
}

/* ------------------------------------------------------------------ floating assistant launcher */
/*
 * [data-chat-fab] tucks away while any [data-chat-tuck] element (the home hero, the inline support chat) is on
 * screen, so it never covers the picture or duplicates the chat in view. chat.js does the chat itself.
 */
function initFab() {
  const fab = $('[data-chat-fab]');
  const zones = $$('[data-chat-tuck], [data-chat-inline]');
  if (!fab || !zones.length || !('IntersectionObserver' in window)) return;
  const visible = new Set();
  const io = new IntersectionObserver((entries) => {
    for (const entry of entries) { if (entry.isIntersecting) visible.add(entry.target); else visible.delete(entry.target); }
    const tuck = visible.size > 0 && fab.getAttribute('aria-expanded') !== 'true';
    fab.classList.toggle('is-tucked', tuck);
    if (tuck && document.activeElement === fab) fab.blur();
  }, { threshold: 0, rootMargin: '-64px 0px -96px 0px' });
  zones.forEach((z) => io.observe(z));
}

/* -------------------------------------------------------------------------- cue ladder (GO/Back) */
/*
 * [data-ladder] with items [data-cue] (data-state done|standby|waiting), a [data-ladder-go] and [data-ladder-back]
 * button, an optional [data-ladder-status] (aria-live) and [data-cue-label] inside each cue for the state word.
 */
function initLadder() {
  const words = { done: 'Done', standby: 'Standby', waiting: 'Waiting' };
  for (const ladder of $$('[data-ladder]')) {
    const cues = $$('[data-cue]', ladder);
    const go = $('[data-ladder-go]', ladder);
    const back = $('[data-ladder-back]', ladder);
    const status = $('[data-ladder-status]', ladder);
    if (!cues.length || !go) continue;
    let standby = cues.findIndex((c) => c.dataset.state === 'standby');
    if (standby < 0) standby = cues.findIndex((c) => c.dataset.state !== 'done');
    if (standby < 0) standby = cues.length;
    const render = (speak) => {
      cues.forEach((cue, i) => {
        const state = i < standby ? 'done' : i === standby ? 'standby' : 'waiting';
        cue.dataset.state = state;
        const label = $('[data-cue-label]', cue);
        if (label) label.textContent = words[state];
      });
      const q = cues[standby];
      go.disabled = !q;
      const goQ = $('[data-ladder-go-q]', go);
      if (goQ) goQ.textContent = q ? (q.dataset.cue || `Q${standby + 1}`) : '';
      if (back) back.disabled = standby === 0;
      if (speak && status) {
        const title = q ? ($('h3', q)?.textContent || '').trim() : '';
        status.textContent = q ? `Standing by: ${q.dataset.cue || `Q${standby + 1}`}, ${title}.` : 'All cues done.';
      }
    };
    go.addEventListener('click', () => { if (standby < cues.length) { standby += 1; render(true); } });
    back?.addEventListener('click', () => { if (standby > 0) { standby -= 1; render(true); } });
    render(false);
  }
}

/* ------------------------------------------------------------------------------------ tabs */
/* [data-tabs] containing [role="tab"][aria-controls] buttons; arrow keys move, panels are toggled with hidden. */
function initTabs() {
  for (const list of $$('[data-tabs]')) {
    const tabs = $$('[role="tab"]', list);
    if (!tabs.length) continue;
    const select = (tab, focus) => {
      for (const t of tabs) {
        const on = t === tab;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
        const panel = document.getElementById(t.getAttribute('aria-controls') || '');
        if (panel) panel.hidden = !on;
      }
      if (focus) tab.focus();
    };
    list.addEventListener('click', (e) => { const t = e.target.closest('[role="tab"]'); if (t) select(t, false); });
    list.addEventListener('keydown', (e) => {
      const i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      const to = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (to === undefined) return;
      e.preventDefault();
      select(tabs[(to + tabs.length) % tabs.length], true);
    });
    select(tabs.find((t) => t.getAttribute('aria-selected') === 'true') || tabs[0], false);
  }
}

/* --------------------------------------------------------------------------------- forms */
/*
 * textarea[data-count="#id"] writes "n / max" into the element #id. A server-rendered [data-error-summary] takes
 * focus on load so the first thing read after a failed submit is what to fix.
 */
function initForms() {
  for (const field of $$('textarea[data-count], input[data-count]')) {
    const out = $(field.dataset.count);
    if (!out) continue;
    const max = Number(field.getAttribute('maxlength')) || 0;
    const update = () => { out.textContent = max ? `${field.value.length} / ${max}` : String(field.value.length); };
    field.addEventListener('input', update);
    update();
  }
  const summary = $('[data-error-summary]');
  if (summary && !summary.hidden) {
    if (!summary.hasAttribute('tabindex')) summary.setAttribute('tabindex', '-1');
    summary.focus();
  }
}

guard('theme', initTheme);
guard('nav', initNav);
guard('mega', initMega);
guard('header', initHeaderScroll);
guard('reveal', initReveal);
guard('compare', initCompare);
guard('scroll-regions', initScrollRegions);
guard('copy', initCopy);
guard('account', initAccount);
guard('dismiss', initDismiss);
guard('fab', initFab);
guard('ladder', initLadder);
guard('tabs', initTabs);
guard('forms', initForms);
