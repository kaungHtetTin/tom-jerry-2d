<?php

return [
    'up' => ["CREATE TABLE lottery_announcement (
        id TINYINT UNSIGNED PRIMARY KEY,
        message TEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT single_lottery_announcement CHECK (id = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    'down' => ['DROP TABLE lottery_announcement'],
];
