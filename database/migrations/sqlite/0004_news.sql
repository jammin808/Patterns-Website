-- The same schema as mysql/0004_news.sql, in SQLite's dialect (development and tests).

CREATE TABLE news_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    seo_title TEXT NULL,
    dek TEXT NOT NULL DEFAULT '',
    summary TEXT NULL,
    body TEXT NOT NULL,
    confirmed TEXT NULL,
    speculation TEXT NULL,
    impact TEXT NULL,
    verdict TEXT NULL,
    verdict_note TEXT NULL,
    tags TEXT NULL,
    byline TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    published_at INTEGER NULL,
    origin TEXT NOT NULL DEFAULT 'manual',
    ai_written INTEGER NOT NULL DEFAULT 0,
    review_reason TEXT NULL,
    story_key TEXT NULL,
    comments_open INTEGER NOT NULL DEFAULT 1,
    comment_count INTEGER NOT NULL DEFAULT 0,
    word_count INTEGER NOT NULL DEFAULT 0,
    author_id INTEGER NULL,
    run_id INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX news_articles_live ON news_articles (status, published_at);
CREATE INDEX news_articles_story ON news_articles (story_key);

CREATE TABLE news_article_categories (
    article_id INTEGER NOT NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    category TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (article_id, category)
);
CREATE INDEX news_article_categories_cat ON news_article_categories (category, article_id);

CREATE TABLE news_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    url_key TEXT NOT NULL,
    host TEXT NOT NULL,
    title TEXT NULL,
    publisher TEXT NULL,
    kind TEXT NOT NULL DEFAULT 'other',
    is_source INTEGER NOT NULL DEFAULT 1,
    position INTEGER NOT NULL DEFAULT 0,
    clicks INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE INDEX news_links_article ON news_links (article_id, position);
CREATE INDEX news_links_url ON news_links (url_key);

CREATE TABLE news_link_clicks (
    link_id INTEGER NOT NULL REFERENCES news_links(id) ON DELETE CASCADE,
    day TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, day)
);

CREATE TABLE news_related (
    article_id INTEGER NOT NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    related_id INTEGER NOT NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, related_id)
);
CREATE INDEX news_related_back ON news_related (related_id);

CREATE TABLE news_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    base TEXT NOT NULL,
    widths TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    alt TEXT NOT NULL,
    caption TEXT NULL,
    credit TEXT NULL,
    credit_url TEXT NULL,
    original_name TEXT NOT NULL,
    bytes INTEGER NOT NULL,
    created_by INTEGER NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX news_images_article ON news_images (article_id, position);

CREATE TABLE news_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES news_articles(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'visible',
    reports INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX news_comments_article ON news_comments (article_id, status, created_at);
CREATE INDEX news_comments_user ON news_comments (user_id);
CREATE INDEX news_comments_status ON news_comments (status, reports);

CREATE TABLE news_comment_reports (
    comment_id INTEGER NOT NULL REFERENCES news_comments(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (comment_id, user_id)
);
CREATE INDEX news_comment_reports_user ON news_comment_reports (user_id);

CREATE TABLE news_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slot TEXT NOT NULL UNIQUE,
    kind TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    requested_by INTEGER NULL,
    started_at INTEGER NULL,
    deadline_at INTEGER NULL,
    finished_at INTEGER NULL,
    candidates INTEGER NOT NULL DEFAULT 0,
    articles INTEGER NOT NULL DEFAULT 0,
    held INTEGER NOT NULL DEFAULT 0,
    usage_in INTEGER NOT NULL DEFAULT 0,
    usage_out INTEGER NOT NULL DEFAULT 0,
    cache_write INTEGER NOT NULL DEFAULT 0,
    cache_read INTEGER NOT NULL DEFAULT 0,
    searches INTEGER NOT NULL DEFAULT 0,
    fetches INTEGER NOT NULL DEFAULT 0,
    cost_micros INTEGER NOT NULL DEFAULT 0,
    models TEXT NULL,
    summary TEXT NULL,
    brief TEXT NULL,
    refs TEXT NULL,
    considered TEXT NULL,
    log TEXT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX news_runs_status ON news_runs (status, created_at);

CREATE TABLE news_settings (
    name TEXT NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_by INTEGER NULL,
    updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE news_prompt_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    body TEXT NOT NULL,
    created_by INTEGER NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX news_prompt_versions_field ON news_prompt_versions (field, created_at);

CREATE TABLE news_imports (
    import_key TEXT NOT NULL PRIMARY KEY,
    article_id INTEGER NULL,
    imported_at INTEGER NOT NULL
);
