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
];
