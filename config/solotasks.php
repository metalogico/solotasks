<?php

return [
    'username' => env('SOLOTASKS_USERNAME', ''),
    'password_hash' => env('SOLOTASKS_PASSWORD_HASH', ''),
    'mcp_token' => env('SOLOTASKS_MCP_TOKEN', ''),
    'calendar_include_done' => (bool) env('SOLOTASKS_CALENDAR_INCLUDE_DONE', false),
];
