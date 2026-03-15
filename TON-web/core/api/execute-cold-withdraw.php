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

require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
$config = getConfig();
$admins = $config['admins'] ?? [];
$admin_emails = is_array($admins) ? array_map('strval', $admins) : [];
$current_email = isset($_SESSION['email']) ? trim((string)$_SESSION['email']) : '';
if (!in_array($current_email, $admin_emails, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

requireCSRFToken();
$data = safeJsonDecode();
if ($data === null || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$id = (int)$data['id'];
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit();
}

$conn = getCore()->getConn();
$stmt = $conn->prepare("SELECT id, user_id, cashier_id, amount, wallet, currency, api_token FROM PendingColdWithdraw WHERE id = ? AND status = 'pending'");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Заявка не найдена или уже выполнена']);
    exit();
}

$site_url = $config['site']['url'] ?? 'https://pay.whaile.ru';
$withdraw_port = $config['site']['withdraw_port'] ?? 2998;
$withdraw_api = $site_url . ':' . $withdraw_port;
$withdrawClient = getHttpClient($withdraw_api);
$payload = [
    'cashier_id' => (int)$row['cashier_id'],
    'amount' => (float)$row['amount'],
    'wallet' => $row['wallet'],
    'api_token' => $row['api_token']
];
$result = $withdrawClient->post('/withdraw', $payload);

if ($result['error']) {
    echo json_encode(['success' => false, 'message' => 'Ошибка соединения: ' . $result['error']]);
    exit();
}

if ($result['http_code'] === 200) {
    $stmt = $conn->prepare("UPDATE PendingColdWithdraw SET status = 'done', executed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logSecurityEvent('withdraw_cold_executed', ['pending_id' => $id, 'cashier_id' => $row['cashier_id'], 'amount' => $row['amount']]);
    echo json_encode(['success' => true, 'message' => 'Вывод выполнен', 'data' => json_decode($result['response'], true)]);
} else {
    $err = json_decode($result['response'], true);
    $detail = $err['detail'] ?? $result['response'];
    $stmt = $conn->prepare("UPDATE PendingColdWithdraw SET status = 'failed', executed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => false, 'message' => $detail]);
}
