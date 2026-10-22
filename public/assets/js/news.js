/*
 * Industry Updates: counts the clicks on an article's source links. An ES module with no dependencies, loaded on
 * article pages only:
 *   <script type="module" src="/assets/js/news.js"></script>
 *
 * A link with [data-news-link="<id>"] keeps its real address, so the browser follows it as it would anyway; this
 * only tells the site it was followed, on a click (mouse, keyboard or touch) and on a middle click. The report is a
 * same-origin POST with keepalive, so it survives the page being left, and carries X-Requested-With, which the
 * endpoint requires. It carries no cookie and nothing about the visitor; robots, repeats within ten minutes and
 * articles that are not live are not counted, so the counts are approximate. Nothing here is needed to follow a
 * link, and a report that fails is dropped.
 */

const sent = new Map();

function report(link) {
  const id = link.dataset.newsLink;
  if (!/^\d{1,10}$/.test(id ?? '')) return;
  // One report per link per second: a double click is one visit.
  const now = Date.now();
  if (now - (sent.get(id) ?? 0) < 1000) return;
  sent.set(id, now);
  try {
    fetch(`/api/news/links/${id}/click`, {
      method: 'POST',
      keepalive: true,
      credentials: 'omit',
      headers: { 'X-Requested-With': 'fetch' },
    }).catch(() => { /* not counted: the visitor still reaches the source */ });
  } catch { /* fetch unavailable: nothing to count with */ }
}

function linkFrom(event) {
  const target = event.target instanceof Element ? event.target : null;
  return target?.closest('a[data-news-link]') ?? null;
}

function initClicks() {
  document.addEventListener('click', (event) => {
    const link = linkFrom(event);
    if (link && event.button === 0) report(link);
  });
  // A middle click opens the source in a new tab without a click event.
  document.addEventListener('auxclick', (event) => {
    const link = linkFrom(event);
    if (link && event.button === 1) report(link);
  });
}

try { initClicks(); } catch (err) { console.warn('[news] clicks skipped:', err); }
