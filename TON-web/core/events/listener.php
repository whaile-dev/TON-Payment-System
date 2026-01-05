<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!headers_sent()) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = $_SERVER['DOCUMENT_ROOT'];
require_once($root . '/config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');

if (!RateLimiter::check('listener', 10, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

$config = getConfig();
$page = sanitizeInput($_POST['page'] ?? '', 50);

if ($page !== 'logout') {
    requireCSRFToken();
}

$handled = false;
if ($page === 'register') {
    registerUser();
    $handled = true;
} elseif ($page === 'login') {
    loginUser();
    $handled = true;
} elseif ($page === 'logout') {
    logoutUser();
    $handled = true;
} elseif ($page === 'regenerate_api_token') {
    regenerateApiToken();
    $handled = true;
} elseif ($page === 'verify_password_for_token') {
    verifyPasswordForToken();
    $handled = true;
}

if (!$handled) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Неизвестный тип запроса']);
    exit;
}

$output = ob_get_clean();

if (!empty($output) && (strpos($output, '<br />') !== false || strpos($output, '<b>') !== false)) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Внутренняя ошибка сервера'
    ]);
} else {
    if (!empty($output)) {
        echo $output;
    }
}

function registerUser() {
    $conn = getCore()->getConn();

    $email = sanitizeInput(trim($_POST['email'] ?? ''), 255);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $agree_terms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === '1';

    if (empty($email) || empty($password) || empty($password_confirm)) {
        logSecurityEvent('register_validation_failed', ['reason' => 'empty_fields']);
        echo json_encode(['success' => false, 'message' => 'Все поля обязательны для заполнения']);
        return;
    }
    
    if (strlen($email) > 255) {
        logSecurityEvent('register_validation_failed', ['reason' => 'email_too_long']);
        echo json_encode(['success' => false, 'message' => 'Email слишком длинный']);
        return;
    }
    
    if (strlen($password) > 1000) {
        logSecurityEvent('register_validation_failed', ['reason' => 'password_too_long']);
        echo json_encode(['success' => false, 'message' => 'Пароль слишком длинный']);
        return;
    }
    
    if (!$agree_terms) {
        logSecurityEvent('register_validation_failed', ['reason' => 'terms_not_agreed']);
        echo json_encode(['success' => false, 'message' => 'Необходимо согласиться с условиями использования']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logSecurityEvent('register_validation_failed', ['reason' => 'invalid_email']);
        echo json_encode(['success' => false, 'message' => 'Некорректный email']);
        return;
    }
    
    $emailParts = explode('@', $email);
    if (count($emailParts) !== 2) {
        logSecurityEvent('register_validation_failed', ['reason' => 'invalid_email_format']);
        echo json_encode(['success' => false, 'message' => 'Некорректный email']);
        return;
    }
    
    $domain = $emailParts[1];
    if (strlen($domain) > 253 || strlen($domain) < 1) {
        logSecurityEvent('register_validation_failed', ['reason' => 'invalid_email_domain_length']);
        echo json_encode(['success' => false, 'message' => 'Некорректный email']);
        return;
    }
    
    if (strpos($domain, '.') === false) {
        logSecurityEvent('register_validation_failed', ['reason' => 'invalid_email_domain']);
        echo json_encode(['success' => false, 'message' => 'Некорректный email']);
        return;
    }

    if ($password !== $password_confirm) {
        logSecurityEvent('register_validation_failed', ['reason' => 'passwords_mismatch']);
        echo json_encode(['success' => false, 'message' => 'Пароли не совпадают']);
        return;
    }

    if (strlen($password) < 8) {
        logSecurityEvent('register_validation_failed', ['reason' => 'password_too_short']);
        echo json_encode(['success' => false, 'message' => 'Пароль должен содержать минимум 8 символов']);
        return;
    }

    $stmt = $conn->prepare("SELECT id FROM Users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        logSecurityEvent('register_failed', ['reason' => 'email_exists']);
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
        $stmt->close();
        return;
    }
    $stmt->close();

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $api_token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare("INSERT INTO Users (email, password_hash, api_token, status) VALUES (?, ?, ?, ?)");
    $status = 'active';
    $stmt->bind_param("ssss", $email, $password_hash, $api_token, $status);

    if ($stmt->execute()) {
        $user_id = $conn->insert_id;
        
        $_SESSION['id'] = $user_id;
        $_SESSION['email'] = $email;
        $_SESSION['api_token'] = $api_token;

        logSecurityEvent('register_success', ['user_id' => $user_id]);
        echo json_encode(['success' => true, 'message' => 'Регистрация успешна!', 'redirect' => '/dashboard']);
    } else {
        if ($conn->errno === 1062) {
            logSecurityEvent('register_failed', ['reason' => 'email_exists_race']);
            echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
        } else {
            error_log("Register error: " . $conn->error);
            logSecurityEvent('register_error', ['error' => 'database_error']);
            echo json_encode(['success' => false, 'message' => 'Ошибка при регистрации. Попробуйте позже.']);
        }
    }

    $stmt->close();
}

function loginUser() {
    $conn = getCore()->getConn();

    $login = sanitizeInput(trim($_POST['login'] ?? ''), 255);
    $password = $_POST['password'] ?? '';

    if (empty($login) || empty($password)) {
        logSecurityEvent('login_failed', ['reason' => 'empty_fields']);
        echo json_encode(['success' => false, 'message' => 'Все поля обязательны для заполнения']);
        return;
    }
    
    if (strlen($password) > 1000) {
        logSecurityEvent('login_failed', ['reason' => 'password_too_long']);
        echo json_encode(['success' => false, 'message' => 'Неверный email или пароль']);
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkLoginAttempts($login, $ip)) {
        logSecurityEvent('login_blocked', ['reason' => 'too_many_attempts', 'email' => $login, 'ip' => $ip]);
        echo json_encode(['success' => false, 'message' => 'Слишком много неудачных попыток. Попробуйте позже.']);
        return;
    }

    $stmt = $conn->prepare("SELECT * FROM Users WHERE email = ?");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $result = $stmt->get_result();

    $dummy_hash = '$2y$10$' . str_repeat('0', 22) . str_repeat('A', 31);
    $user = $result->fetch_assoc();
    $password_hash = $user ? $user['password_hash'] : $dummy_hash;

    $password_valid = password_verify($password, $password_hash);

    if (!$user || !$password_valid) {
        recordFailedLoginAttempt($login, $ip);
        logSecurityEvent('login_failed', ['reason' => 'invalid_credentials', 'email' => $login]);
        echo json_encode(['success' => false, 'message' => 'Неверный email или пароль']);
        $stmt->close();
        return;
    }

    if ($user['status'] !== 'active') {
        logSecurityEvent('login_failed', ['reason' => 'account_inactive', 'user_id' => $user['id']]);
        echo json_encode(['success' => false, 'message' => 'Аккаунт заблокирован или неактивен']);
        $stmt->close();
        return;
    }

    clearFailedLoginAttempts($login, $ip);

    $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
    
    $session_name = session_name();
    $session_id = session_id();
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
    $expires = $remember_me ? time() + (60 * 60 * 24 * 100) : 0;
    
    if (PHP_VERSION_ID >= 70300) {
        $cookie_options = [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        setcookie($session_name, $session_id, $cookie_options);
    } else {
        $cookie_value = $session_name . '=' . urlencode($session_id);
        $cookie_value .= '; Path=/';
        $cookie_value .= '; SameSite=Strict';
        if ($secure) {
            $cookie_value .= '; Secure';
        }
        $cookie_value .= '; HttpOnly';
        if ($expires > 0) {
            $cookie_value .= '; Max-Age=' . ($expires - time());
        }
        header('Set-Cookie: ' . $cookie_value, false);
    }

    session_regenerate_id(true);
    
    $_SESSION['id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['created_at'] = $user['created_at'];
    $_SESSION['api_token'] = $user['api_token'] ?? null;
    $_SESSION['remember_me'] = $remember_me;

    logSecurityEvent('login_success', ['user_id' => $user['id']]);
    echo json_encode(['success' => true, 'message' => 'Вход выполнен успешно!', 'redirect' => '/dashboard']);
    $stmt->close();
}

function logoutUser() {
    $response = ['success' => true, 'message' => 'Выход выполнен', 'redirect' => '/'];
    
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        $session_name = session_name();
        if ($session_name) {
            setcookie($session_name, '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    echo json_encode($response);
}

function verifyPasswordForToken() {
    if (!isset($_SESSION['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
        return;
    }
    
    $password = $_POST['password'] ?? '';
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Введите пароль']);
        return;
    }
    
    if (strlen($password) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
        return;
    }
    
    $conn = getCore()->getConn();
    $user_id = $_SESSION['id'];
    $user_id_int = intval($user_id);
    
    // Проверяем пароль
    $stmt = $conn->prepare("SELECT password_hash, api_token FROM Users WHERE id = ?");
    $stmt->bind_param("i", $user_id_int);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        return;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Проверяем пароль
    if (!password_verify($password, $user['password_hash'])) {
        logSecurityEvent('api_token_view_failed', ['user_id' => $user_id_int, 'reason' => 'invalid_password']);
        echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
        return;
    }
    
    logSecurityEvent('api_token_viewed', ['user_id' => $user_id_int]);
    echo json_encode([
        'success' => true,
        'api_token' => $user['api_token'] ?? null
    ]);
}

function regenerateApiToken() {
    if (!isset($_SESSION['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
        return;
    }
    
    $password = $_POST['password'] ?? '';
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Введите пароль']);
        return;
    }
    
    if (strlen($password) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
        return;
    }
    
    $conn = getCore()->getConn();
    $user_id = $_SESSION['id'];
    $user_id_int = intval($user_id);
    
    $stmt = $conn->prepare("SELECT password_hash FROM Users WHERE id = ?");
    $stmt->bind_param("i", $user_id_int);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        return;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Проверяем пароль
    if (!password_verify($password, $user['password_hash'])) {
        logSecurityEvent('api_token_regeneration_failed', ['user_id' => $user_id_int, 'reason' => 'invalid_password']);
        echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
        return;
    }
    
    // Генерируем новый токен
    $new_api_token = bin2hex(random_bytes(32));
    
    // Обновляем токен в БД с использованием транзакции для защиты от race condition
    $conn->begin_transaction();
    
    try {
        // Повторно проверяем пароль и получаем текущий токен в рамках транзакции
        $check_stmt = $conn->prepare("SELECT password_hash, api_token FROM Users WHERE id = ? FOR UPDATE");
        $check_stmt->bind_param("i", $user_id_int);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_user = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if (!$check_user || !password_verify($password, $check_user['password_hash'])) {
            $conn->rollback();
            logSecurityEvent('api_token_regeneration_failed', ['user_id' => $user_id_int, 'reason' => 'invalid_password_race']);
            echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
            return;
        }
        
        // Обновляем токен в БД
        $stmt = $conn->prepare("UPDATE Users SET api_token = ? WHERE id = ?");
        $stmt->bind_param("si", $new_api_token, $user_id_int);
        
        if ($stmt->execute()) {
            $conn->commit();
            $stmt->close();
            
            // Обновляем токен в сессии
            $_SESSION['api_token'] = $new_api_token;
            
            logSecurityEvent('api_token_regenerated', ['user_id' => $user_id_int]);
            echo json_encode([
                'success' => true, 
                'message' => 'Новый API токен успешно сгенерирован',
                'api_token' => $new_api_token
            ]);
        } else {
            $conn->rollback();
            error_log("API token regeneration error: " . $conn->error);
            logSecurityEvent('api_token_regeneration_error', ['user_id' => $user_id_int, 'error' => 'database_error']);
            echo json_encode(['success' => false, 'message' => 'Ошибка при генерации токена. Попробуйте позже.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("API token regeneration exception: " . $e->getMessage());
        logSecurityEvent('api_token_regeneration_error', ['user_id' => $user_id_int, 'error' => 'exception']);
        echo json_encode(['success' => false, 'message' => 'Ошибка при генерации токена. Попробуйте позже.']);
    }
}
