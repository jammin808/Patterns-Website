-- Cached prompt tokens, reported by the API apart from input tokens, count towards the assistant's daily budget.
ALTER TABLE chat_usage ADD COLUMN cache_write INT NOT NULL DEFAULT 0, ADD COLUMN cache_read INT NOT NULL DEFAULT 0;
