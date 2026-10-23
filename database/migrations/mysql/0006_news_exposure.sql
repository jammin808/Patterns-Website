-- An Industry Updates article's corrections: a JSON list of {at, text}, each a dated line the article shows, and the
-- time of the newest, which its dateModified, the feeds and the sitemaps follow.
ALTER TABLE news_articles ADD COLUMN corrections TEXT NULL, ADD COLUMN corrected_at INT NULL;

-- What the tick told each channel (indexnow: the search engines; websub: the feed hub) about each address, so an
-- address is announced again only when it changes: live at a version (its last change), or gone (taken down,
-- deleted or moved). The hubs and feeds an announcement touched are recorded too, with no article.
CREATE TABLE news_pings (
    channel VARCHAR(16) NOT NULL,
    path VARCHAR(190) NOT NULL,
    article_id INT UNSIGNED NULL,
    state VARCHAR(8) NOT NULL DEFAULT 'live',
    version INT NOT NULL DEFAULT 0,
    pinged_at INT NOT NULL,
    PRIMARY KEY (channel, path),
    KEY news_pings_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
