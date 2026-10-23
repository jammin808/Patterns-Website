-- An Industry Updates article's corrections: a JSON list of {at, text}, each a dated line the article shows, and the
-- time of the newest, which its dateModified, the feeds and the sitemaps follow.
ALTER TABLE news_articles ADD COLUMN corrections TEXT NULL;
ALTER TABLE news_articles ADD COLUMN corrected_at INTEGER NULL;

-- What the tick told each channel (indexnow: the search engines; websub: the feed hub) about each address, so an
-- address is announced again only when it changes: live at a version (its last change), or gone (taken down,
-- deleted or moved). The hubs and feeds an announcement touched are recorded too, with no article.
CREATE TABLE news_pings (
    channel TEXT NOT NULL,
    path TEXT NOT NULL,
    article_id INTEGER NULL,
    state TEXT NOT NULL DEFAULT 'live',
    version INTEGER NOT NULL DEFAULT 0,
    pinged_at INTEGER NOT NULL,
    PRIMARY KEY (channel, path)
);
CREATE INDEX news_pings_article ON news_pings (article_id);
