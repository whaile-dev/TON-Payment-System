<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/http_client.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');

header('Content-Type: application/json; charset=utf-8');

if (!getCore()->isAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['id'];
$api_token = $_SESSION['api_token'] ?? null;

if (!$api_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'API token not found. Please log in again.']);
    exit();
}

    if ($method === 'POST') {
    requireCSRFToken();
    
    if (!RateLimiter::check('withdraw', 3, 60)) {
        http_response_code(429);
        echo json_encode([
            'success' => false, 
            'message' => 'Слишком много запросов. Попробуйте позже.',
            'retry_after' => 60
        ]);
        exit();
    }
    
    $data = safeJsonDecode();
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат данных или размер запроса слишком большой']);
        exit();
    }
    
    if (!$data || !isset($data['cashier_id']) || !isset($data['amount']) || !isset($data['wallet'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указаны обязательные параметры: cashier_id, amount и wallet']);
        exit();
    }
    
    $cashier_id = intval($data['cashier_id']);
    
    $amount_str = trim((string)($data['amount'] ?? ''));
    if (!preg_match('/^\d+(\.\d{1,9})?$/', $amount_str)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректный формат суммы']);
        exit();
    }
    
    if (function_exists('bccomp')) {
        if (bccomp($amount_str, '1000000000', 9) > 0 || bccomp($amount_str, '0', 9) < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Некорректная сумма']);
            exit();
        }
        
        if (bccomp($amount_str, '0.01', 9) < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Минимальная сумма вывода: 0.01']);
            exit();
        }
        
        if (bccomp($amount_str, '0', 9) <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Сумма должна быть больше 0']);
            exit();
        }
    } else {
        $amount = (float)$amount_str;
        if ($amount > 1000000000 || $amount < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Некорректная сумма']);
            exit();
        }
        
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Сумма должна быть больше 0']);
            exit();
        }
        
        if ($amount < 0.01) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Минимальная сумма вывода: 0.01']);
            exit();
        }
    }
    
    $parts = explode('.', $amount_str);
    if (isset($parts[1]) && strlen($parts[1]) > 9) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Слишком много знаков после запятой']);
        exit();
    }
    
    $amount = (float)$amount_str;
    
    $wallet = sanitizeInput(trim($data['wallet']), 100);
    
    if (!validateTONAddress($wallet)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат адреса кошелька TON']);
        exit();
    }
    
    if ($cashier_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный ID кассы']);
        exit();
    }
    
    if (empty($wallet)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан адрес кошелька']);
        exit();
    }
    
    $conn = getCore()->getConn();
    $stmt = $conn->prepare("SELECT id, balance, currency FROM Cashiers WHERE id = ? AND user_id = ?");
    $user_id_int = intval($user_id);
    $stmt->bind_param("ii", $cashier_id, $user_id_int);
    $stmt->execute();
    $result = $stmt->get_result();
    $cashier_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$cashier_data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Касса не найдена или доступ запрещен']);
        exit();
    }
    
    $balance_str = (string)($cashier_data['balance'] ?? '0');
    $currency = strtoupper($cashier_data['currency'] ?? 'TON');
    
    $insufficient_balance = false;
    if (function_exists('bccomp')) {
        $balance_compare = bccomp($balance_str, $amount_str, 9);
        if ($balance_compare < 0) {
            $insufficient_balance = true;
        }
    } else {
        $balance = (float)$balance_str;
        $balance_rounded = round($balance, 9);
        $amount_rounded = round($amount, 9);
        
        if ($balance_rounded < $amount_rounded) {
            $insufficient_balance = true;
        }
    }
    
    if ($insufficient_balance) {
        $balance = (float)$balance_str;
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Недостаточно средств на кассе. Доступно: ' . number_format($balance, 2, '.', ' ') . ' ' . $currency,
            'balance' => $balance,
            'requested' => $amount,
            'currency' => $currency
        ]);
        exit();
    }
    
    $withdrawClient = getHttpClient('https://pay.whaile.ru:2998');
    
    $payload = [
        'cashier_id' => $cashier_id,
        'amount' => $amount,
        'wallet' => $wallet,
        'api_token' => $api_token
    ];
    
    $result = $withdrawClient->post('/withdraw', $payload);
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка соединения: ' . $result['error']
        ]);
        exit();
    }
    
    if ($result['http_code'] === 200) {
        $response_data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in withdraw.php response: " . json_last_error_msg());
        }
        
        logSecurityEvent('withdraw_requested', [
            'user_id' => $user_id_int,
            'cashier_id' => $cashier_id,
            'amount' => $amount,
            'wallet' => substr($wallet, 0, 20) . '...'
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Запрос на вывод принят в обработку',
            'data' => $response_data
        ]);
    } else {
        http_response_code($result['http_code']);
        $error_data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in withdraw.php error response: " . json_last_error_msg());
        }
        
        $error_message = $error_data['detail'] ?? 'Ошибка обработки вывода';
        
        $error_messages = [
            'Insufficient balance' => 'Недостаточно средств на кассе',
            'Transfer failed' => 'Ошибка при выполнении перевода',
            'Invalid wallet address' => 'Неверный адрес кошелька',
            'Amount must be greater than 0' => 'Сумма должна быть больше 0',
            'Minimum withdrawal amount is 0.01' => 'Минимальная сумма вывода: 0.01',
            'Cashier not found' => 'Касса не найдена',
            'Database connection error' => 'Ошибка подключения к базе данных',
            'Failed to deduct balance' => 'Не удалось списать баланс',
            'Unsupported currency' => 'Неподдерживаемая валюта',
            'Jetton address not set for this cashier' => 'Адрес джетона не установлен для этой кассы'
        ];
        
        foreach ($error_messages as $en => $ru) {
            if (stripos($error_message, $en) !== false) {
                $error_message = $ru;
                break;
            }
        }
        
        echo json_encode([
            'success' => false,
            'message' => $error_message,
            'detail' => $error_data['detail'] ?? null
        ]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

