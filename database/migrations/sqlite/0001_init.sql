-- The same schema as mysql/0001_init.sql, in SQLite's dialect (development and tests).

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    company TEXT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'client',
    status TEXT NOT NULL DEFAULT 'pending',
    email_verified_at INTEGER NULL,
    marketing_opt_in INTEGER NOT NULL DEFAULT 0,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    locked_until INTEGER NULL,
    last_login_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE user_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose TEXT NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    used_at INTEGER NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX user_tokens_user ON user_tokens (user_id, purpose);

CREATE TABLE rate_limits (
    bucket TEXT NOT NULL PRIMARY KEY,
    hits INTEGER NOT NULL,
    reset_at INTEGER NOT NULL
);
CREATE INDEX rate_limits_reset ON rate_limits (reset_at);

CREATE TABLE releases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product TEXT NOT NULL,
    version TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT 'stable',
    title TEXT NOT NULL,
    notes TEXT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    sha256 TEXT NULL,
    access TEXT NOT NULL DEFAULT 'registered',
    is_published INTEGER NOT NULL DEFAULT 0,
    download_count INTEGER NOT NULL DEFAULT 0,
    published_at INTEGER NULL,
    created_by INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX releases_product ON releases (product, is_published, published_at);

CREATE TABLE download_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    release_id INTEGER NOT NULL REFERENCES releases(id) ON DELETE CASCADE,
    user_id INTEGER NULL,
    ip_hash TEXT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX download_events_release ON download_events (release_id);

CREATE TABLE licences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product TEXT NOT NULL,
    tier TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'requested',
    hardware_ids TEXT NULL,
    nonprofit INTEGER NOT NULL DEFAULT 0,
    quantity INTEGER NOT NULL DEFAULT 1,
    customer_note TEXT NULL,
    admin_note TEXT NULL,
    reference TEXT NOT NULL UNIQUE,
    updates_until INTEGER NULL,
    expires_at INTEGER NULL,
    issued_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX licences_user ON licences (user_id);
CREATE INDEX licences_status ON licences (status);

CREATE TABLE licence_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    licence_id INTEGER NOT NULL REFERENCES licences(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    size INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX licence_files_licence ON licence_files (licence_id);

CREATE TABLE tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    product TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    severity TEXT NULL,
    app_version TEXT NULL,
    environment TEXT NULL,
    visibility TEXT NOT NULL DEFAULT 'private',
    votes INTEGER NOT NULL DEFAULT 0,
    comment_count INTEGER NOT NULL DEFAULT 0,
    last_activity_at INTEGER NOT NULL,
    closed_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX tickets_user ON tickets (user_id);
CREATE INDEX tickets_board ON tickets (type, visibility, status);
CREATE INDEX tickets_activity ON tickets (last_activity_at);

CREATE TABLE ticket_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    is_staff INTEGER NOT NULL DEFAULT 0,
    is_internal INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE INDEX ticket_comments_ticket ON ticket_comments (ticket_id, created_at);

CREATE TABLE ticket_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    comment_id INTEGER NULL,
    user_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX ticket_attachments_ticket ON ticket_attachments (ticket_id);

CREATE TABLE ticket_votes (
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (ticket_id, user_id)
);

CREATE TABLE contact_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    company TEXT NULL,
    topic TEXT NOT NULL,
    product TEXT NULL,
    message TEXT NOT NULL,
    ip_hash TEXT NULL,
    status TEXT NOT NULL DEFAULT 'new',
    created_at INTEGER NOT NULL
);
CREATE INDEX contact_messages_status ON contact_messages (status, created_at);

CREATE TABLE chat_conversations (
    id TEXT NOT NULL PRIMARY KEY,
    user_id INTEGER NULL,
    ip_hash TEXT NULL,
    page TEXT NULL,
    turns INTEGER NOT NULL DEFAULT 0,
    tokens_in INTEGER NOT NULL DEFAULT 0,
    tokens_out INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_at INTEGER NOT NULL
);
CREATE INDEX chat_conversations_last ON chat_conversations (last_at);

CREATE TABLE chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id TEXT NOT NULL REFERENCES chat_conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    verdict TEXT NULL,
    tokens_in INTEGER NOT NULL DEFAULT 0,
    tokens_out INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE INDEX chat_messages_conversation ON chat_messages (conversation_id, id);

CREATE TABLE chat_usage (
    day TEXT NOT NULL PRIMARY KEY,
    requests INTEGER NOT NULL DEFAULT 0,
    tokens_in INTEGER NOT NULL DEFAULT 0,
    tokens_out INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    subject TEXT NULL,
    meta TEXT NULL,
    ip_hash TEXT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX audit_log_created ON audit_log (created_at);
CREATE INDEX audit_log_user ON audit_log (user_id);
