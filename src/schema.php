<?php
// Database migrations. Each key runs once, in order. Never edit a migration
// that has already run on the server; add a new numbered one instead.

$engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    1 => [
        "CREATE TABLE events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code CHAR(6) NOT NULL UNIQUE,
            name VARCHAR(200) NOT NULL,
            current_slide_id INT NULL,
            created_at DATETIME NOT NULL
        )$engine",

        // A module instance: one data collection inside an event.
        // module: m1 yes/no/don't know, m2 text input, m3 collateral request, m4 pledge.
        "CREATE TABLE instances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            module VARCHAR(4) NOT NULL,
            title VARCHAR(200) NOT NULL,
            config LONGTEXT NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX (event_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        )$engine",

        // Run of show. kind: collect | results (m1) | wall (m2).
        "CREATE TABLE slides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            instance_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            position INT NOT NULL,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (event_id, position),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
        )$engine",

        // An attendee's phone, identified by an anonymous cookie token.
        "CREATE TABLE participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            token CHAR(32) NOT NULL UNIQUE,
            last_seen DATETIME NOT NULL,
            seen_instance_id INT NULL,
            created_at DATETIME NOT NULL,
            INDEX (event_id, last_seen),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        )$engine",

        // Module 1 statements. Editing the text bumps version; counting restarts.
        "CREATE TABLE m1_statements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            position INT NOT NULL,
            text VARCHAR(500) NOT NULL,
            version INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX (instance_id),
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
        )$engine",

        "CREATE TABLE m1_statement_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            statement_id INT NOT NULL,
            version INT NOT NULL,
            text VARCHAR(500) NOT NULL,
            replaced_at DATETIME NOT NULL,
            FOREIGN KEY (statement_id) REFERENCES m1_statements(id) ON DELETE CASCADE
        )$engine",

        "CREATE TABLE m1_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            statement_id INT NOT NULL,
            version INT NOT NULL,
            participant_id INT NOT NULL,
            answer VARCHAR(3) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY one_answer (statement_id, version, participant_id),
            INDEX (participant_id),
            FOREIGN KEY (statement_id) REFERENCES m1_statements(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )$engine",

        // Modules 2, 3 and 4: one submission per participant per instance.
        "CREATE TABLE submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            participant_id INT NOT NULL,
            full_name VARCHAR(200) NULL,
            email VARCHAR(254) NULL,
            body TEXT NULL,
            answers LONGTEXT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY one_submission (instance_id, participant_id),
            INDEX (instance_id, status),
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )$engine",
    ],

    // Uploaded slide decks. Each page is stored as an image; slides of kind 'image' point at a page.
    // A deck is replaced by uploading a new batch of pages and relinking its slides.
    2 => [
        "CREATE TABLE IF NOT EXISTS decks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            current_batch INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (event_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        )$engine",

        "CREATE TABLE IF NOT EXISTS deck_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deck_id INT NOT NULL,
            batch INT NOT NULL,
            page_no INT NOT NULL,
            pkey CHAR(16) NOT NULL,
            mime VARCHAR(20) NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            data MEDIUMBLOB NOT NULL,
            thumb MEDIUMBLOB NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY one_page (deck_id, batch, page_no),
            FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE
        )$engine",

        "ALTER TABLE slides MODIFY instance_id INT NULL",
        "ALTER TABLE slides ADD COLUMN page_id INT NULL,
            ADD CONSTRAINT fk_slide_page FOREIGN KEY (page_id) REFERENCES deck_pages(id) ON DELETE CASCADE",
    ],

    // One collateral request per event, switched on/off in the event settings.
    3 => [
        "ALTER TABLE events ADD COLUMN collateral_on TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN collateral_instance_id INT NULL,
            ADD CONSTRAINT fk_event_collateral FOREIGN KEY (collateral_instance_id) REFERENCES instances(id) ON DELETE SET NULL",
        // Adopt an existing collateral module, if an event already has one.
        "UPDATE events e SET
            collateral_instance_id = (SELECT MIN(i.id) FROM instances i WHERE i.event_id = e.id AND i.module = 'm3'),
            collateral_on = (SELECT COUNT(*) > 0 FROM slides s JOIN instances i ON i.id = s.instance_id
                             WHERE i.event_id = e.id AND i.module = 'm3')",
    ],

    // Presence counts ("in the room", "watching") look phones up by the page they are on.
    4 => [
        "ALTER TABLE participants ADD INDEX seen_idx (seen_instance_id, last_seen)",
    ],

    // Module 5, quick-fire cards. Statements belong to a module instance (and so to one event);
    // code is the statement's own id from the CSV (Q001...). Answering twice overwrites.
    5 => [
        "CREATE TABLE IF NOT EXISTS qf_statements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            code VARCHAR(20) NOT NULL,
            theme VARCHAR(120) NOT NULL DEFAULT '',
            topic VARCHAR(160) NOT NULL DEFAULT '',
            stage VARCHAR(60) NOT NULL DEFAULT '',
            statement VARCHAR(600) NOT NULL,
            core_set TINYINT(1) NOT NULL DEFAULT 0,
            is_check TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY one_code (instance_id, code),
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
        )$engine",
        "CREATE TABLE IF NOT EXISTS qf_participants (
            instance_id INT NOT NULL,
            participant_id INT NOT NULL,
            side VARCHAR(12) NOT NULL,
            segment VARCHAR(40) NOT NULL DEFAULT '',
            created DATETIME NOT NULL,
            PRIMARY KEY (instance_id, participant_id),
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )$engine",
        "CREATE TABLE IF NOT EXISTS qf_answers (
            instance_id INT NOT NULL,
            participant_id INT NOT NULL,
            statement_id INT NOT NULL,
            answer CHAR(1) NOT NULL,
            created DATETIME NOT NULL,
            PRIMARY KEY (participant_id, statement_id),
            INDEX (instance_id, created),
            FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
            FOREIGN KEY (statement_id) REFERENCES qf_statements(id) ON DELETE CASCADE
        )$engine",
    ],

    // One-off text fixes: "Armed Forced" typo in names, and US spelling for stored quick-fire statements.
    6 => [
        "UPDATE events SET name = REPLACE(name, 'Armed Forced', 'Armed Forces')",
        "UPDATE instances SET title = REPLACE(title, 'Armed Forced', 'Armed Forces')",
        function () {
            foreach (q('SELECT id, statement, topic, theme FROM qf_statements')->fetchAll() as $r) {
                $new = [qf_us_spelling($r['statement']), qf_us_spelling($r['topic']), qf_us_spelling($r['theme'])];
                if ($new !== [$r['statement'], $r['topic'], $r['theme']]) {
                    q('UPDATE qf_statements SET statement = ?, topic = ?, theme = ? WHERE id = ?', array_merge($new, [$r['id']]));
                }
            }
        },
    ],

    // Impact story per event: the host's notes and the last story written (editable).
    7 => [
        "CREATE TABLE IF NOT EXISTS event_stories (
            event_id INT PRIMARY KEY,
            notes TEXT NULL,
            story MEDIUMTEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'none',
            error TEXT NULL,
            model VARCHAR(60) NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        )$engine",
    ],
];
