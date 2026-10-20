/*
 * Runs before first paint (a classic script in <head>, no inline code): applies the visitor's stored theme and hides
 * announcements they dismissed. Public pages are cached for everyone, so the server never renders these per visitor.
 */
(function () {
  try {
    var root = document.documentElement;
    var read = function (name) {
      var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    };
    var theme = localStorage.getItem('psc-theme') || read('psc_theme');
    if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
    var dismissed = localStorage.getItem('psc-dismissed') || read('psc_dismissed');
    if (dismissed) root.setAttribute('data-dismissed', dismissed.split(',').join(' '));
  } catch (e) {
    /* storage blocked: the page follows the system theme */
  }
})();
