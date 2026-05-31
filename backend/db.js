const Database = require("better-sqlite3");

const db = new Database("database.db");

db.prepare(`
  CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT,
    prenom TEXT,
    pc_id TEXT,
    email TEXT,
    type_panne TEXT,
    custom_panne TEXT,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )
`).run();

db.prepare(`
  CREATE TABLE IF NOT EXISTS verified_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT,
    device_fingerprint TEXT,
    verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(email, device_fingerprint)
  )
`).run();

db.prepare(`
  CREATE TABLE IF NOT EXISTS pending_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE,
    nom TEXT,
    prenom TEXT,
    pc_id TEXT,
    email TEXT,
    type_panne TEXT,
    custom_panne TEXT,
    description TEXT,
    device_fingerprint TEXT,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )
`).run();

try {
  db.prepare(`
    ALTER TABLE pending_tickets
    ADD COLUMN nom TEXT
  `).run();
} catch (error) {}

try {
  db.prepare(`
    ALTER TABLE pending_tickets
    ADD COLUMN prenom TEXT
  `).run();
} catch (error) {}

try {
  db.prepare(`
    ALTER TABLE pending_tickets
    ADD COLUMN device_fingerprint TEXT
  `).run();
} catch (error) {}

try {
  db.prepare(`
    ALTER TABLE tickets
    ADD COLUMN nom TEXT
  `).run();
} catch (error) {}

try {
  db.prepare(`
    ALTER TABLE tickets
    ADD COLUMN prenom TEXT
  `).run();
} catch (error) {}

try {
  db.prepare(`
    ALTER TABLE verified_users
    ADD COLUMN device_fingerprint TEXT
  `).run();
} catch (error) {}

module.exports = db;