-- When an Industry Updates article first went live, kept once a publish or an unpublish would otherwise lose it
-- (a reschedule moves published_at on, a takedown of a scheduled time clears it): its slug never changes after.
ALTER TABLE news_articles ADD COLUMN first_live_at INTEGER NULL;

-- An article that is live now has gone live: its time is kept before any reschedule could move it.
UPDATE news_articles SET first_live_at = published_at
    WHERE first_live_at IS NULL AND status = 'published' AND published_at IS NOT NULL AND published_at <= CAST(strftime('%s', 'now') AS INTEGER);

-- The slug of every deleted article, live or not: never given to another article, so an old link, a search result
-- or a feed's id never leads to something else (its addresses answer 410).
CREATE TABLE news_retired_slugs (
    slug TEXT NOT NULL PRIMARY KEY,
    article_id INTEGER NOT NULL,
    retired_at INTEGER NOT NULL
);
