<?php

function getConfig() {
    $config = [
        "site" => [
            "link" => "https://pay.whaile.ru/"
        ],
        "database" => [
            "user" => "",
            "host" => "",
            "name" => "",
            "pass" => ""
        ],
        "support" => [
            "telegram_url" => "https://t.me/whaile_dev"
        ],
        "security" => [
            "public_pages" => ['/status', '/index', '/docs', '/payment'],
            "protected_paths" => ['/dashboard', '/csh']
        ]
    ];

    return $config;
}