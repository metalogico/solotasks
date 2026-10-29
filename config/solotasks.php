<?php

return [
    'username' => env('SOLOTASKS_USERNAME', ''),
    'password_hash' => env('SOLOTASKS_PASSWORD_HASH', ''),
    'backup_path' => env('SOLOTASKS_BACKUP_PATH'),
    'calendar_include_done' => (bool) env('SOLOTASKS_CALENDAR_INCLUDE_DONE', false),
];
