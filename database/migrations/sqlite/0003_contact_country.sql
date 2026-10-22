-- The contact form's optional country (ISO 3166-1 alpha-2).
ALTER TABLE contact_messages ADD COLUMN country TEXT NULL;
