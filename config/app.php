<?php

return [
    'name' => env('APP_NAME', 'တပင်ရွှေထီး'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    // Draw times and server timestamps always use Myanmar time.
    'timezone' => 'Asia/Yangon',
    'session_name' => env('SESSION_NAME', 'mini_php_session'),
    'layout' => 'layouts/app',
];
