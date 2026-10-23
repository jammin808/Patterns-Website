-- The weekly digest of Industry Updates (docs/NEWS.md §14): one row per address that has ever asked for it (its
-- consent record while pending or confirmed; once unsubscribed, the suppression record, with the address gone and
-- only its keyed hash, the times and the versions kept; while an unsubscribed address asks again, the prior_ columns
-- keep where, when and with which words that record was asked, put back if the new request is never confirmed), one
-- row per London week that reached its send time, and one row per digest and recipient, written before the message
-- is sent, so nobody is ever sent a digest twice.
-- Times are Unix seconds (UTC).

CREATE TABLE news_digest_subscribers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NULL,
    email_hash CHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    source VARCHAR(16) NOT NULL,
    selector CHAR(24) NOT NULL,
    generation INT NOT NULL DEFAULT 0,
    consent_version VARCHAR(32) NOT NULL,
    privacy_version VARCHAR(32) NOT NULL,
    requested_at INT NOT NULL,
    confirmed_at INT NULL,
    unsubscribed_at INT NULL,
    unsubscribe_method VARCHAR(16) NULL,
    prior_source VARCHAR(16) NULL,
    prior_consent_version VARCHAR(32) NULL,
    prior_privacy_version VARCHAR(32) NULL,
    prior_requested_at INT NULL,
    bounces INT NOT NULL DEFAULT 0,
    bounced_at INT NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    UNIQUE KEY news_digest_subscribers_email (email_hash),
    UNIQUE KEY news_digest_subscribers_selector (selector),
    UNIQUE KEY news_digest_subscribers_user (user_id),
    KEY news_digest_subscribers_state (state, confirmed_at),
    CONSTRAINT fk_news_digest_subscribers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_digests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    week CHAR(8) NOT NULL,
    send_at INT NOT NULL,
    window_start INT NOT NULL,
    window_end INT NOT NULL,
    articles TEXT NULL,
    left_out INT NOT NULL DEFAULT 0,
    state VARCHAR(16) NOT NULL,
    note VARCHAR(300) NULL,
    recipients INT NOT NULL DEFAULT 0,
    composed_at INT NOT NULL,
    finished_at INT NULL,
    UNIQUE KEY news_digests_week (week),
    KEY news_digests_state (state, composed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_digest_sends (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    digest_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'claimed',
    attempts INT NOT NULL DEFAULT 1,
    claimed_at INT NOT NULL,
    sent_at INT NULL,
    reply VARCHAR(40) NULL,
    UNIQUE KEY news_digest_sends_pair (digest_id, subscriber_id),
    KEY news_digest_sends_subscriber (subscriber_id),
    KEY news_digest_sends_claimed (claimed_at),
    CONSTRAINT fk_news_digest_sends_digest FOREIGN KEY (digest_id) REFERENCES news_digests(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_digest_sends_subscriber FOREIGN KEY (subscriber_id) REFERENCES news_digest_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
