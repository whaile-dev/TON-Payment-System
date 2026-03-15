<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/cold_wallet_config.php');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

requireCSRFToken();
$data = safeJsonDecode();
if ($data === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$enabled = isset($data['enabled']) ? (bool)$data['enabled'] : false;
$address = isset($data['address']) ? trim((string)$data['address']) : '';
$label = isset($data['label']) ? trim((string)$data['label']) : 'SafePal S1';
$threshold = isset($data['threshold_ton']) ? (float)$data['threshold_ton'] : 1000.0;

if ($threshold < 0.01 || $threshold > 1e9) {
    echo json_encode(['success' => false, 'message' => 'Некорректный порог']);
    exit();
}

if ($address !== '' && !validateTONAddress($address)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат адреса TON']);
    exit();
}

$label = mb_substr($label, 0, 100);
$address = mb_substr($address, 0, 100);

$conn = getCore()->getConn();
$conn->query("CREATE TABLE IF NOT EXISTS ColdWalletSettings (
    id INT PRIMARY KEY DEFAULT 1,
    enabled TINYINT NOT NULL DEFAULT 1,
    address VARCHAR(100) NOT NULL DEFAULT '',
    label VARCHAR(100) NOT NULL DEFAULT 'SafePal S1',
    threshold_ton DECIMAL(10,2) NOT NULL DEFAULT 1000,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$stmt = $conn->prepare("UPDATE ColdWalletSettings SET enabled = ?, address = ?, label = ?, threshold_ton = ? WHERE id = 1");
$en = $enabled ? 1 : 0;
$stmt->bind_param("issd", $en, $address, $label, $threshold);
$stmt->execute();
if ($stmt->affected_rows === 0) {
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO ColdWalletSettings (id, enabled, address, label, threshold_ton) VALUES (1, ?, ?, ?, ?)");
    $stmt->bind_param("issd", $en, $address, $label, $threshold);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt->close();
}

echo json_encode(['success' => true, 'message' => 'Сохранено']);
