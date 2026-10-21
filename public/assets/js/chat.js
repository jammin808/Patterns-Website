/*
 * The support assistant widget. The panel's markup is rendered by the server (templates/partials/chat.php) and
 * works without JavaScript as a link to the contact page; this module turns it into a chat.
 *
 * Protocol (POST /api/chat, JSON {message, page}): a Server-Sent Events stream of
 *   meta {conversation, turn, max_turns} · delta {t} · replace {t} · done {scope, handoff, truncated} · error {code, message}
 * or, refused before the stream, JSON {error, message} with 400/403/429/503.
 *
 * The model's text is never inserted as HTML: a small Markdown subset is rendered by building DOM nodes, and links
 * are kept only when they stay on this site.
 *
 * Openers ([data-chat-open]) may be links to the contact page, so they still lead somewhere without scripting; here
 * every opener becomes a button for the panel (role, aria-controls, aria-expanded, Enter and Space). Focus moves
 * only when a person acts: opening puts them in the input, closing the floating panel hands focus back to its
 * opener, and nothing is focused on load. An inline panel ([data-chat-inline], the support page) is always shown
 * and never closes.
 */

const CLASS = {
  msg: 'c-chat-msg',
  user: 'c-chat-msg--user',
  bot: 'c-chat-msg--assistant',
  error: 'c-chat-msg--error',
  typing: 'c-chat-typing',
  actions: 'c-chat-handoff',
  button: 'c-btn c-btn--secondary c-btn--sm',
  feedback: 'c-chat-feedback',
};
const STORE_KEY = 'psc-chat-v1';

function store(read, value) {
  try {
    if (read) return JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null');
    sessionStorage.setItem(STORE_KEY, JSON.stringify(value));
  } catch {
    /* storage can be unavailable (private mode); the chat still works for this page */
  }
  return null;
}

/* ---------- safe Markdown subset → DOM ---------- */

function safeHref(href) {
  try {
    const url = new URL(href, location.origin);
    if (url.origin === location.origin && (url.protocol === 'https:' || url.protocol === 'http:')) {
      return url.pathname + url.search + url.hash;
    }
    if (url.protocol === 'mailto:') return url.href;
  } catch {
    return null;
  }
  return null;
}

function inline(text, parent) {
  const re = /(\*\*([^*]+)\*\*|\*([^*\s][^*]*)\*|\[([^\]]+)\]\(([^)\s]+)\))/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) parent.append(document.createTextNode(text.slice(last, m.index)));
    if (m[2] !== undefined) {
      const b = document.createElement('strong');
      b.textContent = m[2];
      parent.append(b);
    } else if (m[3] !== undefined) {
      const i = document.createElement('em');
      i.textContent = m[3];
      parent.append(i);
    } else {
      const href = safeHref(m[5]);
      if (href) {
        const a = document.createElement('a');
        a.href = href;
        a.textContent = m[4];
        parent.append(a);
      } else {
        parent.append(document.createTextNode(m[4]));
      }
    }
    last = re.lastIndex;
  }
  if (last < text.length) parent.append(document.createTextNode(text.slice(last)));
}

function renderMarkdown(text, target) {
  target.replaceChildren();
  const blocks = text.replace(/\r\n?/g, '\n').trim().split(/\n{2,}/);
  for (const block of blocks) {
    const lines = block.split('\n');
    const bullet = lines.every((l) => /^\s*[-*•]\s+/.test(l));
    const numbered = lines.every((l) => /^\s*\d+[.)]\s+/.test(l));
    if (bullet || numbered) {
      const list = document.createElement(numbered ? 'ol' : 'ul');
      for (const l of lines) {
        const li = document.createElement('li');
        inline(l.replace(/^\s*([-*•]|\d+[.)])\s+/, ''), li);
        list.append(li);
      }
      target.append(list);
    } else {
      const p = document.createElement('p');
      lines.forEach((l, i) => {
        if (i) p.append(document.createElement('br'));
        inline(l.replace(/^#{1,6}\s+/, ''), p);
      });
      target.append(p);
    }
  }
}

/* ---------- the widget ---------- */

function init(root) {
  const panel = root.querySelector('[data-chat-panel]');
  const log = root.querySelector('[data-chat-log]');
  const form = root.querySelector('[data-chat-form]');
  const input = root.querySelector('[data-chat-input]');
  const status = root.querySelector('[data-chat-status]');
  const counter = root.querySelector('[data-chat-count]');
  const openers = Array.from(document.querySelectorAll('[data-chat-open]'));
  if (!panel || !log || !form || !input) return;

  const inlinePanel = root.hasAttribute('data-chat-inline');
  const maxChars = Number(input.getAttribute('maxlength')) || 1200;
  let busy = false;
  let lastOpener = null;
  let transcript = store(true) || [];

  const say = (msg) => { if (status) status.textContent = msg; };

  function bubble(role, text, opts = {}) {
    const el = document.createElement('div');
    el.className = `${CLASS.msg} ${role === 'user' ? CLASS.user : CLASS.bot}${opts.error ? ` ${CLASS.error}` : ''}`;
    const who = document.createElement('span');
    who.className = 'u-visually-hidden';
    who.textContent = role === 'user' ? 'You said: ' : 'Assistant: ';
    const body = document.createElement('div');
    body.className = 'c-chat-msg-body';
    if (role === 'user') body.textContent = text; else renderMarkdown(text, body);
    el.append(who, body);
    log.append(el);
    log.scrollTop = log.scrollHeight;
    return { el, body };
  }

  function handoff(container) {
    const wrap = document.createElement('div');
    wrap.className = CLASS.actions;
    const links = [
      ['Contact us', '/contact/?topic=support'],
      ['Open a support ticket', '/portal/tickets/new/'],
    ];
    for (const [label, href] of links) {
      const a = document.createElement('a');
      a.className = CLASS.button;
      a.href = href;
      a.textContent = label;
      wrap.append(a);
    }
    container.append(wrap);
  }

  function feedback(container) {
    const wrap = document.createElement('div');
    wrap.className = CLASS.feedback;
    const label = document.createElement('span');
    label.textContent = 'Was this helpful?';
    wrap.append(label);
    for (const [rating, text] of [['up', 'Yes'], ['down', 'No']]) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = CLASS.button;
      b.textContent = text;
      b.setAttribute('aria-label', rating === 'up' ? 'This answer was helpful' : 'This answer was not helpful');
      b.addEventListener('click', async () => {
        wrap.replaceChildren(document.createTextNode('Thanks for the feedback.'));
        try {
          await fetch('/api/chat/feedback', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ rating }) });
        } catch { /* feedback is best-effort */ }
      });
      wrap.append(b);
    }
    container.append(wrap);
  }

  function remember(role, text) {
    transcript.push({ role, text });
    transcript = transcript.slice(-30);
    store(false, transcript);
  }

  function restore() {
    for (const m of transcript) bubble(m.role, m.text);
  }

  const setExpanded = (on) => openers.forEach((o) => o.setAttribute('aria-expanded', String(on)));

  function open(from) {
    lastOpener = from || null;
    panel.hidden = false;
    root.classList.add('is-open');
    setExpanded(true);
    requestAnimationFrame(() => input.focus());
  }

  /* Only the floating panel closes; focus goes back to the opener that was used (or the first one). */
  function close() {
    if (inlinePanel || panel.hidden) return;
    panel.hidden = true;
    root.classList.remove('is-open');
    setExpanded(false);
    const back = lastOpener && lastOpener.isConnected ? lastOpener : openers[0];
    back?.focus();
  }

  /*
   * An opener on a page with the inline panel takes the person to its input; elsewhere it opens or closes, and
   * closing from an opener leaves focus on the opener that was pressed.
   */
  function toggle(from) {
    if (inlinePanel) { input.focus(); return; }
    if (panel.hidden) { open(from); return; }
    lastOpener = from || lastOpener;
    close();
  }

  async function send(text) {
    if (busy) return;
    const message = text.trim();
    if (!message) return;
    busy = true;
    form.setAttribute('aria-busy', 'true');
    input.value = '';
    updateCount();
    bubble('user', message);
    remember('user', message);

    const typing = document.createElement('div');
    typing.className = CLASS.typing;
    typing.setAttribute('aria-hidden', 'true');
    typing.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));
    log.append(typing);
    log.setAttribute('aria-busy', 'true');
    say('The assistant is answering…');

    let answer = '';
    let target = null;
    let finished = false;
    const finish = (kind, data) => {
      if (finished) return;
      finished = true;
      typing.remove();
      if (!target) target = bubble('assistant', answer);
      if (answer) remember('assistant', answer);
      if (kind === 'done' && data && data.handoff) handoff(target.el);
      if (kind === 'error') handoff(target.el);
      if (kind === 'done' && data && data.scope === 'in') feedback(target.el);
      log.setAttribute('aria-busy', 'false');
      say('');
    };

    try {
      const res = await fetch('/api/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-Requested-With': 'fetch' },
        body: JSON.stringify({ message, page: location.pathname }),
      });
      const type = res.headers.get('content-type') || '';
      if (!res.ok || !type.includes('text/event-stream')) {
        let msg = "The assistant isn't available right now.";
        try { msg = (await res.json()).message || msg; } catch { /* keep the default */ }
        answer = msg;
        target = bubble('assistant', msg, { error: true });
        finish('error');
        return;
      }
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let cut;
        while ((cut = buffer.indexOf('\n\n')) !== -1) {
          const raw = buffer.slice(0, cut);
          buffer = buffer.slice(cut + 2);
          let event = 'message';
          let data = '';
          for (const line of raw.split('\n')) {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
          }
          if (!data) continue;
          let payload;
          try { payload = JSON.parse(data); } catch { continue; }
          if (event === 'delta' || event === 'replace') {
            answer = event === 'replace' ? payload.t : answer + payload.t;
            if (!target) { typing.remove(); target = bubble('assistant', answer); } else renderMarkdown(answer, target.body);
            log.scrollTop = log.scrollHeight;
          } else if (event === 'done') {
            finish('done', payload);
          } else if (event === 'error') {
            answer = (answer ? `${answer}\n\n` : '') + (payload.message || 'Something went wrong.');
            if (target) renderMarkdown(answer, target.body); else target = bubble('assistant', answer, { error: true });
            finish('error', payload);
          }
        }
      }
      if (!finished) finish('done', { scope: 'in' });
    } catch {
      answer = "The connection dropped before the answer arrived. Please try again, or use the contact form.";
      target = target || bubble('assistant', answer, { error: true });
      finish('error');
    } finally {
      busy = false;
      form.removeAttribute('aria-busy');
      input.focus();
    }
  }

  function updateCount() {
    if (!counter) return;
    const left = maxChars - input.value.length;
    counter.textContent = left < 200 ? `${left} characters left` : '';
  }

  const isSpace = (e) => e.key === ' ' || e.key === 'Spacebar';
  openers.forEach((o) => {
    const isButton = o.tagName === 'BUTTON';
    if (!isButton) o.setAttribute('role', 'button');
    if (!o.hasAttribute('aria-controls') && panel.id) o.setAttribute('aria-controls', panel.id);
    if (inlinePanel) o.removeAttribute('aria-expanded'); else o.setAttribute('aria-expanded', String(!panel.hidden));

    o.addEventListener('click', (e) => {
      e.preventDefault();
      toggle(o);
    });
    o.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !inlinePanel && !panel.hidden) { e.preventDefault(); close(); }
    });
    if (isButton) return;

    // A link standing in for a button answers a button's keys: Enter at once, Space on release, and neither
    // follows the link nor scrolls the page.
    let spaceDown = false;
    o.addEventListener('keydown', (e) => {
      if (e.altKey || e.ctrlKey || e.metaKey) return;
      if (isSpace(e)) {
        e.preventDefault();
        spaceDown = true;
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (!e.repeat) toggle(o);
      }
    });
    o.addEventListener('keyup', (e) => {
      if (!isSpace(e) || !spaceDown) return;
      spaceDown = false;
      e.preventDefault();
      toggle(o);
    });
    o.addEventListener('blur', () => { spaceDown = false; });
  });
  root.querySelectorAll('[data-chat-close]').forEach((b) => b.addEventListener('click', close));
  root.querySelectorAll('[data-chat-reset]').forEach((b) => b.addEventListener('click', async () => {
    transcript = [];
    store(false, transcript);
    log.querySelectorAll(`.${CLASS.msg}`).forEach((n) => n.remove());
    try {
      await fetch('/api/chat/reset', { method: 'POST', headers: { 'X-Requested-With': 'fetch' } });
    } catch { /* the next message starts a new conversation anyway */ }
    say('Started a new conversation.');
    input.focus();
  }));
  root.querySelectorAll('[data-chat-suggest]').forEach((b) => b.addEventListener('click', () => send(b.textContent || '')));
  form.addEventListener('submit', (e) => { e.preventDefault(); send(input.value); });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
  });
  input.addEventListener('input', updateCount);
  // Escape closes the floating panel; on the inline panel it does nothing, so the assistant never disappears.
  panel.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || inlinePanel || e.isComposing) return;
    e.preventDefault();
    close();
  });

  restore();
}

document.querySelectorAll('[data-chat]').forEach(init);
