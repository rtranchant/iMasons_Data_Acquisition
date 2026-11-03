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
];
