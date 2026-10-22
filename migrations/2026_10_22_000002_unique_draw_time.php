<?php

return [
    'up' => ['ALTER TABLE lottery_results ADD CONSTRAINT lottery_result_date_time_unique UNIQUE (draw_date, draw_time)'],
    'down' => ['ALTER TABLE lottery_results DROP INDEX lottery_result_date_time_unique'],
];
