<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
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

if (!RateLimiter::check('export_all_cashiers', 10, 60)) {
    http_response_code(429);
    die('Too many requests. Please try again later.');
}

$user_id = $_SESSION['id'];
$user_id_int = intval($user_id);

$conn = getCore()->getConn();
$stmt = $conn->prepare("SELECT id, name, description, category, currency, status, created_at, webhook_url, balance FROM Cashiers WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
$cashiers = [];
while ($row = $result->fetch_assoc()) {
    $cashiers[] = $row;
}
$stmt->close();

if (empty($cashiers)) {
    http_response_code(404);
    die('No cashiers found');
}

$all_transactions = [];

$stmt = $conn->prepare("
    SELECT td.id, td.time_recorded, td.price, td.status, td.hash, 'TON' as currency, td.cashier_id, c.name as cashier_name
    FROM TONDeposit td 
    INNER JOIN Cashiers c ON td.cashier_id = c.id 
    WHERE c.user_id = ?
    ORDER BY td.time_recorded DESC
");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_transactions[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("
    SELECT jd.id, jd.time_recorded, jd.price, jd.status, jd.hash, 'JETTON' as currency, jd.cashier_id, c.name as cashier_name
    FROM JETTONDeposit jd 
    INNER JOIN Cashiers c ON jd.cashier_id = c.id 
    WHERE c.user_id = ?
    ORDER BY jd.time_recorded DESC
");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_transactions[] = $row;
}
$stmt->close();

usort($all_transactions, function($a, $b) {
    return strtotime($b['time_recorded']) - strtotime($a['time_recorded']);
});

$transactions_by_cashier = [];
foreach ($all_transactions as $tx) {
    $cashier_id = $tx['cashier_id'] ?? 0;
    if (!isset($transactions_by_cashier[$cashier_id])) {
        $transactions_by_cashier[$cashier_id] = [];
    }
    $transactions_by_cashier[$cashier_id][] = $tx;
}

$filename = 'all_cashiers_report_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

fputcsv($output, ['Отчет по всем кассам'], ';');
fputcsv($output, ['Дата создания отчета', date('d.m.Y H:i:s')], ';');
fputcsv($output, ['Всего касс', count($cashiers)], ';');
fputcsv($output, ['Всего транзакций', count($all_transactions)], ';');
fputcsv($output, [], ';');

fputcsv($output, ['Сводка по кассам'], ';');
fputcsv($output, ['ID кассы', 'Название', 'Валюта', 'Статус', 'Баланс', 'Транзакций', 'Создана'], ';');

foreach ($cashiers as $cashier) {
    $cashier_id = $cashier['id'];
    $cashier_transactions = $transactions_by_cashier[$cashier_id] ?? [];
    
    $balance = floatval($cashier['balance'] ?? 0);
    
    fputcsv($output, [
        $cashier_id,
        $cashier['name'] ?? '',
        strtoupper($cashier['currency'] ?? 'TON'),
        ($cashier['status'] ?? 'inactive') === 'active' ? 'Активна' : 'Неактивна',
        number_format($balance, 2, '.', ''),
        count($cashier_transactions),
        isset($cashier['created_at']) ? date('d.m.Y H:i', strtotime($cashier['created_at'])) : ''
    ], ';');
}

fputcsv($output, [], ';');
fputcsv($output, [], ';');

foreach ($cashiers as $cashier) {
    $cashier_id = $cashier['id'];
    $cashier_transactions = $transactions_by_cashier[$cashier_id] ?? [];
    
    if (empty($cashier_transactions)) {
        continue;
    }
    
    fputcsv($output, ['Касса: ' . ($cashier['name'] ?? 'Без названия') . ' (ID: ' . $cashier_id . ')'], ';');
    fputcsv($output, ['Валюта', strtoupper($cashier['currency'] ?? 'TON')], ';');
    fputcsv($output, ['Статус', ($cashier['status'] ?? 'inactive') === 'active' ? 'Активна' : 'Неактивна'], ';');
    fputcsv($output, ['Создана', isset($cashier['created_at']) ? date('d.m.Y H:i', strtotime($cashier['created_at'])) : ''], ';');
    fputcsv($output, [], ';');
    
    fputcsv($output, ['ID', 'Дата и время', 'Сумма', 'Валюта', 'Статус', 'Хеш транзакции'], ';');
    
    foreach ($cashier_transactions as $tx) {
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
            strtoupper($tx['currency'] ?? 'TON'),
            $status_text,
            $tx['hash'] ?? ''
        ], ';');
    }
    
    fputcsv($output, [], ';');
    fputcsv($output, [], ';');
}

fclose($output);
exit();
?>

