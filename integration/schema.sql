CREATE TABLE IF NOT EXISTS gdpr_consents (
 user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 value TEXT NOT NULL CHECK(value IN ('yes','no')),
 withdrawn INTEGER NOT NULL DEFAULT 0 CHECK(withdrawn IN (0,1))
);
