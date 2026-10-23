/*
 * Patterns Show Control: site behaviour. An ES module with no dependencies, loaded on every page:
 *   <script type="module" src="/assets/js/site.js"></script>
 *
 * Every feature finds its elements by data-* attributes (classes are for styling only), does nothing when they
 * are missing, and runs inside its own guard so one failure never stops the others. Nothing here is needed to
 * read a page or follow a link: without JavaScript the navigation, FAQ, forms and tables all still work.
 *
 * Public pages are identical for every visitor (they are cached), so nothing a visitor chooses is rendered by the
 * server. The theme choice (`psc-theme`) and dismissed announcements (`psc-dismissed`) live in this browser's
 * localStorage only and are never sent to the server; theme-init.js, a classic script in <head>, applies them
 * before the first paint (and again whenever a page is revealed), and this module keeps them in step afterwards. No
 * stored theme means the CSS follows prefers-color-scheme. When storage is blocked the page simply follows the
 * system and shows every announcement. Pages dissolve into each other (site.css); theme-init.js keeps each dissolve
 * to one theme, and nothing here ever starts a view transition.
 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const motionOK = () => !matchMedia('(prefers-reduced-motion: reduce)').matches;

const store = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  /* Whether the write reached storage: only a stored choice is one the site's other pages can read. */
  set(key, value) {
    try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); return true; } catch { /* storage blocked: the choice holds on this page only */ return false; }
  },
  /* Whether storage can be read at all (blocked site data throws): when it cannot, nothing is stored to follow. */
  readable() { try { return localStorage !== null; } catch { return false; } },
};
/*
 * A channel between this site's pages in this browser, told when a stored choice (the theme, a dismissed notice)
 * changes, or the system's scheme does while no theme is stored. Only a page whose storage works speaks on it. A page
 * open in another tab follows at once. A page held in the back/forward cache cannot run, and its frozen frame would
 * show the old choice on Back before any script could fix it: a message dropped on it evicts it (Chrome), so Back
 * loads it afresh and theme-init.js applies the choice before its first frame.
 */
const prefs = (() => { try { return new BroadcastChannel('psc-prefs'); } catch { return null; } })();
const tellOtherPages = (what) => { try { prefs?.postMessage(what); } catch { /* the other pages catch up on their next load */ } };

/* Reads a cookie the server set; the only one read here is the portal's psc_li hint. */
const readCookie = (name) => {
  const hit = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
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

  /*
   * The page shows exactly the stored choice: the attribute is set at once, never inside a view transition (a page
   * dissolve that is running is cut short by theme-init.js). Whoever sets it (a click here, theme-init.js on a
   * reveal, another tab), the controls and theme-color follow, before the next frame.
   */
  const show = (value) => {
    if (valid(value)) { if (root.dataset.theme !== value) root.dataset.theme = value; } else if ('theme' in root.dataset) delete root.dataset.theme;
  };
  new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
  const apply = (value) => {
    const saved = store.set(KEY, valid(value) ? value : null);
    show(value);
    if (saved) tellOtherPages('theme');
    announce(valid(value) ? `${value[0].toUpperCase()}${value.slice(1)} theme` : 'Theme follows your system');
  };

  for (const control of controls) {
    control.addEventListener('click', (e) => {
      const option = e.target.closest('[data-theme-set]');
      if (option && control.contains(option)) { apply(option.dataset.themeSet); return; }
      if (!$('[data-theme-set]', control)) apply(effective() === 'dark' ? 'light' : 'dark');
    });
  }
  // The system's scheme changed. A page that follows it repaints by itself, but one held in the back/forward cache
  // keeps a frozen frame in the old scheme: telling the other pages evicts it, as a theme click does. With storage
  // blocked nothing is sent, so no page loses a choice made on it alone.
  system.addEventListener('change', () => { sync(); if (!valid(root.dataset.theme) && store.readable()) tellOtherPages('theme'); });
  // A page restored from the back/forward cache (theme-init.js has already applied it), or one open in another tab,
  // shows the choice stored since. With storage blocked there is none, and the choice made on this page holds.
  const follow = () => { if (store.readable()) show(store.get(KEY)); };
  window.addEventListener('pageshow', (e) => { if (e.persisted) follow(); });
  window.addEventListener('storage', (e) => { if (e.key === KEY || e.key === null) follow(); });
  prefs?.addEventListener('message', (e) => { if (e.data === 'theme') follow(); });
  show(store.get(KEY));
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
    // Only a close made from the open drawer fades it out (site.css): a close as the window widens, or under reduced
    // motion, has nothing to fade, and the closed drawer itself has no transition.
    header.classList.toggle('is-nav-closing', !open && header.classList.contains('is-nav-open') && !desktop.matches && motionOK());
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
  // The fade has ended, or was cut short: the closed drawer is back to having no transition.
  const settle = (e) => { if (e.target === nav) header.classList.remove('is-nav-closing'); };
  nav.addEventListener('transitionend', settle);
  nav.addEventListener('transitioncancel', settle);
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
    // The panel is displayed the moment aria-expanded is set (its fade-in is only paint), so focus moves at once.
    button.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); set(true); focusLink(0); }
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
    // Following one of its links closes the panel, as the drawer does: it fades while the next page loads (and goes
    // with the old header in the dissolve), and Back never restores the page with it hanging open.
    panel.addEventListener('click', (e) => {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (e.target.closest('a[href]')) set(false);
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
    // The idle words are read once, before the first press, so a press while "Copied" shows still restores them.
    if (label) label.dataset.copyIdle ??= label.textContent;
    button.classList.toggle('is-copied', ok);
    if (label) label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
    announce(ok ? 'Copied to the clipboard' : 'Copy failed; select the text and press Ctrl+C');
    clearTimeout(Number(button.dataset.copyTimer || 0));
    button.dataset.copyTimer = String(setTimeout(() => {
      button.classList.remove('is-copied');
      if (label) label.textContent = label.dataset.copyIdle;
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
  if (readCookie('psc_li') !== '1') return;
  for (const link of $$('[data-account-link]')) {
    const words = $('[data-account-text]', link) || link;
    words.textContent = link.dataset.accountLabel || 'My account';
    link.setAttribute('href', link.dataset.accountHref || '/portal/');
  }
}

/* ------------------------------------------------------------------------------- dismissibles */
/*
 * [data-dismissible="id"] with a [data-dismiss] button inside. The id is remembered in localStorage
 * (`psc-dismissed`, a comma list); theme-init.js copies it to <html data-dismissed> before the first paint, so the
 * CSS hides a dismissed strip without anything shifting on load.
 */
function initDismiss() {
  const read = () => (store.get('psc-dismissed') || '').split(',').filter(Boolean);
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
      if (store.set('psc-dismissed', [...new Set([...read(), id])].slice(-12).join(','))) tellOtherPages('dismissed');
    }
  });
  // A notice dismissed in another tab goes from this one too.
  prefs?.addEventListener('message', (e) => {
    if (e.data !== 'dismissed') return;
    const now = read();
    for (const box of $$('[data-dismissible]')) if (now.includes(box.dataset.dismissible)) box.hidden = true;
  });
}

/* --------------------------------------------------------------------------------- cookie choice */
/*
 * [data-consent] (templates/partials/consent.php) asks whether Google Analytics may set its cookies. The answer is
 * kept in localStorage `psc-consent` as "granted:<ms>" or "denied:<ms>" and stands for a year; with no answer from
 * the last year the banner shows. The Google tag's inline part (Psc\View\Analytics) starts with every consent type
 * denied and loads gtag.js only when the stored answer is "granted"; accepting here does the same at once.
 * Rejecting after accepting withdraws consent and removes the _ga cookies. Any [data-consent-open] reopens the
 * banner (it is hidden until this runs, as the choice needs scripting). Without an answer nothing is sent to Google.
 */
function initConsent() {
  const banner = $('[data-consent]');
  if (!banner) return;
  const KEY = 'psc-consent';
  const YEAR = 365 * 24 * 3600 * 1000;
  const answer = () => {
    const [value, at] = (store.get(KEY) || '').split(':');
    return (value === 'granted' || value === 'denied') && Date.now() - Number(at) < YEAR ? value : null;
  };
  const loadTag = () => {
    if ($('script[src^="https://www.googletagmanager.com/gtag/js"]')) return;
    const tag = document.createElement('script');
    tag.async = true;
    tag.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(banner.dataset.ga || '')}`;
    document.head.append(tag);
  };
  const removeGaCookies = () => {
    const host = location.hostname;
    for (const pair of document.cookie.split('; ')) {
      const name = pair.split('=')[0];
      if (name !== '_ga' && !name.startsWith('_ga_')) continue;
      for (const domain of ['', `; domain=${host}`, `; domain=.${host}`]) document.cookie = `${name}=; Max-Age=0; path=/${domain}`;
    }
  };
  let opener = null;
  const close = () => {
    banner.hidden = true;
    if (opener && document.contains(opener)) opener.focus();
    opener = null;
  };
  const choose = (value) => {
    store.set(KEY, `${value}:${Date.now()}`);
    if (typeof window.gtag === 'function') window.gtag('consent', 'update', { analytics_storage: value });
    if (value === 'granted') loadTag(); else removeGaCookies();
    close();
    announce(value === 'granted' ? 'Statistics cookies accepted' : 'Statistics cookies rejected');
  };
  banner.addEventListener('click', (e) => {
    const button = e.target.closest('[data-consent-choice]');
    if (button) choose(button.dataset.consentChoice === 'granted' ? 'granted' : 'denied');
  });
  // Escape closes a banner reopened from a link, keeping the answer already given; a first answer is still owed.
  banner.addEventListener('keydown', (e) => { if (e.key === 'Escape' && opener && answer()) close(); });
  for (const link of $$('[data-consent-open]')) {
    link.hidden = false;
    link.addEventListener('click', () => {
      opener = link;
      banner.hidden = false;
      const current = answer();
      $(`[data-consent-choice="${current === 'denied' ? 'denied' : 'granted'}"]`, banner)?.focus();
    });
  }
  banner.hidden = answer() !== null;
}

/* ------------------------------------------------------------------ floating assistant launcher */
/*
 * [data-chat-fab] is rendered tucked away (is-tucked) and shows once this has measured the page, so a page that
 * opens with a zone in view never flashes it. A zone is a [data-chat-tuck] element (the home hero), an inline
 * assistant [data-chat-inline] or any other launcher [data-chat-open]: while one is on screen the launcher stays
 * tucked, so it never covers the picture or repeats a way in that is already in view. It never tucks while its
 * panel is open (aria-expanded, which chat.js keeps) or while it has focus. chat.js does the chat itself; without
 * scripting the template's <noscript> link to the contact page stands in for it.
 */
function initFab() {
  const fab = $('[data-chat-fab]');
  if (!fab) return;
  const zones = $$('[data-chat-tuck], [data-chat-inline], [data-chat-open]').filter((z) => z !== fab);
  const watch = zones.length > 0 && 'IntersectionObserver' in window;
  const inView = new Set();
  let measured = !watch;
  const update = () => {
    if (!measured) return;
    const open = fab.getAttribute('aria-expanded') === 'true';
    fab.classList.toggle('is-tucked', inView.size > 0 && !open && document.activeElement !== fab);
  };
  if (watch) {
    const io = new IntersectionObserver((entries) => {
      for (const entry of entries) { if (entry.isIntersecting) inView.add(entry.target); else inView.delete(entry.target); }
      measured = true;
      update();
    }, { threshold: 0, rootMargin: '-64px 0px -96px 0px' });
    zones.forEach((z) => io.observe(z));
  }
  new MutationObserver(update).observe(fab, { attributes: true, attributeFilter: ['aria-expanded'] });
  fab.addEventListener('blur', update);
  update();
}

/* -------------------------------------------------------------------------- cue ladder (GO/Back) */
/*
 * [data-ladder] with items [data-cue] (data-state done|standby|waiting), a [data-ladder-go] and [data-ladder-back]
 * button, an optional [data-ladder-status] (aria-live) and [data-cue-label] inside each cue for the state word.
 * After GO or Back the cue now standing by is scrolled just into view: clear of the sticky header (the root's
 * scroll-padding-top) and of the controls when they stick to the bottom of the screen; smooth only when motion is
 * allowed.
 */
function initLadder() {
  const words = { done: 'Done', standby: 'Standby', waiting: 'Waiting' };
  for (const ladder of $$('[data-ladder]')) {
    const cues = $$('[data-cue]', ladder);
    const go = $('[data-ladder-go]', ladder);
    const back = $('[data-ladder-back]', ladder);
    const status = $('[data-ladder-status]', ladder);
    if (!cues.length || !go) continue;
    const bar = go.parentElement;
    // The nearest edge is chosen here because browsers skip a block: 'nearest' scroll when the cue is on screen
    // but under the stuck controls; 'start' honours the root's scroll-padding-top, 'end' the cue's scroll-margin.
    const reveal = (cue) => {
      const stuck = bar && bar !== ladder && getComputedStyle(bar).position === 'sticky';
      const floor = stuck ? Math.ceil(bar.getBoundingClientRect().height) + 12 : 0;
      const top = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
      const bottom = document.documentElement.clientHeight - floor;
      const r = cue.getBoundingClientRect();
      let block = null;
      if (r.top < top) block = 'start';
      else if (r.bottom > bottom) block = r.height > bottom - top ? 'start' : 'end';
      if (!block) return;
      cue.style.scrollMarginBottom = floor ? `${floor}px` : '';
      cue.scrollIntoView({ block, behavior: motionOK() ? 'smooth' : 'auto' });
    };
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
      if (speak && q) reveal(q);
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
 * focus on load so the first thing read after a failed submit is what to fix. input[type=file][data-max-bytes]
 * refuses files over that size before the form is sent, with the field's data-max-message; the server still
 * checks, this only saves sending a large file that would be refused.
 */
function initForms() {
  for (const input of $$('input[type="file"][data-max-bytes]')) {
    const max = Number(input.dataset.maxBytes) || 0;
    if (!max) continue;
    const check = () => {
      const total = Array.from(input.files || []).reduce((sum, f) => sum + f.size, 0);
      input.setCustomValidity(total > max ? (input.dataset.maxMessage || 'This file is too large.') : '');
    };
    input.addEventListener('change', () => { check(); input.reportValidity(); });
    check();
  }
  for (const field of $$('textarea[data-count], input[data-count]')) {
    const out = $(field.dataset.count);
    if (!out) continue;
    const max = Number(field.getAttribute('maxlength')) || 0;
    const update = () => { out.textContent = max ? `${field.value.length} / ${max}` : String(field.value.length); };
    field.addEventListener('input', update);
    update();
  }
  /*
   * Feedback on load. A server-rendered [data-error-summary] takes focus, so the first thing read after a refused
   * send is what to fix, and each of its links moves focus to its field with the label in view. Otherwise the
   * answer to what the visitor just did ([data-feedback]: a flash, "Message sent") takes focus, unless the address's
   * #fragment has put the visitor elsewhere on the page (their new reply, the vote they pressed): there it is read
   * out instead, and the page stays where the fragment put it.
   */
  const fromHash = (hash) => {
    try { return hash.length > 1 ? document.getElementById(decodeURIComponent(hash.slice(1))) : null; } catch { return null; }
  };
  const summary = $('[data-error-summary]');
  if (summary && !summary.hidden) {
    if (!summary.hasAttribute('tabindex')) summary.setAttribute('tabindex', '-1');
    summary.focus();
    summary.addEventListener('click', (event) => {
      const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
      const field = link ? fromHash(link.hash) : null;
      if (!field) return;
      event.preventDefault();
      field.focus({ preventScroll: true });
      // The question goes into view with the field: a radio group's legend, otherwise the field's own label.
      const label = field.labels && field.labels.length ? field.labels[0] : null;
      ((field.type === 'radio' && field.closest('fieldset')) || label || field).scrollIntoView({ block: 'start' });
    });
  } else {
    const answer = $$('[data-feedback]').find((el) => el.getClientRects().length > 0);
    const elsewhere = fromHash(location.hash);
    if (answer && elsewhere && !answer.contains(elsewhere) && !elsewhere.contains(answer)) {
      announce(answer.textContent.replace(/\s+/g, ' ').trim());
    } else if (answer) {
      if (!answer.hasAttribute('tabindex')) answer.setAttribute('tabindex', '-1');
      answer.focus();
    }
  }

  /*
   * A form that posts is sent once. The first press marks it busy until the next page: aria-busy on the form,
   * aria-disabled on the button, and a button with data-busy shows (and announces) those words, such as "Sending…",
   * in place of its label. Another press or Enter does nothing. The button is never disabled, so its name and value
   * still go with the form. A page restored from the back/forward cache is ready to send again. Without scripting,
   * the server's own guards stand alone (duplicate messages are dropped, every sending form is rate limited).
   */
  const sending = new Map();
  const release = (form) => {
    const state = sending.get(form);
    if (!state) return;
    sending.delete(form);
    form.removeAttribute('aria-busy');
    if (!state.button) return;
    state.button.removeAttribute('aria-disabled');
    if (state.label !== null) {
      state.button.textContent = state.label;
      state.button.style.minWidth = state.minWidth;
    }
  };
  for (const form of $$('form')) {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') continue;
    form.addEventListener('submit', (event) => {
      if (sending.has(form)) {
        event.preventDefault();
        return;
      }
      const button = event.submitter instanceof HTMLElement
        ? event.submitter
        : form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
      const state = { button, label: null, minWidth: '' };
      sending.set(form, state);
      form.setAttribute('aria-busy', 'true');
      if (button) {
        button.setAttribute('aria-disabled', 'true');
        const words = button.dataset.busy;
        // Only a plain-text button's label is swapped, keeping its width so nothing beside it moves.
        if (words && button.tagName === 'BUTTON' && button.children.length === 0) {
          state.label = button.textContent;
          state.minWidth = button.style.minWidth;
          button.style.minWidth = `${button.getBoundingClientRect().width}px`;
          button.textContent = words;
          announce(words);
        }
      }
      // A later handler may still cancel this submit: then nothing is on its way and the form is ready again.
      setTimeout(() => { if (event.defaultPrevented) release(form); }, 0);
    });
  }
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) [...sending.keys()].forEach(release);
  });
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
guard('consent', initConsent);
guard('fab', initFab);
guard('ladder', initLadder);
guard('tabs', initTabs);
guard('forms', initForms);
