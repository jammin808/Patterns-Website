-- The same schema as mysql/0007_news_digest.sql, in SQLite's dialect (development and tests): the weekly digest's
-- subscribers (the consent and suppression records), its weeks, and its sends, one per digest and recipient.

CREATE TABLE news_digest_subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NULL,
    email_hash TEXT NOT NULL,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    state TEXT NOT NULL DEFAULT 'pending',
    source TEXT NOT NULL,
    selector TEXT NOT NULL,
    generation INTEGER NOT NULL DEFAULT 0,
    consent_version TEXT NOT NULL,
    privacy_version TEXT NOT NULL,
    requested_at INTEGER NOT NULL,
    confirmed_at INTEGER NULL,
    unsubscribed_at INTEGER NULL,
    unsubscribe_method TEXT NULL,
    prior_source TEXT NULL,
    prior_consent_version TEXT NULL,
    prior_privacy_version TEXT NULL,
    prior_requested_at INTEGER NULL,
    bounces INTEGER NOT NULL DEFAULT 0,
    bounced_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX news_digest_subscribers_email ON news_digest_subscribers (email_hash);
CREATE UNIQUE INDEX news_digest_subscribers_selector ON news_digest_subscribers (selector);
CREATE UNIQUE INDEX news_digest_subscribers_user ON news_digest_subscribers (user_id);
CREATE INDEX news_digest_subscribers_state ON news_digest_subscribers (state, confirmed_at);

CREATE TABLE news_digests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    week TEXT NOT NULL,
    send_at INTEGER NOT NULL,
    window_start INTEGER NOT NULL,
    window_end INTEGER NOT NULL,
    articles TEXT NULL,
    left_out INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL,
    note TEXT NULL,
    recipients INTEGER NOT NULL DEFAULT 0,
    composed_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);
CREATE UNIQUE INDEX news_digests_week ON news_digests (week);
CREATE INDEX news_digests_state ON news_digests (state, composed_at);

CREATE TABLE news_digest_sends (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    digest_id INTEGER NOT NULL REFERENCES news_digests(id) ON DELETE CASCADE,
    subscriber_id INTEGER NOT NULL REFERENCES news_digest_subscribers(id) ON DELETE CASCADE,
    state TEXT NOT NULL DEFAULT 'claimed',
    attempts INTEGER NOT NULL DEFAULT 1,
    claimed_at INTEGER NOT NULL,
    sent_at INTEGER NULL,
    reply TEXT NULL
);
CREATE UNIQUE INDEX news_digest_sends_pair ON news_digest_sends (digest_id, subscriber_id);
CREATE INDEX news_digest_sends_subscriber ON news_digest_sends (subscriber_id);
CREATE INDEX news_digest_sends_claimed ON news_digest_sends (claimed_at);
