-- Industry Updates (docs/NEWS.md §3): articles with their categories, links, clicks, follow-ups and images, readers'
-- comments and reports, the AI desk's runs, settings and prompt versions, and the record of imported files.
-- Times are Unix seconds (UTC).

CREATE TABLE news_articles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(190) NOT NULL,
    seo_title VARCHAR(70) NULL,
    dek VARCHAR(320) NOT NULL DEFAULT '',
    summary VARCHAR(170) NULL,
    body TEXT NOT NULL,
    confirmed TEXT NULL,
    speculation TEXT NULL,
    impact TEXT NULL,
    verdict VARCHAR(16) NULL,
    verdict_note VARCHAR(320) NULL,
    tags VARCHAR(320) NULL,
    byline VARCHAR(120) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    published_at INT NULL,
    origin VARCHAR(16) NOT NULL DEFAULT 'manual',
    ai_written TINYINT(1) NOT NULL DEFAULT 0,
    review_reason VARCHAR(300) NULL,
    story_key VARCHAR(190) NULL,
    comments_open TINYINT(1) NOT NULL DEFAULT 1,
    comment_count INT NOT NULL DEFAULT 0,
    word_count INT NOT NULL DEFAULT 0,
    author_id INT UNSIGNED NULL,
    run_id INT UNSIGNED NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    UNIQUE KEY news_articles_slug (slug),
    KEY news_articles_live (status, published_at),
    KEY news_articles_story (story_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_article_categories (
    article_id INT UNSIGNED NOT NULL,
    category VARCHAR(32) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    PRIMARY KEY (article_id, category),
    KEY news_article_categories_cat (category, article_id),
    CONSTRAINT fk_news_article_categories_article FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    url VARCHAR(2000) NOT NULL,
    url_key CHAR(64) NOT NULL,
    -- Any host Urls accepts (up to 253 characters); not indexed, so wider than 190.
    host VARCHAR(255) NOT NULL,
    title VARCHAR(300) NULL,
    publisher VARCHAR(160) NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'other',
    is_source TINYINT(1) NOT NULL DEFAULT 1,
    position INT NOT NULL DEFAULT 0,
    clicks INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    KEY news_links_article (article_id, position),
    KEY news_links_url (url_key),
    CONSTRAINT fk_news_links_article FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_link_clicks (
    link_id INT UNSIGNED NOT NULL,
    day CHAR(10) NOT NULL,
    clicks INT NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, day),
    CONSTRAINT fk_news_link_clicks_link FOREIGN KEY (link_id) REFERENCES news_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_related (
    article_id INT UNSIGNED NOT NULL,
    related_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, related_id),
    KEY news_related_back (related_id),
    CONSTRAINT fk_news_related_article FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_related_related FOREIGN KEY (related_id) REFERENCES news_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NULL,
    position INT NOT NULL DEFAULT 0,
    base CHAR(32) NOT NULL,
    widths VARCHAR(40) NOT NULL,
    width INT NOT NULL,
    height INT NOT NULL,
    alt VARCHAR(300) NOT NULL,
    caption VARCHAR(300) NULL,
    credit VARCHAR(160) NULL,
    credit_url VARCHAR(500) NULL,
    original_name VARCHAR(190) NOT NULL,
    bytes INT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at INT NOT NULL,
    KEY news_images_article (article_id, position),
    CONSTRAINT fk_news_images_article FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'visible',
    reports INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    KEY news_comments_article (article_id, status, created_at),
    KEY news_comments_user (user_id),
    KEY news_comments_status (status, reports),
    CONSTRAINT fk_news_comments_article FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_comment_reports (
    comment_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at INT NOT NULL,
    PRIMARY KEY (comment_id, user_id),
    KEY news_comment_reports_user (user_id),
    CONSTRAINT fk_news_comment_reports_comment FOREIGN KEY (comment_id) REFERENCES news_comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_comment_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slot VARCHAR(40) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    requested_by INT UNSIGNED NULL,
    started_at INT NULL,
    deadline_at INT NULL,
    finished_at INT NULL,
    candidates INT NOT NULL DEFAULT 0,
    articles INT NOT NULL DEFAULT 0,
    held INT NOT NULL DEFAULT 0,
    usage_in INT NOT NULL DEFAULT 0,
    usage_out INT NOT NULL DEFAULT 0,
    cache_write INT NOT NULL DEFAULT 0,
    cache_read INT NOT NULL DEFAULT 0,
    searches INT NOT NULL DEFAULT 0,
    fetches INT NOT NULL DEFAULT 0,
    cost_micros INT NOT NULL DEFAULT 0,
    models VARCHAR(160) NULL,
    summary VARCHAR(500) NULL,
    brief TEXT NULL,
    refs TEXT NULL,
    considered TEXT NULL,
    log TEXT NULL,
    created_at INT NOT NULL,
    UNIQUE KEY news_runs_slot (slot),
    KEY news_runs_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_prompt_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    field VARCHAR(16) NOT NULL,
    body TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at INT NOT NULL,
    KEY news_prompt_versions_field (field, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_imports (
    import_key VARCHAR(190) NOT NULL PRIMARY KEY,
    article_id INT UNSIGNED NULL,
    imported_at INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
