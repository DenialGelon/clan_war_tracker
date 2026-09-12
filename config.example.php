<?php
// Copy this file to config.php (kept out of git) and fill in the values.
// On the host, place config.php outside public_html and point CWT_CONFIG at it.

return [
    // Supercell Clash Royale API token. IP-whitelisted; create one per outbound IP.
    'api_key' => 'PASTE_TOKEN_HERE',

    // Clan tag, with or without the leading #. Normalized before use.
    'clan_tag' => '#JUQRRL8',

    // Absolute or repo-relative path to the SQLite database file.
    'db_path' => __DIR__ . '/data/clan_war_tracker.sqlite',

    // When true, scripts/fetch.php reads tests/fixtures/*.json instead of calling the API.
    'use_fixtures' => false,
];
