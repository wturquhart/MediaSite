<?php
return [
    // Shared secret for the HTTP API
    'api_token'       => getenv('SCRAPER_TOKEN') ?: 'change-me',

    // Where finished files land. Must be outside the docroot or protected.
    'storage_dir'     => __DIR__ . '/storage',

    'max_bytes'       => 2 * 1024 * 1024 * 1024, // 2 GB hard cap
    'connect_timeout' => 10,
    'read_timeout'    => 120,
    'max_depth'       => 2,                      // nested iframe depth

    // yt-dlp binary; null disables it
    'yt_dlp'          => trim((string) shell_exec('command -v yt-dlp')) ?: null,
    'ffmpeg'          => trim((string) shell_exec('command -v ffmpeg')) ?: null,

    'allow_private_hosts' => false,

    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=videos;charset=utf8mb4',
        'user' => getenv('DB_USER'),
        'pass' => getenv('DB_PASS'),
    ],
];