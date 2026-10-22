/*
 * Industry Updates' article editor (templates/admin/news/): the repeating rows, the character counts and the guard
 * on unsaved changes. An ES module with no dependencies, on the admin article pages only:
 *   <script type="module" src="/assets/js/admin-news.js"></script>
 *
 * Nothing here is needed to write an article. Without it the server's six empty rows under each list stay in view,
 * each count shows the length the page was drawn with, and every form posts as it is.
 *
 * - Rows: in [data-news-rows] (with data-news-max), every empty [data-news-row] after the first empty one is hidden
 *   and the [data-news-add] button appears. It shows the next hidden row, or, once none is hidden, adds a copy of the
 *   last row with its values cleared and its names, ids and number moved on, up to the list's limit. The row's first
 *   field takes focus.
 * - Counts: a field with data-news-count="#id" and data-news-max writes "n / max" into #id, counting characters as
 *   the server does (code points, not UTF-16 units). The count is not a live region, so it never talks over typing.
 * - Unsaved changes: once a field of the article form ([data-news-editor]) changes, every image form's buttons
 *   ([data-news-image-form]) are disabled and [data-news-dirty] says why, since those forms send only themselves;
 *   leaving the page asks first. Sending the article clears it.
 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function guard(name, fn) {
  try { fn(); } catch (err) { console.warn(`[admin-news] ${name} skipped:`, err); }
}

/* A row is empty when nothing is typed in it (a source's kind is a choice, not content). */
const isEmpty = (row) => $$('input, textarea', row).every((el) => el.value.trim() === '');

/* Moves a copied row to position index: list[3][url] and list-3-url become list[index][…] and list-index-…. */
function renumber(row, index) {
  for (const el of $$('[name], [id], [for], [aria-describedby]', row)) {
    for (const attr of ['name', 'id', 'for', 'aria-describedby']) {
      const value = el.getAttribute(attr);
      if (value) el.setAttribute(attr, value.replace(/\[\d+\]/, `[${index}]`).replace(/-\d+-/g, `-${index}-`));
    }
  }
  for (const n of $$('[data-news-n]', row)) n.textContent = String(index + 1);
}

/* A copied row starts clean: no values, no errors, the default kind. */
function clear(row) {
  for (const el of $$('input, textarea', row)) el.value = '';
  for (const select of $$('select', row)) {
    const other = Array.from(select.options).findIndex((o) => o.value === 'other');
    select.selectedIndex = other >= 0 ? other : 0;
  }
  for (const err of $$('.pt-error', row)) err.remove();
  for (const el of $$('[aria-invalid]', row)) {
    el.removeAttribute('aria-invalid');
    el.removeAttribute('aria-describedby');
  }
}

function initRows() {
  for (const list of $$('[data-news-rows]')) {
    const rows = () => $$('[data-news-row]', list);
    const max = Number(list.dataset.newsMax) || 12;
    let shownEmpty = false;
    for (const row of rows()) {
      if (!isEmpty(row)) continue;
      if (shownEmpty) row.hidden = true;
      shownEmpty = true;
    }
    const add = $('[data-news-add]', list);
    if (!add) continue;
    const refresh = () => {
      const all = rows();
      add.hidden = all.length >= max && all.every((r) => !r.hidden);
    };
    add.addEventListener('click', () => {
      const all = rows();
      let next = all.find((r) => r.hidden);
      if (!next) {
        if (all.length >= max) return;
        const last = all[all.length - 1];
        next = last.cloneNode(true);
        renumber(next, all.length);
        clear(next);
        last.after(next);
      }
      next.hidden = false;
      $('input, textarea, select', next)?.focus();
      refresh();
    });
    refresh();
  }
}

function initCounts() {
  for (const field of $$('[data-news-count]')) {
    const out = $(field.dataset.newsCount);
    if (!out) continue;
    const max = Number(field.dataset.newsMax) || 0;
    const update = () => {
      const n = Array.from(field.value).length;
      out.textContent = max ? `${n} / ${max}` : String(n);
    };
    field.addEventListener('input', update);
    update();
  }
}

function initDirty() {
  const editor = $('[data-news-editor]');
  if (!editor) return;
  let dirty = false;
  const mark = (event) => {
    if (dirty || !(event.target instanceof Element) || !event.target.matches('input, textarea, select')) return;
    dirty = true;
    for (const button of $$('[data-news-image-form] button[type="submit"], [data-news-image-form] button:not([type])')) button.disabled = true;
    for (const note of $$('[data-news-dirty]')) note.hidden = false;
  };
  editor.addEventListener('input', mark);
  editor.addEventListener('change', mark);
  editor.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
}

guard('rows', initRows);
guard('counts', initCounts);
guard('unsaved changes', initDirty);
