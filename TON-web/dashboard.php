<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');

if (!getCore()->isAuth()) {
    header('Location: /');
    exit();
}

$user_id = $_SESSION['id'];
$user_email = $_SESSION['email'];
$conn = getCore()->getConn();
$user_id_int = intval($user_id);

$stmt = $conn->prepare("SELECT api_token FROM Users WHERE id = ?");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
$user_token_data = $result->fetch_assoc();
$user_api_token = $user_token_data ? $user_token_data['api_token'] : null;
$stmt->close();

if ($user_api_token) {
    $_SESSION['api_token'] = $user_api_token;
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(balance), 0) as total_balance FROM Cashiers WHERE user_id = ? AND currency = 'TON'");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$user_balance = $user_data ? (float)$user_data['total_balance'] : 0;
$stmt->close();

$month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
$user_id_int = intval($user_id);
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM (
        SELECT td.id FROM TONDeposit td 
        INNER JOIN Cashiers c ON td.cashier_id = c.id 
        WHERE c.user_id = ? AND td.time_recorded >= ?
        UNION ALL
        SELECT jd.id FROM JETTONDeposit jd 
        INNER JOIN Cashiers c ON jd.cashier_id = c.id 
        WHERE c.user_id = ? AND jd.time_recorded >= ?
    ) as all_transactions
");
$stmt->bind_param("isis", $user_id_int, $month_ago, $user_id_int, $month_ago);
$stmt->execute();
$result = $stmt->get_result();
$transactions_data = $result->fetch_assoc();
$transactions_count = $transactions_data ? (int)$transactions_data['count'] : 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM Cashiers WHERE user_id = ? AND status = 'active'");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
$cashiers_data = $result->fetch_assoc();
$active_cashiers = $cashiers_data ? (int)$cashiers_data['count'] : 0;
$stmt->close();

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful
    FROM (
        SELECT td.status FROM TONDeposit td 
        INNER JOIN Cashiers c ON td.cashier_id = c.id 
        WHERE c.user_id = ? AND td.time_recorded >= ?
        UNION ALL
        SELECT jd.status FROM JETTONDeposit jd 
        INNER JOIN Cashiers c ON jd.cashier_id = c.id 
        WHERE c.user_id = ? AND jd.time_recorded >= ?
    ) as all_transactions
");
$stmt->bind_param("isis", $user_id_int, $month_ago, $user_id_int, $month_ago);
$stmt->execute();
$result = $stmt->get_result();
$success_data = $result->fetch_assoc();
$total_payments = $success_data ? (int)$success_data['total'] : 0;
$successful_payments = $success_data ? (int)$success_data['successful'] : 0;
$success_rate = $total_payments > 0 ? round(($successful_payments / $total_payments) * 100, 1) : 0;
$stmt->close();

$current_month_start = date('Y-m-01 00:00:00');
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as current_month_income
    FROM (
        SELECT td.price, td.status FROM TONDeposit td 
        INNER JOIN Cashiers c ON td.cashier_id = c.id 
        WHERE c.user_id = ? AND td.time_recorded >= ?
        UNION ALL
        SELECT jd.price, jd.status FROM JETTONDeposit jd 
        INNER JOIN Cashiers c ON jd.cashier_id = c.id 
        WHERE c.user_id = ? AND jd.time_recorded >= ?
    ) as all_transactions
");
$stmt->bind_param("isis", $user_id_int, $current_month_start, $user_id_int, $current_month_start);
$stmt->execute();
$result = $stmt->get_result();
$current_month_data = $result->fetch_assoc();
$current_month_income = $current_month_data ? (float)$current_month_data['current_month_income'] : 0;
$stmt->close();

$prev_month_start = date('Y-m-01 00:00:00', strtotime('-1 month'));
$prev_month_end = date('Y-m-t 23:59:59', strtotime('-1 month'));
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as prev_month_income
    FROM (
        SELECT td.price, td.status FROM TONDeposit td 
        INNER JOIN Cashiers c ON td.cashier_id = c.id 
        WHERE c.user_id = ? AND td.time_recorded >= ? AND td.time_recorded <= ?
        UNION ALL
        SELECT jd.price, jd.status FROM JETTONDeposit jd 
        INNER JOIN Cashiers c ON jd.cashier_id = c.id 
        WHERE c.user_id = ? AND jd.time_recorded >= ? AND jd.time_recorded <= ?
    ) as all_transactions
");
$stmt->bind_param("ississ", $user_id_int, $prev_month_start, $prev_month_end, $user_id_int, $prev_month_start, $prev_month_end);
$stmt->execute();
$result = $stmt->get_result();
$prev_month_data = $result->fetch_assoc();
$prev_month_income = $prev_month_data ? (float)$prev_month_data['prev_month_income'] : 0;
$stmt->close();

$balance_change_percent = 0;
$balance_change_direction = 'neutral';
if ($prev_month_income > 0) {
    $balance_change_percent = round((($current_month_income - $prev_month_income) / $prev_month_income) * 100, 1);
    $balance_change_direction = $current_month_income > $prev_month_income ? 'positive' : ($current_month_income < $prev_month_income ? 'negative' : 'neutral');
} elseif ($current_month_income > 0) {
    $balance_change_percent = 100;
    $balance_change_direction = 'positive';
}
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php 
    require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');
    $csrf_token = generateCSRFToken();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <?php
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
    $config = getConfig();
    $site_name = $config['site']['name'] ?? 'TonPay';
    ?>
    <title>Кабинет | <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="apple-touch-icon" href="scripts/img/logo.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="scripts/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="scripts/libs/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="scripts/libs/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="scripts/css/custom.css" rel="stylesheet">
    <style>
        .dashboard-section {
            padding-top: 120px;
            min-height: 100vh;
            background: var(--ton-bg);
        }

        .dashboard-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--ton-border);
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
            align-items: stretch;
        }

        @media (min-width: 1400px) {
            .dashboard-stats {
                grid-template-columns: repeat(5, 1fr);
            }
            
            #apiTokenContent {
                display: block !important;
            }
            
            #apiTokenChevron {
                transform: rotate(180deg);
            }
        }
        
        #apiTokenContent {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        #apiTokenPasswordForm {
            margin: 0 !important;
        }
        
        #apiTokenPasswordForm input,
        #apiTokenPasswordForm button:not(:last-child),
        #apiTokenPasswordForm #apiTokenPasswordError {
            margin-bottom: 0.5rem !important;
        }
        
        #apiTokenActions {
            margin: 0 !important;
            margin-bottom: 0 !important;
            gap: 0.5rem !important;
            flex-direction: column;
        }
        
        #apiTokenContainer {
            margin: 0 !important;
            margin-bottom: 0.5rem !important;
        }

        #apiTokenValue {
            cursor: text !important;
            user-select: all !important;
        }
        
        #apiTokenValue:focus {
            outline: 2px solid var(--ton-primary);
            outline-offset: 2px;
        }

        
        @media (min-width: 992px) and (max-width: 1399px) {
            .dashboard-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        
        @media (max-width: 991px) {
            .dashboard-stats {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            
            #apiTokenActions {
                flex-direction: row !important;
                gap: 0.5rem !important;
            }
            
            #apiTokenActions button {
                flex: 1 !important;
                width: auto !important;
                padding: 0.4rem 0.5rem !important;
                font-size: 0.75rem !important;
                min-width: 0 !important;
            }
            
            #apiTokenActions button i {
                font-size: 0.7rem !important;
            }
            
            
            #apiTokenActions button span,
            #apiTokenActions button {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }

        .stat-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        
        .stat-card.api-token-card {
            min-height: 100%;
        }
        
        .stat-card.api-token-card #apiTokenContent {
            flex: 1;
            display: flex;
            flex-direction: column;
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

        .dashboard-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0;
            color: var(--ton-text);
        }

        .cashiers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            padding-top: 1.5rem;
        }

        .cashier-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .cashier-card:hover {
            border-color: rgba(0, 136, 204, 0.3);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .cashier-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .cashier-name {
            font-weight: 600;
            font-size: 1.1rem;
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

        .cashier-info {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .cashier-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-label {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 500;
            color: var(--ton-text);
        }

        .cashier-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-cashier {
            flex: 1;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-cashier-primary {
            background: var(--ton-primary);
            color: white;
            border: none;
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
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            margin-bottom: 2rem;
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--ton-text);
        }

        .empty-description {
            color: var(--ton-text-secondary);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        
        .modal-step {
            display: none;
        }

        .modal-step.active {
            display: block;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            color: var(--ton-text-secondary);
        }

        .step.active {
            background: var(--ton-primary);
            border-color: var(--ton-primary);
            color: white;
        }

        .step.completed {
            background: var(--ton-success);
            border-color: var(--ton-success);
            color: white;
        }

        .step-connector {
            flex: 1;
            height: 2px;
            background: var(--ton-border);
            margin-top: 14px;
        }

        .step-connector.active {
            background: var(--ton-primary);
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

        .integration-code {
            font-family: 'Courier New', monospace;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--ton-border);
            border-radius: 8px;
            padding: 1rem;
            color: var(--ton-text);
            word-break: break-all;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        [data-bs-theme="light"] .integration-code {
            background: rgba(0, 0, 0, 0.05);
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
            font-weight: 500;
        }

        .custom-select-option:first-child {
            border-radius: 12px 12px 0 0;
        }

        .custom-select-option:last-child {
            border-radius: 0 0 12px 12px;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--ton-text-secondary);
            margin-top: 0.5rem;
        }

        .modal-footer {
            display: flex;
            justify-content: space-between;
            border-top: 1px solid var(--ton-border);
        }

        .btn-modal {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-modal-secondary {
            background: transparent;
            border: 1px solid var(--ton-border);
            color: var(--ton-text);
        }

        .btn-modal-secondary:hover {
            background: var(--ton-card-hover);
        }

        .btn-modal-primary {
            background: var(--ton-primary);
            border: none;
            color: white;
        }

        .btn-modal-primary:hover {
            background: var(--ton-primary-dark);
            color: white !important;
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
        }

        .copy-btn:hover {
            background: var(--ton-card-hover);
        }

        .success-checkmark {
            text-align: center;
            margin: 2rem 0;
        }

        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--ton-success);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: white;
        }

        
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
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        
        @media (max-width: 767.98px) {
            .dashboard-section {
                padding-top: calc(80px + 2.5rem);
            }

            .dashboard-stats {
                grid-template-columns: 1fr;
            }

            .dashboard-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }
            
            .dashboard-actions button:first-child,
            .dashboard-actions button:nth-child(2) {
                width: 100%;
            }
            
            .dashboard-actions .btn-group {
                grid-column: 1 / -1;
                width: 100%;
                display: flex;
                flex-direction: row;
                gap: 0.75rem;
            }
            
            .dashboard-actions .btn-group button {
                flex: 1;
                width: 100%;
                margin-left: 0 !important;
            }

            .cashier-actions {
                flex-direction: column;
            }

            .modal-footer {
                flex-direction: column;
                gap: 1rem;
            }

            .btn-modal {
                width: 100%;
            }
        }
        
        .form-control:disabled,
        .form-control[readonly] {
            opacity: 1;
            cursor: not-allowed;
        }

        
        [data-bs-theme="dark"] .form-control:disabled,
        [data-bs-theme="dark"] .form-control[readonly] {
            background-color: rgba(255, 255, 255, 0.05) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: rgba(255, 255, 255, 0.5) !important;
        }

        
        [data-bs-theme="light"] .form-control:disabled,
        [data-bs-theme="light"] .form-control[readonly] {
            background-color: rgba(0, 0, 0, 0.03) !important;
            border-color: rgba(0, 0, 0, 0.1) !important;
            color: rgba(0, 0, 0, 0.5) !important;
        }

    </style>
</head>
<body>

<?php
$home_link = '/';
$show_integration = true;
$container_class = 'container';
$nav_links = [
    ['href' => '/#features', 'text' => 'Возможности'],
    ['href' => '/#security', 'text' => 'Безопасность'],
    ['href' => '/docs', 'text' => 'Интеграция']
];
require_once('core/blocks/navbar.php');
?>

<section class="dashboard-section">
    <div class="container">
        <div class="dashboard-header">
            <h1>Кабинет мерчанта</h1>
            <p class="text-ton-secondary">Управляйте вашими платежными кассами и отслеживайте статистику</p>
        </div>

        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value" id="totalBalance"><?php echo number_format($user_balance, 2); ?> TON</div>
                <div class="stat-label">Общий баланс</div>
                <div class="stat-change <?php echo $balance_change_direction; ?>">
                    <?php if ($balance_change_direction === 'positive'): ?>
                    <span>↑</span>
                        <span>+<?php echo abs($balance_change_percent); ?>% за месяц</span>
                    <?php elseif ($balance_change_direction === 'negative'): ?>
                        <span>↓</span>
                        <span><?php echo $balance_change_percent; ?>% за месяц</span>
                    <?php else: ?>
                        <span>→</span>
                        <span>Без изменений</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($transactions_count, 0, ',', ' '); ?></div>
                <div class="stat-label">Транзакций за месяц</div>
                <div class="stat-change <?php echo $transactions_count > 0 ? 'positive' : ''; ?>">
                    <?php if ($transactions_count > 0): ?>
                    <span>↑</span>
                        <span>Активность</span>
                    <?php else: ?>
                        <span>—</span>
                        <span>Нет транзакций</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?php echo $active_cashiers; ?></div>
                <div class="stat-label">Активных касс</div>
                <div class="stat-change <?php echo $active_cashiers > 0 ? 'positive' : ''; ?>">
                    <?php if ($active_cashiers > 0): ?>
                        <span>✓</span>
                        <span>Работают</span>
                    <?php else: ?>
                        <span>—</span>
                        <span>Нет активных</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?php echo $success_rate; ?>%</div>
                <div class="stat-label">Успешных платежей</div>
                <div class="stat-change <?php echo $success_rate >= 95 ? 'positive' : ($success_rate >= 80 ? '' : 'negative'); ?>">
                    <?php if ($total_payments > 0): ?>
                        <?php if ($success_rate >= 95): ?>
                            <span>↑</span>
                            <span>Отлично</span>
                        <?php elseif ($success_rate >= 80): ?>
                            <span>→</span>
                            <span>Нормально</span>
                        <?php else: ?>
                    <span>↓</span>
                            <span>Требует внимания</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span>—</span>
                        <span>Нет данных</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card api-token-card" style="position: relative; cursor: default;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; cursor: pointer;" onclick="window.toggleApiTokenSection()">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-key" style="color: var(--ton-primary); font-size: 1rem;"></i>
                        <div class="stat-label" style="margin: 0; font-weight: 600;">API Токен</div>
                    </div>
                    <i class="fas fa-chevron-down" id="apiTokenChevron" style="color: var(--ton-text-secondary); transition: transform 0.3s ease; font-size: 0.8rem;"></i>
                </div>
                <div id="apiTokenContent" style="display: none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--ton-border);">
                    <div id="apiTokenContainer" style="display: none; margin-bottom: 0.5rem;">
                        <input type="text" id="apiTokenValue" readonly class="form-control" value="<?php echo $user_api_token ? str_repeat('•', 64) : 'Токен не найден'; ?>" style="font-family: 'Courier New', monospace; font-size: 0.75rem; padding: 0.5rem 0.75rem; cursor: text; user-select: all; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--ton-border); border-radius: 6px; overflow-x: auto; white-space: nowrap;">
                    </div>
                    <div id="apiTokenPasswordForm" style="display: none;">
                        <input type="password" id="apiTokenPassword" class="form-control" placeholder="Введите пароль" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; margin-bottom: 0.5rem;">
                        <button class="btn btn-ton-primary" id="apiTokenSubmitBtn" onclick="submitApiTokenForm()" style="width: 100%; padding: 0.5rem; font-size: 0.8rem; margin-bottom: 0.5rem;">
                            <i class="fas fa-eye me-1"></i> Показать токен
                        </button>
                        <div id="apiTokenPasswordError" style="display: none; color: var(--ton-error); font-size: 0.7rem; margin-bottom: 0.5rem;"></div>
                    </div>
                    <div id="apiTokenActions" style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-ton-outline" onclick="showPasswordForm()" id="showPasswordBtn" style="padding: 0.5rem; font-size: 0.8rem;">
                            <i class="fas fa-key me-1"></i> Показать токен
                        </button>
                        <button class="btn btn-ton-outline" onclick="copyApiToken()" id="copyTokenBtn" style="padding: 0.5rem; font-size: 0.8rem; display: none;">
                            <i class="fas fa-copy me-1"></i> Копировать
                        </button>
                        <button class="btn btn-ton-primary" onclick="regenerateApiToken()" style="padding: 0.5rem; font-size: 0.8rem;">
                            <i class="fas fa-sync-alt me-1"></i> Новый токен
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-actions">
            <button class="btn btn-ton-primary" data-bs-toggle="modal" data-bs-target="#createCashierModal">
                Создать кассу
            </button>

            <button class="btn btn-ton-outline" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                Вывести
            </button>

            <div class="btn-group" style="display: inline-flex;">
                <button class="btn btn-ton-outline" onclick="downloadReport('csv')">
                    <i class="fas fa-file-csv me-2"></i> CSV
                </button>
                <button class="btn btn-ton-outline" onclick="downloadReport('html')" style="margin-left: 5px;">
                    <i class="fas fa-file-code me-2"></i> HTML
                </button>
            </div>
        </div>

        <div class="cashiers-section" style="padding-bottom: 1.5rem;">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="section-title">Мои кассы</h2>
                <div class="text-ton-secondary" id="cashiersLimit">Загрузка...</div>
            </div>

            <div class="cashiers-grid" id="cashiersGrid">
                <div class="text-center p-4">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="empty-state" id="emptyState" style="display: none;">
            <div class="empty-icon">💸</div>
            <h3 class="empty-title">У вас пока нет касс</h3>
            <p class="empty-description">Создайте свою первую платежную кассу, чтобы начать принимать платежи через <?php echo htmlspecialchars($site_name); ?></p>
            <button class="btn btn-ton-primary" style="margin: auto;" data-bs-toggle="modal" data-bs-target="#createCashierModal">
                Создать первую кассу
            </button>
        </div>
    </div>
</section>

<div class="modal fade" id="createCashierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content glass-modal">
            <div class="modal-header border-0">
                <h5 class="modal-title">Создание новой кассы</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="step-indicator">
                    <div class="step active">1</div>
                    <div class="step-connector"></div>
                    <div class="step">2</div>
                    <div class="step-connector"></div>
                    <div class="step">3</div>
                </div>

                <div class="modal-step active" id="step1">
                    <h6 class="mb-3">Основная информация</h6>

                    <div class="form-group" style="margin-bottom: 0.8rem;">
                        <label class="form-label">Название кассы *</label>
                        <input type="text" class="form-control" id="cashierName" placeholder="Например: Интернет-магазин TechStore" required>
                        <div class="form-text">Это название будет отображаться в вашем кабинете</div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.8rem;">
                        <label class="form-label">Описание</label>
                        <input type="text" class="form-control" id="cashierDescription" placeholder="Краткое описание назначения кассы">
                    </div>

                    <div class="form-group" style="margin-bottom: 0.8rem;">
                        <label class="form-label">Категория</label>
                        <div class="custom-select-wrapper">
                            <div class="custom-select" id="cashierCategorySelect" data-value="">
                                <span class="custom-select-placeholder">Выберите категорию</span>
                                <span class="custom-select-arrow">▼</span>
                            </div>
                            <div class="custom-select-dropdown" id="cashierCategoryDropdown">
                                <div class="custom-select-option" data-value="">Выберите категорию</div>
                                <div class="custom-select-option" data-value="Электронная коммерция">Электронная коммерция</div>
                                <div class="custom-select-option" data-value="Фриланс услуги">Фриланс услуги</div>
                                <div class="custom-select-option" data-value="Консультации">Консультации</div>
                                <div class="custom-select-option" data-value="Образовательные услуги">Образовательные услуги</div>
                                <div class="custom-select-option" data-value="Другое">Другое</div>
                            </div>
                            <input type="hidden" id="cashierCategory" value="">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.8rem;">
                        <label class="form-label">Выбор валюты</label>
                        <div class="custom-select-wrapper">
                            <div class="custom-select" id="cashierCurrencySelect" data-value="ton">
                                <span class="custom-select-text">TON</span>
                                <span class="custom-select-arrow">▼</span>
                            </div>
                            <div class="custom-select-dropdown" id="cashierCurrencyDropdown">
                                <div class="custom-select-option selected" data-value="ton">TON</div>
                                <div class="custom-select-option" data-value="jetton">JETTON</div>
                            </div>
                            <input type="hidden" id="cashierCurrency" value="ton">
                        </div>
                    </div>

                    <div class="form-group" id="jettonAddressGroup" style="display: none; margin-bottom: 0.8rem;">
                        <label class="form-label">Адрес джетона *</label>
                        <input type="text" class="form-control" id="cashierJettonAddress" placeholder="Введите адрес джетона в любом формате">
                        <div class="form-text">Введите адрес контракта джетона в любом формате</div>
                    </div>
                </div>

                <div class="modal-step" id="step2" style="padding-bottom: 0.5rem;">
                    <h6 class="mb-3">Настройки платежей</h6>

                    <div class="form-group">
                        <label class="form-label">Минимальная сумма платежа</label>
                        <input type="number" class="form-control" id="cashierMinAmount" placeholder="0.01" step="0.01" min="0.01" value="0.01">
                        <div class="form-text">Минимальная сумма: 0.01 TON</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Максимальная сумма платежа</label>
                        <input type="number" class="form-control" id="cashierMaxAmount" placeholder="Оставьте пустым" step="0.01" min="0">
                        <div class="form-text">Оставьте пустым для неограниченной суммы</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">URL для уведомлений (webhook) *</label>
                        <input type="url" class="form-control" id="cashierWebhook" placeholder="https://yourdomain.com/webhook" required>
                        <div class="form-text">URL на который будут отправляться уведомления о платежах</div>
                    </div>
                    </div>

                <div class="modal-step" id="step3">
                    <div class="success-checkmark">
                        <div class="checkmark">✓</div>
                        <h5>Касса успешно создана!</h5>
                        <p class="text-ton-secondary">Теперь вы можете интегрировать её на свой сайт</p>
                    </div>
                </div>

                <div class="modal-footer" style="padding: .75rem 0;">
                    <button type="button" style="margin: unset;" class="btn-modal btn-modal-secondary" id="prevStep" disabled>
                        Назад
                    </button>
                    <button type="button" style="margin: unset;" class="btn-modal btn-modal-primary" id="nextStep">
                        Продолжить
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-modal">
            <div class="modal-header border-0">
                <h5 class="modal-title">Вывод средств</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding-top: 0;">
                <div class="form-group">
                    <label class="form-label">Касса</label>
                    <div class="custom-select-wrapper">
                        <div class="custom-select" id="withdrawCashierSelect" data-value="">
                            <span class="custom-select-placeholder">Выберите кассу...</span>
                            <span class="custom-select-arrow">▼</span>
                            <input type="hidden" id="withdrawCashier" value="">
                        </div>
                        <div class="custom-select-dropdown" id="withdrawCashierDropdown">
                        </div>
                    </div>
                    <div class="form-text">Выберите кассу для вывода средств</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Сумма</label>
                    <input type="number" class="form-control" id="withdrawAmount" placeholder="0.01" step="0.01" min="0.01" disabled>
                    <div class="form-text">Доступно: <span id="availableBalance">0.00</span> <span id="availableCurrency">TON</span></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Адрес кошелька получателя</label>
                    <input type="text" class="form-control" id="withdrawWallet" placeholder="UQ... или 0:...">
                    <div class="form-text">Введите адрес кошелька для получения средств</div>
                </div>
                <div class="alert alert-info" style="margin-top: 1rem; padding: 0.75rem; font-size: 0.9rem;">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Важно:</strong> Комиссия блокчейна вычитается из суммы перевода. Вы получите сумму за вычетом комиссии.
                </div>
                <div id="withdrawError" class="alert alert-danger" style="display: none; margin: 0.5rem 0;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-modal-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn-modal btn-modal-primary" id="confirmWithdraw">Вывести</button>
            </div>
        </div>
    </div>
</div>

<?php require_once('core/blocks/footer.php') ?>

<script src="scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
<script src="scripts/js/app.js"></script>
<script>
    window.updateTotalBalance = function() {
        const balanceElement = document.getElementById('totalBalance');
        if (!balanceElement) return;
        
        fetch('/core/api/cashiers.php')
            .then(response => response.json())
            .then(data => {
                if (data && data.cashiers && Array.isArray(data.cashiers)) {
                    const totalBalance = data.cashiers.reduce((sum, cashier) => {
                        if (cashier.currency && cashier.currency.toUpperCase() === 'TON') {
                            return sum + parseFloat(cashier.balance || 0);
                        }
                        return sum;
                    }, 0);
                    
                    balanceElement.textContent = totalBalance.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' TON';
                }
            })
            .catch(error => {
                console.error('Ошибка обновления баланса:', error);
            });
    };
    
    setInterval(window.updateTotalBalance, 5000);
    
    if (typeof initTheme !== 'function') {
        console.warn('initTheme не загружена из app.js');
    }
    if (typeof toggleTheme !== 'function') {
        console.warn('toggleTheme не загружена из app.js');
    }
    let currentStep = 0;
    let steps, stepIndicators, stepConnectors, prevBtn, nextBtn;

    function updateStep(newStep) {
        if (!steps || !stepIndicators || !prevBtn || !nextBtn) {
            console.error('Элементы модального окна не инициализированы');
            return;
        }
        steps.forEach(step => step.classList.remove('active'));
        stepIndicators.forEach(indicator => {
            indicator.classList.remove('active', 'completed');
        });

        if (steps[newStep]) {
            steps[newStep].classList.add('active');
        }

        for (let i = 0; i <= newStep; i++) {
            if (stepIndicators[i]) {
                if (i < newStep) {
                    stepIndicators[i].classList.add('completed');
                } else {
                    stepIndicators[i].classList.add('active');
                }
            }

            if (i < newStep && stepConnectors[i]) {
                stepConnectors[i].classList.add('active');
            }
        }

        if (prevBtn) prevBtn.disabled = newStep === 0;

        if (nextBtn) {
            if (newStep === steps.length - 1) {
                nextBtn.textContent = 'Завершить';
            } else {
                nextBtn.textContent = 'Продолжить';
            }
        }

        currentStep = newStep;
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initTheme === 'function') {
            initTheme();
        }

        setTimeout(function() {
            const themeToggleBtn = document.getElementById('themeToggle');
            if (themeToggleBtn) {
                const newBtn = themeToggleBtn.cloneNode(true);
                themeToggleBtn.parentNode.replaceChild(newBtn, themeToggleBtn);
                
                newBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (typeof toggleTheme === 'function') {
                        toggleTheme();
                    } else {
                        const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'dark';
                        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
                        document.documentElement.setAttribute('data-bs-theme', newTheme);
                        localStorage.setItem('theme', newTheme);
                        const themeIcon = document.querySelector('.theme-icon');
                        if (themeIcon) {
                            themeIcon.className = newTheme === 'light' ? 'fas fa-sun theme-icon' : 'fas fa-moon theme-icon';
                        }
                    }
                });
            }
        }, 100);

        const createCashierModal = document.getElementById('createCashierModal');
        if (createCashierModal) {
            steps = document.querySelectorAll('.modal-step');
            stepIndicators = document.querySelectorAll('.step');
            stepConnectors = document.querySelectorAll('.step-connector');
            prevBtn = document.getElementById('prevStep');
            nextBtn = document.getElementById('nextStep');

            currentStep = 0;
            
            if (steps.length > 0) {
                updateStep(0);
            }
        }

        nextBtn.addEventListener('click', function() {
            if (currentStep === 0) {
                const name = document.getElementById('cashierName').value.trim();
                if (!name) {
                    showNotification('Введите название кассы', 'error');
                    return;
                }
                const currency = document.getElementById('cashierCurrency').value;
                const jettonAddress = document.getElementById('cashierJettonAddress').value.trim();
                if (currency === 'jetton' && !jettonAddress) {
                    showNotification('Введите адрес джетона', 'error');
                    return;
                }
            }
            
            if (currentStep === 1) {
                const minAmount = parseFloat(document.getElementById('cashierMinAmount').value);
                if (!minAmount || minAmount < 0.01) {
                    document.getElementById('cashierMinAmount').value = '0.01';
                }
                const webhook = document.getElementById('cashierWebhook').value.trim();
                if (!webhook) {
                    showNotification('Введите URL для уведомлений (webhook)', 'error');
                    return;
                }
                createCashier();
                return;
            }
            
            if (currentStep < steps.length - 1) {
                updateStep(currentStep + 1);
            } else {
                bootstrap.Modal.getInstance(document.getElementById('createCashierModal')).hide();
                loadCashiers();
            }
        });

        prevBtn.addEventListener('click', function() {
            if (currentStep > 0) {
                updateStep(currentStep - 1);
            }
        });


        if (typeof window.initCustomSelects === 'function') {
            window.initCustomSelects();
        }

        if (typeof window.initUserDropdown === 'function') {
            window.initUserDropdown();
        }
        
        createCashierModal.addEventListener('hidden.bs.modal', function() {
            resetCashierForm();
            loadCashiers();
        });

        loadCashiers();
        
        setInterval(function() {
            loadCashiers(false);
            if (typeof window.updateTotalBalance === 'function') {
                window.updateTotalBalance();
            }
        }, 10000);

        <?php if (isset($_SESSION['error_message'])): ?>
            showNotification('<?php echo htmlspecialchars($_SESSION['error_message'], ENT_QUOTES); ?>', 'error');
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    });

    function loadCashiers(showLoading = true) {
        const grid = document.getElementById('cashiersGrid');
        const limit = document.getElementById('cashiersLimit');
        const emptyState = document.getElementById('emptyState');

        if (grid && showLoading) {
            grid.innerHTML = '<div class="text-center p-4"><div class="spinner-border" role="status"><span class="visually-hidden">Загрузка...</span></div></div>';
        }
        
        fetch('/core/api/cashiers.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Данные касс получены:', data);

                let cashiersList = null;
                if (data && Array.isArray(data)) {
                    cashiersList = data;
                } else if (data && data.cashiers && Array.isArray(data.cashiers)) {
                    cashiersList = data.cashiers;
                } else if (data && data.status === 'ok' && Array.isArray(data.cashiers)) {
                    cashiersList = data.cashiers;
                } else if (data && data.success !== false && Array.isArray(data.cashiers)) {
                    cashiersList = data.cashiers;
                }
                
                if (cashiersList && cashiersList.length > 0) {
                    displayCashiers(cashiersList);
                    if (grid) grid.style.display = 'grid';
                    if (emptyState) emptyState.style.display = 'none';
                    if (typeof window.updateTotalBalance === 'function') {
                        window.updateTotalBalance();
                    }
                } else {
                    if (grid) {
                        grid.innerHTML = '';
                        grid.style.display = 'none';
                    }
                    if (emptyState) emptyState.style.display = 'block';
                    if (limit) limit.textContent = '0 касс';
                    if (cashiersList === null) {
                        console.warn('Неверный формат данных касс:', data);
                        if (grid) {
                            grid.innerHTML = '<div class="alert alert-warning">Неверный формат ответа сервера</div>';
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки касс:', error);
                if (grid) {
                    grid.innerHTML = '<div class="alert alert-danger">Ошибка загрузки касс: ' + error.message + '</div>';
                }
                if (limit) limit.textContent = 'Ошибка';
            });
    }

    function displayCashiers(cashiers) {
        const grid = document.getElementById('cashiersGrid');
        const limit = document.getElementById('cashiersLimit');
        const emptyState = document.getElementById('emptyState');
        
        if (!grid) {
            console.error('Элемент cashiersGrid не найден');
            return;
        }
        
        if (!cashiers || cashiers.length === 0) {
            grid.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            if (limit) limit.textContent = '0 касс';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';
        if (limit) limit.textContent = `${cashiers.length} касс${cashiers.length > 1 ? 'ы' : 'а'}`;
        
        try {
            grid.innerHTML = cashiers.map(cashier => {
                if (!cashier) {
                    console.warn('Пустой объект кассы:', cashier);
                    return '';
                }
                
                const createdDate = cashier.created_at ? new Date(cashier.created_at).toLocaleDateString('ru-RU') : 'Не указана';
                const statusClass = (cashier.status === 'active') ? 'status-active' : 'status-inactive';
                const statusText = (cashier.status === 'active') ? 'Активна' : 'Неактивна';
                const balance = parseFloat(cashier.balance || 0);
                const totalBalance = isNaN(balance) ? '0.00' : balance.toFixed(2);
                const totalTransactions = parseInt(cashier.total_transactions || 0) || 0;
                const cashierIdRaw = String(cashier.id || cashier.cashier_id || '');
                const cashierId = escapeHtml(cashierIdRaw);
                const currency = (cashier.currency || 'TON').toUpperCase();
                const cashierName = escapeHtml(cashier.name || 'Без названия');
                const cashierIdEscaped = cashierIdRaw.replace(/'/g, "\\'");
                
                return `
                    <div class="cashier-card">
                        <div class="cashier-header">
                            <div class="cashier-name">${cashierName}</div>
                            <div class="cashier-status ${statusClass}">${statusText}</div>
                        </div>
                        <div class="cashier-info">
                            <div class="cashier-info-item">
                                <span class="info-label">ID кассы:</span>
                                <span class="info-value">${cashierId}</span>
                            </div>
                            <div class="cashier-info-item">
                                <span class="info-label">Баланс:</span>
                                <span class="info-value">${totalBalance} ${currency}</span>
                            </div>
                            <div class="cashier-info-item">
                                <span class="info-label">Транзакций:</span>
                                <span class="info-value">${totalTransactions}</span>
                            </div>
                            <div class="cashier-info-item">
                                <span class="info-label">Создана:</span>
                                <span class="info-value">${createdDate}</span>
                            </div>
                        </div>
                        <div class="cashier-actions">
                            <a href="/csh.php?cashier_id=${encodeURIComponent(cashierIdRaw)}" class="btn-cashier btn-cashier-primary" style="text-decoration: none; display: inline-block; text-align: center;">Управление</a>
                            <button class="btn-cashier btn-cashier-outline" onclick="toggleCashierStatus('${cashierIdEscaped}', '${cashier.status}')">
                                ${cashier.status === 'active' ? 'Деактивировать' : 'Активировать'}
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        } catch (error) {
            console.error('Ошибка при отображении касс:', error);
            console.error('Детали ошибки:', {
                message: error.message,
                stack: error.stack,
                cashiers: cashiers
            });
            if (grid) {
                grid.innerHTML = '<div class="alert alert-danger">Ошибка отображения касс: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }


    function createCashier() {
        const name = document.getElementById('cashierName').value.trim();
        const currency = document.getElementById('cashierCurrency').value;
        const jettonAddress = document.getElementById('cashierJettonAddress').value.trim();
        const webhook = document.getElementById('cashierWebhook').value.trim();
        
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
            name: name,
            description: document.getElementById('cashierDescription').value || null,
            category: document.getElementById('cashierCategory').value || null,
            currency: currency,
            min_amount: minAmount,
            max_amount: maxAmount,
            webhook_url: webhook,
            jetton_address: currency === 'jetton' ? jettonAddress : null
        };
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('/core/api/cashiers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({...data, csrf_token: csrfToken})
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.message || `HTTP error! status: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(result => {
            console.log('Результат создания кассы:', result);
            if (result.success === true || (result.status === 'ok' && result.cashier)) {
                const cashier = result.cashier || result;
                const cashierId = cashier.id || cashier.cashier_id || result.cashier?.id || result.cashier_id;
                if (!cashierId) {
                    throw new Error('Не получен ID кассы');
                }

                updateStep(2);
                showNotification('Касса успешно создана!', 'success');
                
            } else {
                const errorMsg = result.message || result.detail || 'Неизвестная ошибка';
                console.error('Ошибка создания кассы:', errorMsg, result);
                showNotification('Ошибка создания кассы: ' + errorMsg, 'error');
                
            }
        })
        .catch(error => {
            console.error('Ошибка при создании кассы:', error);
            const errorMsg = error.message || 'Ошибка соединения с сервером';
            showNotification('Ошибка создания кассы: ' + errorMsg, 'error');
        });
    }
    
    function resetCashierForm() {
        document.getElementById('cashierName').value = '';
        document.getElementById('cashierDescription').value = '';
        
        const categorySelect = document.getElementById('cashierCategorySelect');
        const categoryText = categorySelect.querySelector('.custom-select-text') || categorySelect.querySelector('.custom-select-placeholder');
        if (categoryText) {
            categoryText.textContent = 'Выберите категорию';
            categoryText.classList.add('custom-select-placeholder');
            categoryText.classList.remove('custom-select-text');
        }
        categorySelect.setAttribute('data-value', '');
        document.getElementById('cashierCategory').value = '';
        
        const currencySelect = document.getElementById('cashierCurrencySelect');
        const currencyText = currencySelect.querySelector('.custom-select-text');
        if (currencyText) {
            currencyText.textContent = 'TON';
        }
        currencySelect.setAttribute('data-value', 'ton');
        document.getElementById('cashierCurrency').value = 'ton';
        document.getElementById('jettonAddressGroup').style.display = 'none';
        
        document.getElementById('cashierMinAmount').value = '0.01';
        document.getElementById('cashierMaxAmount').value = '';
        document.getElementById('cashierWebhook').value = '';
        document.getElementById('cashierJettonAddress').value = '';
        updateStep(0);
    }

    function toggleCashierStatus(cashierId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
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
        .then(response => {
            console.log('Response status:', response.status, response.statusText);
            return response.text().then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('Parsed data:', data);
                    if (!response.ok) {
                        const errorMsg = typeof data.message === 'string' ? data.message : 
                                        (typeof data.detail === 'string' ? data.detail : 
                                        (typeof data === 'string' ? data : `HTTP error! status: ${response.status}`));
                        throw new Error(errorMsg);
                    }
                    return data;
                } catch (e) {
                    if (e instanceof SyntaxError) {
                        console.error('JSON parse error:', e, 'Text:', text);
                        if (!response.ok) {
                            throw new Error(text || `HTTP error! status: ${response.status}`);
                        }
                        return { success: false, message: 'Неверный формат ответа: ' + text };
                    }
                    throw e;
                }
            });
        })
        .then(result => {
            console.log('Result:', result);
            if (result && result.success === true) {
                showNotification(`Касса ${newStatus === 'active' ? 'активирована' : 'деактивирована'}`, 'success');
                loadCashiers();
            } else {
                let errorMsg = 'Неизвестная ошибка';
                if (result) {
                    if (typeof result.message === 'string') {
                        errorMsg = result.message;
                    } else if (typeof result.detail === 'string') {
                        errorMsg = result.detail;
                    } else if (typeof result === 'string') {
                        errorMsg = result;
                    } else {
                        errorMsg = JSON.stringify(result);
                    }
                }
                console.error('Update status error:', errorMsg, 'Full result:', result);
                showNotification('Ошибка обновления статуса: ' + errorMsg, 'error');
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            let errorMsg = 'Ошибка обновления статуса кассы';
            if (error && typeof error.message === 'string') {
                errorMsg = error.message;
            } else if (typeof error === 'string') {
                errorMsg = error;
            } else if (error && error.toString) {
                errorMsg = error.toString();
            }
            showNotification(errorMsg, 'error');
            
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    const withdrawModal = document.getElementById('withdrawModal');
    if (withdrawModal) {
        withdrawModal.addEventListener('show.bs.modal', function() {
            const confirmBtn = document.getElementById('confirmWithdraw');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Вывести';
            }
            const errorDiv = document.getElementById('withdrawError');
            if (errorDiv) {
                errorDiv.style.display = 'none';
            }
            const cashierSelect = document.getElementById('withdrawCashierSelect');
            const cashierHidden = document.getElementById('withdrawCashier');
            const selectText = cashierSelect?.querySelector('.custom-select-placeholder') || cashierSelect?.querySelector('.custom-select-text');
            
            if (cashierHidden) cashierHidden.value = '';
            if (cashierSelect) {
                cashierSelect.setAttribute('data-value', '');
                cashierSelect.classList.remove('active');
            }
            if (selectText) {
                selectText.textContent = 'Выберите кассу...';
                if (selectText.classList.contains('custom-select-text')) {
                    selectText.classList.remove('custom-select-text');
                    selectText.classList.add('custom-select-placeholder');
                }
            }
            document.getElementById('withdrawAmount').value = '';
            document.getElementById('withdrawAmount').disabled = true;
            document.getElementById('withdrawWallet').value = '';
            document.getElementById('availableBalance').textContent = '0.00';
            document.getElementById('availableCurrency').textContent = 'TON';
            document.getElementById('withdrawError').style.display = 'none';
            document.getElementById('withdrawCashierDropdown')?.classList.remove('show');
            
            loadCashiersForWithdraw();
        });
    }

    function loadCashiersForWithdraw() {
        const cashierSelect = document.getElementById('withdrawCashierSelect');
        const cashierDropdown = document.getElementById('withdrawCashierDropdown');
        const cashierHidden = document.getElementById('withdrawCashier');
        const selectText = cashierSelect?.querySelector('.custom-select-placeholder') || cashierSelect?.querySelector('.custom-select-text');
        const amountInput = document.getElementById('withdrawAmount');
        const balanceSpan = document.getElementById('availableBalance');
        const currencySpan = document.getElementById('availableCurrency');
        
        if (!cashierSelect || !cashierDropdown || !selectText) return;
        
        selectText.textContent = 'Загрузка касс...';
        cashierSelect.style.pointerEvents = 'none';
        cashierDropdown.innerHTML = '';
        cashierDropdown.classList.remove('show');
        
        fetch('/core/api/cashiers.php')
            .then(response => response.json())
            .then(data => {
                cashierDropdown.innerHTML = '';
                
                if (data.success !== false && data.cashiers && Array.isArray(data.cashiers)) {
                    const cashiersWithBalance = data.cashiers.filter(c => parseFloat(c.balance || 0) > 0);
                    
                    if (cashiersWithBalance.length === 0) {
                        selectText.textContent = 'Нет касс с балансом';
                        cashierSelect.style.pointerEvents = 'none';
                        amountInput.disabled = true;
                        balanceSpan.textContent = '0.00';
                        currencySpan.textContent = 'TON';
                        return;
                    }
                    
                    cashiersWithBalance.forEach(cashier => {
                        const balance = parseFloat(cashier.balance || 0);
                        const currency = (cashier.currency || 'TON').toUpperCase();
                        const cashierId = cashier.id || cashier.cashier_id;
                        const cashierName = cashier.name || 'Без названия';
                        
                        const option = document.createElement('div');
                        option.className = 'custom-select-option';
                        const displayText = `${cashierName} (${balance.toFixed(2)} ${currency})`;
                        option.textContent = displayText;
                        option.setAttribute('data-value', cashierId);
                        option.dataset.balance = balance;
                        option.dataset.currency = currency;
                        option.dataset.name = cashierName;
                        
                        cashierDropdown.appendChild(option);
                    });
                    
                    selectText.textContent = 'Выберите кассу...';
                    if (selectText.classList.contains('custom-select-text')) {
                        selectText.classList.remove('custom-select-text');
                        selectText.classList.add('custom-select-placeholder');
                    }
                    cashierSelect.style.pointerEvents = 'auto';

                    initWithdrawCashierSelect();
                } else {
                    selectText.textContent = 'Ошибка загрузки касс';
                    cashierSelect.style.pointerEvents = 'none';
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки касс:', error);
                selectText.textContent = 'Ошибка загрузки';
                cashierSelect.style.pointerEvents = 'none';
            });
    }

    function initWithdrawCashierSelect() {
        initCustomSelect('withdrawCashierSelect', 'withdrawCashierDropdown', 'withdrawCashier', function(value) {
            const selectedOption = document.querySelector(`#withdrawCashierDropdown .custom-select-option[data-value="${value}"]`);
            if (selectedOption) {
                const balance = parseFloat(selectedOption.dataset.balance);
                const currency = (selectedOption.dataset.currency || 'TON').toUpperCase();
                const name = selectedOption.dataset.name;
                const amountInput = document.getElementById('withdrawAmount');
                const balanceSpan = document.getElementById('availableBalance');
                const currencySpan = document.getElementById('availableCurrency');
                const selectText = document.querySelector('#withdrawCashierSelect .custom-select-text') || 
                                 document.querySelector('#withdrawCashierSelect .custom-select-placeholder');
                
                if (selectText) {
                    selectText.textContent = `${name} (${balance.toFixed(2)} ${currency})`;
                    if (selectText.classList.contains('custom-select-placeholder')) {
                        selectText.classList.remove('custom-select-placeholder');
                        selectText.classList.add('custom-select-text');
                    }
                }
                
                balanceSpan.textContent = balance.toFixed(2);
                currencySpan.textContent = currency;
                amountInput.disabled = false;
                amountInput.max = balance;
            }
        });
    }

    document.getElementById('confirmWithdraw')?.addEventListener('click', function() {
        const cashierId = document.getElementById('withdrawCashier')?.value;
        const amount = parseFloat(document.getElementById('withdrawAmount').value);
        const wallet = document.getElementById('withdrawWallet').value.trim();
        const errorDiv = document.getElementById('withdrawError');

        if (!cashierId) {
            errorDiv.textContent = 'Выберите кассу';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (!amount || amount <= 0) {
            errorDiv.textContent = 'Введите корректную сумму';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (amount < 0.01) {
            errorDiv.textContent = 'Минимальная сумма вывода: 0.01';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (!wallet) {
            errorDiv.textContent = 'Введите адрес кошелька';
            errorDiv.style.display = 'block';
            return;
        }
        
        errorDiv.style.display = 'none';
        this.disabled = true;
        this.textContent = 'Обработка...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
        showNotification('Запрос на вывод средств отправлен. Обработка...', 'info');
        
        fetch('/core/api/withdraw.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                cashier_id: parseInt(cashierId),
                amount: amount,
                wallet: wallet,
                csrf_token: csrfToken
            })
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.message || `HTTP error! status: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const withdrawData = data.data || {};
                const txHash = withdrawData.tx_hash || '';
                const currency = (withdrawData.currency || 'TON').toUpperCase();
                const requestId = withdrawData.request_id || '';

                let processingMessage = `Вывод ${amount.toFixed(2)} ${currency} принят в обработку`;
                if (txHash) {
                    const shortHash = txHash.length > 16 ? 
                        `${txHash.substring(0, 8)}...${txHash.substring(txHash.length - 8)}` : 
                        txHash;
                    processingMessage += `. Хеш: ${shortHash}`;
                }
                
                showNotification(processingMessage, 'info');

                const confirmBtn = document.getElementById('confirmWithdraw');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Вывести';
                
                bootstrap.Modal.getInstance(document.getElementById('withdrawModal')).hide();

                if (requestId) {
                    checkWithdrawStatus(requestId, cashierId, amount, currency);
                }

                setTimeout(() => {
                    if (typeof window.updateTotalBalance === 'function') {
                        window.updateTotalBalance();
                    }
                    loadCashiers();
                }, 500);
            } else {
                const errorMessage = data.message || data.detail || 'Ошибка при выводе средств';
                errorDiv.textContent = errorMessage;
                errorDiv.style.display = 'block';

                showNotification(errorMessage, 'error');
                
                this.disabled = false;
                this.textContent = 'Вывести';
            }
        })
        .catch(error => {
            console.error('Ошибка при выводе средств:', error);
            const errorMessage = error.message || 'Ошибка соединения с сервером';
            errorDiv.textContent = errorMessage;
            errorDiv.style.display = 'block';

            showNotification(errorMessage, 'error');
            
            this.disabled = false;
            this.textContent = 'Вывести';
        });
    });

    function checkWithdrawStatus(requestId, cashierId, amount, currency) {
        let attempts = 0;
        const maxAttempts = 60;
        const checkInterval = 5000;
        
        const statusCheck = setInterval(async () => {
            attempts++;
            
            try {
                const response = await fetch(`/csh.php?cashier_id=${cashierId}&action=get_all_transactions&status=all&limit=10`);
                const data = await response.json();
                
                if (data.success && data.transactions) {
                    const withdrawTx = data.transactions.find(tx => 
                        tx.type === 'withdraw' && 
                        (tx.request_id === requestId || 
                         (Math.abs(parseFloat(tx.price) - amount) < 0.01 && 
                          tx.currency === currency &&
                          Date.now() - new Date(tx.time_recorded).getTime() < 300000))
                    );
                    
                    if (withdrawTx) {
                        if (withdrawTx.status === 'success') {
                            clearInterval(statusCheck);
                            if (typeof showNotification === 'function') {
                                const hash = withdrawTx.hash ? 
                                    (withdrawTx.hash.length > 16 ? 
                                        `${withdrawTx.hash.substring(0, 8)}...${withdrawTx.hash.substring(withdrawTx.hash.length - 8)}` : 
                                        withdrawTx.hash) : '';
                                showNotification(
                                    `Вывод ${amount.toFixed(2)} ${currency} выполнен успешно!${hash ? ' Хеш: ' + hash : ''}`,
                                    'success'
                                );
                            }
                            if (typeof window.updateTotalBalance === 'function') {
                                window.updateTotalBalance();
                            }
                            if (typeof loadCashiers === 'function') {
                                loadCashiers();
                            }
                        } else if (withdrawTx.status === 'failed') {
                            clearInterval(statusCheck);
                            showNotification(
                                `Вывод ${amount.toFixed(2)} ${currency} не выполнен. Транзакция отклонена.`,
                                'error'
                            );
                            if (typeof window.updateTotalBalance === 'function') {
                                window.updateTotalBalance();
                            }
                            if (typeof loadCashiers === 'function') {
                                loadCashiers();
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Ошибка проверки статуса вывода:', error);
            }
            
            if (attempts >= maxAttempts) {
                clearInterval(statusCheck);
                showNotification(
                    `Проверка статуса вывода ${amount.toFixed(2)} ${currency} завершена. Проверьте статус вручную.`,
                    'warning'
                );
            }
        }, checkInterval);
    }

    let currentApiToken = null;
    let apiTokenFormMode = 'show';

    function resetApiTokenState() {
        const tokenContainer = document.getElementById('apiTokenContainer');
        const passwordForm = document.getElementById('apiTokenPasswordForm');
        const showPasswordBtn = document.getElementById('showPasswordBtn');
        const copyTokenBtn = document.getElementById('copyTokenBtn');
        const passwordInput = document.getElementById('apiTokenPassword');
        const errorDiv = document.getElementById('apiTokenPasswordError');
        const submitBtn = document.getElementById('apiTokenSubmitBtn');
        
        if (tokenContainer) tokenContainer.style.display = 'none';
        if (passwordForm) passwordForm.style.display = 'none';
        if (showPasswordBtn) showPasswordBtn.style.display = 'block';
        if (copyTokenBtn) copyTokenBtn.style.display = 'none';
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.disabled = false;
        }
        if (errorDiv) errorDiv.style.display = 'none';
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-eye me-1"></i> Показать токен';
            submitBtn.disabled = false;
        }
        currentApiToken = null;
        apiTokenFormMode = 'show';

        setTimeout(applyMobileStyles, 10);
    }

    function applyMobileStyles() {
        const apiTokenActions = document.getElementById('apiTokenActions');
        if (!apiTokenActions) return;
        
        const isMobile = window.innerWidth <= 991;
        
        if (isMobile) {
            apiTokenActions.style.setProperty('flex-direction', 'row', 'important');
            apiTokenActions.style.setProperty('display', 'flex', 'important');
            apiTokenActions.style.setProperty('gap', '0.5rem', 'important');

            const buttons = apiTokenActions.querySelectorAll('button');
            buttons.forEach(btn => {
                const computedStyle = window.getComputedStyle(btn);
                if (computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden') {
                    btn.style.setProperty('width', 'auto', 'important');
                    btn.style.setProperty('flex', '1 1 0%', 'important');
                    btn.style.setProperty('min-width', '0', 'important');
                }
            });
        } else {
            apiTokenActions.style.setProperty('flex-direction', 'column', 'important');
            const buttons = apiTokenActions.querySelectorAll('button');
            buttons.forEach(btn => {
                btn.style.setProperty('width', '100%', 'important');
                btn.style.setProperty('flex', 'none', 'important');
            });
        }
    }

    window.showPasswordForm = function() {
        const passwordForm = document.getElementById('apiTokenPasswordForm');
        const showPasswordBtn = document.getElementById('showPasswordBtn');
        const passwordInput = document.getElementById('apiTokenPassword');
        const errorDiv = document.getElementById('apiTokenPasswordError');
        const submitBtn = document.getElementById('apiTokenSubmitBtn');
        
        if (!passwordForm || !showPasswordBtn) {
            console.error('Элементы формы не найдены');
            return;
        }
        
        apiTokenFormMode = 'show';

        const tokenContainer = document.getElementById('apiTokenContainer');
        const copyTokenBtn = document.getElementById('copyTokenBtn');
        if (tokenContainer) tokenContainer.style.display = 'none';
        if (copyTokenBtn) copyTokenBtn.style.display = 'none';

        passwordForm.style.display = 'block';
        showPasswordBtn.style.display = 'none';
        if (errorDiv) errorDiv.style.display = 'none';
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.disabled = false;
            setTimeout(() => passwordInput.focus(), 100);
        }

        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-eye me-1"></i> Показать токен';
            submitBtn.disabled = false;
        }

        requestAnimationFrame(() => {
            applyMobileStyles();
            setTimeout(() => {
                applyMobileStyles();
                setTimeout(applyMobileStyles, 100);
            }, 50);
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth >= 992) {
            const content = document.getElementById('apiTokenContent');
            const chevron = document.getElementById('apiTokenChevron');
            if (content && chevron) {
                content.style.display = 'block';
                chevron.style.transform = 'rotate(180deg)';
            }
        }

        applyMobileStyles();
    });

    window.addEventListener('resize', function() {
        applyMobileStyles();
    });

    window.toggleApiTokenSection = function() {
        const content = document.getElementById('apiTokenContent');
        const chevron = document.getElementById('apiTokenChevron');
        
        if (!content || !chevron) {
            console.error('Элементы API токена не найдены', {content, chevron});
            return;
        }

        const isMobile = window.innerWidth < 992;
        if (isMobile) {
            const isHidden = content.style.display === 'none' || content.style.display === '';
            if (isHidden) {
                content.style.display = 'block';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                content.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        }
    };

    function submitApiTokenForm() {
        const passwordInput = document.getElementById('apiTokenPassword');
        const password = passwordInput ? passwordInput.value.trim() : '';
        const errorDiv = document.getElementById('apiTokenPasswordError');
        const submitBtn = document.getElementById('apiTokenSubmitBtn');
        
        if (!password) {
            if (errorDiv) {
                errorDiv.textContent = 'Введите пароль';
                errorDiv.style.display = 'block';
            }
            return;
        }
        
        if (errorDiv) errorDiv.style.display = 'none';
        if (passwordInput) passwordInput.disabled = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = apiTokenFormMode === 'regenerate'
                ? '<i class="fas fa-spinner fa-spin me-1"></i> Генерация...'
                : '<i class="fas fa-spinner fa-spin me-1"></i> Проверка...';
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const formData = new FormData();
        
        if (apiTokenFormMode === 'regenerate') {
            formData.append('page', 'regenerate_api_token');
        } else {
            formData.append('page', 'verify_password_for_token');
        }
        
        formData.append('password', password);
        formData.append('csrf_token', csrfToken);
        
        fetch('/core/events/listener.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                throw new Error(`Ошибка сервера: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (passwordInput) passwordInput.disabled = false;
            if (submitBtn) submitBtn.disabled = false;
            
            if (data.success) {
                if (apiTokenFormMode === 'regenerate') {
                    resetApiTokenState();
                    showNotification('Новый API токен успешно сгенерирован. Нажмите "Показать токен" для просмотра.', 'success');
                } else {
                    if (data.api_token) {
                        currentApiToken = data.api_token;
                        const tokenContainer = document.getElementById('apiTokenContainer');
                        const tokenValue = document.getElementById('apiTokenValue');
                        const passwordForm = document.getElementById('apiTokenPasswordForm');
                        const showPasswordBtn = document.getElementById('showPasswordBtn');
                        const copyTokenBtn = document.getElementById('copyTokenBtn');
                        
                        if (tokenValue) tokenValue.value = data.api_token;
                        if (tokenContainer) tokenContainer.style.display = 'block';
                        if (passwordForm) passwordForm.style.display = 'none';
                        if (showPasswordBtn) showPasswordBtn.style.display = 'none';
                        if (copyTokenBtn) copyTokenBtn.style.display = 'block';
                        
                        setTimeout(applyMobileStyles, 10);
                        showNotification('Токен отображен', 'success');
                    }
                }
            } else {
                if (errorDiv) {
                    errorDiv.textContent = data.message || (apiTokenFormMode === 'regenerate' ? 'Ошибка при генерации токена' : 'Неверный пароль');
                    errorDiv.style.display = 'block';
                }
                if (passwordInput) {
                    passwordInput.focus();
                }
                if (submitBtn) {
                    submitBtn.innerHTML = apiTokenFormMode === 'regenerate'
                        ? '<i class="fas fa-sync-alt me-1"></i> Подтвердить генерацию'
                        : '<i class="fas fa-eye me-1"></i> Показать токен';
                }
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            if (passwordInput) {
                passwordInput.disabled = false;
                passwordInput.focus();
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = apiTokenFormMode === 'regenerate'
                    ? '<i class="fas fa-sync-alt me-1"></i> Подтвердить генерацию'
                    : '<i class="fas fa-eye me-1"></i> Показать токен';
            }
            if (errorDiv) {
                errorDiv.textContent = apiTokenFormMode === 'regenerate'
                    ? 'Ошибка при генерации токена: ' + error.message
                    : 'Ошибка при проверке пароля';
                errorDiv.style.display = 'block';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('apiTokenPassword');
        if (passwordInput) {
            passwordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    submitApiTokenForm();
                }
            });
        }
    });

    function copyApiToken() {
        if (!currentApiToken) {
            showNotification('Сначала покажите токен, введя пароль', 'warning');
            return;
        }
        
        navigator.clipboard.writeText(currentApiToken).then(() => {
            showNotification('API токен скопирован в буфер обмена', 'success');
        }).catch(err => {
            console.error('Ошибка копирования:', err);
            const textArea = document.createElement('textarea');
            textArea.value = currentApiToken;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                showNotification('API токен скопирован в буфер обмена', 'success');
            } catch (err) {
                showNotification('Не удалось скопировать токен', 'error');
            }
            document.body.removeChild(textArea);
        });
    }

    function regenerateApiToken() {
        const passwordForm = document.getElementById('apiTokenPasswordForm');
        const showPasswordBtn = document.getElementById('showPasswordBtn');
        const passwordInput = document.getElementById('apiTokenPassword');
        const errorDiv = document.getElementById('apiTokenPasswordError');
        const submitBtn = document.getElementById('apiTokenSubmitBtn');
        const tokenContainer = document.getElementById('apiTokenContainer');
        const copyTokenBtn = document.getElementById('copyTokenBtn');
        
        if (!passwordForm || !passwordInput) {
            showNotification('Элементы формы не найдены', 'error');
            return;
        }
        
        const isFormVisible = passwordForm.style.display !== 'none' && passwordForm.style.display !== '';
        const hasPassword = passwordInput.value.trim().length > 0;

        if (isFormVisible && hasPassword) {
            apiTokenFormMode = 'regenerate';
            submitApiTokenForm();
            return;
        }
        
        apiTokenFormMode = 'regenerate';

        if (tokenContainer) tokenContainer.style.display = 'none';
        if (copyTokenBtn) copyTokenBtn.style.display = 'none';

        passwordForm.style.display = 'block';
        if (showPasswordBtn) showPasswordBtn.style.display = 'none';
        if (errorDiv) errorDiv.style.display = 'none';
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.disabled = false;
            setTimeout(() => passwordInput.focus(), 100);
        }
        
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-eye me-1"></i> Показать токен';
            submitBtn.disabled = false;
        }

        showNotification('Введите пароль и нажмите "Новый токен" еще раз для подтверждения генерации. Старый токен перестанет работать!', 'warning');
        setTimeout(applyMobileStyles, 10);
    }

    function downloadReport(format = 'csv') {
        const btn = document.querySelector('.btn.btn-ton-outline[onclick="downloadReport()"]');
        if (!btn) {
            const buttons = document.querySelectorAll('.btn.btn-ton-outline');
            for (let b of buttons) {
                if (b.textContent.trim() === 'Скачать отчет' || b.textContent.includes('Скачать отчет')) {
                    btn = b;
                    break;
                }
            }
        }
        
        if (btn) {
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Генерация...';
            
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            if (format === 'html') {
                fetch('/core/api/export-all-cashiers-html.php', {
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
                    link.download = 'all_cashiers_report_' + new Date().toISOString().slice(0, 10) + '.html';
                    link.target = '_blank';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);
                    btn.disabled = false;
                    btn.textContent = originalText;
                    showNotification('HTML отчет с графиками открыт', 'success');
                })
                .catch(error => {
                    console.error('Ошибка экспорта:', error);
                    btn.disabled = false;
                    btn.textContent = originalText;
                    showNotification('Ошибка при экспорте данных', 'error');
                });
                return;
            }
            
            fetch('/core/api/export-all-cashiers.php', {
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
                link.download = 'all_cashiers_report_' + new Date().toISOString().slice(0, 10) + '.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
                btn.disabled = false;
                btn.textContent = originalText;
                showNotification('Отчет успешно скачан', 'success');
            })
            .catch(error => {
                console.error('Ошибка экспорта:', error);
                btn.disabled = false;
                btn.textContent = originalText;
                showNotification('Ошибка при экспорте данных', 'error');
            });
        } else {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            if (format === 'html') {
                fetch('/core/api/export-all-cashiers-html.php', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                })
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    window.open(url, '_blank');
                })
                .catch(error => {
                    console.error('Ошибка экспорта:', error);
                    showNotification('Ошибка при экспорте данных', 'error');
                });
            } else {
                fetch('/core/api/export-all-cashiers.php', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                })
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = 'all_cashiers_report_' + new Date().toISOString().slice(0, 10) + '.csv';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);
                })
                .catch(error => {
                    console.error('Ошибка экспорта:', error);
                    showNotification('Ошибка при экспорте данных', 'error');
                });
            }
        }
    }
</script>
</body>
</html>
