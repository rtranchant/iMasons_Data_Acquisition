<?php
// Server-only settings. Keep this file on the server as imasons_config.php,
// either in the home directory or in the app folder (next to public/).
// It is never committed to Git.
return [
    'db' => [
        'host'     => 'mysql03.hostinguk.net',
        'port'     => 3306,
        'name'     => 'romaintr_imasons_sessions',
        'user'     => 'imasons_sessions',
        'password' => 'PUT-THE-DATABASE-PASSWORD-HERE',
    ],
    'admin_password' => 'CHOOSE-A-LONG-ADMIN-PASSWORD',
    // Optional: how often an idle attendee phone checks what is live, in seconds (2–60, default 10).
    // Phones also check at once when tapped, unlocked or brought back to the page.
    // 'phone_check_s' => 10,
    // Optional: how long the big screen waits after each reply before asking again, in seconds (default 5).
    // 'screen_check_s' => 5,
    // Optional: lets the app write the impact story itself (Event → Impact story). Create a key at
    // https://console.anthropic.com. Without it, the page gives you the material to paste into Claude.
    // 'anthropic_api_key' => 'sk-ant-...',
    // 'anthropic_model'   => 'claude-sonnet-5-5',
];
