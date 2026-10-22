/*
 * Industry Updates comments on a cached article page (templates/partials/news/comments.php). The page is the same
 * for every visitor and starts no session: its [data-comments] section holds the visible comments as they were when
 * the page was drawn (data-rendered-at), a sign-in link, and inert <template>s for the comment form, one comment and
 * two notices. Only while the portal's psc_li hint is set does this ask /api/news/{id}/state what the visitor may do
 * (so a reader who never signed in starts no session either); then it drops the comments removed since, merges in the
 * person's own recent and pending comments, offers Delete on theirs and Report on everyone else's, and swaps the
 * sign-in link for the form. When the server says the visitor is signed out the sign-in link stays, so a stale hint
 * does no harm. An unsent comment is kept in sessionStorage per article and put back.
 *
 * Requests are same-origin fetches with X-Requested-With, the api middleware's CSRF defence. Text from the server is
 * set with textContent, never as HTML. Focus moves only after the person acts: to their posted comment, to the
 * heading after a delete, to the answer that replaces a report button. A polite live region says what happened.
 */

const HEADERS = { 'X-Requested-With': 'fetch', Accept: 'application/json' };
const TEXT = {
  posting: 'Posting your comment…',
  posted: 'Your comment is posted.',
  waiting: 'Your comment is posted. Only you can see it until it is approved.',
  notPosted: 'Your comment was not posted. Please try again in a moment.',
  remove: 'Delete',
  removeAsk: 'Delete your comment?',
  removeYes: 'Yes, delete it',
  removed: 'Your comment is deleted.',
  notRemoved: 'Your comment was not deleted. Please try again in a moment.',
  report: 'Report',
  reportAsk: 'Report this comment to the team?',
  reportYes: 'Yes, report it',
  reported: 'Reported. Thank you, the team will take a look.',
  notReported: 'The comment was not reported. Please try again in a moment.',
  cancel: 'Cancel',
};

const readCookie = (name) => {
  const hit = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
};

/* The unsent comment, per article, for this tab only; storage can be blocked (private mode), and then it is not kept. */
const draft = {
  get(key) { try { return sessionStorage.getItem(key) || ''; } catch { return ''; } },
  set(key, value) {
    try { if (value) sessionStorage.setItem(key, value); else sessionStorage.removeItem(key); } catch { /* not kept */ }
  },
};

async function call(url, options = {}) {
  const res = await fetch(url, { credentials: 'same-origin', ...options, headers: { ...HEADERS, ...(options.headers || {}) } });
  let data = {};
  try { data = await res.json(); } catch { /* an empty or non-JSON answer: the status says enough */ }
  return { status: res.status, ok: res.ok, data };
}
const post = (url, body = {}) => call(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

function setBody(el, text) {
  el.replaceChildren();
  String(text).split('\n').forEach((line, i) => {
    if (i > 0) el.append(document.createElement('br'));
    el.append(document.createTextNode(line));
  });
}

function button(label, className, hidden = '') {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = className;
  b.textContent = label;
  if (hidden) {
    const extra = document.createElement('span');
    extra.className = 'u-visually-hidden';
    extra.textContent = hidden;
    b.append(extra);
  }
  return b;
}

function init(root) {
  const id = root.dataset.articleId;
  const since = root.dataset.renderedAt || '0';
  const list = root.querySelector('[data-comment-list]');
  const empty = root.querySelector('[data-comment-empty]');
  const box = root.querySelector('[data-comment-box]');
  const status = root.querySelector('[data-comment-status]');
  const heading = root.querySelector('h2');
  const tpl = (name) => root.querySelector(`template[data-comment-${name}]`);
  const draftKey = `psc-news-draft:${id}`;
  const back = `${location.pathname}#comments`;
  if (!id || !list || !box) return;

  /* Signing in from here comes back to this article, where the box appears, rather than to the comment page. */
  for (const link of root.querySelectorAll('[data-comment-signin]')) link.href = `/portal/login/?next=${encodeURIComponent(back)}`;
  for (const link of root.querySelectorAll('[data-comment-register]')) link.href = `/portal/register/?next=${encodeURIComponent(back)}`;

  const announce = (text) => {
    if (!status) return;
    status.textContent = '';
    requestAnimationFrame(() => { status.textContent = text; });
  };
  const sync = () => { if (empty) empty.hidden = list.children.length > 0; };
  const find = (cid) => list.querySelector(`[data-comment-id="${Number(cid)}"]`);
  const notice = (name) => {
    const t = tpl(name);
    if (!t) return;
    const node = t.content.firstElementChild.cloneNode(true);
    for (const link of node.querySelectorAll('[data-comment-signin]')) link.href = `/portal/login/?next=${encodeURIComponent(back)}`;
    box.replaceChildren(node);
  };

  let viewer = { signedIn: false, staff: false };

  /* A comment from the API as a list item, built from the page's own template. */
  function item(c) {
    const t = tpl('item');
    if (!t) return null;
    const li = t.content.firstElementChild.cloneNode(true);
    li.id = `comment-${c.id}`;
    li.dataset.commentId = String(c.id);
    li.querySelector('[data-comment-author]').textContent = c.name;
    const time = li.querySelector('[data-comment-time]');
    time.dateTime = c.time;
    time.textContent = c.time_label;
    setBody(li.querySelector('[data-comment-body]'), c.body);
    if (c.staff) {
      li.classList.add('news-comment--staff');
      li.querySelector('[data-comment-staff]').hidden = false;
    }
    if (c.pending) {
      li.classList.add('news-comment--pending');
      li.dataset.pending = '1';
      li.querySelector('[data-comment-pending]').hidden = false;
    }
    return li;
  }

  function upsert(c) {
    const fresh = item(c);
    if (!fresh) return null;
    fresh.dataset.own = '1';
    const old = find(c.id);
    if (old) {
      old.replaceWith(fresh);
    } else {
      // In the order they were posted: a comment waiting from before the page was drawn goes among the older ones.
      const at = Date.parse(c.time);
      const later = [...list.children].find((el) => Date.parse(el.querySelector('time')?.dateTime || '') > at);
      list.insertBefore(fresh, later || null);
    }
    actions(fresh);
    sync();
    return fresh;
  }

  /* A question with Yes and Cancel in place of a comment's buttons; Cancel puts the buttons back. */
  function ask(li, question, yesLabel, yesClass, onYes) {
    const area = li.querySelector('[data-comment-actions]');
    const before = [...area.childNodes];
    const q = document.createElement('span');
    q.textContent = question;
    const yes = button(yesLabel, yesClass);
    const no = button(TEXT.cancel, 'c-btn c-btn--ghost c-btn--sm');
    no.addEventListener('click', () => { area.replaceChildren(...before); before[0]?.focus(); });
    yes.addEventListener('click', () => { yes.setAttribute('aria-disabled', 'true'); onYes(area); }, { once: true });
    area.replaceChildren(q, yes, no);
    yes.focus();
  }

  /* An answer in place of a comment's buttons, which takes focus so it is read where the button was. */
  function say(area, text) {
    const p = document.createElement('span');
    p.className = 'news-comment-said';
    p.tabIndex = -1;
    p.textContent = text;
    area.replaceChildren(p);
    p.focus();
    announce(text);
  }

  function actions(li) {
    const area = li.querySelector('[data-comment-actions]');
    if (!area || !viewer.signedIn) return;
    area.replaceChildren();
    const who = li.querySelector('[data-comment-author]')?.textContent || '';
    const when = li.querySelector('[data-comment-time]')?.textContent || '';
    const cid = li.dataset.commentId;
    if (li.dataset.own === '1') {
      const del = button(TEXT.remove, 'c-btn c-btn--secondary c-btn--sm', ` your comment from ${when}`);
      del.addEventListener('click', () => ask(li, TEXT.removeAsk, TEXT.removeYes, 'c-btn c-btn--danger c-btn--sm', async (where) => {
        const res = await post(`/api/news/comments/${cid}/delete`).catch(() => ({ status: 0, ok: false, data: {} }));
        if (res.ok || res.status === 404) {
          // Deleted now, or already gone: either way it is not on the article any more.
          li.remove();
          sync();
          announce(TEXT.removed);
          heading.tabIndex = -1;
          heading.focus();
        } else if (res.status === 401) {
          expired();
        } else {
          say(where, res.data.message || TEXT.notRemoved);
        }
      }));
      area.append(del);
    } else if (!viewer.staff && li.dataset.pending !== '1') {
      const rep = button(TEXT.report, 'c-btn c-btn--ghost c-btn--sm', ` the comment by ${who} from ${when}`);
      rep.addEventListener('click', () => ask(li, TEXT.reportAsk, TEXT.reportYes, 'c-btn c-btn--secondary c-btn--sm', async (where) => {
        const res = await post(`/api/news/comments/${cid}/report`).catch(() => ({ status: 0, ok: false, data: {} }));
        if (res.status === 401) expired();
        else say(where, res.data.message || (res.ok ? TEXT.reported : TEXT.notReported));
      }));
      area.append(rep);
    }
  }

  /* The sign-in ended between the page loading and a send: the words stay in this tab for after signing in. */
  function expired() {
    viewer = { signedIn: false, staff: false };
    for (const area of list.querySelectorAll('[data-comment-actions]')) area.replaceChildren();
    notice('expired');
  }

  function form(state) {
    const t = tpl('form');
    if (!t) return;
    const node = t.content.firstElementChild.cloneNode(true);
    const input = node.querySelector('[data-comment-input]');
    const count = node.querySelector('[data-comment-count]');
    const error = node.querySelector('[data-comment-error]');
    const send = node.querySelector('[data-comment-send]');
    const name = node.querySelector('[data-comment-name]');
    if (name) name.textContent = state.name || '';
    if (state.staff) node.querySelector('[data-comment-staff-note]')?.removeAttribute('hidden');
    const max = Number(input.getAttribute('maxlength')) || 0;
    const counted = () => { if (count) count.textContent = max ? `${input.value.length} / ${max}` : String(input.value.length); };
    const described = input.getAttribute('aria-describedby') || '';
    const clear = () => {
      error.hidden = true;
      error.textContent = '';
      input.removeAttribute('aria-invalid');
      input.setAttribute('aria-describedby', described);
    };
    /* What to fix, under the field; the field is marked invalid only when its words are the reason. */
    const fail = (text, invalid) => {
      error.textContent = text;
      error.hidden = false;
      if (invalid) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
      input.setAttribute('aria-describedby', `${described} ${error.id}`);
      input.focus();
      announce(text);
    };
    input.value = draft.get(draftKey);
    counted();
    input.addEventListener('input', () => { counted(); draft.set(draftKey, input.value); });

    let busy = false;
    node.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy) return;
      busy = true;
      send.setAttribute('aria-disabled', 'true');
      node.setAttribute('aria-busy', 'true');
      announce(TEXT.posting);
      const res = await post(`/api/news/${id}/comments`, { body: input.value }).catch(() => ({ status: 0, ok: false, data: {} }));
      busy = false;
      send.removeAttribute('aria-disabled');
      node.removeAttribute('aria-busy');
      if (res.ok && res.data.comment) {
        clear();
        input.value = '';
        counted();
        draft.set(draftKey, '');
        const li = upsert(res.data.comment);
        announce(res.data.comment.pending ? TEXT.waiting : TEXT.posted);
        if (li) {
          li.tabIndex = -1;
          li.focus();
        }
      } else if (res.status === 401) {
        expired();
      } else if (res.status === 403 && res.data.error === 'closed') {
        closed(res.data.message);
      } else {
        fail(res.data.message || TEXT.notPosted, res.status === 422);
      }
    });
    box.replaceChildren(node);
  }

  function closed(reason) {
    const p = document.createElement('p');
    p.className = 'news-comments-closed';
    p.textContent = reason;
    box.replaceChildren(p);
    announce(reason);
  }

  async function load() {
    const ids = [...list.querySelectorAll('[data-comment-id]')].map((li) => li.dataset.commentId).filter(Boolean);
    const url = `/api/news/${id}/state?since=${encodeURIComponent(since)}&ids=${ids.join(',')}`;
    const res = await call(url).catch(() => ({ status: 0, ok: false, data: {} }));
    if (!res.ok) {
      // Signed in by the hint but the box could not load: say so, with the page that works without it.
      if (res.status !== 404) notice('failed');
      return;
    }
    const state = res.data;
    for (const cid of state.removed || []) find(cid)?.remove();
    if (!state.signed_in) {
      sync();
      return;
    }
    viewer = { signedIn: true, staff: Boolean(state.staff) };
    const own = new Set((state.own || []).map(String));
    for (const li of list.querySelectorAll('[data-comment-id]')) {
      if (own.has(li.dataset.commentId)) li.dataset.own = '1';
      actions(li);
    }
    for (const c of state.mine || []) upsert(c);
    sync();
    if (state.can_comment) form(state);
    else if (state.closed_reason) closed(state.closed_reason);
  }

  if (readCookie('psc_li') === '1') load().catch((err) => console.warn('[news-comments] state skipped:', err));
}

const root = document.querySelector('[data-comments]');
if (root) {
  try {
    init(root);
  } catch (err) {
    console.warn('[news-comments] skipped:', err);
  }
}
