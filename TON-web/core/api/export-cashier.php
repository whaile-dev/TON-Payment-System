<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/http_client.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');

if (!getCore()->isAuth()) {
    http_response_code(401);
    die('Unauthorized');
}

$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || !verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    die('Invalid CSRF token. Token must be provided via X-CSRF-Token header.');
}

if (!RateLimiter::check('export_cashier', 10, 60)) {
    http_response_code(429);
    die('Too many requests. Please try again later.');
}

$user_id = $_SESSION['id'];
$cashier_id = isset($_GET['cashier_id']) ? intval($_GET['cashier_id']) : 0;

if ($cashier_id <= 0) {
    http_response_code(400);
    die('Cashier ID is required');
}

$user_id_int = intval($user_id);
$api_token = $_SESSION['api_token'] ?? null;

if (!$api_token) {
    http_response_code(401);
    die('API token not found');
}

$client = getHttpClient();
$endpoint = '/cashier/' . $cashier_id . '?user_id=' . $user_id_int . '&api_token=' . urlencode($api_token);
$result = $client->get($endpoint);

if ($result['error']) {
    http_response_code(500);
    die('Ошибка соединения: ' . $result['error']);
}

if ($result['http_code'] !== 200) {
    http_response_code($result['http_code']);
    die('Failed to fetch cashier data');
}

$cashier_data = json_decode($result['response'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error in export-cashier.php: " . json_last_error_msg());
    http_response_code(500);
    die('Invalid response format');
}
if (!$cashier_data || !isset($cashier_data['cashier'])) {
    http_response_code(500);
    die('Invalid response format');
}

$cashier = $cashier_data['cashier'];

$cashier_user_id = (string)($cashier['user_id'] ?? '');
$user_id_str = (string)$user_id;

if ($cashier_user_id !== $user_id_str) {
    http_response_code(403);
    die('Access denied');
}

$conn = getCore()->getConn();
$webhook_url = $cashier['webhook_url'] ?? '';

if (!empty($webhook_url)) {
    if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        error_log("Invalid webhook_url in export-cashier.php: " . substr($webhook_url, 0, 100));
        http_response_code(500);
        die('Invalid webhook URL format');
    }
    
    if (preg_match('/[;\'"\\x00\\n\\r]/', $webhook_url)) {
        error_log("Potentially dangerous webhook_url in export-cashier.php: " . substr($webhook_url, 0, 100));
        http_response_code(500);
        die('Invalid webhook URL format');
    }
    
    if (strlen($webhook_url) > 2000) {
        error_log("Webhook URL too long in export-cashier.php");
        http_response_code(500);
        die('Invalid webhook URL format');
    }
}

$all_transactions = [];

if (!empty($webhook_url)) {
    $stmt = $conn->prepare("
        SELECT id, time_recorded, price, status, hash, 'TON' as currency
        FROM TONDeposit 
        WHERE callback_url = ? AND user_id = ?
        ORDER BY time_recorded DESC
    ");
    $user_id_int = intval($user_id);
    $stmt->bind_param("si", $webhook_url, $user_id_int);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_transactions[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, time_recorded, price, status, hash, 'JETTON' as currency
        FROM JETTONDeposit 
        WHERE callback_url = ? AND user_id = ?
        ORDER BY time_recorded DESC
    ");
    $stmt->bind_param("si", $webhook_url, $user_id_int);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_transactions[] = $row;
    }
    $stmt->close();
}

usort($all_transactions, function($a, $b) {
    return strtotime($b['time_recorded']) - strtotime($a['time_recorded']);
});

$filename = 'cashier_' . $cashier_id . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

fputcsv($output, ['Экспорт данных кассы'], ';');
fputcsv($output, [], ';');
fputcsv($output, ['ID кассы', $cashier_id], ';');
fputcsv($output, ['Название', $cashier['name'] ?? ''], ';');
fputcsv($output, ['Статус', ($cashier['status'] ?? 'inactive') === 'active' ? 'Активна' : 'Неактивна'], ';');
fputcsv($output, ['Валюта', strtoupper($cashier['currency'] ?? 'TON')], ';');
fputcsv($output, ['Создана', isset($cashier['created_at']) ? date('d.m.Y H:i', strtotime($cashier['created_at'])) : ''], ';');
fputcsv($output, ['Всего транзакций', count($all_transactions)], ';');
fputcsv($output, [], ';');

fputcsv($output, ['Транзакции'], ';');
fputcsv($output, ['ID', 'Дата и время', 'Сумма', 'Валюта', 'Статус', 'Хеш транзакции'], ';');

foreach ($all_transactions as $tx) {
    $status_text = '';
    if ($tx['status'] === 'success') {
        $status_text = 'Успешно';
    } elseif ($tx['status'] === 'pending') {
        $status_text = 'Ожидание';
    } else {
        $status_text = 'Отменено';
    }
    
    fputcsv($output, [
        $tx['id'],
        isset($tx['time_recorded']) ? date('d.m.Y H:i:s', strtotime($tx['time_recorded'])) : '',
        number_format($tx['price'], 2, '.', ''),
        $tx['currency'] ?? 'TON',
        $status_text,
        $tx['hash'] ?? ''
    ], ';');
}

fclose($output);
exit();
?>

