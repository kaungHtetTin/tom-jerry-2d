<?php

return [
    'up' => [
        "CREATE TABLE lottery_admin (
            id TINYINT UNSIGNED PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            auth_version INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            CONSTRAINT single_lottery_admin CHECK (id = 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE lottery_login_attempts (
            bucket CHAR(64) PRIMARY KEY,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE lottery_schedules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            draw_time TIME NOT NULL UNIQUE,
            label VARCHAR(80) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE lottery_results (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            schedule_id BIGINT UNSIGNED NOT NULL,
            draw_date DATE NOT NULL,
            draw_time TIME NOT NULL,
            draw_label VARCHAR(80) NOT NULL DEFAULT '',
            number CHAR(2) NOT NULL,
            published_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT lottery_result_schedule FOREIGN KEY (schedule_id) REFERENCES lottery_schedules(id),
            CONSTRAINT lottery_result_occurrence UNIQUE (draw_date, schedule_id),
            INDEX lottery_result_date_time (draw_date, draw_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO lottery_schedules (draw_time, created_at, updated_at) VALUES
            ('17:00:00', NOW(), NOW()), ('18:00:00', NOW(), NOW()), ('19:00:00', NOW(), NOW()),
            ('20:00:00', NOW(), NOW()), ('21:00:00', NOW(), NOW()), ('22:00:00', NOW(), NOW())",
    ],
    'down' => [
        'DROP TABLE lottery_results', 'DROP TABLE lottery_schedules',
        'DROP TABLE lottery_login_attempts', 'DROP TABLE lottery_admin',
    ],
];
