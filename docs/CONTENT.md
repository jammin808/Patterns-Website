# Content data

The site's words live as PHP arrays under `content/`, not inside templates. One fact is written once and used by
the pages, the navigation, the structured data (JSON-LD), `llms.txt` and the support assistant's knowledge
(`app/src/Chat/Knowledge.php` compiles it). Templates read these arrays; they hold layout, not facts.

All text is plain (no HTML) unless a key says Markdown. British English. Promotional, confident and **true**:
nothing that the product does not do today; things that are coming are listed only under `roadmap` and always
called coming. No internal jargon (round numbers, ledgers, "unwalked", lab lines, class or file names), no
competitor names, no code, commands or install scripts, no invented numbers, prices, dates, customers or quotes.

## `content/site.php`

```php
return [
    'name' => 'Patterns Show Control',
    'summary' => '…one sentence, used by llms.txt and the assistant…',
    'llms_intro' => '…two or three sentences…',
    'tagline' => '…',
    'publisher' => ['name' => 'Ben Rohleder', 'country' => 'United Kingdom'],
    'nav' => [ // the header; 'children' make the Products mega-menu
        ['label' => 'Ecosystem', 'href' => '/ecosystem/'],
        ['label' => 'Products', 'children' => [['product' => 'patterns'], ['product' => 'stagewatch'], …]],
        ['label' => 'Workflows', 'href' => '/workflows/'],
        ['label' => 'Licensing', 'href' => '/licensing/'],
        ['label' => 'News', 'href' => '/news/'],             // Industry Updates; the bar fits a short label only
        ['label' => 'Support', 'href' => '/support/'],
    ],
    'footer' => [ ['title' => 'Products', 'links' => [['label' => …, 'href' => …], …]], … ], // Company lists Industry Updates
    'trademarks' => '…NDI® is a registered trademark of Vizrt NDI AB. … belong to their owners…',
    'block_training' => false, // robots.txt: true asks model-training crawlers to stay away
];
```

## `content/products/<key>.php` (patterns, stagewatch, agmessage, playmap)

```php
return [
    'key' => 'stagewatch',
    'name' => 'StageWatch',
    'alternate_name' => 'Stagewatch',          // optional, structured data
    'role' => 'Time',                          // Picture | Time | People | Mapping
    'role_line' => 'The show clock',           // short, under the name on cards
    'definition' => '…ONE sentence that defines the product, used first on its pages and everywhere it is described…',
    'tagline' => '…≤ 12 words…',
    'summary' => '…1 or 2 sentences: cards, meta, llms.txt…',
    'pitch' => '…2 or 3 sentences: the overview hero…',
    'version' => '2.16',                       // only if stated by the product; else null
    'os' => 'Windows 10 and 11 (64-bit)',      // the "Runs on" line; may be a sentence
    'schema_os' => 'Windows 10, Windows 11 (64-bit)', // structured data's operatingSystem: operating systems only
    'distribution' => 'Installer',             // or 'Portable app (no install)'
    'schema_category' => 'MultimediaApplication',
    'status' => 'available',                   // available | early-access
    'external_url' => null,                    // the product's own site, if any
    'audiences' => ['Show callers', '…'],
    'highlights' => [['title' => '…', 'text' => '…'], …],          // 6 to 8, the hero claims
    'feature_groups' => [                                           // 6 to 10; features page sections
        ['id' => 'running-order', 'title' => '…', 'benefit' => '…', 'items' => ['…', …], 'icon' => 'list'],
    ],
    'workflows' => ['conference-timing', …],                        // slugs in content/workflows.php
    'integrations' => [['name' => 'Bitfocus Companion', 'text' => '…'], …],
    'ai' => ['title' => '…', 'text' => '…', 'items' => ['…']],     // always "a person approves"
    'requirements' => [['label' => 'Windows', 'value' => '…'], …],
    'getting_started' => [['title' => '…', 'text' => '…'], …],     // 5 to 8 steps, plain words, no commands
    'troubleshooting' => [['q' => '…', 'a' => '…'], …],            // the support page; surface level
    'faqs' => [['q' => '…', 'a' => '…'], …],                       // 8 to 12, plain-text answers
    'glossary' => [['term' => '…', 'definition' => '…'], …],
    'screenshots' => [['src' => '/assets/img/products/stagewatch/x.webp', 'src_small' => '…-800.webp',
                       'width' => 1600, 'height' => 1000, 'alt' => '…', 'caption' => '…'], …],
    'illustration' => 'countdown',             // the drawn visual a page uses when there is no screenshot
    'roadmap' => ['…coming, plainly labelled…'],
    'ecosystem' => '…how it works with the other apps, 2 or 3 sentences…',
    'architecture' => [['title' => '…', 'text' => '…'], …],       // a high-level "built for show day" overview: no internals
];
```

## `content/licensing/<key>.php`

See `content/licensing/stagewatch.php`: its header comment is the schema. A product without announced prices uses
`'pricing_status' => 'coming-soon'`, every tier's `price` null and `price_label` 'Coming soon', and no tier
`requestable`; its CTA registers interest through `/contact/?topic=sales&product=<key>`.

## `content/workflows.php`

```php
return [
    'conference-timing' => [
        'title' => '…', 'h1' => '…', 'summary' => '…', 'audience' => '…',
        'products' => ['stagewatch', 'patterns'],
        'duration' => '…', 'you_need' => ['…'],
        'steps' => [['title' => '…', 'text' => '…', 'product' => 'stagewatch'], …],   // 5 to 8
        'outcome' => '…', 'tips' => ['…'], 'faqs' => [['q' => '…', 'a' => '…']],
        'related' => ['led-wall-line-up'],
    ],
];
```

## `content/faq.php`

```php
return [
    ['id' => 'general', 'title' => 'General', 'items' => [['q' => '…', 'a' => '…'], …]],
    ['id' => 'licensing', 'title' => 'Licences and buying', 'items' => […]],
    …
];
```

## `knowledge/<topic>.md`

Extra notes for the support assistant only (never shown on a page): surface-level facts in plain prose and short
lists, headed `# <Product>: assistant notes`. The same rules apply, and in addition: no code, commands, file
paths inside the products, configuration contents, internals or protection details. Architecture only as a short,
high-level overview of its advantages.

## Industry Updates (`/news/`)

The section's own words (its labels, the empty states, the verdict labels and the fourteen categories with their
titles, descriptions and intros) are in `content/news.php`, and they follow the rules above like any other content
file. The articles do not: they are news, kept in the database (docs/NEWS.md), not site copy. An article may name
other companies and their products and may say "open-source" when the story does; the confirmed, speculation and
source rules of docs/NEWS.md §7 keep it true instead. The launch articles in `content/news/articles/*.json` are
imported into the database once, and the same applies to them. The articles' voice is `content/news/voice.md` (and
the research brief `content/news/brief.md`): the AI desk's defaults, which an administrator edits on the desk's
prompt page. No em dash in any of them; the site's dash fence reads all of `content/`.
