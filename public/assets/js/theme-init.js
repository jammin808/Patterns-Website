/*
 * Runs before first paint (a classic script in <head>, no inline code): applies the visitor's stored theme and hides
 * announcements they dismissed. Both live in this browser's localStorage only (site.js writes them) and are never
 * sent to the server: public pages are cached for everyone, so the server never renders them per visitor.
 *
 * Pages dissolve into each other (a cross-document view transition, site.css), and that dissolve never shows the
 * other theme:
 *   - the stored choices are applied again at every reveal (pagereveal, which comes before the first frame, and
 *     pageshow for browsers without it), so a page restored from the back/forward cache, which keeps the state it had
 *     when the visitor left it, shows the choice made since;
 *   - a cached page's frozen frame shows before any reveal, so site.js tells the site's other pages when the stored
 *     theme changes, or when the system's scheme does while the page follows it: in Chrome that message evicts the
 *     cached page, and Back loads it afresh. A change made while no page of the site is open (on another site, say)
 *     reaches no cached page, and script cannot catch it;
 *   - the outgoing page records the colour scheme it shows (pageswap) and the incoming page cuts instead of
 *     dissolving when its own differs, or when it cannot tell;
 *   - a theme change while the dissolve runs (a click, another tab, the system) cuts it short.
 * A theme change itself is never a view transition: site.js sets the attribute at once.
 */
(function () {
  var root = document.documentElement;
  var SCHEME = 'psc-swap-scheme';

  function applyStored() {
    try {
      var theme = localStorage.getItem('psc-theme');
      if (theme === 'light' || theme === 'dark') {
        if (root.getAttribute('data-theme') !== theme) root.setAttribute('data-theme', theme);
      } else if (root.hasAttribute('data-theme')) {
        root.removeAttribute('data-theme');
      }
      var dismissed = (localStorage.getItem('psc-dismissed') || '').split(',').join(' ');
      if (dismissed) root.setAttribute('data-dismissed', dismissed); else root.removeAttribute('data-dismissed');
    } catch (e) {
      /* storage blocked: the page follows the system theme and shows every announcement */
    }
  }

  /* The scheme the CSS resolved from data-theme and prefers-color-scheme: "light" or "dark". */
  function scheme() {
    return getComputedStyle(root).colorScheme;
  }

  applyStored();

  addEventListener('pageshow', function (e) {
    if (e.persisted) applyStored();
  });

  addEventListener('pageswap', function (e) {
    if (!e.viewTransition) return;
    try {
      sessionStorage.setItem(SCHEME, scheme());
    } catch (err) {
      e.viewTransition.skipTransition();
    }
  });

  addEventListener('pagereveal', function (e) {
    applyStored();
    var from = null;
    try {
      from = sessionStorage.getItem(SCHEME);
      sessionStorage.removeItem(SCHEME);
    } catch (err) {
      from = null;
    }
    var transition = e.viewTransition;
    if (!transition) return;
    if (from === null || from !== scheme()) {
      transition.skipTransition();
      return;
    }
    var cut = function () { transition.skipTransition(); };
    var watch = new MutationObserver(cut);
    var system = matchMedia('(prefers-color-scheme: light)');
    watch.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
    system.addEventListener('change', cut);
    transition.finished.then(function () {
      watch.disconnect();
      system.removeEventListener('change', cut);
    }, function () {
      watch.disconnect();
      system.removeEventListener('change', cut);
    });
  });
})();
