-- The client portal, the support assistant and the site's forms. Times are Unix seconds (UTC).

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL,
    company VARCHAR(160) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'client',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    email_verified_at INT NULL,
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    failed_logins INT NOT NULL DEFAULT 0,
    locked_until INT NULL,
    last_login_at INT NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(16) NOT NULL,
    selector VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at INT NOT NULL,
    used_at INT NULL,
    created_at INT NOT NULL,
    UNIQUE KEY user_tokens_selector (selector),
    KEY user_tokens_user (user_id, purpose),
    CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket VARCHAR(190) NOT NULL PRIMARY KEY,
    hits INT NOT NULL,
    reset_at INT NOT NULL,
    KEY rate_limits_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE releases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product VARCHAR(32) NOT NULL,
    version VARCHAR(40) NOT NULL,
    channel VARCHAR(16) NOT NULL DEFAULT 'stable',
    title VARCHAR(190) NOT NULL,
    notes TEXT NULL,
    file_name VARCHAR(190) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    access VARCHAR(16) NOT NULL DEFAULT 'registered',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    download_count INT NOT NULL DEFAULT 0,
    published_at INT NULL,
    created_by INT UNSIGNED NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    KEY releases_product (product, is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE download_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    release_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    created_at INT NOT NULL,
    KEY download_events_release (release_id),
    CONSTRAINT fk_download_events_release FOREIGN KEY (release_id) REFERENCES releases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE licences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product VARCHAR(32) NOT NULL,
    tier VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested',
    hardware_ids TEXT NULL,
    nonprofit TINYINT(1) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 1,
    customer_note TEXT NULL,
    admin_note TEXT NULL,
    reference VARCHAR(40) NOT NULL,
    updates_until INT NULL,
    expires_at INT NULL,
    issued_at INT NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    UNIQUE KEY licences_reference (reference),
    KEY licences_user (user_id),
    KEY licences_status (status),
    CONSTRAINT fk_licences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE licence_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    licence_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    size INT NOT NULL,
    created_at INT NOT NULL,
    KEY licence_files_licence (licence_id),
    CONSTRAINT fk_licence_files_licence FOREIGN KEY (licence_id) REFERENCES licences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(16) NOT NULL,
    product VARCHAR(32) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    severity VARCHAR(16) NULL,
    app_version VARCHAR(40) NULL,
    environment VARCHAR(255) NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'private',
    votes INT NOT NULL DEFAULT 0,
    comment_count INT NOT NULL DEFAULT 0,
    last_activity_at INT NOT NULL,
    closed_at INT NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    KEY tickets_user (user_id),
    KEY tickets_board (type, visibility, status),
    KEY tickets_activity (last_activity_at),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    KEY ticket_comments_ticket (ticket_id, created_at),
    CONSTRAINT fk_ticket_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    comment_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size INT NOT NULL,
    created_at INT NOT NULL,
    KEY ticket_attachments_ticket (ticket_id),
    CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_votes (
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at INT NOT NULL,
    PRIMARY KEY (ticket_id, user_id),
    CONSTRAINT fk_ticket_votes_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    company VARCHAR(160) NULL,
    topic VARCHAR(32) NOT NULL,
    product VARCHAR(32) NULL,
    message TEXT NOT NULL,
    ip_hash CHAR(64) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    created_at INT NOT NULL,
    KEY contact_messages_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_conversations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    page VARCHAR(190) NULL,
    turns INT NOT NULL DEFAULT 0,
    tokens_in INT NOT NULL DEFAULT 0,
    tokens_out INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    last_at INT NOT NULL,
    KEY chat_conversations_last (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id CHAR(32) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    verdict VARCHAR(24) NULL,
    tokens_in INT NOT NULL DEFAULT 0,
    tokens_out INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    KEY chat_messages_conversation (conversation_id, id),
    CONSTRAINT fk_chat_messages_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_usage (
    day CHAR(10) NOT NULL PRIMARY KEY,
    requests INT NOT NULL DEFAULT 0,
    tokens_in INT NOT NULL DEFAULT 0,
    tokens_out INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    subject VARCHAR(64) NULL,
    meta TEXT NULL,
    ip_hash CHAR(64) NULL,
    created_at INT NOT NULL,
    KEY audit_log_created (created_at),
    KEY audit_log_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
