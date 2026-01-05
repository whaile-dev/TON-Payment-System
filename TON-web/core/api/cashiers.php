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
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] === 'PATCH') {
    $method = 'PATCH';
}

$user_id = $_SESSION['id'];
$api_token = $_SESSION['api_token'] ?? null;

if (!$api_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'API token not found. Please log in again.']);
    exit();
}

$client = getHttpClient();

function validateDecimalPlaces($value, $maxPlaces = 2) {
    if ($value === null || $value === '') return true;
    $str = (string)$value;
    $decimalIndex = strpos($str, '.');
    if ($decimalIndex === false) return true; // Нет десятичной точки
    $decimalPart = substr($str, $decimalIndex + 1);
    return strlen($decimalPart) <= $maxPlaces;
}

if ($method === 'GET') {
    if (!RateLimiter::check('cashiers_get', 60, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Слишком много запросов. Попробуйте позже.']);
        exit();
    }
    
    $user_id_int = intval($user_id);
    $endpoint = '/cashiers/' . $user_id_int . '?api_token=' . urlencode($api_token);
    $result = $client->get($endpoint);
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Connection error: ' . $result['error'],
            'cashiers' => []
        ]);
    } elseif ($result['http_code'] === 200) {
        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in cashiers.php GET: " . json_last_error_msg());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Ошибка обработки данных',
                'cashiers' => []
            ]);
            exit();
        }
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo json_encode([
                'success' => true,
                'cashiers' => $data['cashiers'] ?? []
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'cashiers' => []
            ]);
        }
    } else {
        http_response_code($result['http_code']);
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching cashiers (HTTP ' . $result['http_code'] . ')',
            'cashiers' => []
        ]);
    }
    
} elseif ($method === 'POST') {
    requireCSRFToken();
    
    $data = safeJsonDecode();
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат данных или размер запроса слишком большой']);
        exit();
    }
    
    if (!$data || !isset($data['name']) || empty(trim($data['name']))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Название кассы обязательно']);
        exit();
    }
    
    $data['name'] = sanitizeInput(trim($data['name']), 255);
    if (isset($data['description'])) {
        $data['description'] = sanitizeInput(trim($data['description']), 1000);
    }
    
    if (!isset($data['webhook_url']) || empty(trim($data['webhook_url']))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'URL для уведомлений обязателен']);
        exit();
    }
    
    $webhook_url = trim($data['webhook_url']);
    if (!validateWebhookURL($webhook_url)) {
        logSecurityEvent('invalid_webhook_url_create', ['url' => $webhook_url, 'user_id' => $user_id]);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректный URL для уведомлений. URL должен начинаться с https:// и не быть внутренним адресом']);
        exit();
    }
    
    $allowed_currencies = ['ton', 'jetton'];
    $currency = isset($data['currency']) && in_array($data['currency'], $allowed_currencies) ? $data['currency'] : 'ton';
    
    $jetton_address = null;
    if ($currency === 'jetton') {
        if (!isset($data['jetton_address']) || empty(trim($data['jetton_address']))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Адрес джетона обязателен']);
            exit();
        }
        $jetton_address = trim($data['jetton_address']);
    }
    
    $min_amount = isset($data['min_amount']) ? (float)$data['min_amount'] : 0.01;
    if ($min_amount < 0.01) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Минимальная сумма должна быть не менее 0.01']);
        exit();
    }
    if (!validateDecimalPlaces($data['min_amount'] ?? null, 2)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Минимальная сумма должна иметь не более 2 знаков после запятой']);
        exit();
    }
    
    $max_amount = null;
    if (isset($data['max_amount']) && $data['max_amount'] !== null && $data['max_amount'] !== '') {
        $max_amount = (float)$data['max_amount'];
        if ($max_amount <= 0 || $max_amount < $min_amount) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Максимальная сумма должна быть больше минимальной']);
            exit();
        }
        if (!validateDecimalPlaces($data['max_amount'], 2)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Максимальная сумма должна иметь не более 2 знаков после запятой']);
            exit();
        }
    }
    
    $allowed_categories = ['Электронная коммерция', 'Фриланс услуги', 'Консультации', 'Образовательные услуги', 'Другое'];
    $category = null;
    if (isset($data['category']) && in_array($data['category'], $allowed_categories)) {
        $category = $data['category'];
    }
    
    $payload = [
        'user_id' => intval($user_id),
        'api_token' => $api_token,
        'name' => trim($data['name']),
        'description' => isset($data['description']) ? trim($data['description']) : null,
        'category' => $category,
        'currency' => $currency,
        'min_amount' => $min_amount,
        'max_amount' => $max_amount,
        'webhook_url' => $webhook_url,
        'jetton_address' => $jetton_address
    ];
    
    $result = $client->post('/create_cashier', $payload);
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Ошибка соединения: ' . $result['error']
        ]);
        exit();
    }
    
    if ($result['http_code'] === 200) {
        $data = json_decode($result['response'], true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo json_encode([
                'success' => true,
                'cashier' => $data['cashier'],
                'cashier_id' => $data['cashier']['id'] ?? $data['cashier_id'] ?? null
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Неизвестная ошибка при создании кассы'
            ]);
        }
    } else {
        http_response_code($result['http_code']);
        $error_data = json_decode($result['response'], true);
        echo json_encode([
            'success' => false, 
            'message' => $error_data['detail'] ?? 'Error creating cashier'
        ]);
    }
    
} elseif ($method === 'PUT') {
    requireCSRFToken();
    
    $data = safeJsonDecode();
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат данных или размер запроса слишком большой']);
        exit();
    }
    
    if (!$data || !isset($data['cashier_id']) || !isset($data['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
        exit();
    }
    
    $cashier_id = intval($data['cashier_id']);
    $status = $data['status'];
    
    if (!in_array($status, ['active', 'inactive'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректный статус']);
        exit();
    }
    
    $endpoint = '/cashier/' . $cashier_id . '/status?user_id=' . intval($user_id) . '&api_token=' . urlencode($api_token) . '&status=' . urlencode($status);
    $result = $client->request('POST', $endpoint);
    
    error_log("Cashier status update - Endpoint: $endpoint");
    error_log("Cashier status update - HTTP Code: " . $result['http_code']);
    error_log("Cashier status update - Response: " . $result['response']);
    error_log("Cashier status update - CURL Error: " . ($result['error'] ?? 'none'));
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Ошибка соединения: ' . $result['error']
        ]);
        exit();
    }
    
    if ($result['http_code'] === 200) {
        $data = json_decode($result['response'], true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo json_encode([
                'success' => true, 
                'message' => isset($data['message']) ? $data['message'] : 'Статус обновлен'
            ]);
        } else {
            http_response_code(500);
            $error_msg = 'Ошибка обновления статуса';
            if ($data && isset($data['detail'])) {
                $error_msg = is_string($data['detail']) ? $data['detail'] : 'Ошибка обновления статуса';
            } elseif ($data && isset($data['message'])) {
                $error_msg = is_string($data['message']) ? $data['message'] : 'Ошибка обновления статуса';
            }
            error_log("Cashier status update failed - Data: " . print_r($data, true));
            echo json_encode([
                'success' => false, 
                'message' => $error_msg
            ]);
        }
    } else {
        http_response_code($result['http_code']);
        $error_data = json_decode($result['response'], true);
        $error_message = 'Ошибка обновления статуса';
        if ($error_data && is_array($error_data)) {
            if (isset($error_data['detail']) && is_string($error_data['detail'])) {
                $error_message = $error_data['detail'];
            } elseif (isset($error_data['message']) && is_string($error_data['message'])) {
                $error_message = $error_data['message'];
            }
        } elseif (is_string($result['response']) && !empty($result['response'])) {
            $error_message = $result['response'];
        }
        error_log("Cashier status update error - HTTP " . $result['http_code'] . ", Message: $error_message");
        echo json_encode([
            'success' => false, 
            'message' => $error_message
        ]);
    }
    
} elseif ($method === 'PATCH') {
    requireCSRFToken();
    
    if (!RateLimiter::check('update_cashier', 10, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Слишком много запросов. Попробуйте позже.']);
        exit();
    }
    
    // Безопасное чтение JSON с проверкой размера
    $data = safeJsonDecode();
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат данных или размер запроса слишком большой']);
        exit();
    }
    
    if (!$data || !isset($data['cashier_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
        exit();
    }
    
    if (isset($data['min_amount'])) {
        $min_amount = (float)$data['min_amount'];
        if ($min_amount < 0.01) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Минимальная сумма должна быть не менее 0.01']);
            exit();
        }
        if (!validateDecimalPlaces($data['min_amount'], 2)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Минимальная сумма должна иметь не более 2 знаков после запятой']);
            exit();
        }
    }
    
    if (isset($data['max_amount']) && $data['max_amount'] !== null && $data['max_amount'] !== '') {
        $max_amount = (float)$data['max_amount'];
        $min_amount_check = isset($data['min_amount']) ? (float)$data['min_amount'] : null;
        if ($max_amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Максимальная сумма должна быть больше 0']);
            exit();
        }
        if ($min_amount_check && $max_amount < $min_amount_check) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Максимальная сумма должна быть больше минимальной']);
            exit();
        }
        if (!validateDecimalPlaces($data['max_amount'], 2)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Максимальная сумма должна иметь не более 2 знаков после запятой']);
            exit();
        }
    }
    
    $cashier_id = intval($data['cashier_id']);
    
    $payload = [
        'user_id' => intval($user_id),
        'api_token' => $api_token
    ];
    
    if (isset($data['name']) && trim($data['name']) !== '') {
        $payload['name'] = sanitizeInput(trim($data['name']), 255);
    }
    
    if (isset($data['description'])) {
        $description = trim($data['description']);
        $payload['description'] = $description !== '' ? sanitizeInput($description, 1000) : null;
    }
    
    if (isset($data['category'])) {
        $category = trim($data['category']);
        $payload['category'] = $category !== '' ? $category : null;
    }
    
    if (isset($data['min_amount'])) {
        $payload['min_amount'] = (float)$data['min_amount'];
    }
    
    if (isset($data['max_amount'])) {
        $payload['max_amount'] = ($data['max_amount'] !== null && $data['max_amount'] !== '') ? (float)$data['max_amount'] : null;
    }
    
    if (isset($data['webhook_url'])) {
        $webhook_url = sanitizeInput(trim($data['webhook_url']), 500);
        if (strlen($webhook_url) > 500) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'URL для уведомлений слишком длинный (максимум 500 символов)']);
            exit();
        }
        if (validateWebhookURL($webhook_url)) {
            $payload['webhook_url'] = $webhook_url;
        } else {
            logSecurityEvent('invalid_webhook_url_update', ['url' => substr($webhook_url, 0, 50) . '...', 'user_id' => $user_id]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Некорректный URL для уведомлений. URL должен начинаться с https://']);
            exit();
        }
    }
    
    $updateFields = array_diff_key($payload, ['user_id' => '', 'api_token' => '']);
    if (count($updateFields) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указаны поля для обновления']);
        exit();
    }
    
    error_log("Cashier update - Cashier ID: $cashier_id, Payload: " . json_encode($payload));
    
    $result = $client->put('/cashier/' . $cashier_id, $payload);
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Ошибка соединения: ' . $result['error']
        ]);
        exit();
    }
    
    error_log("Cashier update response - HTTP Code: " . $result['http_code'] . ", Response: " . $result['response']);
    
    if ($result['http_code'] === 200) {
        $data = json_decode($result['response'], true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo json_encode(['success' => true, 'cashier' => $data['cashier']]);
        } else {
            http_response_code(500);
            $error_msg = 'Ошибка обновления настроек';
            if ($data && isset($data['detail'])) {
                $error_msg = is_string($data['detail']) ? $data['detail'] : $error_msg;
            } elseif ($data && isset($data['message'])) {
                $error_msg = is_string($data['message']) ? $data['message'] : $error_msg;
            } elseif ($data && isset($data['error'])) {
                $error_msg = is_string($data['error']) ? $data['error'] : $error_msg;
            }
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
    } else {
        http_response_code($result['http_code']);
        $error_data = json_decode($result['response'], true);
        $error_message = 'Ошибка обновления настроек';
        if ($error_data && is_array($error_data)) {
            if (isset($error_data['detail']) && is_string($error_data['detail'])) {
                $error_message = $error_data['detail'];
            } elseif (isset($error_data['message']) && is_string($error_data['message'])) {
                $error_message = $error_data['message'];
            } elseif (isset($error_data['error']) && is_string($error_data['error'])) {
                $error_message = $error_data['error'];
            }
        } elseif (is_string($result['response']) && !empty($result['response'])) {
            $error_message = $result['response'];
        }
        
        if (stripos($error_message, 'Update failed') !== false && $result['http_code'] === 500) {
            $get_result = $client->get('/cashier/' . $cashier_id . '?user_id=' . intval($user_id) . '&api_token=' . urlencode($api_token));
            if ($get_result['http_code'] === 200) {
                $cashier_data = json_decode($get_result['response'], true);
                if ($cashier_data && isset($cashier_data['cashier'])) {
                    http_response_code(200);
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Настройки сохранены',
                        'cashier' => $cashier_data['cashier']
                    ]);
                    exit();
                }
            }
            // Если не удалось получить данные кассы, показываем более понятное сообщение
            $error_message = 'Данные не изменились или произошла ошибка при обновлении';
        }
        
        echo json_encode(['success' => false, 'message' => $error_message]);
    }
    
} elseif ($method === 'DELETE') {
    requireCSRFToken();
    
    if (!RateLimiter::check('delete_cashier', 5, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Слишком много запросов. Попробуйте позже.']);
        exit();
    }
    
    // Безопасное чтение JSON с проверкой размера
    $data = safeJsonDecode();
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный формат данных или размер запроса слишком большой']);
        exit();
    }
    
    if (!$data || !isset($data['cashier_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
        exit();
    }
    
    logSecurityEvent('cashier_delete_attempt', ['cashier_id' => $data['cashier_id'], 'user_id' => $user_id]);
    
    $cashier_id = intval($data['cashier_id']);
    
    $endpoint = '/cashier/' . $cashier_id . '?user_id=' . intval($user_id) . '&api_token=' . urlencode($api_token);
    $result = $client->delete($endpoint);
    
    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Ошибка соединения: ' . $result['error']
        ]);
        exit();
    }
    
    if ($result['http_code'] === 200) {
        $data = json_decode($result['response'], true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo json_encode(['success' => true, 'message' => 'Касса успешно удалена']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ошибка удаления кассы']);
        }
    } else {
        http_response_code($result['http_code']);
        $error_data = json_decode($result['response'], true);
        echo json_encode(['success' => false, 'message' => $error_data['detail'] ?? 'Ошибка удаления кассы']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

