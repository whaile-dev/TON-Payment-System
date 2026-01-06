<?php

function getConfig() {
    $config = [
        "site" => [
            "name" => "TonPay",
            "url" => "https://pay.whaile.ru",
            "api_port" => 3000,
            "withdraw_port" => 2998
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