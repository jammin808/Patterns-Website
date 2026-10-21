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
| `form.pt-form` | a form; fields are `div.pt-field` > `label` + control + `p.pt-help` + `p.pt-error` (only when invalid, with the control `aria-invalid="true"` and `aria-describedby`) |
| `pt-field--inline` | checkbox/radio with its label on one line |
| `pt-fieldset` > `legend` | grouped fields |
| `pt-alert` + `pt-alert--{info,success,warning,danger}` | flash messages and notices (`role="status"` or `role="alert"` for danger) |
| `pt-kv` (a `dl`) | key–value facts: `dt` / `dd` |
| `pt-thread` > `article.pt-comment` (`pt-comment--staff`, `pt-comment--internal`) | ticket conversation; `pt-comment-meta`, `pt-comment-body` |
| `pt-files` > `li` | attachment or licence-file list with size |
| `pt-tabs` > `a` (`aria-current="page"` on the active one) | filter tabs |
| `pt-pager` | pagination: `a[rel=prev]`, `span.pt-pager-info`, `a[rel=next]` |
| `pt-vote` (`button.pt-vote` with `aria-pressed`) + `pt-vote-count` | feature-request votes |
| `pt-search` | a small inline search/filter form |
| `pt-prose` | rendered Markdown (ticket bodies, release notes) |
| `pt-muted`, `pt-small`, `pt-mono` | text helpers |
| `pt-auth` | the narrow centred card for sign-in, register, reset |

## Rules

- One `h1` per page (`pt-title`); sections use `h2.pt-h2`.
- Every form has a CSRF field (`<?= csrf_field() ?>`), labels tied to controls, `autocomplete` attributes, and
  repopulates from `$old` after a validation error, showing `$errors[field]` in `p.pt-error`.
- Tables have a `caption` (it may be visually hidden with `u-visually-hidden`) and `th scope`.
- Dates: `date_short()` or `ago()` with the full date in a `<time datetime>` attribute.
- User text is printed with `e()`; Markdown with `md()` inside `.pt-prose`.
