-- An account's optional country (ISO 3166-1 alpha-2), from the sign-up form and the account page.
ALTER TABLE users ADD COLUMN country TEXT NULL;
