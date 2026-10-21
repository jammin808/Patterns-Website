/*
 * Runs before first paint (a classic script in <head>, no inline code): applies the visitor's stored theme and hides
 * announcements they dismissed. Both live in this browser's localStorage only (site.js writes them) and are never
 * sent to the server: public pages are cached for everyone, so the server never renders them per visitor.
 */
(function () {
  try {
    var root = document.documentElement;
    var theme = localStorage.getItem('psc-theme');
    if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
    var dismissed = localStorage.getItem('psc-dismissed');
    if (dismissed) root.setAttribute('data-dismissed', dismissed.split(',').join(' '));
  } catch (e) {
    /* storage blocked: the page follows the system theme and shows every announcement */
  }
})();
