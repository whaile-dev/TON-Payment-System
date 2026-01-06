<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
$config = getConfig();
$site_name = $config['site']['name'] ?? 'TonPay';
$site_url = $config['site']['url'] ?? 'https://pay.whaile.ru';
$api_port = $config['site']['api_port'] ?? 3000;
$withdraw_port = $config['site']['withdraw_port'] ?? 2998;
$api_base = $site_url . ':' . $api_port;
$withdraw_api = $site_url . ':' . $withdraw_port;

if (!getCore()->isAuth()) {
    header('Location: /');
    exit();
}

$user_id = $_SESSION['id'];
$user_email = $_SESSION['email'];

$cashier_id = isset($_GET['cashier_id']) ? intval($_GET['cashier_id']) : 0;

if ($cashier_id <= 0) {
    header('Location: /dashboard.php');
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/http_client.php');

$user_id_int = intval($user_id);
$api_token = $_SESSION['api_token'] ?? null;

if (!$api_token) {
    $_SESSION['error_message'] = 'API токен не найден. Пожалуйста, войдите снова.';
    header('Location: /dashboard.php');
    exit();
}

$client = getHttpClient();
$endpoint = '/cashier/' . $cashier_id . '?user_id=' . $user_id_int . '&api_token=' . urlencode($api_token);
$result = $client->get($endpoint);

if ($result['error']) {
    $_SESSION['error_message'] = "Ошибка соединения: " . $result['error'];
    header('Location: /dashboard.php');
    exit();
}

if ($result['http_code'] !== 200) {
    $_SESSION['error_message'] = "Не удалось загрузить данные кассы. Код ошибки: " . $result['http_code'];
    header('Location: /dashboard.php');
    exit();
}

$response = $result['response'];

$cashier_data = json_decode($response, true);
if (!$cashier_data || !isset($cashier_data['cashier'])) {
    $_SESSION['error_message'] = "Неверный формат ответа от сервера";
    header('Location: /dashboard.php');
    exit();
}

$cashier_user_id = intval($cashier_data['cashier']['user_id'] ?? 0);
$user_id_int = intval($user_id);

if ($cashier_user_id !== $user_id_int || $cashier_user_id <= 0) {
    $_SESSION['error_message'] = "У вас нет доступа к этой кассе";
    header('Location: /dashboard.php');
    exit();
}

$cashier = $cashier_data['cashier'];
$cashier_id_int = intval($cashier_id);
$cashier_currency = strtolower($cashier['currency'] ?? 'ton');

$conn = getCore()->getConn();
$webhook_url = $cashier['webhook_url'] ?? '';

if (isset($_GET['action']) && $_GET['action'] === 'get_all_transactions') {
    header('Content-Type: application/json; charset=utf-8');
    
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $limit = min(max($limit, 1), 100);
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $offset = max($offset, 0);
    
    $has_status_filter = false;
    $status_value = null;
    if ($status_filter !== 'all') {
        $allowed_statuses = ['success', 'pending', 'failed', 'expired', 'cancelled', 'duplicate', 'nohash'];
        if (in_array($status_filter, $allowed_statuses, true)) {
            $has_status_filter = true;
            $status_value = $status_filter;
        }
    }

    $currency_upper = strtoupper($cashier_currency);
    
    if ($has_status_filter) {
        if ($cashier_currency === 'jetton') {
            $sql = "
                (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'deposit' as type, wallet, payload, NULL as request_id
                 FROM JETTONDeposit 
                 WHERE cashier_id = ? AND status = ?)
                UNION ALL
                (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'withdraw' as type, wallet, NULL as payload, request_id
                 FROM JETTONWithdraw 
                 WHERE cashier_id = ? AND status = ?)
                ORDER BY time_recorded DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare error in csh.php: " . $conn->error);
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
                exit();
            }
            $stmt->bind_param("isisii", $cashier_id_int, $status_value, $cashier_id_int, $status_value, $limit, $offset);
        } else {
            $sql = "
                (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'deposit' as type, wallet, payload, NULL as request_id
                 FROM TONDeposit 
                 WHERE cashier_id = ? AND status = ?)
                UNION ALL
                (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'withdraw' as type, wallet, NULL as payload, request_id
                 FROM TONWithdraw 
                 WHERE cashier_id = ? AND status = ?)
                ORDER BY time_recorded DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare error in csh.php: " . $conn->error);
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
                exit();
            }
            $stmt->bind_param("isisii", $cashier_id_int, $status_value, $cashier_id_int, $status_value, $limit, $offset);
        }
    } else {
        if ($cashier_currency === 'jetton') {
            $sql = "
                (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'deposit' as type, wallet, payload, NULL as request_id
                 FROM JETTONDeposit 
                 WHERE cashier_id = ?)
                UNION ALL
                (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'withdraw' as type, wallet, NULL as payload, request_id
                 FROM JETTONWithdraw 
                 WHERE cashier_id = ?)
                ORDER BY time_recorded DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare error in csh.php: " . $conn->error);
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
                exit();
            }
            $stmt->bind_param("iiii", $cashier_id_int, $cashier_id_int, $limit, $offset);
        } else {
            $sql = "
                (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'deposit' as type, wallet, payload, NULL as request_id
                 FROM TONDeposit 
                 WHERE cashier_id = ?)
                UNION ALL
                (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'withdraw' as type, wallet, NULL as payload, request_id
                 FROM TONWithdraw 
                 WHERE cashier_id = ?)
                ORDER BY time_recorded DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare error in csh.php: " . $conn->error);
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
                exit();
            }
            $stmt->bind_param("iiii", $cashier_id_int, $cashier_id_int, $limit, $offset);
        }
    }
    if (!$stmt->execute()) {
        error_log("SQL execute error in csh.php: " . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
        $stmt->close();
        exit();
    }
    $result = $stmt->get_result();
    $all_transactions = [];
    while ($row = $result->fetch_assoc()) {
        $all_transactions[] = $row;
    }
    $stmt->close();
    
    $count_stmt = null;
    $total_count = count($all_transactions);
    
    if ($has_status_filter) {
        if ($cashier_currency === 'jetton') {
            $count_sql = "
                SELECT COUNT(*) as total
                FROM (
                    (SELECT id FROM JETTONDeposit WHERE cashier_id = ? AND status = ?)
                    UNION ALL
                    (SELECT id FROM JETTONWithdraw WHERE cashier_id = ? AND status = ?)
                ) as all_txs
            ";
            $count_stmt = $conn->prepare($count_sql);
            if ($count_stmt) {
                $count_stmt->bind_param("isis", $cashier_id_int, $status_value, $cashier_id_int, $status_value);
                $count_stmt->execute();
            }
        } else {
            $count_sql = "
                SELECT COUNT(*) as total
                FROM (
                    (SELECT id FROM TONDeposit WHERE cashier_id = ? AND status = ?)
                    UNION ALL
                    (SELECT id FROM TONWithdraw WHERE cashier_id = ? AND status = ?)
                ) as all_txs
            ";
            $count_stmt = $conn->prepare($count_sql);
            if ($count_stmt) {
                $count_stmt->bind_param("isis", $cashier_id_int, $status_value, $cashier_id_int, $status_value);
                $count_stmt->execute();
            }
        }
    } else {
        if ($cashier_currency === 'jetton') {
            $count_sql = "
                SELECT COUNT(*) as total
                FROM (
                    (SELECT id FROM JETTONDeposit WHERE cashier_id = ?)
                    UNION ALL
                    (SELECT id FROM JETTONWithdraw WHERE cashier_id = ?)
                ) as all_txs
            ";
        } else {
            $count_sql = "
                SELECT COUNT(*) as total
                FROM (
                    (SELECT id FROM TONDeposit WHERE cashier_id = ?)
                    UNION ALL
                    (SELECT id FROM TONWithdraw WHERE cashier_id = ?)
                ) as all_txs
            ";
        }
        $count_stmt = $conn->prepare($count_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("ii", $cashier_id_int, $cashier_id_int);
            $count_stmt->execute();
        }
    }
    
    if ($count_stmt) {
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $total_count = isset($count_row['total']) ? (int)$count_row['total'] : 0;
        $count_stmt->close();
    }
    
    echo json_encode([
        'success' => true, 
        'transactions' => $all_transactions,
        'total' => intval($total_count),
        'limit' => $limit,
        'offset' => $offset
    ]);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_chart_data') {
    header('Content-Type: application/json; charset=utf-8');
    
    $period = isset($_GET['period']) ? $_GET['period'] : 'month';
    $chart_type = isset($_GET['chart_type']) ? $_GET['chart_type'] : 'amount';
    $transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : 'all';
    
    $date_from = '';
    switch ($period) {
        case 'week':
            $date_from = date('Y-m-d H:i:s', strtotime('-7 days'));
            break;
        case 'month':
            $date_from = date('Y-m-d H:i:s', strtotime('-1 month'));
            break;
        case 'quarter':
            $date_from = date('Y-m-d H:i:s', strtotime('-3 months'));
            break;
        case 'year':
            $date_from = date('Y-m-d H:i:s', strtotime('-1 year'));
            break;
        default:
            $date_from = date('Y-m-d H:i:s', strtotime('-1 month'));
    }
    
    $include_deposits = ($transaction_type === 'all' || $transaction_type === 'deposits');
    $include_withdraws = ($transaction_type === 'all' || $transaction_type === 'withdraws');
    
    if ($chart_type === 'count') {
        $sql_parts = [];
        $params = [];
        $param_types = '';
        
        if ($include_deposits) {
            if ($cashier_currency === 'jetton') {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, COUNT(*) as count, SUM(price) as amount
                    FROM JETTONDeposit 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            } else {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, COUNT(*) as count, SUM(price) as amount
                    FROM TONDeposit 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            }
        }
        
        if ($include_withdraws) {
            if (!empty($sql_parts)) {
                $sql_parts[] = "UNION ALL";
            }
            if ($cashier_currency === 'jetton') {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, COUNT(*) as count, SUM(price) as amount
                    FROM JETTONWithdraw 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            } else {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, COUNT(*) as count, SUM(price) as amount
                    FROM TONWithdraw 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            }
        }
        
        if (empty($sql_parts)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit();
        }
        
        $sql = "
            SELECT 
                date,
                SUM(count) as count,
                SUM(amount) as amount
            FROM (" . implode(' ', $sql_parts) . ") as all_data
            GROUP BY date
            ORDER BY date ASC
        ";
    } else {
        $sql_parts = [];
        $params = [];
        $param_types = '';
        
        if ($include_deposits) {
            if ($cashier_currency === 'jetton') {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, SUM(price) as amount, COUNT(*) as count
                    FROM JETTONDeposit 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            } else {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, SUM(price) as amount, COUNT(*) as count
                    FROM TONDeposit 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            }
        }
        
        if ($include_withdraws) {
            if (!empty($sql_parts)) {
                $sql_parts[] = "UNION ALL";
            }
            if ($cashier_currency === 'jetton') {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, -SUM(price) as amount, COUNT(*) as count
                    FROM JETTONWithdraw 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            } else {
                $sql_parts[] = "
                    SELECT DATE(time_recorded) as date, -SUM(price) as amount, COUNT(*) as count
                    FROM TONWithdraw 
                    WHERE cashier_id = ? AND time_recorded >= ? AND status = 'success'
                    GROUP BY DATE(time_recorded)
                ";
                $params = array_merge($params, [$cashier_id_int, $date_from]);
                $param_types .= 'is';
            }
        }
        
        if (empty($sql_parts)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit();
        }
        
        $sql = "
            SELECT 
                date,
                SUM(amount) as amount,
                SUM(count) as count
            FROM (" . implode(' ', $sql_parts) . ") as all_data
            GROUP BY date
            ORDER BY date ASC
        ";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL prepare error in csh.php (chart): " . $conn->error);
        echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
        exit();
    }
    
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("SQL execute error in csh.php (chart): " . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Ошибка обработки запроса']);
        $stmt->close();
        exit();
    }
    $result = $stmt->get_result();
    
    $chart_data = [];
    while ($row = $result->fetch_assoc()) {
        $amount = floatval($row['amount']);
        if ($amount > 1000000000 || $amount < -1000000000) {
            $amount = 0;
        }
        $count = intval($row['count']);
        if ($count < 0 || $count > 1000000) {
            $count = 0;
        }
        $chart_data[] = [
            'date' => $row['date'],
            'amount' => round($amount, 2),
            'count' => $count
        ];
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'data' => $chart_data, 'chart_type' => $chart_type, 'transaction_type' => $transaction_type]);
    exit();
}

$stats = [
    'total_balance' => 0,
    'total_transactions' => 0,
    'successful_transactions' => 0,
    'success_rate' => 0,
    'average_amount' => 0,
    'week_balance' => 0,
    'week_transactions' => 0
];

$week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

if ($cashier_currency === 'jetton') {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'success' THEN price ELSE 0 END) as total_balance,
                AVG(CASE WHEN status = 'success' THEN price ELSE NULL END) as avg_amount
            FROM JETTONDeposit 
            WHERE cashier_id = ?
        ");
        $stmt->bind_param("i", $cashier_id_int);
        $stmt->execute();
        $result = $stmt->get_result();
        $jetton_stats = $result->fetch_assoc();
        $stmt->close();
        
        $stats['total_transactions'] = $jetton_stats['total'] ?? 0;
        $stats['successful_transactions'] = $jetton_stats['successful'] ?? 0;
        $stats['total_balance'] = floatval($cashier['balance'] ?? 0);
        
        if ($stats['total_transactions'] > 0) $stats['success_rate'] = round(($stats['successful_transactions'] / $stats['total_transactions']) * 100, 1);
        if ($stats['successful_transactions'] > 0 && isset($jetton_stats['avg_amount']) && $jetton_stats['avg_amount'] !== null) $stats['average_amount'] = round($jetton_stats['avg_amount'], 2);
        
        $stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN status = 'success' THEN price ELSE 0 END) as week_balance,
                COUNT(*) as week_transactions
            FROM JETTONDeposit 
            WHERE cashier_id = ? AND time_recorded >= ?
        ");
        $stmt->bind_param("is", $cashier_id_int, $week_ago);
        $stmt->execute();
        $result = $stmt->get_result();
        $week_jetton = $result->fetch_assoc();
        $stmt->close();
        
        $stats['week_balance'] = $week_jetton['week_balance'] ?? 0;
        $stats['week_transactions'] = $week_jetton['week_transactions'] ?? 0;
    } else {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'success' THEN price ELSE 0 END) as total_balance,
                AVG(CASE WHEN status = 'success' THEN price ELSE NULL END) as avg_amount
            FROM TONDeposit 
            WHERE cashier_id = ?
        ");
        $stmt->bind_param("i", $cashier_id_int);
        $stmt->execute();
        $result = $stmt->get_result();
        $ton_stats = $result->fetch_assoc();
        $stmt->close();
        
        $stats['total_transactions'] = $ton_stats['total'] ?? 0;
        $stats['successful_transactions'] = $ton_stats['successful'] ?? 0;
        $stats['total_balance'] = floatval($cashier['balance'] ?? 0);
        
        if ($stats['total_transactions'] > 0) $stats['success_rate'] = round(($stats['successful_transactions'] / $stats['total_transactions']) * 100, 1);
        if ($stats['successful_transactions'] > 0 && isset($ton_stats['avg_amount']) && $ton_stats['avg_amount'] !== null) $stats['average_amount'] = round($ton_stats['avg_amount'], 2);

        $stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN status = 'success' THEN price ELSE 0 END) as week_balance,
                COUNT(*) as week_transactions
            FROM TONDeposit 
            WHERE cashier_id = ? AND time_recorded >= ?
        ");
        $stmt->bind_param("is", $cashier_id_int, $week_ago);
        $stmt->execute();
        $result = $stmt->get_result();
        $week_ton = $result->fetch_assoc();
        $stmt->close();
        
        $stats['week_balance'] = $week_ton['week_balance'] ?? 0;
        $stats['week_transactions'] = $week_ton['week_transactions'] ?? 0;
    }

    if ($cashier_currency === 'jetton') {
        $stmt = $conn->prepare("
            (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'deposit' as type
             FROM JETTONDeposit 
             WHERE cashier_id = ?
             ORDER BY time_recorded DESC LIMIT 10)
            UNION ALL
            (SELECT id, time_recorded, price, status, hash, 'JETTON' as currency, 'withdraw' as type
             FROM JETTONWithdraw 
             WHERE cashier_id = ?
             ORDER BY time_recorded DESC LIMIT 10)
            ORDER BY time_recorded DESC LIMIT 10
        ");
        $stmt->bind_param("ii", $cashier_id_int, $cashier_id_int);
    } else {
        $stmt = $conn->prepare("
            (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'deposit' as type
             FROM TONDeposit 
             WHERE cashier_id = ?
             ORDER BY time_recorded DESC LIMIT 10)
            UNION ALL
            (SELECT id, time_recorded, price, status, hash, 'TON' as currency, 'withdraw' as type
             FROM TONWithdraw 
             WHERE cashier_id = ?
             ORDER BY time_recorded DESC LIMIT 10)
            ORDER BY time_recorded DESC LIMIT 10
        ");
        $stmt->bind_param("ii", $cashier_id_int, $cashier_id_int);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_transactions = [];
    while ($row = $result->fetch_assoc()) {
        $recent_transactions[] = $row;
    }
    $stmt->close();

$created_date = isset($cashier['created_at']) ? date('d.m.Y', strtotime($cashier['created_at'])) : 'Не указана';
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <?php 
    require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');
    $csrf_token = generateCSRFToken();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Касса "<?php echo htmlspecialchars($cashier['name'] ?? 'Без названия'); ?>" | <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="apple-touch-icon" href="scripts/img/logo.svg">
    <link rel="stylesheet" href="scripts/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="scripts/libs/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="scripts/libs/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="scripts/css/custom.css" rel="stylesheet">
    <style>
        .navbar-actions {
            position: relative;
            overflow: visible !important;
        }

        .navbar .container {
            overflow: visible !important;
        }

        .glass-navbar {
            overflow: visible !important;
        }

        .navbar {
            overflow: visible !important;
        }

        .user-dropdown {
            position: relative;
            z-index: 1050;
        }

        .user-dropdown-btn {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            color: var(--ton-text);
            padding: 0.5rem 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-weight: 500;
            cursor: pointer;
        }

        .user-dropdown-btn:hover {
            background: var(--ton-card-hover);
            border-color: var(--ton-primary);
        }

        .user-dropdown-btn::after {
            content: '▼';
            font-size: 0.7rem;
            margin-left: 0.5rem;
            transition: transform 0.3s ease;
        }

        .user-dropdown-btn[aria-expanded="true"]::after {
            transform: rotate(180deg);
        }

        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: #1e1e2e;
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            width: 100%;
            min-width: 200px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            padding: 0.5rem 0;
        }

        [data-bs-theme="light"] .user-dropdown-menu {
            background: #ffffff;
        }

        .user-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .user-dropdown-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: var(--ton-text);
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .user-dropdown-item:hover {
            background: var(--ton-card-hover);
            color: var(--ton-primary);
        }

        .user-dropdown-item.text-danger {
            color: var(--ton-error) !important;
        }

        .user-dropdown-item.text-danger:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--ton-error) !important;
        }

        .user-dropdown-divider {
            height: 1px;
            background: var(--ton-border);
            margin: 0.5rem 0;
            border: none;
        }

        [data-bs-theme="light"] .user-dropdown-menu {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* Кастомный селект */
        .custom-select-wrapper {
            position: relative;
            width: 100%;
        }

        .custom-select {
            width: 100%;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            padding-right: 3rem;
            color: var(--ton-text);
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 48px;
            display: flex;
            align-items: center;
        }

        .custom-select:hover {
            border-color: var(--ton-primary);
        }

        .custom-select.active {
            border-color: var(--ton-primary);
            box-shadow: 0 0 0 2px rgba(0, 136, 204, 0.1);
        }

        .custom-select-placeholder {
            color: var(--ton-text-secondary);
        }

        .custom-select-text {
            color: var(--ton-text);
        }

        .custom-select-arrow {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            transition: transform 0.3s ease;
            color: var(--ton-text-secondary);
        }

        .custom-select.active .custom-select-arrow {
            transform: translateY(-50%) rotate(180deg);
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1e1e2e;
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            margin-top: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }

        [data-bs-theme="light"] .custom-select-dropdown {
            background: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .custom-select-dropdown.show {
            display: block;
        }

        .custom-select-option {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
            color: var(--ton-text);
        }

        .custom-select-option:hover {
            background: var(--ton-card-hover);
        }

        .custom-select-option.selected {
            background: rgba(0, 136, 204, 0.1);
            color: var(--ton-primary);
        }

        .custom-select-option:first-child {
            border-radius: 12px 12px 0 0;
        }

        .custom-select-option:last-child {
            border-radius: 0 0 12px 12px;
        }

        .cashier-section {
            padding-top: 100px;
            min-height: 100vh;
            background: var(--ton-bg);
        }

        .cashier-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--ton-border);
        }

        .cashier-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .cashier-name {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--ton-text);
        }

        .cashier-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.1);
            color: var(--ton-success);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--ton-error);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .cashier-id {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .cashier-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .export-buttons-group {
            display: flex;
            flex-direction: row;
            gap: 0.75rem;
            flex-wrap: nowrap;
            align-items: center;
        }

        .btn-cashier-action {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            font-size: 0.95rem;
        }

        .btn-cashier-primary {
            background: var(--ton-primary);
            border: none;
            color: white;
        }

        .btn-cashier-primary:hover {
            background: var(--ton-primary-dark);
            color: white !important;
        }

        .btn-cashier-outline {
            background: transparent;
            border: 1px solid var(--ton-border);
            color: var(--ton-text);
        }

        .btn-cashier-outline:hover {
            border-color: var(--ton-primary);
            color: var(--ton-primary);
            background: rgba(0, 136, 204, 0.05);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 136, 204, 0.15);
        }

        .btn-cashier-outline:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(0, 136, 204, 0.1);
        }

        .btn-cashier-danger {
            background: transparent;
            border: 1px solid var(--ton-error);
            color: var(--ton-error);
        }

        .btn-cashier-danger:hover {
            background: var(--ton-error);
            color: white;
        }

        .cashier-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        #integration .main-content {
            max-width: 100%;
            overflow-wrap: break-word;
        }

        #integration .chart-container {
            max-width: 100%;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        #integration .integration-code {
            max-width: 100%;
            word-break: break-all;
            overflow-wrap: break-word;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: rgba(0, 136, 204, 0.3);
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: var(--ton-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }

        .stat-change.positive {
            color: var(--ton-success);
        }

        .stat-change.negative {
            color: var(--ton-error);
        }

        .chart-container {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        #chartContainer {
            position: relative;
            height: 400px;
            width: 100%;
            max-width: 100%;
            background: transparent;
            border-radius: 12px;
            padding: 1rem;
            overflow: hidden;
        }
        
        #chartContainer canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        .chart-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--ton-text);
        }

        .chart-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .chart-actions .custom-select-wrapper {
            width: auto;
            min-width: 120px;
        }
        
        .chart-actions .custom-select {
            white-space: nowrap;
        }

        .chart-period {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--ton-text);
            font-size: 0.9rem;
        }

        .chart-placeholder {
            height: 300px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .recent-transactions {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .transactions-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--ton-text);
        }

        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .transaction-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .transaction-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .transaction-icon.success {
            background: rgba(34, 197, 94, 0.1);
            color: var(--ton-success);
        }

        .transaction-icon.pending {
            background: rgba(234, 179, 8, 0.1);
            color: var(--ton-warning);
        }

        .transaction-icon.withdraw {
            background: rgba(239, 68, 68, 0.1);
        }

        .transaction-icon.withdraw.success {
            color: #ef4444;
        }

        .transaction-details {
            display: flex;
            flex-direction: column;
        }

        .transaction-amount {
            font-weight: 600;
            color: var(--ton-text);
        }

        .transaction-amount.withdraw {
            color: #ef4444;
        }

        .transaction-amount.deposit {
            color: var(--ton-success);
        }

        .tx-actions {
            text-align: center;
            white-space: nowrap;
        }

        .btn-view-details {
            background: var(--ton-bg-secondary);
            border: 1px solid var(--ton-border);
            border-radius: 8px;
            padding: 0.5rem;
            color: var(--ton-text);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .btn-view-details:hover {
            background: var(--ton-primary);
            color: white;
            border-color: var(--ton-primary);
        }

        .transaction-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }

        .transaction-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .transaction-modal-content {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .transaction-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--ton-border);
        }

        .transaction-modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--ton-text);
        }

        .transaction-modal-close {
            background: transparent;
            border: none;
            color: var(--ton-text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .transaction-modal-close:hover {
            color: var(--ton-text);
        }

        .transaction-detail-row {
            display: flex;
            flex-direction: column;
            margin-bottom: 1.5rem;
        }

        .transaction-detail-label {
            font-size: 0.85rem;
            color: var(--ton-text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .transaction-detail-value {
            font-size: 1rem;
            color: var(--ton-text);
            word-break: break-all;
            padding: 0.75rem;
            background: var(--ton-bg-secondary);
            border-radius: 8px;
            border: 1px solid var(--ton-border);
        }

        .transaction-detail-value.empty {
            color: var(--ton-text-secondary);
            font-style: italic;
        }

        .transaction-detail-value.payload {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }

        .transaction-meta {
            font-size: 0.8rem;
            color: var(--ton-text-secondary);
        }

        .transaction-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .sidebar-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--ton-text);
        }

        .sidebar-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ton-primary);
            margin-bottom: 0.5rem;
        }

        .integration-code {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 1rem;
            font-family: monospace;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            overflow-x: auto;
            color: var(--ton-text);
            border: 1px solid var(--ton-border);
        }

        [data-bs-theme="dark"] .integration-code,
        :not([data-bs-theme]) .integration-code {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.9);
            border-color: rgba(255, 255, 255, 0.15);
        }

        [data-bs-theme="light"] .integration-code {
            background: rgba(0, 0, 0, 0.05);
            color: var(--ton-text);
            border-color: rgba(0, 0, 0, 0.1);
        }

        .copy-btn {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--ton-text);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .copy-btn:hover {
            background: var(--ton-card-hover);
        }

        .webhook-url {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-family: monospace;
            font-size: 0.8rem;
            margin-bottom: 1rem;
            overflow-x: auto;
            word-break: break-all;
            color: var(--ton-text);
            border: 1px solid var(--ton-border);
        }

        [data-bs-theme="dark"] .webhook-url,
        :not([data-bs-theme]) .webhook-url {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.9);
            border-color: rgba(255, 255, 255, 0.15);
        }

        [data-bs-theme="light"] .webhook-url {
            background: rgba(0, 0, 0, 0.05);
            color: var(--ton-text);
            border-color: rgba(0, 0, 0, 0.1);
        }

        .alert {
            color: var(--ton-text);
        }

        .alert-info {
            background: rgba(0, 136, 204, 0.1);
            border: 1px solid rgba(0, 136, 204, 0.3);
            color: var(--ton-text);
        }

        [data-bs-theme="dark"] .alert-info,
        :not([data-bs-theme]) .alert-info {
            background: rgba(0, 136, 204, 0.15);
            border-color: rgba(0, 136, 204, 0.4);
            color: rgba(255, 255, 255, 0.95);
        }

        [data-bs-theme="dark"] .alert-info strong,
        :not([data-bs-theme]) .alert-info strong {
            color: rgba(255, 255, 255, 1);
        }

        [data-bs-theme="dark"] .alert-info ul,
        :not([data-bs-theme]) .alert-info ul {
            color: rgba(255, 255, 255, 0.9);
        }

        [data-bs-theme="light"] .alert-info {
            background: rgba(0, 136, 204, 0.1);
            border-color: rgba(0, 136, 204, 0.3);
            color: var(--ton-text);
        }

        .api-keys-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .api-key-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
        }

        .api-key-name {
            font-weight: 500;
            color: var(--ton-text);
        }

        .api-key-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-api-action {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            background: transparent;
            border: 1px solid var(--ton-border);
            color: var(--ton-text);
            transition: all 0.3s ease;
        }

        .btn-api-action:hover {
            background: var(--ton-card-hover);
        }

        .btn-api-copy {
            border-color: var(--ton-primary);
            color: var(--ton-primary);
        }

        .btn-api-revoke {
            border-color: var(--ton-error);
            color: var(--ton-error);
        }

        .nav-tabs-cashier {
            border-bottom: 1px solid var(--ton-border);
            margin-bottom: 2rem;
        }

        .nav-tabs-cashier .nav-link {
            background: transparent;
            border: none;
            color: var(--ton-text-secondary);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 0;
            position: relative;
        }

        .nav-tabs-cashier .nav-link.active {
            color: var(--ton-primary);
            background: transparent;
        }

        .nav-tabs-cashier .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--ton-primary);
        }

        .nav-tabs-cashier .nav-link:hover {
            color: var(--ton-accent);
        }

        .settings-section {
            display: none;
        }

        .settings-section.active {
            display: block;
        }

        .settings-form {
            max-width: 600px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--ton-text);
        }

        .form-control {
            width: 100%;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--ton-text);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--ton-primary);
            box-shadow: 0 0 0 2px rgba(0, 136, 204, 0.1);
        }

        .form-select {
            width: 100%;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--ton-text);
            transition: all 0.3s ease;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%230088cc' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--ton-primary);
            box-shadow: 0 0 0 2px rgba(0, 136, 204, 0.1);
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--ton-text-secondary);
            margin-top: 0.25rem;
        }

        .danger-zone {
            border: 1px solid var(--ton-error);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .danger-title {
            color: var(--ton-error);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .danger-description {
            color: var(--ton-text-secondary);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        @media (min-width: 768px) {
            .export-buttons-group {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
            }
            
            .chart-actions .export-buttons-group {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
            }
        }

        @media (max-width: 991.98px) {
            .cashier-content {
                grid-template-columns: 1fr;
            }

            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 767.98px) {
            .cashier-section {
                padding-top: calc(80px + 2rem);
            }

            .cashier-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .cashier-actions {
                flex-direction: column;
            }

            .export-buttons-group {
                width: 100%;
                flex-direction: column;
                flex-wrap: nowrap;
                gap: 0.75rem;
            }

            .btn-cashier-action {
                width: 100%;
            }

            .stats-overview {
                grid-template-columns: 1fr;
            }

            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .chart-actions {
                width: 100%;
                flex-direction: column;
                gap: 1rem;
                justify-content: flex-start;
            }
            
            .chart-actions .custom-select-wrapper {
                width: 100%;
                min-width: auto;
            }
            
            .chart-actions .export-buttons-group {
                width: 100%;
                flex-direction: column;
                flex-wrap: nowrap;
                gap: 0.75rem;
            }

            .chart-period {
                flex: 1;
            }
            
            #chartContainer {
                height: 300px;
                padding: 0.5rem;
            }
            
            .chart-container {
                padding: 1rem;
            }

            .transactions-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .nav-tabs-cashier .nav-link {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }

        .transactions-wrapper {
            width: 100%;
        }
        
        .transactions-table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            background: var(--ton-card);
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            background: transparent;
            color: var(--ton-text);
            font-size: 0.9rem;
            margin: 0;
        }
        
        .transactions-table thead {
            background: var(--ton-bg-secondary);
        }
        
        .transactions-table thead th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--ton-text);
            border-bottom: 2px solid var(--ton-border);
            white-space: nowrap;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .transactions-table tbody tr {
            border-bottom: 1px solid var(--ton-border);
            transition: background-color 0.2s ease;
            background: transparent;
        }
        
        .transactions-table tbody tr:hover {
            background: var(--ton-bg-secondary);
        }
        
        .transactions-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .transactions-table tbody td {
            padding: 1rem;
            vertical-align: middle;
            color: var(--ton-text);
        }
        
        .transactions-table .tx-date {
            white-space: nowrap;
            min-width: 140px;
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }
        
        .transactions-table .tx-amount {
            font-weight: 600;
            white-space: nowrap;
            min-width: 100px;
            color: var(--ton-text);
            font-size: 1rem;
        }
        
        .transactions-table .tx-amount-deposit {
            color: #00b894;
        }
        
        .transactions-table .tx-amount-withdraw {
            color: #d63031;
        }
        
        .transactions-table .tx-currency {
            white-space: nowrap;
            min-width: 80px;
            color: var(--ton-text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .transactions-table .tx-status {
            white-space: nowrap;
            min-width: 120px;
        }
        
        .transactions-table .tx-hash {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: var(--ton-text-secondary);
            white-space: nowrap;
            min-width: 140px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tx-hash-link {
            color: var(--ton-primary);
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-block;
        }

        .tx-hash-link:hover {
            color: var(--ton-primary);
            text-decoration: underline;
            opacity: 0.8;
        }

        .tx-hash-empty {
            color: var(--ton-text-secondary);
        }

        .tx-hash-link-inline {
            color: var(--ton-primary);
            text-decoration: none;
            transition: all 0.2s ease;
            font-family: 'Courier New', monospace;
        }

        .tx-hash-link-inline:hover {
            color: var(--ton-primary);
            text-decoration: underline;
            opacity: 0.8;
        }

        .tx-hash-link-detail {
            color: var(--ton-primary);
            text-decoration: none;
            transition: all 0.2s ease;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            display: inline-flex;
            align-items: center;
        }

        .tx-hash-link-detail:hover {
            color: var(--ton-primary);
            text-decoration: underline;
            opacity: 0.8;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .status-badge.status-success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }
        
        .status-badge.status-pending {
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
        }
        
        .status-badge.status-expired {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .status-badge.status-cancelled {
            background: rgba(107, 114, 128, 0.15);
            color: #6b7280;
        }
        
        .transactions-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding: 1rem;
            background: var(--ton-card);
            border-radius: 12px;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .pagination-info {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }
        
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .pagination-btn {
            min-width: 36px;
            height: 36px;
            padding: 0.5rem 0.75rem;
            background: var(--ton-bg-secondary);
            border: 1px solid var(--ton-border);
            border-radius: 8px;
            color: var(--ton-text);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .pagination-btn:hover:not(.pagination-btn-disabled):not(.pagination-btn-active) {
            background: var(--ton-primary);
            color: white;
            border-color: var(--ton-primary);
        }
        
        .pagination-btn-active {
            background: var(--ton-primary);
            color: white;
            border-color: var(--ton-primary);
        }
        
        .pagination-btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-dots {
            padding: 0 0.5rem;
            color: var(--ton-text-secondary);
        }

        @media (max-width: 991.98px) {
            .transactions-table {
                font-size: 0.85rem;
            }

            .transactions-table .tx-date {
                min-width: 120px;
            }
            
            .transactions-table .tx-hash {
                min-width: 100px;
                max-width: 150px;
            }
        }
        
        @media (max-width: 767.98px) {
            .transactions-pagination {
                flex-direction: column;
                align-items: stretch;
            }
            
            .pagination-controls {
                justify-content: center;
            }
            
            .transactions-table .tx-date {
                min-width: 100px;
            }
            
            .transactions-table .tx-amount {
                min-width: 80px;
            }
            
            .transactions-table .tx-currency {
                min-width: 60px;
            }
            
            .transactions-table .tx-status {
                min-width: 100px;
            }
            
            .transactions-table .tx-hash {
                min-width: 80px;
                max-width: 120px;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

<?php
$home_link = 'index.php';
$show_integration = true;
$nav_links = [
    ['href' => 'dashboard.php', 'text' => 'Кабинет'],
    ['href' => 'index.php#features', 'text' => 'Возможности'],
    ['href' => 'index.php#integration', 'text' => 'Интеграция']
];
require_once('core/blocks/navbar.php');
?>

<section class="cashier-section">
    <div class="container">
        <div class="cashier-header">
            <div class="cashier-title">
                <h1 class="cashier-name"><?php echo htmlspecialchars($cashier['name'] ?? 'Без названия'); ?></h1>
                <div class="cashier-status <?php echo ($cashier['status'] ?? 'inactive') === 'active' ? 'status-active' : 'status-inactive'; ?>">
                    <?php echo ($cashier['status'] ?? 'inactive') === 'active' ? 'Активна' : 'Неактивна'; ?>
                </div>
            </div>
            <div class="cashier-id">ID: <?php echo htmlspecialchars($cashier_id); ?> • Создана: <?php echo $created_date; ?></div>

            <div class="cashier-actions">
                <div class="export-buttons-group">
                    <button class="btn-cashier-action btn-cashier-outline" onclick="exportCashierData('csv')">
                        <i class="fas fa-file-csv me-2"></i> CSV
                    </button>
                    <button class="btn-cashier-action btn-cashier-outline" onclick="exportCashierData('html')">
                        <i class="fas fa-file-code me-2"></i> HTML с графиками
                    </button>
                </div>
                <button class="btn-cashier-action btn-cashier-danger" onclick="toggleCashierStatus()">
                    <?php if (($cashier['status'] ?? 'inactive') === 'active'): ?>
                        <i class="fas fa-ban me-2"></i> Деактивировать
                    <?php else: ?>
                        <i class="fas fa-check me-2"></i> Активировать
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs nav-tabs-cashier" id="cashierTabs">
            <li class="nav-item">
                <a class="nav-link active" id="overview-tab" data-bs-toggle="tab" href="#overview">Обзор</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="transactions-tab" data-bs-toggle="tab" href="#transactions">Транзакции</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="integration-tab" data-bs-toggle="tab" href="#integration">API</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="settings-tab" data-bs-toggle="tab" href="#settings">Настройки</a>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="overview">
                <div class="cashier-content">
                    <div class="main-content">
                        <div class="stats-overview">
                            <div class="stat-card">
                                <div class="stat-value" id="cashierBalance"><?php echo number_format($stats['total_balance'], 2, '.', ' '); ?> <?php echo strtoupper($cashier['currency'] ?? 'TON'); ?></div>
                                <div class="stat-label">Текущий баланс</div>
                                <div class="stat-change <?php echo $stats['week_balance'] > 0 ? 'positive' : ''; ?>">
                                    <?php if ($stats['week_balance'] > 0): ?>
                                        <span>↑</span>
                                        <span>+<?php echo number_format($stats['week_balance'], 2, '.', ' '); ?> <?php echo strtoupper($cashier['currency'] ?? 'TON'); ?> за неделю</span>
                                    <?php else: ?>
                                        <span>—</span>
                                        <span>Нет изменений</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($stats['total_transactions'], 0, ',', ' '); ?></div>
                                <div class="stat-label">Всего транзакций</div>
                                <div class="stat-change <?php echo $stats['week_transactions'] > 0 ? 'positive' : ''; ?>">
                                    <?php if ($stats['week_transactions'] > 0): ?>
                                        <span>↑</span>
                                        <span>+<?php echo $stats['week_transactions']; ?> за неделю</span>
                                    <?php else: ?>
                                        <span>—</span>
                                        <span>Нет транзакций</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-value"><?php echo $stats['success_rate']; ?>%</div>
                                <div class="stat-label">Успешных платежей</div>
                                <div class="stat-change <?php echo $stats['success_rate'] >= 95 ? 'positive' : ($stats['success_rate'] >= 80 ? '' : 'negative'); ?>">
                                    <?php if ($stats['success_rate'] >= 95): ?>
                                        <span>↑</span>
                                        <span>Отлично</span>
                                    <?php elseif ($stats['success_rate'] >= 80): ?>
                                        <span>→</span>
                                        <span>Нормально</span>
                                    <?php else: ?>
                                        <span>↓</span>
                                        <span>Требует внимания</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="chart-container">
                            <div class="chart-header">
                                <h3 class="chart-title" id="chartTitle">Сумма прибыли</h3>
                                <div class="chart-actions">
                                    <div class="custom-select-wrapper" style="margin-right: 0.5rem;">
                                        <div class="custom-select" id="chartTypeSelect" data-value="amount">
                                            <span class="custom-select-text">Сумма</span>
                                            <span class="custom-select-arrow">▼</span>
                                        </div>
                                        <div class="custom-select-dropdown" id="chartTypeDropdown">
                                            <div class="custom-select-option selected" data-value="amount">Сумма</div>
                                            <div class="custom-select-option" data-value="count">Количество</div>
                                        </div>
                                    </div>
                                    <div class="custom-select-wrapper" style="margin-right: 0.5rem;">
                                        <div class="custom-select" id="transactionTypeSelect" data-value="all">
                                            <span class="custom-select-text">Все</span>
                                            <span class="custom-select-arrow">▼</span>
                                        </div>
                                        <div class="custom-select-dropdown" id="transactionTypeDropdown">
                                            <div class="custom-select-option selected" data-value="all">Все</div>
                                            <div class="custom-select-option" data-value="deposits">Входящие</div>
                                            <div class="custom-select-option" data-value="withdraws">Исходящие</div>
                                        </div>
                                    </div>
                                    <div class="custom-select-wrapper">
                                        <div class="custom-select" id="chartPeriodSelect" data-value="month">
                                            <span class="custom-select-text">За месяц</span>
                                            <span class="custom-select-arrow">▼</span>
                                        </div>
                                        <div class="custom-select-dropdown" id="chartPeriodDropdown">
                                            <div class="custom-select-option" data-value="week">За неделю</div>
                                            <div class="custom-select-option selected" data-value="month">За месяц</div>
                                            <div class="custom-select-option" data-value="quarter">За квартал</div>
                                            <div class="custom-select-option" data-value="year">За год</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="chartContainer" style="position: relative; height: 400px;">
                                <canvas id="activityChart"></canvas>
                            </div>
                        </div>

                        <div class="recent-transactions">
                            <div class="transactions-header">
                                <h3 class="transactions-title">Последние транзакции</h3>
                                <a href="#" class="btn-cashier-outline btn-cashier-action" onclick="showAllTransactions(); return false;">Все транзакции</a>
                            </div>

                            <div class="transactions-list">
                                <?php if (empty($recent_transactions)): ?>
                                    <div class="text-center p-4 text-ton-secondary">
                                        Нет транзакций
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_transactions as $tx): ?>
                                        <?php
                                        $tx_type = $tx['type'] ?? 'deposit';
                                        $is_withdraw = $tx_type === 'withdraw';
                                        $status_class = $tx['status'] === 'success' ? 'success' : ($tx['status'] === 'pending' ? 'pending' : '');
                                        $status_text = $tx['status'] === 'success' ? 'Успешно' : ($tx['status'] === 'pending' ? 'Ожидание' : ucfirst($tx['status']));
                                        $status_badge = $tx['status'] === 'success' ? 'status-active' : 'status-inactive';
                                        $time_ago = '';
                                        if (isset($tx['time_recorded'])) {
                                            $tx_time = strtotime($tx['time_recorded']);
                                            $diff = time() - $tx_time;
                                            if ($diff < 60) {
                                                $time_ago = $diff . ' сек назад';
                                            } elseif ($diff < 3600) {
                                                $time_ago = floor($diff / 60) . ' мин назад';
                                            } elseif ($diff < 86400) {
                                                $time_ago = floor($diff / 3600) . ' час' . (floor($diff / 3600) > 1 ? 'ов' : '') . ' назад';
                                            } else {
                                                $time_ago = date('d.m.Y H:i', $tx_time);
                                            }
                                        }
                                        $currency = strtoupper($tx['currency'] ?? 'TON');
                                        $amount_prefix = $is_withdraw ? '-' : '+';
                                        $amount_class = $is_withdraw ? 'withdraw' : 'deposit';
                                        ?>
                                        <div class="transaction-item">
                                            <div class="transaction-info">
                                                <div class="transaction-icon <?php echo $status_class; ?> <?php echo $amount_class; ?>">
                                                    <?php 
                                                    if ($is_withdraw) {
                                                        echo $tx['status'] === 'success' ? '↓' : ($tx['status'] === 'pending' ? '⏳' : '✗');
                                                    } else {
                                                        echo $tx['status'] === 'success' ? '✓' : ($tx['status'] === 'pending' ? '⏳' : '✗');
                                                    }
                                                    ?>
                                                </div>
                                                <div class="transaction-details">
                                                    <div class="transaction-amount <?php echo $amount_class; ?>"><?php echo $amount_prefix; ?><?php echo number_format($tx['price'], 2, '.', ' '); ?> <?php echo $currency; ?></div>
                                                    <div class="transaction-meta"><?php echo $is_withdraw ? 'Вывод' : 'Пополнение'; ?> • <?php echo $time_ago; ?><?php if (!empty($tx['hash'])): ?> • <a href="https://tonviewer.com/transaction/<?php echo htmlspecialchars($tx['hash']); ?>" target="_blank" rel="noopener noreferrer" class="tx-hash-link-inline" title="Открыть в TonViewer"><?php echo substr($tx['hash'], 0, 8) . '...'; ?></a><?php endif; ?></div>
                                                </div>
                                            </div>
                                            <div class="transaction-status <?php echo $status_badge; ?>"><?php echo $status_text; ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="sidebar">
                        <div class="sidebar-card">
                            <h4 class="sidebar-title">Средний чек</h4>
                            <div class="sidebar-value">
                                <?php echo $stats['average_amount'] > 0 ? number_format($stats['average_amount'], 2, '.', ' ') : '0.00'; ?> <?php echo strtoupper($cashier['currency'] ?? 'TON'); ?>
                            </div>
                        </div>

                        <?php if (!empty($cashier['webhook_url'])): ?>
                        <div class="sidebar-card">
                            <h4 class="sidebar-title">Webhook URL</h4>
                            <div class="webhook-url" id="webhookUrl">
                                <?php echo htmlspecialchars($cashier['webhook_url']); ?>
                            </div>
                            <button class="copy-btn" onclick="copyWebhookUrl()">
                                <i class="fas fa-copy me-1"></i> Скопировать URL
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="transactions">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Все транзакции</h3>
                        <div class="chart-actions">
                            <div class="export-buttons-group">
                                <button class="btn-cashier-outline btn-cashier-action" style="display: flex;justify-content: center;gap: 0.2rem;" onclick="exportCashierData('csv')">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button class="btn-cashier-outline btn-cashier-action" style="display: flex;justify-content: center;gap: 0.2rem;" onclick="exportCashierData('html')">
                                    <i class="fas fa-file-code me-1"></i> HTML
                                </button>
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" style="min-width: 150px;" id="transactionStatusSelect" data-value="all">
                                    <span class="custom-select-text">Все</span>
                                    <span class="custom-select-arrow">▼</span>
                                </div>
                                <div class="custom-select-dropdown" id="transactionStatusDropdown">
                                    <div class="custom-select-option selected" data-value="all">Все</div>
                                    <div class="custom-select-option" data-value="success">Успешные</div>
                                    <div class="custom-select-option" data-value="pending">Ожидающие</div>
                                    <div class="custom-select-option" data-value="cancelled">Отмененные</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="transactionsTableContainer" style="min-height: 500px;">
                        <div class="text-center p-4 text-ton-secondary">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <p class="mt-2">Загрузка транзакций...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="transactionModal" class="transaction-modal">
                <div class="transaction-modal-content">
                    <div class="transaction-modal-header">
                        <h3 class="transaction-modal-title">Детали транзакции</h3>
                        <button class="transaction-modal-close" onclick="closeTransactionModal()">&times;</button>
                    </div>
                    <div id="transactionModalBody"></div>
                </div>
            </div>

            <div class="tab-pane fade" id="integration">
                <div class="cashier-content">
                    <div class="main-content">
                        <div class="chart-container">
                            <h3 class="chart-title">Документация по интеграции</h3>
                            <p>Используйте наш REST API для интеграции платежей <?php echo htmlspecialchars($site_name); ?> в ваше приложение или сайт.</p>

                            <h5 class="mt-4">API Токен</h5>
                            <p>Для работы с API требуется API токен. Вы можете получить его в <a href="/dashboard.php" style="color: var(--ton-primary); text-decoration: underline;">вашем кабинете</a> в разделе "API Токен".</p>
                            <div class="alert alert-info" style="background: rgba(0, 136, 204, 0.1); border: 1px solid rgba(0, 136, 204, 0.3); border-radius: 12px; padding: 1rem; margin-top: 1rem;">
                                <strong><i class="fas fa-info-circle me-2"></i>Где взять API токен:</strong>
                                <ul style="margin: 0.5rem 0 0 1.5rem; padding: 0;">
                                    <li>Перейдите в <a href="/dashboard.php" style="color: var(--ton-primary); text-decoration: underline;">ваш кабинет</a></li>
                                    <li>Найдите раздел "API Токен"</li>
                                    <li>Скопируйте токен или сгенерируйте новый при необходимости</li>
                                </ul>
                            </div>

                            <h5 class="mt-4">Базовый URL</h5>
                            <div class="integration-code">
                                <?php echo htmlspecialchars($api_base); ?>
                            </div>

                            <h5 class="mt-4">Создание платежа</h5>
                            <p>Для создания платежа отправьте POST запрос на <code>/create_payment</code>:</p>
                            <div class="integration-code">
                                POST <?php echo htmlspecialchars($api_base); ?>/create_payment<br>
                                Content-Type: application/json<br><br>
                                {<br>
                                &nbsp;&nbsp;"cashier_id": <?php echo htmlspecialchars($cashier_id); ?>,<br>
                                &nbsp;&nbsp;"amount": 10.50,<br>
                                &nbsp;&nbsp;"wallet": "UQ...",<br>
                                &nbsp;&nbsp;"currency": "ton",<br>
                                &nbsp;&nbsp;"payload": "order_12345",<br>
                                &nbsp;&nbsp;"return_url": "https://example.com/success"<br>
                                }
                            </div>
                            <p class="text-ton-secondary mt-2" style="font-size: 0.9rem;"><i class="fas fa-info-circle me-1"></i> Аутентификация не требуется. Валюта опциональна - если не указана, берется из настроек кассы.</p>

                            <h5 class="mt-4">Создание платежной ссылки</h5>
                            <p>Самый простой способ - создать прямую ссылку на страницу оплаты:</p>
                            <div class="integration-code">
                                <?php echo htmlspecialchars($site_url); ?>/payment.php?<br>
                                &nbsp;&nbsp;cashier_id=<?php echo htmlspecialchars($cashier_id); ?>&<br>
                                &nbsp;&nbsp;amount=10.50&<br>
                                &nbsp;&nbsp;wallet=UQ...&<br>
                                &nbsp;&nbsp;return_url=https://example.com/success
                            </div>

                            <h5 class="mt-4">Проверка статуса платежа</h5>
                            <p>Для проверки статуса отправьте GET запрос:</p>
                            <div class="integration-code">
                                GET <?php echo htmlspecialchars($api_base); ?>/payment_status/{currency}/{payment_id}<br><br>
                                Пример:<br>
                                GET <?php echo htmlspecialchars($api_base); ?>/payment_status/ton/123
                            </div>

                            <h5 class="mt-4">Вывод средств</h5>
                            <p>Для вывода средств отправьте POST запрос на <code>/withdraw</code>:</p>
                            <div class="integration-code">
                                POST <?php echo htmlspecialchars($withdraw_api); ?>/withdraw<br>
                                Content-Type: application/json<br><br>
                                {<br>
                                &nbsp;&nbsp;"cashier_id": <?php echo htmlspecialchars($cashier_id); ?>,<br>
                                &nbsp;&nbsp;"amount": 1.5,<br>
                                &nbsp;&nbsp;"wallet": "UQ...",<br>
                                &nbsp;&nbsp;"api_token": "ваш_api_токен"<br>
                                }
                            </div>
                            <p class="text-ton-secondary mt-2" style="font-size: 0.9rem;"><i class="fas fa-info-circle me-1"></i> Требуется API токен. Используется порт 2998, а не 3000.</p>

                            <div class="alert alert-info mt-4" style="background: rgba(0, 136, 204, 0.1); border: 1px solid rgba(0, 136, 204, 0.3); border-radius: 12px; padding: 1rem;">
                                <strong><i class="fas fa-lightbulb me-2"></i>Важно:</strong> При выводе средств комиссия блокчейна вычитается из суммы перевода. Пользователь получит сумму за вычетом комиссии.
                            </div>
                        </div>
                    </div>

                    <div class="sidebar">
                        <div class="sidebar-card">
                            <h4 class="sidebar-title">Полезные ссылки</h4>
                            <div class="d-flex flex-column gap-2">
                                <a href="docs.php#idocs_start" class="btn-cashier-outline btn-cashier-action text-start">
                                    <i class="fas fa-book me-2"></i> Полная документация
                                </a>
                                <a href="docs.php#idocs_create_payment" class="btn-cashier-outline btn-cashier-action text-start">
                                    <i class="fas fa-credit-card me-2"></i> Создание платежа
                                </a>
                                <a href="docs.php#idocs_payment_status" class="btn-cashier-outline btn-cashier-action text-start">
                                    <i class="fas fa-chart-line me-2"></i> Статус платежа
                                </a>
                                <a href="docs.php#idocs_withdraw" class="btn-cashier-outline btn-cashier-action text-start">
                                    <i class="fas fa-money-bill-wave me-2"></i> Вывод средств
                                </a>
                                <a href="docs.php#idocs_errors" class="btn-cashier-outline btn-cashier-action text-start">
                                    <i class="me-2">❓</i> Обработка ошибок
                                </a>
                            </div>
                        </div>

                        <div class="sidebar-card mt-3">
                            <h4 class="sidebar-title">ID кассы</h4>
                            <div class="integration-code" style="font-size: 1.1rem; font-weight: 600; text-align: center; padding: 1rem;">
                                <?php echo htmlspecialchars($cashier_id); ?>
                            </div>
                            <button class="btn-cashier-primary btn-cashier-action w-100 mt-2" onclick="copyToClipboard('<?php echo htmlspecialchars($cashier_id); ?>', this)">
                                <i class="fas fa-copy me-2"></i> Копировать ID
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="settings">
                <div class="settings-form">
                    <h3 class="chart-title mb-4">Настройки кассы</h3>

                    <div class="form-group">
                        <label class="form-label">Название кассы</label>
                        <input type="text" class="form-control" id="cashierName" value="<?php echo htmlspecialchars($cashier['name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" id="cashierDescription" rows="3" style="resize: none;"><?php echo htmlspecialchars($cashier['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Категория</label>
                        <div class="custom-select-wrapper">
                            <div class="custom-select" id="cashierCategorySelect" data-value="<?php echo htmlspecialchars($cashier['category'] ?? ''); ?>">
                                <span class="<?php echo empty($cashier['category']) ? 'custom-select-placeholder' : 'custom-select-text'; ?>">
                                    <?php echo !empty($cashier['category']) ? htmlspecialchars($cashier['category']) : 'Выберите категорию'; ?>
                                </span>
                                <span class="custom-select-arrow">▼</span>
                            </div>
                            <div class="custom-select-dropdown" id="cashierCategoryDropdown">
                                <div class="custom-select-option" data-value="">Выберите категорию</div>
                                <div class="custom-select-option <?php echo ($cashier['category'] ?? '') === 'Электронная коммерция' ? 'selected' : ''; ?>" data-value="Электронная коммерция">Электронная коммерция</div>
                                <div class="custom-select-option <?php echo ($cashier['category'] ?? '') === 'Фриланс услуги' ? 'selected' : ''; ?>" data-value="Фриланс услуги">Фриланс услуги</div>
                                <div class="custom-select-option <?php echo ($cashier['category'] ?? '') === 'Консультации' ? 'selected' : ''; ?>" data-value="Консультации">Консультации</div>
                                <div class="custom-select-option <?php echo ($cashier['category'] ?? '') === 'Образовательные услуги' ? 'selected' : ''; ?>" data-value="Образовательные услуги">Образовательные услуги</div>
                                <div class="custom-select-option <?php echo ($cashier['category'] ?? '') === 'Другое' ? 'selected' : ''; ?>" data-value="Другое">Другое</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Валюта</label>
                        <input type="text" class="form-control" id="cashierCurrency" value="<?php echo strtoupper($cashier['currency'] ?? 'TON'); ?>" readonly disabled style="background-color: var(--ton-bg-secondary); cursor: not-allowed;">
                        <div class="form-text">Валюта не может быть изменена после создания кассы</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Минимальная сумма платежа (<?php echo strtoupper($cashier['currency'] ?? 'TON'); ?>)</label>
                        <input type="number" class="form-control" id="cashierMinAmount" value="<?php echo $cashier['min_amount'] ?? '0.01'; ?>" step="0.01" min="0.01">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Максимальная сумма платежа (<?php echo strtoupper($cashier['currency'] ?? 'TON'); ?>)</label>
                        <input type="number" class="form-control" id="cashierMaxAmount" value="<?php echo $cashier['max_amount'] ?? ''; ?>" step="0.01" min="0" placeholder="Оставьте пустым для неограниченной суммы">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Webhook URL</label>
                        <input type="url" class="form-control" id="cashierWebhook" value="<?php echo htmlspecialchars($cashier['webhook_url'] ?? ''); ?>" required>
                        <div class="form-text">URL для получения уведомлений о платежах</div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="webhookEnabled" checked>
                        <label class="form-check-label" for="webhookEnabled">
                            Включить webhook уведомления
                        </label>
                    </div>

                    <button class="btn-cashier-action btn-cashier-primary" onclick="saveCashierSettings()">
                        <i class="me-2">💾</i> Сохранить изменения
                    </button>

                    <div class="danger-zone">
                        <h5 class="danger-title">Опасная зона</h5>
                        <p class="danger-description">
                            Эти действия необратимы. Пожалуйста, будьте осторожны.
                        </p>

                        <div class="d-flex flex-column gap-2">
                            <button class="btn-cashier-action btn-cashier-danger" onclick="toggleCashierStatus()">
                                <?php if (($cashier['status'] ?? 'inactive') === 'active'): ?>
                                    <i class="fas fa-ban me-2"></i> Деактивировать кассу
                                <?php else: ?>
                                    <i class="fas fa-check me-2"></i> Активировать кассу
                                <?php endif; ?>
                            </button>
                            <button class="btn-cashier-action btn-cashier-danger" onclick="deleteCashier()">
                                <i class="fas fa-trash me-2"></i> Удалить кассу
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once('core/blocks/auth-modal.php'); ?>
<?php require_once('core/blocks/footer.php') ?>

<script src="scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
<script src="scripts/js/app.js"></script>
<script>
    window.toggleCashierStatus = function() {
        const currentStatus = '<?php echo $cashier['status'] ?? 'inactive'; ?>';
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const cashierId = '<?php echo htmlspecialchars($cashier_id); ?>';
        
        const confirmMessage = `Вы уверены, что хотите ${newStatus === 'active' ? 'активировать' : 'деактивировать'} эту кассу?`;
        
        if (typeof showConfirm === 'function') {
            showConfirm(confirmMessage, () => {
                proceedWithStatusChange(cashierId, newStatus);
            });
        } else {
            if (confirm(confirmMessage)) {
                proceedWithStatusChange(cashierId, newStatus);
            }
        }
    };
    
    function proceedWithStatusChange(cashierId, newStatus) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            showNotification('Ошибка: CSRF токен не найден', 'error');
            return;
        }
        
        fetch('/core/api/cashiers.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                cashier_id: cashierId,
                status: newStatus
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result && result.success === true) {
                if (typeof showNotification === 'function') {
                    showNotification(`Касса ${newStatus === 'active' ? 'активирована' : 'деактивирована'}`, 'success');
                }
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                if (typeof showNotification === 'function') {
                    showNotification('Ошибка обновления статуса: ' + (result.message || 'Неизвестная ошибка'), 'error');
                }
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            showNotification('Ошибка обновления статуса кассы', 'error');
        });
    }

    window.saveCashierSettings = function() {
        const cashierId = parseInt('<?php echo intval($cashier_id); ?>');
        const categorySelect = document.getElementById('cashierCategorySelect');
        const category = categorySelect ? categorySelect.getAttribute('data-value') : '';
        
        const minAmountInput = document.getElementById('cashierMinAmount').value;
        let minAmount = parseFloat(minAmountInput);
        if (!minAmount || minAmount < 0.01) {
            showNotification('Минимальная сумма должна быть не менее 0.01', 'error');
            return;
        }
        if (!window.validateDecimalPlaces(minAmountInput, 2)) {
            showNotification('Минимальная сумма должна иметь не более 2 знаков после запятой', 'error');
            return;
        }
        
        const maxAmountInput = document.getElementById('cashierMaxAmount').value;
        let maxAmount = null;
        if (maxAmountInput && maxAmountInput.trim() !== '') {
            maxAmount = parseFloat(maxAmountInput);
            if (maxAmount <= 0 || maxAmount < minAmount) {
                showNotification('Максимальная сумма должна быть больше минимальной', 'error');
                return;
            }
            if (!window.validateDecimalPlaces(maxAmountInput, 2)) {
                showNotification('Максимальная сумма должна иметь не более 2 знаков после запятой', 'error');
                return;
            }
        }
        
        const data = {
            cashier_id: cashierId,
            name: document.getElementById('cashierName').value.trim(),
            description: document.getElementById('cashierDescription').value.trim(),
            min_amount: minAmount,
            max_amount: maxAmount,
            webhook_url: document.getElementById('cashierWebhook').value.trim()
        };
        
        if (category && category.trim() !== '') {
            data.category = category.trim();
        } else {
            data.category = '';
        }
        
        if (!data.name) {
            showNotification('Введите название кассы', 'error');
            return;
        }
        
        if (!data.webhook_url) {
            showNotification('Введите Webhook URL', 'error');
            return;
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            showNotification('Ошибка: CSRF токен не найден', 'error');
            return;
        }
        
        fetch('/core/api/cashiers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-HTTP-Method-Override': 'PATCH',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            const responseClone = response.clone();
            return response.json().then(data => {
                if (data && data.success === true) {
                    return data;
                }
                if (!response.ok) {
                    const errorMessage = data.message || data.detail || data.error || `HTTP error! status: ${response.status}`;
                    throw new Error(errorMessage);
                }
                return data;
            }).catch(parseError => {
                return responseClone.text().then(text => {
                    if (!response.ok) {
                        try {
                            const jsonData = JSON.parse(text);
                            const errorMsg = jsonData.message || jsonData.detail || text;
                            throw new Error(errorMsg);
                        } catch (e) {
                            throw new Error(text || `HTTP error! status: ${response.status}`);
                        }
                    }
                    throw new Error('Неверный формат ответа');
                });
            });
        })
        .then(result => {
            if (typeof result === 'string') {
                try {
                    result = JSON.parse(result);
                } catch (e) {
                    console.error('Ошибка парсинга результата:', e);
                    result = { success: false, message: 'Ошибка обработки ответа' };
                }
            }
            
            if (result && result.success) {
                if (typeof showNotification === 'function') {
                    showNotification('Настройки успешно сохранены', 'success');
                }
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                if (typeof showNotification === 'function') {
                    let errorMsg = 'Ошибка сохранения настроек';
                    if (result && typeof result === 'object') {
                        errorMsg = result.message || result.detail || errorMsg;
                    } else if (typeof result === 'string') {
                        errorMsg = result;
                    }
                    if (typeof errorMsg !== 'string') {
                        errorMsg = 'Ошибка сохранения настроек';
                    }
                    showNotification(errorMsg, 'error');
                }
            }
        })
        .catch(error => {
            console.error('Ошибка сохранения настроек:', error);
            if (typeof showNotification === 'function') {
                let errorMsg = 'Ошибка сохранения настроек';
                
                if (error) {
                    if (error.message && typeof error.message === 'string') {
                        errorMsg = error.message;
                        if (errorMsg.trim().startsWith('{') || errorMsg.trim().startsWith('[')) {
                            try {
                                const parsed = JSON.parse(errorMsg);
                                if (parsed && typeof parsed === 'object') {
                                    errorMsg = parsed.message || parsed.detail || 'Ошибка сохранения настроек';
                                }
                            } catch (e) {}
                        }
                    }
                    else if (typeof error === 'string') {
                        errorMsg = error;
                        if (errorMsg.trim().startsWith('{') || errorMsg.trim().startsWith('[')) {
                            try {
                                const parsed = JSON.parse(errorMsg);
                                if (parsed && typeof parsed === 'object') {
                                    errorMsg = parsed.message || parsed.detail || 'Ошибка сохранения настроек';
                                }
                            } catch (e) {}
                        }
                    }
                    else if (typeof error === 'object') {
                        errorMsg = error.message || error.detail || 'Ошибка сохранения настроек';
                    }
                }
                
                if (typeof errorMsg !== 'string') {
                    errorMsg = 'Ошибка сохранения настроек';
                }
                
                showNotification(errorMsg, 'error');
            }
        });
    };
    
    window.exportCashierData = function(format = 'csv') {
        const cashierId = '<?php echo htmlspecialchars($cashier_id); ?>';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let exportUrl;
        let filename;
        let mimeType;
        
        if (format === 'html') {
            exportUrl = '/core/api/export-cashier-html.php?cashier_id=' + encodeURIComponent(cashierId);
            filename = 'cashier_' + cashierId + '_report_' + new Date().toISOString().slice(0, 10) + '.html';
            mimeType = 'text/html';

            fetch(exportUrl, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Ошибка экспорта: ' + response.status);
                }
                return response.blob();
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
                showNotification('HTML отчет с графиками открыт', 'success');
            })
            .catch(error => {
                console.error('Ошибка экспорта:', error);
                showNotification('Ошибка при экспорте данных', 'error');
            });
            
            return;
        } else {
            exportUrl = '/core/api/export-cashier.php?cashier_id=' + encodeURIComponent(cashierId);
            filename = 'cashier_' + cashierId + '_' + new Date().toISOString().slice(0, 10) + '.csv';
            mimeType = 'text/csv';
        }
        
        fetch(exportUrl, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': csrfToken
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Ошибка экспорта: ' + response.status);
            }
            return response.blob();
        })
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
            showNotification('Экспорт данных завершен', 'success');
        })
        .catch(error => {
            console.error('Ошибка экспорта:', error);
            showNotification('Ошибка при экспорте данных', 'error');
        });
    };

    window.deleteCashier = function() {
        const cashierId = '<?php echo htmlspecialchars($cashier_id); ?>';
        const confirmMessage = 'Вы уверены, что хотите удалить эту кассу? Это действие необратимо. Все данные будут потеряны.';
        
        if (typeof showConfirm === 'function') {
            showConfirm(confirmMessage, () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (!csrfToken) {
                    showNotification('Ошибка: CSRF токен не найден', 'error');
                    return;
                }
                
                fetch('/core/api/cashiers.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        cashier_id: cashierId
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('Касса успешно удалена', 'success');
                        
                        setTimeout(() => {
                            window.location.href = '/dashboard.php';
                        }, 1000);
                    } else {
                        showNotification(result.message || 'Ошибка удаления кассы', 'error');
                        
                    }
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    showNotification('Ошибка удаления кассы', 'error');
                    
                });
            });
        } else {
            if (confirm(confirmMessage)) {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (!csrfToken) {
                    showNotification('Ошибка: CSRF токен не найден', 'error');
                    return;
                }
                
                fetch('/core/api/cashiers.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        cashier_id: cashierId
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('Касса успешно удалена', 'success');
                        
                        setTimeout(() => {
                            window.location.href = '/dashboard.php';
                        }, 1000);
                    } else {
                        showNotification(result.message || 'Ошибка удаления кассы', 'error');
                        
                    }
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    showNotification('Ошибка удаления кассы', 'error');
                    
                });
            }
        }
    };
    

    document.addEventListener('DOMContentLoaded', function() {
        initTheme();

        document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
        
        if (typeof window.initCustomSelect === 'function') {
            const categorySelect = document.getElementById('cashierCategorySelect');
            const categoryDropdown = document.getElementById('cashierCategoryDropdown');
            const categoryHidden = document.getElementById('cashierCategory');
            
            if (categorySelect && categoryDropdown && categoryHidden) {
                window.initCustomSelect('cashierCategorySelect', 'cashierCategoryDropdown', 'cashierCategory');
            }
        }
        
        function initChartSelects() {
            const chartPeriodSelect = document.getElementById('chartPeriodSelect');
            const chartPeriodDropdown = document.getElementById('chartPeriodDropdown');
            
            if (chartPeriodSelect && chartPeriodDropdown) {
                const chartOptions = chartPeriodDropdown.querySelectorAll('.custom-select-option');
                
                chartPeriodSelect.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isActive = this.classList.contains('active');
                    
                    document.querySelectorAll('.custom-select').forEach(s => {
                        if (s !== this) {
                            s.classList.remove('active');
                            s.nextElementSibling?.classList.remove('show');
                        }
                    });
                    
                    this.classList.toggle('active');
                    chartPeriodDropdown.classList.toggle('show', !isActive);
                });
                
                chartOptions.forEach(option => {
                    option.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = this.getAttribute('data-value');
                        
                        const selectText = chartPeriodSelect.querySelector('.custom-select-text');
                        if (selectText) {
                            selectText.textContent = this.textContent;
                        }
                        chartPeriodSelect.setAttribute('data-value', value);
                        
                        chartOptions.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                        
                        chartPeriodSelect.classList.remove('active');
                        chartPeriodDropdown.classList.remove('show');

                        loadChartData();
                    });
                });
            }
            
            const chartTypeSelect = document.getElementById('chartTypeSelect');
            const chartTypeDropdown = document.getElementById('chartTypeDropdown');
            
            if (chartTypeSelect && chartTypeDropdown) {
                const chartTypeOptions = chartTypeDropdown.querySelectorAll('.custom-select-option');
                
                chartTypeSelect.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isActive = this.classList.contains('active');
                    
                    document.querySelectorAll('.custom-select').forEach(s => {
                        if (s !== this) {
                            s.classList.remove('active');
                            s.nextElementSibling?.classList.remove('show');
                        }
                    });
                    
                    this.classList.toggle('active');
                    chartTypeDropdown.classList.toggle('show', !isActive);
                });
                
                chartTypeOptions.forEach(option => {
                    option.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = option.getAttribute('data-value');
                        
                        const selectText = chartTypeSelect.querySelector('.custom-select-text');
                        if (selectText) {
                            selectText.textContent = option.textContent;
                        }
                        chartTypeSelect.setAttribute('data-value', value);
                        
                        chartTypeOptions.forEach(opt => opt.classList.remove('selected'));
                        option.classList.add('selected');
                        
                        chartTypeSelect.classList.remove('active');
                        chartTypeDropdown.classList.remove('show');
                        
                        updateChartTitle();
                        loadChartData();
                    });
                });
            }

            const transactionTypeSelect = document.getElementById('transactionTypeSelect');
            const transactionTypeDropdown = document.getElementById('transactionTypeDropdown');
            
            if (transactionTypeSelect && transactionTypeDropdown) {
                const transactionTypeOptions = transactionTypeDropdown.querySelectorAll('.custom-select-option');
                
                transactionTypeSelect.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isActive = this.classList.contains('active');
                    
                    document.querySelectorAll('.custom-select').forEach(s => {
                        if (s !== this) {
                            s.classList.remove('active');
                            s.nextElementSibling?.classList.remove('show');
                        }
                    });
                    
                    this.classList.toggle('active');
                    transactionTypeDropdown.classList.toggle('show', !isActive);
                });
                
                transactionTypeOptions.forEach(option => {
                    option.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = option.getAttribute('data-value');
                        
                        const selectText = transactionTypeSelect.querySelector('.custom-select-text');
                        if (selectText) {
                            selectText.textContent = option.textContent;
                        }
                        transactionTypeSelect.setAttribute('data-value', value);
                        
                        transactionTypeOptions.forEach(opt => opt.classList.remove('selected'));
                        option.classList.add('selected');
                        
                        transactionTypeSelect.classList.remove('active');
                        transactionTypeDropdown.classList.remove('show');
                        
                        updateChartTitle();
                        loadChartData();
                    });
                });
            }

            function updateChartTitle() {
                const chartTitle = document.getElementById('chartTitle');
                if (!chartTitle) return;
                
                const chartType = document.getElementById('chartTypeSelect')?.getAttribute('data-value') || 'amount';
                const transactionType = document.getElementById('transactionTypeSelect')?.getAttribute('data-value') || 'all';
                
                let title = '';
                if (chartType === 'count') {
                    title = 'Количество транзакций';
                } else {
                    if (transactionType === 'all') {
                        title = 'Сумма прибыли';
                    } else if (transactionType === 'deposits') {
                        title = 'Входящие транзакции';
                    } else if (transactionType === 'withdraws') {
                        title = 'Исходящие транзакции';
                    }
                }
                
                chartTitle.textContent = title;
            }
            
            window.updateChartTitle = updateChartTitle;
            
            const transactionStatusSelect = document.getElementById('transactionStatusSelect');
            const transactionStatusDropdown = document.getElementById('transactionStatusDropdown');
            
            if (transactionStatusSelect && transactionStatusDropdown) {
                const statusOptions = transactionStatusDropdown.querySelectorAll('.custom-select-option');
                
                transactionStatusSelect.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isActive = this.classList.contains('active');
                    
                    document.querySelectorAll('.custom-select').forEach(s => {
                        if (s !== this) {
                            s.classList.remove('active');
                            s.nextElementSibling?.classList.remove('show');
                        }
                    });
                    
                    this.classList.toggle('active');
                    transactionStatusDropdown.classList.toggle('show', !isActive);
                });
                
                statusOptions.forEach(option => {
                    option.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = this.getAttribute('data-value');
                        
                        const selectText = transactionStatusSelect.querySelector('.custom-select-text');
                        if (selectText) {
                            selectText.textContent = this.textContent;
                        }
                        transactionStatusSelect.setAttribute('data-value', value);
                        
                        statusOptions.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                        
                        transactionStatusSelect.classList.remove('active');
                        transactionStatusDropdown.classList.remove('show');
                    });
                });
            }
            
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.custom-select') && !e.target.closest('.custom-select-dropdown')) {
                    document.querySelectorAll('.custom-select').forEach(s => {
                        s.classList.remove('active');
                        s.nextElementSibling?.classList.remove('show');
                    });
                }
            });
        }
        
        initChartSelects();

        const copyButtons = document.querySelectorAll('.copy-btn');
        copyButtons.forEach(button => {
            button.addEventListener('click', function() {
                const webhookUrl = document.getElementById('webhookUrl');
                if (webhookUrl) {
                    const textToCopy = webhookUrl.textContent.trim();
                    navigator.clipboard.writeText(textToCopy).then(() => {
                        const originalText = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-check me-1"></i> Скопировано!';
                        setTimeout(() => {
                            this.innerHTML = originalText;
                        }, 2000);
                        showNotification('URL скопирован в буфер обмена', 'success');
                        
                    }).catch(err => {
                        console.error('Ошибка копирования:', err);
                        showNotification('Не удалось скопировать URL', 'error');
                        
                    });
                }
            });
        });

        function initUserDropdown() {
            const dropdownBtn = document.getElementById('userMenuBtn');
            const dropdownMenu = document.getElementById('userDropdownMenu');
            
            if (!dropdownBtn || !dropdownMenu) return;

            dropdownBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                dropdownMenu.classList.toggle('show', !isExpanded);
            });
            
            document.addEventListener('click', function(e) {
                if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                    dropdownMenu.classList.remove('show');
                }
            });
            
            dropdownMenu.querySelectorAll('.user-dropdown-item').forEach(item => {
                item.addEventListener('click', function() {
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                    dropdownMenu.classList.remove('show');
                });
            });
        }

        function logout() {
            const formData = new FormData();
            formData.append('page', 'logout');
            
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                             document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            fetch('/core/events/listener.php', {
                method: 'POST',
                body: formData
            })
                .then(async response => {
                    if (!response.ok) {
                        const text = await response.text();
                        console.error('Ошибка сервера:', response.status, text);
                        throw new Error(`Ошибка сервера: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        window.location.href = '/';
                    }
                })
                .catch(error => {
                    console.error('Ошибка выхода:', error);
                    window.location.href = '/';
                });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initUserDropdown);
        } else {
            initUserDropdown();
        }
    });
    
    let currentPage = 1;
    let transactionsPerPage = 20;
    let currentStatus = 'all';
    let totalTransactions = 0;
    
    let chartData = null;

    window.showAllTransactions = function() {
        console.log('showAllTransactions вызвана');
        const transactionsTab = document.querySelector('#transactions-tab') || document.querySelector('a[href="#transactions"]');
        if (transactionsTab) {
            console.log('Найдена вкладка транзакций, переключаемся');
            if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                const tab = new bootstrap.Tab(transactionsTab);
                tab.show();
            } else {
                transactionsTab.click();
            }
        } else {
            console.error('Вкладка транзакций не найдена');
        }
        setTimeout(function() {
            console.log('Загружаем транзакции');
            loadAllTransactions();
        }, 300);
        return false;
    };
        
    window.loadAllTransactions = function(status = 'all', page = 1) {
        const container = document.getElementById('transactionsTableContainer');
        if (!container) return;
        
        currentStatus = status;
        currentPage = page;
        
        container.innerHTML = '<div class="text-center p-4 text-ton-secondary"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Загрузка...</span></div><p class="mt-2">Загрузка транзакций...</p></div>';
        
        const cashierId = <?php echo $cashier_id_int; ?>;
        const offset = (page - 1) * transactionsPerPage;
        const url = `?cashier_id=${cashierId}&action=get_all_transactions&status=${status}&limit=${transactionsPerPage}&offset=${offset}`;
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Данные транзакций получены:', data);
                if (data.success && data.transactions) {
                    totalTransactions = data.total || data.transactions.length;
                    renderTransactionsTable(data.transactions, data.total || data.transactions.length, page);
                } else {
                    const errorMsg = data.error || 'Ошибка загрузки транзакций';
                    console.error('Ошибка:', errorMsg);
                    container.innerHTML = '<div class="text-center p-4 text-ton-secondary">' + errorMsg + '</div>';
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки транзакций:', error);
                container.innerHTML = '<div class="text-center p-4 text-ton-secondary">Ошибка загрузки транзакций: ' + error.message + '</div>';
            });
    };
        
    window.renderTransactionsTable = function(transactions, total, currentPage) {
        const container = document.getElementById('transactionsTableContainer');
        if (!container) return;
        
        if (transactions.length === 0) {
            container.innerHTML = '<div class="text-center p-4 text-ton-secondary">Нет транзакций</div>';
            return;
        }
        
        const totalPages = Math.ceil(total / transactionsPerPage);
        const startItem = (currentPage - 1) * transactionsPerPage + 1;
        const endItem = Math.min(currentPage * transactionsPerPage, total);
        
        let html = '<div class="transactions-wrapper">';
        html += '<div class="transactions-table-container"><table class="transactions-table">';
        html += '<thead><tr><th>Дата</th><th>Сумма</th><th>Валюта</th><th>Статус</th><th>Хеш</th><th>Действия</th></tr></thead><tbody>';
        
        transactions.forEach(tx => {
            const date = new Date(tx.time_recorded);
            const dateStr = date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
            let statusClass = 'status-badge';
            let statusText = tx.status;
            if (tx.status === 'success') {
                statusClass += ' status-success';
                statusText = 'Успешно';
            } else if (tx.status === 'pending' || tx.status === 'nohash') {
                statusClass += ' status-pending';
                statusText = 'Ожидание';
            } else if (tx.status === 'expired') {
                statusClass += ' status-expired';
                statusText = 'Истекло';
            } else if (tx.status === 'failed') {
                statusClass += ' status-cancelled';
                statusText = 'Ошибка';
            } else if (tx.status === 'cancelled' || tx.status === 'duplicate') {
                statusClass += ' status-cancelled';
                statusText = tx.status === 'cancelled' ? 'Отменено' : 'Дубликат';
            }
            const currency = (tx.currency || 'TON').toUpperCase();
            const hash = tx.hash ? (tx.hash.substring(0, 8) + '...' + tx.hash.substring(tx.hash.length - 8)) : '-';
            const isWithdraw = tx.type === 'withdraw';
            const amount = parseFloat(tx.price).toFixed(2);
            const amountDisplay = isWithdraw ? `-${amount}` : `+${amount}`;
            const amountClass = isWithdraw ? 'tx-amount-withdraw' : 'tx-amount-deposit';
            
            const txData = {
                id: tx.id,
                date: dateStr,
                amount: amountDisplay,
                currency: currency,
                status: statusText,
                hash: tx.hash || '',
                wallet: tx.wallet || '',
                payload: tx.payload || '',
                type: tx.type || 'deposit'
            };
            
            const txDataJson = JSON.stringify(txData).replace(/'/g, "&#39;").replace(/"/g, '&quot;');
            
            const hashLink = tx.hash ? 
                `<a href="https://tonviewer.com/transaction/${tx.hash}" target="_blank" rel="noopener noreferrer" class="tx-hash-link" title="Открыть в TonViewer">${hash}</a>` : 
                '<span class="tx-hash-empty">-</span>';
            
            html += `<tr>
                <td class="tx-date">${dateStr}</td>
                <td class="tx-amount ${amountClass}">${amountDisplay}</td>
                <td class="tx-currency">${currency}</td>
                <td class="tx-status"><span class="${statusClass}">${statusText}</span></td>
                <td class="tx-hash" title="${tx.hash || ''}">${hashLink}</td>
                <td class="tx-actions">
                    <button class="btn-view-details" onclick="showTransactionDetails('${txDataJson}')" title="Детали транзакции">
                        <i class="bi bi-info-circle"></i>
                    </button>
                </td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        
        if (totalPages > 1) {
            html += '<div class="transactions-pagination">';
            html += `<div class="pagination-info">Показано ${startItem}-${endItem} из ${total}</div>`;
            html += '<div class="pagination-controls">';

            if (currentPage > 1) {
                html += `<button class="pagination-btn" onclick="loadAllTransactions('${currentStatus}', ${currentPage - 1})" title="Предыдущая страница">
                    <span>←</span>
                </button>`;
            } else {
                html += '<button class="pagination-btn pagination-btn-disabled" disabled><span>←</span></button>';
            }
            
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                html += `<button class="pagination-btn" onclick="loadAllTransactions('${currentStatus}', 1)">1</button>`;
                if (startPage > 2) {
                    html += '<span class="pagination-dots">...</span>';
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                if (i === currentPage) {
                    html += `<button class="pagination-btn pagination-btn-active">${i}</button>`;
                } else {
                    html += `<button class="pagination-btn" onclick="loadAllTransactions('${currentStatus}', ${i})">${i}</button>`;
                }
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += '<span class="pagination-dots">...</span>';
                }
                html += `<button class="pagination-btn" onclick="loadAllTransactions('${currentStatus}', ${totalPages})">${totalPages}</button>`;
            }
            
            if (currentPage < totalPages) {
                html += `<button class="pagination-btn" onclick="loadAllTransactions('${currentStatus}', ${currentPage + 1})" title="Следующая страница">
                    <span>→</span>
                </button>`;
            } else {
                html += '<button class="pagination-btn pagination-btn-disabled" disabled><span>→</span></button>';
            }
            
            html += '</div></div>';
        }
        
        html += '</div>';
        container.innerHTML = html;
    };

    window.showTransactionDetails = function(txData) {
        const modal = document.getElementById('transactionModal');
        const modalBody = document.getElementById('transactionModalBody');
        
        if (!modal || !modalBody) return;
        
        if (typeof txData === 'string') {
            try {
                txData = txData.replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                txData = JSON.parse(txData);
            } catch (e) {
                console.error('Ошибка парсинга данных транзакции:', e);
                return;
            }
        }
        
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        
        const typeText = txData.type === 'withdraw' ? 'Вывод' : 'Пополнение';
        const walletDisplay = txData.wallet || 'Не указан';
        const payloadDisplay = txData.payload || 'Отсутствует';
        
        let html = `
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">ID транзакции</div>
                <div class="transaction-detail-value">${escapeHtml(String(txData.id))}</div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Дата и время</div>
                <div class="transaction-detail-value">${escapeHtml(txData.date)}</div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Тип</div>
                <div class="transaction-detail-value">${escapeHtml(typeText)}</div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Сумма</div>
                <div class="transaction-detail-value">${escapeHtml(txData.amount)} ${escapeHtml(txData.currency)}</div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Статус</div>
                <div class="transaction-detail-value">${escapeHtml(txData.status)}</div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Хеш транзакции</div>
                <div class="transaction-detail-value ${txData.hash ? '' : 'empty'}">
                    ${txData.hash ? 
                        `<a href="https://tonviewer.com/transaction/${escapeHtml(txData.hash)}" target="_blank" rel="noopener noreferrer" class="tx-hash-link-detail" title="Открыть в TonViewer">
                            ${escapeHtml(txData.hash)}
                            <i class="bi bi-box-arrow-up-right" style="margin-left: 0.5rem; font-size: 0.8rem;"></i>
                        </a>` : 
                        'Не указан'}
                </div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Адрес отправителя</div>
                <div class="transaction-detail-value ${txData.wallet ? '' : 'empty'}" style="font-family: 'Courier New', monospace; font-size: 0.9rem;">
                    ${txData.wallet ? 
                        `<a href="https://tonviewer.com/${escapeHtml(txData.wallet)}" target="_blank" rel="noopener noreferrer" class="tx-hash-link-detail" title="Открыть кошелек в TonViewer">
                            ${escapeHtml(walletDisplay)}
                            <i class="bi bi-box-arrow-up-right" style="margin-left: 0.5rem; font-size: 0.8rem;"></i>
                        </a>` : 
                        'Не указан'}
                </div>
            </div>
            <div class="transaction-detail-row">
                <div class="transaction-detail-label">Payload</div>
                <div class="transaction-detail-value payload ${txData.payload ? '' : 'empty'}">${escapeHtml(payloadDisplay)}</div>
            </div>
        `;
        
        modalBody.innerHTML = html;
        modal.classList.add('show');
        
        modal.onclick = function(e) {
            if (e.target === modal) {
                closeTransactionModal();
            }
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                closeTransactionModal();
            }
        });
    };

    window.closeTransactionModal = function() {
        const modal = document.getElementById('transactionModal');
        if (modal) {
            modal.classList.remove('show');
        }
    };
        
    window.loadChartData = function() {
        const cashierId = <?php echo $cashier_id_int; ?>;
        const period = document.getElementById('chartPeriodSelect')?.getAttribute('data-value') || 'month';
        const chartType = document.getElementById('chartTypeSelect')?.getAttribute('data-value') || 'amount';
        const transactionType = document.getElementById('transactionTypeSelect')?.getAttribute('data-value') || 'all';
        
        const url = `?cashier_id=${cashierId}&action=get_chart_data&period=${period}&chart_type=${chartType}&transaction_type=${transactionType}`;
        
        const chartContainer = document.getElementById('chartContainer');
        if (chartContainer) {
            chartContainer.innerHTML = '<div class="text-center p-4 text-ton-secondary"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Загрузка...</span></div><p class="mt-2">Загрузка данных графика...</p></div>';
        }
        
        console.log('Загрузка данных графика:', url);
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Данные графика получены:', data);
                if (data.success && data.data && data.data.length > 0) {
                    chartData = data.data;
                    if (chartContainer) {
                        chartContainer.innerHTML = '<canvas id="activityChart"></canvas>';
                    }
                    setTimeout(function() {
                        renderChart(data.data);
                    }, 100);
                } else {
                    if (chartContainer) {
                        renderEmptyChart();
                    }
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки данных графика:', error);
                if (chartContainer) {
                    renderEmptyChart();
                }
            });
    };
        
    let activityChart = null;
    
    window.renderEmptyChart = function() {
        const container = document.getElementById('chartContainer');
        if (!container) return;
        
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const textColor = isDark ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)';
        const iconColor = isDark ? 'rgba(255, 255, 255, 0.3)' : 'rgba(0, 0, 0, 0.3)';
        
        container.innerHTML = `
            <div class="chart-empty-state" style="
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
                padding: 2rem;
                text-align: center;
            ">
                <div style="
                    font-size: 4rem;
                    color: ${iconColor};
                    margin-bottom: 1rem;
                    opacity: 0.5;
                "><i class="fas fa-chart-bar"></i></div>
                <div style="
                    font-size: 1.1rem;
                    font-weight: 500;
                    color: ${textColor};
                    margin-bottom: 0.5rem;
                ">Нет данных для отображения</div>
                <div style="
                    font-size: 0.9rem;
                    color: ${textColor};
                    opacity: 0.7;
                ">Поступления появятся здесь после первых транзакций</div>
            </div>
        `;
    };
    
    window.renderChart = function(chartData, chartType = 'amount', transactionType = 'all') {
        const container = document.getElementById('chartContainer');
        if (!container) {
            console.error('Контейнер графика не найден');
            return;
        }

        if (activityChart) {
            try {
                activityChart.destroy();
            } catch (e) {
                console.warn('Ошибка при уничтожении графика:', e);
            }
            activityChart = null;
        }

        if (typeof Chart === 'undefined') {
            renderSimpleChart(chartData);
            return;
        }

        const oldCanvas = document.getElementById('activityChart');
        if (oldCanvas) {
            oldCanvas.remove();
        }
        
        const newCanvas = document.createElement('canvas');
        newCanvas.id = 'activityChart';
        container.innerHTML = '';
        container.appendChild(newCanvas);
        
        const ctx = newCanvas.getContext('2d');
        if (!ctx) {
            console.error('Не удалось получить контекст canvas');
            return;
        }

        ctx.clearRect(0, 0, newCanvas.width || 800, newCanvas.height || 400);

        if (typeof Chart !== 'undefined' && Chart.helpers) {
            Chart.helpers.easing = Chart.helpers.easing || {};
            Chart.helpers.easing.easeOutCubic = function(t) {
                return 1 - Math.pow(1 - t, 3);
            };
        }

        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const textColor = isDark ? 'rgba(255, 255, 255, 0.9)' : 'rgba(0, 0, 0, 0.9)';
        const textSecondaryColor = isDark ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)';
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
        const borderColor = isDark ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.2)';

        const depositColor = '#22c55e';
        const withdrawColor = '#ef4444';
        const allColor = '#ff6b9d';

        let primaryColor = allColor;
        if (transactionType === 'deposits') {
            primaryColor = depositColor;
        } else if (transactionType === 'withdraws') {
            primaryColor = withdrawColor;
        }
        
        const labels = chartData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('ru-RU', {day: '2-digit', month: '2-digit'});
        });
   
        const dataValues = chartType === 'count' 
            ? chartData.map(item => item.count)
            : chartData.map(item => item.amount);

        function hexToRgb(hex) {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : {r: 255, g: 107, b: 157};
        }
        
        const rgb = hexToRgb(primaryColor);
        const r = rgb.r;
        const g = rgb.g;
        const b = rgb.b;

        const lineColor = primaryColor;
        
        const fillColorSimple = `rgba(${r}, ${g}, ${b}, 0.1)`;

        let datasetLabel = 'Сумма прибыли';
        if (chartType === 'count') {
            datasetLabel = 'Количество транзакций';
        } else {
            if (transactionType === 'deposits') {
                datasetLabel = 'Входящие транзакции';
            } else if (transactionType === 'withdraws') {
                datasetLabel = 'Исходящие транзакции';
            }
        }
        
        activityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: datasetLabel,
                    data: dataValues,
                    borderColor: lineColor,
                    backgroundColor: fillColorSimple,
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: primaryColor,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverBackgroundColor: primaryColor,
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 3,
                    pointHitRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 0,
                animation: {
                    duration: 1200,
                    easing: 'easeOutCubic'
                },
                animations: {
                    colors: {
                        duration: 800,
                        easing: 'easeOutCubic'
                    },
                    backgroundColor: {
                        duration: 1200,
                        easing: 'easeOutCubic'
                    },
                    borderColor: {
                        duration: 800,
                        easing: 'easeOutCubic'
                    },
                    x: {
                        duration: 1200,
                        easing: 'easeOutCubic',
                        from: 0
                    },
                    y: {
                        duration: 1200,
                        easing: 'easeOutCubic',
                        from: 0,
                        delay: function(context) {
                            return context.dataIndex * 15;
                        }
                    },
                    radius: {
                        duration: 700,
                        easing: 'easeOutCubic',
                        delay: function(context) {
                            return context.dataIndex * 15;
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(10, 10, 10, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                        titleColor: textColor,
                        bodyColor: textColor,
                        borderColor: borderColor,
                        borderWidth: 1,
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                const value = parseFloat(context.parsed.y);
                                if (chartType === 'count') {
                                    return value.toFixed(0);
                                } else {
                                    if (value >= 1000000) {
                                        return (value / 1000000).toFixed(2) + 'M';
                                    } else if (value >= 1000) {
                                        return (value / 1000).toFixed(2) + 'k';
                                    }
                                    return value.toFixed(2);
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        ticks: {
                            color: textSecondaryColor,
                            font: {
                                size: 11
                            },
                            padding: 10
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        border: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        beginAtZero: true,
                        ticks: {
                            color: textSecondaryColor,
                            font: {
                                size: 11
                            },
                            padding: 10,
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                } else if (value >= 1000) {
                                    return (value / 1000).toFixed(0) + 'k';
                                }
                                return value.toFixed(0);
                            }
                        },
                        grid: {
                            color: gridColor,
                            drawBorder: false,
                            lineWidth: 1
                        },
                        border: {
                            color: borderColor,
                            display: false
                        }
                    }
                }
            }
        });
        
        setTimeout(() => {
            if (activityChart) {
                activityChart.update('none');
            }
        }, 100);
        
        let resizeTimeout;
        const handleResize = () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (activityChart) {
                    activityChart.resize();
                }
            }, 150);
        };

        if (window.chartResizeHandler) {
            window.removeEventListener('resize', window.chartResizeHandler);
        }

        window.chartResizeHandler = handleResize;
        window.addEventListener('resize', handleResize);
    };

    window.renderSimpleChart = function(chartData) {
        const container = document.getElementById('chartContainer');
        if (!container) return;
        
        if (chartData.length === 0) {
            container.innerHTML = '<div class="chart-placeholder">Нет данных для отображения</div>';
            return;
        }
        
        const maxAmount = Math.max(...chartData.map(d => d.amount), 1);
        const width = container.offsetWidth || 600;
        const height = 400;
        const padding = 40;
        const chartWidth = width - padding * 2;
        const chartHeight = height - padding * 2;
        
        let svg = `<svg width="${width}" height="${height}" style="background: transparent;">
            <defs>
                <linearGradient id="gradient" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" style="stop-color:rgba(255,107,157,0.2);stop-opacity:1" />
                    <stop offset="100%" style="stop-color:rgba(255,107,157,0);stop-opacity:1" />
                </linearGradient>
            </defs>`;

        svg += `<line x1="${padding}" y1="${height - padding}" x2="${width - padding}" y2="${height - padding}" stroke="var(--ton-border)" stroke-width="2"/>`;
        svg += `<line x1="${padding}" y1="${padding}" x2="${padding}" y2="${height - padding}" stroke="var(--ton-border)" stroke-width="2"/>`;

        const stepX = chartWidth / (chartData.length - 1 || 1);
        let path = `M ${padding} ${height - padding}`;
        let areaPath = `M ${padding} ${height - padding}`;
        
        chartData.forEach((item, index) => {
            const x = padding + index * stepX;
            const y = height - padding - (item.amount / maxAmount) * chartHeight;
            path += ` L ${x} ${y}`;
            areaPath += ` L ${x} ${y}`;
        });
        
        areaPath += ` L ${width - padding} ${height - padding} Z`;
        path += ` L ${width - padding} ${height - padding}`;
        
        svg += `<path d="${areaPath}" fill="url(#gradient)"/>`;
        svg += `<path d="${path}" fill="none" stroke="rgb(255, 107, 157)" stroke-width="2"/>`;

        chartData.forEach((item, index) => {
            const x = padding + index * stepX;
            const y = height - padding - (item.amount / maxAmount) * chartHeight;
            svg += `<circle cx="${x}" cy="${y}" r="4" fill="rgb(255, 107, 157)"/>`;
        });
        
        svg += '</svg>';
        container.innerHTML = svg;
    };
    
    function updateCashierBalance() {
        const balanceElement = document.getElementById('cashierBalance');
        if (!balanceElement) return;
        
        const cashierId = <?php echo $cashier_id_int; ?>;
        const apiUrl = '<?php echo htmlspecialchars($api_base); ?>/cashier/' + cashierId;
        
        fetch(apiUrl + '?user_id=<?php echo $user_id_int; ?>&api_token=<?php echo urlencode($api_token); ?>', {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.cashier && data.cashier.balance !== undefined) {
                const balance = parseFloat(data.cashier.balance || 0);
                const currency = (data.cashier.currency || 'TON').toUpperCase();
                balanceElement.textContent = balance.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + currency;
            }
        })
        .catch(error => {
            console.error('Ошибка обновления баланса:', error);
        });
    }
    
    function autoRefreshTransactions() {
        const transactionsTab = document.getElementById('transactions');
        if (transactionsTab && transactionsTab.classList.contains('active')) {
            if (typeof loadAllTransactions === 'function') {
                loadAllTransactions(currentStatus, currentPage);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        setInterval(updateCashierBalance, 5000);
        setInterval(autoRefreshTransactions, 10000);

        const originalLoadAllTransactions = window.loadAllTransactions;
        if (originalLoadAllTransactions) {
            window.loadAllTransactions = function(status, page) {
                originalLoadAllTransactions(status, page);
                setTimeout(updateCashierBalance, 1000);
            };
        }
        
        const chartPeriodSelect = document.getElementById('chartPeriodSelect');
        if (chartPeriodSelect) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'data-value') {
                        const period = chartPeriodSelect.getAttribute('data-value');
                        loadChartData(period);
                    }
                });
            });
            observer.observe(chartPeriodSelect, { attributes: true });
        }
        
        const themeObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'data-bs-theme') {
                    if (activityChart && chartData) {
                        setTimeout(function() {
                            renderChart(chartData);
                        }, 100);
                    }
                }
            });
        });
        themeObserver.observe(document.documentElement, { attributes: true });

        const transactionStatusSelect = document.getElementById('transactionStatusSelect');
        if (transactionStatusSelect) {
            const statusObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'data-value') {
                        const status = transactionStatusSelect.getAttribute('data-value');
                        loadAllTransactions(status, 1);
                    }
                });
            });
            statusObserver.observe(transactionStatusSelect, { attributes: true });
        }

        setTimeout(function() {
            console.log('Инициализация графика');
            loadChartData('month');
        }, 500);

        const transactionsTab = document.getElementById('transactions');
        if (transactionsTab && transactionsTab.classList.contains('active')) {
            console.log('Вкладка транзакций уже активна, загружаем данные');
            loadAllTransactions(currentStatus, currentPage);
        }

        const tabButtons = document.querySelectorAll('[data-bs-toggle="tab"]');
        tabButtons.forEach(button => {
                    button.addEventListener('shown.bs.tab', function(e) {
                        const target = e.target.getAttribute('data-bs-target') || e.target.getAttribute('href');
                        console.log('Переключена вкладка:', target);
                        if (target === '#transactions') {
                            console.log('Загружаем транзакции для вкладки');
                            loadAllTransactions(currentStatus, currentPage);
                        }
                    });
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
</body>
</html>