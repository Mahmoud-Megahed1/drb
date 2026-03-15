-- =============================================
-- DRB Complete Database Schema
-- Rounds, Notes, Members, Championships System
-- =============================================

-- =============================================
-- SECTION 1: Core Users & Auth
-- =============================================

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin', 'rounds', 'notes', 'gate')),
    device_name TEXT,
    api_token TEXT UNIQUE,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 2: Members (Permanent - Never Delete)
-- =============================================

-- Members (permanent, spans all championships)
CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    permanent_code TEXT UNIQUE NOT NULL,     -- DRB-XXXXXXXXXX (stable hash)
    phone TEXT UNIQUE NOT NULL,              -- Normalized 10 digits (07XXXXXXXX)
    name TEXT NOT NULL,
    governorate TEXT,
    account_activated BOOLEAN DEFAULT 0,
    activation_date DATETIME,
    activation_sent_at DATETIME,
    activation_message_sent BOOLEAN DEFAULT 0,
    activation_message_date DATETIME,
    is_active BOOLEAN DEFAULT 1,             -- Soft delete
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    personal_photo TEXT,
    national_id_front TEXT,
    national_id_back TEXT,
    instagram TEXT DEFAULT ''
);

-- =============================================
-- SECTION 3: Championships
-- =============================================

CREATE TABLE IF NOT EXISTS championships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    start_date DATE,
    end_date DATE,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default championship
INSERT OR IGNORE INTO championships (id, name, is_active) VALUES (1, 'البطولة الحالية', 1);

-- =============================================
-- SECTION 4: Registrations (Per Championship)
-- =============================================

CREATE TABLE IF NOT EXISTS registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    championship_id INTEGER NOT NULL,
    wasel INTEGER,                           -- Sequential number per championship
    car_type TEXT,
    car_year TEXT,
    car_color TEXT,
    plate_governorate TEXT,
    plate_letter TEXT,
    plate_number TEXT,
    engine_size TEXT,
    participation_type TEXT,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'rejected')),
    session_badge_token TEXT,                -- Generated at approval only
    entry_time DATETIME,
    is_active BOOLEAN DEFAULT 1,             -- Soft delete
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    personal_photo TEXT,
    front_image TEXT,
    side_image TEXT,
    back_image TEXT,
    acceptance_image TEXT,
    edited_image TEXT,
    license_front TEXT,
    license_back TEXT,
    championship_name TEXT,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (championship_id) REFERENCES championships(id),
    UNIQUE(member_id, championship_id),
    UNIQUE(plate_governorate, plate_letter, plate_number, championship_id)
);

-- =============================================
-- SECTION 5: Participants (Sync Cache for Rounds)
-- =============================================

-- Participants (synced from data.json for rounds system)
CREATE TABLE IF NOT EXISTS participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    badge_id TEXT UNIQUE NOT NULL,
    registration_code TEXT,
    wasel TEXT,
    name TEXT NOT NULL,
    car_type TEXT,
    car_color TEXT,
    plate TEXT,
    phone TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 6: System Configuration
-- =============================================

CREATE TABLE IF NOT EXISTS system_settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER
);

-- Default Settings
INSERT OR IGNORE INTO system_settings (key, value) VALUES 
('badge_enabled', '1'),
('qr_only_mode', '0'),
('allow_registration', '1');

-- =============================================
-- SECTION 6: Rounds System
-- =============================================

CREATE TABLE IF NOT EXISTS rounds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    round_number INTEGER UNIQUE NOT NULL,
    round_name TEXT NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS round_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id INTEGER NOT NULL,
    round_id INTEGER NOT NULL,
    action TEXT NOT NULL CHECK(action IN ('enter', 'exit')),
    scanned_by INTEGER,
    device_name TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id),
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    FOREIGN KEY (scanned_by) REFERENCES users(id)
);

-- Default rounds
INSERT OR IGNORE INTO rounds (round_number, round_name) VALUES (1, 'الجولة الأولى');
INSERT OR IGNORE INTO rounds (round_number, round_name) VALUES (2, 'الجولة الثانية');
INSERT OR IGNORE INTO rounds (round_number, round_name) VALUES (3, 'الجولة الثالثة');

-- =============================================
-- SECTION 7: Notes System
-- =============================================

CREATE TABLE IF NOT EXISTS notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id INTEGER,
    member_id INTEGER,                       -- Link to permanent member
    note_text TEXT NOT NULL,
    note_type TEXT NOT NULL CHECK(note_type IN ('info', 'warning', 'blocker')),
    priority TEXT NOT NULL CHECK(priority IN ('low', 'medium', 'high')),
    visibility TEXT DEFAULT '["all"]',
    is_resolved BOOLEAN DEFAULT 0,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id),
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- =============================================
-- SECTION 8: Warnings (Separate from Notes)
-- =============================================

CREATE TABLE IF NOT EXISTS warnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    championship_id INTEGER,                 -- NULL = global warning
    warning_text TEXT NOT NULL,
    severity TEXT DEFAULT 'low' CHECK(severity IN ('low', 'medium', 'high')),
    expires_at DATETIME,
    is_resolved BOOLEAN DEFAULT 0,
    resolved_by INTEGER,
    resolved_at DATETIME,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (championship_id) REFERENCES championships(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- =============================================
-- SECTION 9: System Settings (SQLite, not JSON)
-- =============================================

CREATE TABLE IF NOT EXISTS system_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER
);

-- Default settings
INSERT OR IGNORE INTO system_settings (key, value) VALUES 
    ('badge_enabled', 'true'),
    ('qr_only_mode', 'false'),
    ('badge_visible_to_staff', 'true'),
    ('require_current_registration', 'true'),
    ('current_championship_id', '1'),
    ('qr_salt', 'DRB_SECRET_SALT_2025');

-- =============================================
-- SECTION 10: Audit Logs (Track Everything)
-- =============================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,                    -- create, update, delete, approve, reject, etc.
    entity TEXT NOT NULL,                    -- member, registration, warning, etc.
    entity_id INTEGER,
    old_value TEXT,                          -- JSON of old values
    new_value TEXT,                          -- JSON of new values
    user_id INTEGER,
    ip_address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- =============================================
-- SECTION 11: Rate Limiting
-- =============================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    limit_key TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

-- =============================================
-- SECTION 12: Indexes for Performance
-- =============================================

-- Members
CREATE INDEX IF NOT EXISTS idx_members_phone ON members(phone);
CREATE INDEX IF NOT EXISTS idx_members_code ON members(permanent_code);
CREATE INDEX IF NOT EXISTS idx_members_active ON members(is_active);

-- Registrations
CREATE INDEX IF NOT EXISTS idx_registrations_member ON registrations(member_id);
CREATE INDEX IF NOT EXISTS idx_registrations_championship ON registrations(championship_id);
CREATE INDEX IF NOT EXISTS idx_registrations_status ON registrations(status);
CREATE INDEX IF NOT EXISTS idx_registrations_plate ON registrations(plate_governorate, plate_letter, plate_number);

-- Round Logs
CREATE INDEX IF NOT EXISTS idx_round_logs_participant ON round_logs(participant_id);
CREATE INDEX IF NOT EXISTS idx_round_logs_round ON round_logs(round_id);
CREATE INDEX IF NOT EXISTS idx_round_logs_created ON round_logs(created_at);

-- Participants
CREATE INDEX IF NOT EXISTS idx_participants_badge ON participants(badge_id);
CREATE INDEX IF NOT EXISTS idx_participants_code ON participants(registration_code);

-- Notes & Warnings
CREATE INDEX IF NOT EXISTS idx_notes_participant ON notes(participant_id);
CREATE INDEX IF NOT EXISTS idx_notes_member ON notes(member_id);
CREATE INDEX IF NOT EXISTS idx_notes_type ON notes(note_type, is_resolved);
CREATE INDEX IF NOT EXISTS idx_warnings_member ON warnings(member_id);
CREATE INDEX IF NOT EXISTS idx_warnings_championship ON warnings(championship_id);

-- Audit
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);

-- Rate Limits
CREATE INDEX IF NOT EXISTS idx_rate_limits_key ON rate_limits(limit_key, created_at);
