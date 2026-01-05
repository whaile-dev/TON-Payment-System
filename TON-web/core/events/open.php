<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');

function onOpen() {
    date_default_timezone_set('Europe/Moscow');
    
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uriPath = parse_url($uri, PHP_URL_PATH);
    
    $config = getCore()->getConfig();
    $protectedPaths = $config['security']['protected_paths'] ?? [];
    
    foreach ($protectedPaths as $path) {
        if (strpos($uriPath, $path) === 0) {
            if (!getCore()->isAuth()) {
                header('Location: /');
                exit();
            }
            break;
        }
    }
    
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'on' && 
        isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false) {
        $redirectUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header("Location: $redirectUrl", true, 301);
        exit();
    }
}
