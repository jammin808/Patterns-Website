# Portal markup contract

The client portal and the admin pages are written against this small set of classes. The design system
(`public/assets/css/site.css`, `docs/DESIGN.md`) styles them; templates never carry inline styles or their own
CSS. Every class here has a style; a template needs no other.

## Shell (from `templates/layouts/portal.php`)

```html
<div class="pt-shell">
  <nav class="pt-nav" aria-label="Portal">…links with aria-current="page"…</nav>
  <main class="pt-main" id="main">
    <header class="pt-header">
      <p class="pt-eyebrow">Client portal</p>
      <h1 class="pt-title">Tickets</h1>
      <p class="pt-lede">One line saying what the page is for.</p>
      <div class="pt-header-actions"><a class="pt-btn pt-btn--primary" href="…">New ticket</a></div>
    </header>
    <!-- flashes, then the page -->
  </main>
</div>
```

## Building blocks

| Class | Use |
|---|---|
| `pt-section` | a vertical block with a heading (`h2.pt-h2`) |
| `pt-grid` (`pt-grid--2`, `pt-grid--3`) | responsive grid of cards |
| `pt-card` (`pt-card--link` when the whole card is a link) | a bordered surface; `pt-card-title`, `pt-card-meta`, `pt-card-body`, `pt-card-footer` |
| `pt-stats` > `pt-stat` (`pt-stat-value`, `pt-stat-label`) | dashboard numbers |
| `pt-table-wrap` > `table.pt-table` | data tables; `th[scope]`, `td.pt-num` right-aligned numbers, `tr.is-unread` |
| `pt-empty` | empty state: `p` + optional button |
| `pt-badge` + `pt-badge--{open,triaged,planned,in_progress,done,declined,closed,requested,awaiting_payment,issued,revoked,active,pending,approval,disabled,new,read,archived,admin,client,bug,feature,question,stable,beta,private,public}` | status and type pills (text is always present; colour is never the only cue) |
| `pt-btn` + `pt-btn--primary` / `--secondary` / `--ghost` / `--danger` / `--small` | buttons and link-buttons |
| `pt-actions` | a row of buttons |
| `form.pt-form` | a form with `novalidate`; it opens with the shared error summary (`templates/partials/error-summary.php`), and fields are `div.pt-field` > `label` + control + `p.pt-help` + `p.pt-error` (only when invalid, with the control `aria-invalid="true"` and `aria-describedby`) |
| `pt-field--inline` | checkbox/radio with its label on one line |
| `pt-fieldset` > `legend` | grouped fields |
| `pt-alert` + `pt-alert--{info,success,warning,danger}` | flash messages and notices (`role="status"` or `role="alert"` for danger) |
| `pt-kv` (a `dl`) | key-value facts: `dt` / `dd` |
| `pt-thread` > `article.pt-comment` (`pt-comment--staff`, `pt-comment--internal`) | ticket conversation; `pt-comment-meta`, `pt-comment-body` |
| `pt-files` > `li` | attachment or licence-file list with size |
| `pt-tabs` > `a` (`aria-current="page"` on the active one) | filter tabs |
| `pt-pager` | pagination: `a[rel=prev]`, `span.pt-pager-info`, `a[rel=next]` |
| `pt-vote` (`button.pt-vote` with `aria-pressed`) + `pt-vote-count` | feature-request votes |
| `pt-search` | a small inline search/filter form |
| `pt-prose` | rendered Markdown (ticket bodies, release notes) |
| `pt-muted`, `pt-small`, `pt-mono` | text helpers |
| `pt-auth` | the narrow centred card for sign-in, register, reset |

## Industry Updates' article editor (`public/assets/css/pages/admin-news.css`)

The admin article pages (`templates/admin/news/`) push one stylesheet and one script of their own,
`pages/admin-news.css` and `admin-news.js`, for the few parts the contract above has no class for. Each class is
styled there, and only those pages load it. The article form itself is a plain `form.pt-form` with
`data-news-editor` (the unsaved-changes guard), and every image form carries `data-news-image-form`.

| Class | Use |
|---|---|
| `pt-news-count` | a field's character count, with `c-count`: plain text under the control, never a live region (`data-news-count="#id"` and `data-news-max` on the control) |
| `pt-news-rows` > `ol.pt-news-row-list` > `li.pt-news-row` (`pt-news-row--source`) | a repeating list in a `pt-fieldset` (`data-news-rows`, `data-news-row`, a hidden `data-news-add` button): a fact or claim and its address side by side, a source's four fields two by two |
| `pt-news-checks` | the category ticks, one to three across |
| `pt-news-when` | the schedule's date and time side by side |
| `pt-news-images` > `li.pt-news-image` (`pt-news-thumb`, `pt-news-image-body`) | the images section: each picture beside its forms (`data-news-image-form`) |
| `pt-news-upload` | the upload form, a dashed panel under the images |

## Industry Updates' desk (`public/assets/css/pages/admin-desk.css`)

The desk pages (`templates/admin/news/desk/`) push `pages/admin-desk.css` from their shared `_nav.php`. Each class is
styled there, and only those pages load it.

| Class | Use |
|---|---|
| `pt-desk-state`, `pt-desk-cron`, `pt-desk-next` | the desk's state with its reason, the last tick (with the stale warning) and the next sessions |
| `pt-desk-slot` > `pt-desk-slot-time`, `pt-desk-days` > `pt-desk-day`, `pt-desk-slot-remove` | one session row on the settings page: its time, its seven weekday ticks and its Remove tick |
| `pt-desk-cal` > `pt-desk-cal-day` (`-num`, `-meta`, `-mark`, `-changed`, `-past`, `-blank`, `-gone`) | the calendar's month table and its day cells: the date, the session count, the Changed marker, past and padding days |
| `pt-desk-prompt`, `pt-desk-versions`, `pt-desk-version-meta`, `pt-desk-rules`, `pt-desk-brief` | the prompt page: the editable texts, their versions, and the fixed rules shown read-only |

## Rules

- One `h1` per page (`pt-title`); sections use `h2.pt-h2`.
- Every form has a CSRF field (`<?= csrf_field() ?>`), labels tied to controls, `autocomplete` attributes, and
  repopulates from `$old` after a validation error, showing `$errors[field]` in `p.pt-error`.
- Tables have a `caption` (it may be visually hidden with `u-visually-hidden`) and `th scope`.
- Dates: `date_short()` or `ago()` with the full date in a `<time datetime>` attribute.
- User text is printed with `e()`; Markdown with `md()` inside `.pt-prose`.
