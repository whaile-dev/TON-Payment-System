<?php

class Core {
    function init() {

        global $config;
        global $conn;

        $this->inputClasses();

        $config = getConfig();
        $conn = new mysqli($config['database']['host'], $config['database']['user'], $config['database']['pass'], $config['database']['name']);

        if ($conn->connect_error) {
            error_log("Database connection error: " . $conn->connect_error);
            $isProduction = !file_exists($_SERVER['DOCUMENT_ROOT'] . '/.dev');
            if (!$isProduction && ini_get('display_errors')) {
                die("Ошибка подключения к базе данных: " . htmlspecialchars($conn->connect_error));
            } else {
                die("Ошибка подключения к базе данных");
            }
        }

        $conn->set_charset("utf8mb4");

        register_shutdown_function(function() { $this->end(); });
    }

    function end() {
        try {
            $conn = getCore()->getConn();
            if ($conn && !$conn->connect_error) {
                $conn->close();
            }
        } catch (Exception $e) {
        }
    }

    function inputClasses() {
        global $documentRoot;
        
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        
        $docRoot = realpath($docRoot);
        if ($docRoot === false) {
            $docRoot = str_replace(['..', '//'], ['', '/'], $docRoot);
            $docRoot = rtrim($docRoot, '/');
        }
        
        if (empty($docRoot) || !is_dir($docRoot)) {
            error_log("Invalid DOCUMENT_ROOT: " . var_export($_SERVER['DOCUMENT_ROOT'] ?? 'not set', true));
            die("Ошибка конфигурации сервера");
        }
        
        $documentRoot = $docRoot;

        $autoloadFiles = [
            '/config.php',
            '/core/events/open.php'
        ];

        foreach ($autoloadFiles as $file) {
            $filePath = $documentRoot . $file;
            $realPath = realpath($filePath);
            
            if ($realPath === false || strpos($realPath, $documentRoot) !== 0) {
                error_log("Security: Attempted path traversal in inputClasses: " . $file);
                continue;
            }
            
            require_once($realPath);
        }
    }

    function startSession() {
        if (ob_get_level() == 0) ob_start();
        
        if (session_status() === PHP_SESSION_NONE) {
            if (ini_get('session.gc_maxlifetime') != 60 * 60 * 24 * 100) {
                ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 100));
            }
            if (ini_get('session.cookie_lifetime') != 60 * 60 * 24 * 100) {
                ini_set('session.cookie_lifetime', (string)(60 * 60 * 24 * 100));
            }
            session_start();
        } else {
            if (isset($_SESSION['remember_me'])) {
                $remember_me = $_SESSION['remember_me'];
                if ($remember_me) {
                    $lifetime = 60 * 60 * 24 * 100;
                    ini_set('session.gc_maxlifetime', (string)$lifetime);
                    ini_set('session.cookie_lifetime', (string)$lifetime);
                } else {
                    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24));
                    ini_set('session.cookie_lifetime', '0');
                }
            }
        }
        
        $isProduction = !file_exists($_SERVER['DOCUMENT_ROOT'] . '/.dev');
        if (!$isProduction && ini_get('display_errors') !== "1") {
            ini_set('display_errors', "1");
        } elseif ($isProduction) {
            ini_set('display_errors', "0");
        }
        date_default_timezone_set('Europe/Moscow');


        if ($this->isAuth()) {
            $config = $this->getConfig();
            $publicPages = $config['security']['public_pages'] ?? [];
            $currentPage = $_SERVER['PHP_SELF'] ?? '';
            $isPublicPage = false;
            
            foreach ($publicPages as $page) {
                if (strpos($currentPage, $page . '.php') !== false || strpos($currentPage, $page) === 0) {
                    $isPublicPage = true;
                    break;
                }
            }
            
            if (!$isPublicPage) {
                $conn = $this->getConn();
                if (!$conn || $conn->connect_error) {
                    error_log("Database connection error in startSession");
                    return;
                }
                
                $user_id = filter_var($_SESSION['id'], FILTER_VALIDATE_INT, [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => PHP_INT_MAX
                    ]
                ]);
                
                if ($user_id === false) {
                    error_log("Invalid user_id in startSession: " . var_export($_SESSION['id'], true));
                    session_destroy();
                    header('Location: /');
                    exit();
                }
                
                $userData = $this->getSqlResultUser($user_id, $conn);

                if ($userData && $userData->num_rows > 0) {
                    $user = $userData->fetch_assoc();
                    foreach ($user as $key => $value) { $_SESSION[$key] = $value; }
                } else {
                    session_destroy();
                    header('Location: /');
                    exit();
                }
            }
        }
    }

    function loadPHP() {
        onOpen();
    }

    function getSqlResultUser($id, $conn) {
        if (!$conn || $conn->connect_error) {
            error_log("Database connection error in getSqlResultUser");
            return false;
        }
        
        $user_id_int = filter_var($id, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => PHP_INT_MAX
            ]
        ]);
        
        if ($user_id_int === false) {
            error_log("Invalid user_id in getSqlResultUser: " . var_export($id, true));
            return false;
        }
        
        $stmt = $conn->prepare("SELECT * FROM Users WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("i", $user_id_int);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result;
    }
    
    function isAuth() {
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        $user_id = filter_var($_SESSION['id'], FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => PHP_INT_MAX
            ]
        ]);
        
        return $user_id !== false;
    }
    function getConn() { global $conn; return $conn; }
    function getConfig() { global $config; return $config; }
    function getDocumentRoot() { global $documentRoot; return $documentRoot; }
}