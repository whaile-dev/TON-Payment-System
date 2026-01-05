<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/http_client.php');
$config = getConfig();
$support_url = $config['support']['telegram_url'] ?? 'https://t.me/whaile_dev';
header('Content-Type: text/html; charset=utf-8');

function showErrorPage($title, $message, $details = null, $support_url = 'https://t.me/whaile_dev') {
    ?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка - <?php echo htmlspecialchars($title); ?></title>
    <link href="scripts/libs/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="scripts/libs/font-awesome/css/all.min.css" rel="stylesheet">
    <link href="scripts/css/custom.css" rel="stylesheet">
    <style>
        body {
            background: var(--ton-bg);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            padding: 0;
            margin: 0;
            color: var(--ton-text);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-container {
            max-width: 500px;
            width: 100%;
            padding: 20px;
        }
        .error-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            text-align: center;
        }
        .error-header {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.2));
            padding: 40px 30px;
            border-bottom: 1px solid var(--ton-border);
        }
        .error-icon {
            font-size: 64px;
            color: var(--ton-error);
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--ton-text);
            margin-bottom: 10px;
        }
        .error-body {
            padding: 30px;
        }
        .error-message {
            font-size: 16px;
            color: var(--ton-text-secondary);
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .error-details {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
            font-size: 14px;
            color: var(--ton-text-secondary);
            text-align: left;
            font-family: 'Courier New', monospace;
        }
        .error-help {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--ton-border);
            font-size: 14px;
            color: var(--ton-text-secondary);
        }
        .error-help a {
            color: var(--ton-primary);
            text-decoration: none;
        }
        .error-help a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="error-title"><?php echo htmlspecialchars($title); ?></div>
            </div>
            <div class="error-body">
                <div class="error-message">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php if ($details): ?>
                <div class="error-details">
                    <strong>Детали:</strong><br>
                    <?php echo htmlspecialchars($details); ?>
                </div>
                <?php endif; ?>
                <div class="error-help">
                    <p>Проверьте правильность параметров в URL и попробуйте снова.</p>
                    <p>Если проблема сохраняется, обратитесь в <a href="<?php echo htmlspecialchars($support_url); ?>" target="_blank">техническую поддержку</a>.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

$transaction_uuid_raw = isset($_GET['transaction_uuid']) ? trim($_GET['transaction_uuid']) : null;
$transaction_uuid = null;
if ($transaction_uuid_raw) {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $transaction_uuid_raw)) {
        $transaction_uuid = $transaction_uuid_raw;
    } else {
        $filtered = filter_var($transaction_uuid_raw, FILTER_VALIDATE_REGEXP, [
            'options' => ['regexp' => '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i']
        ]);
        if ($filtered !== false) {
            $transaction_uuid = $filtered;
        }
    }
}

$cashier_id = isset($_GET['cashier_id']) ? trim($_GET['cashier_id']) : null;
$amount = isset($_GET['amount']) ? trim($_GET['amount']) : null;
$wallet = isset($_GET['wallet']) ? trim($_GET['wallet']) : null;
$payload = isset($_GET['payload']) ? trim($_GET['payload']) : null;
if ($payload && strlen($payload) > 1000) {
    $payload = substr($payload, 0, 1000);
}
$return_url = isset($_GET['return_url']) ? trim($_GET['return_url']) : null;
$payment_created_at = null;
if ($transaction_uuid) {
    $client = getHttpClient();
    $endpoint = '/payment_by_uuid/' . urlencode($transaction_uuid);
    $result = $client->get($endpoint);
    
    if ($result['error']) {
        showErrorPage('Ошибка соединения', 'Не удалось подключиться к серверу платежей', $result['error'], $support_url);
    }
    
    if ($result['http_code'] === 200 && $result['response']) {
        $payment_response = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in payment.php (UUID): " . json_last_error_msg());
            $transaction_uuid = null;
        } elseif ($payment_response && $payment_response['status'] === 'ok') {
            $payment_status = $payment_response['payment_status'] ?? 'pending';
            $final_statuses = ['confirmed', 'success', 'completed', 'failed', 'error', 'expired'];
            
            if (in_array(strtolower($payment_status), $final_statuses)) {
                if (in_array(strtolower($payment_status), ['confirmed', 'success', 'completed'])) {
                    $return_url = $payment_response['return_url'] ?? null;
                    if ($return_url && validateReturnURL($return_url)) {
                        header('Location: ' . $return_url);
                        exit;
                    }
                }
            }
            
            $payment_id = $payment_response['payment_id'];
            $wallet_to_send = isset($payment_response['wallet_to_send']) ? $payment_response['wallet_to_send'] : 'EQD__________________________________________0vo';
            $amount_exact = floatval($payment_response['amount']);
            $currency = $payment_response['currency'];
            $transaction_uuid = isset($payment_response['transaction_uuid']) ? $payment_response['transaction_uuid'] : $transaction_uuid;
            $payment_created_at = isset($payment_response['time_recorded']) ? intval($payment_response['time_recorded']) : null;
            $existing_payment = true;
            
            $has_other_params = false;
            foreach ($_GET as $key => $value) {
                if ($key !== 'transaction_uuid') {
                    $has_other_params = true;
                    break;
                }
            }
            
            if ($has_other_params) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
                $host = $_SERVER['HTTP_HOST'];
                $script = $_SERVER['SCRIPT_NAME'];
                $redirect_url = $protocol . "://" . $host . $script . '?transaction_uuid=' . urlencode($transaction_uuid);
                header('Location: ' . $redirect_url);
                exit;
            }
            
            $should_render_page = true;
        } else {
            $should_render_page = false;
        }
    } else {
        $should_render_page = false;
    }
    
    if (!$should_render_page) {
        $transaction_uuid = null;
    }
} else {
    $should_render_page = false;
}

if (!$transaction_uuid) {
    if (!$cashier_id || $cashier_id === '') {
        showErrorPage(
            'Отсутствует обязательный параметр',
            'Не указан ID кассы (cashier_id) в параметрах URL.',
            'Пример правильного URL: payment.php?cashier_id=1&amount=10.50&wallet=...',
            $support_url
        );
    }
    
    $cashier_id_int = intval($cashier_id);
    if ($cashier_id_int <= 0) {
        showErrorPage(
            'Неверный параметр',
            'ID кассы должен быть положительным числом.',
            'Получено: ' . htmlspecialchars($cashier_id) . ' (ожидается число больше 0)',
            $support_url
        );
}

    if (!$amount || $amount === '') {
        showErrorPage(
            'Отсутствует обязательный параметр',
            'Не указана сумма платежа (amount) в параметрах URL.',
            'Пример правильного URL: payment.php?cashier_id=1&amount=10.50&wallet=...',
            $support_url
        );
    }
    
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
        showErrorPage(
            'Неверный формат суммы',
            'Сумма должна быть числом с максимум 2 знаками после точки.',
            'Получено: ' . htmlspecialchars($amount) . ' (примеры правильных значений: 10, 10.5, 10.50)',
            $support_url
        );
    }
    
    $amount_float = floatval($amount);
    if ($amount_float <= 0) {
        showErrorPage(
            'Неверная сумма',
            'Сумма платежа должна быть больше нуля.',
            'Получено: ' . htmlspecialchars($amount) . ' (минимум: 0.01)',
            $support_url
        );
    }
    
    if ($amount_float < 0.01) {
        showErrorPage(
            'Сумма слишком мала',
            'Минимальная сумма платежа составляет 0.01.',
            'Получено: ' . htmlspecialchars($amount),
            $support_url
        );
}

if (!$wallet || empty($wallet)) {
        showErrorPage(
            'Отсутствует обязательный параметр',
            'Не указан адрес кошелька получателя (wallet) в параметрах URL.',
            'Пример правильного URL: payment.php?cashier_id=1&amount=10.50&wallet=UQ...',
            $support_url
        );
    }
    
    $wallet_clean = trim($wallet);
    if (strlen($wallet_clean) < 20) {
        showErrorPage(
            'Неверный формат адреса кошелька',
            'Адрес кошелька слишком короткий. Проверьте правильность адреса.',
            'Получено: ' . htmlspecialchars(substr($wallet_clean, 0, 50)) . '...',
            $support_url
        );
    }
    
    if ($return_url && !empty($return_url)) {
        if (!filter_var($return_url, FILTER_VALIDATE_URL)) {
            showErrorPage(
                'Неверный формат URL возврата',
                'Параметр return_url должен быть валидным URL.',
                'Получено: ' . htmlspecialchars($return_url) . ' (пример: https://example.com/success)',
                $support_url
            );
        }
        
        $parsed_url = parse_url($return_url);
        if (!isset($parsed_url['scheme']) || !in_array(strtolower($parsed_url['scheme']), ['http', 'https'])) {
            showErrorPage(
                'Неверный протокол URL возврата',
                'URL возврата должен использовать протокол http или https.',
                'Получено: ' . htmlspecialchars($return_url),
                $support_url
            );
        }
    }
}

$payment_data = [
    'cashier_id' => isset($cashier_id_int) ? $cashier_id_int : intval($cashier_id),
    'amount' => isset($amount_float) ? $amount_float : floatval($amount),
    'wallet' => isset($wallet_clean) ? $wallet_clean : trim($wallet)
];

if ($transaction_uuid) {
    $payment_data['transaction_uuid'] = $transaction_uuid;
}

if ($payload) {
    $payment_data['payload'] = $payload;
}

if ($return_url && !empty($return_url)) {
    if (function_exists('validateReturnURL') && validateReturnURL($return_url)) {
        $payment_data['return_url'] = $return_url;
    }
}

$existing_payment = false;

$paymentClient = getHttpClient();
$result = $paymentClient->post('/create_payment', $payment_data);

if ($result['http_code'] !== 200 || !$result['response']) {
    error_log("Payment API Error: HTTP " . $result['http_code'] . " - " . ($result['error'] ?? 'none'));
    $error_details = "HTTP код: " . $result['http_code'];
    if ($result['error']) {
        $error_details .= "\nОшибка cURL: " . $result['error'];
    }
    if ($result['response']) {
        $error_details .= "\nОтвет сервера: " . substr($result['response'], 0, 200);
    }
    showErrorPage(
        'Ошибка соединения',
        'Не удалось подключиться к платежной системе. Пожалуйста, попробуйте позже.',
        $error_details,
        $support_url
    );
}

$payment_response = json_decode($result['response'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg() . " - Response: " . substr($result['response'], 0, 500));
    showErrorPage(
        'Ошибка обработки данных',
        'Получен неверный ответ от платежной системы. Возможно, сервер временно недоступен.',
        'Ошибка JSON: ' . json_last_error_msg() . "\nПервые 200 символов ответа: " . htmlspecialchars(substr($result['response'], 0, 200)),
        $support_url
    );
}

if (!isset($payment_response['status']) || $payment_response['status'] !== 'ok') {
    $error_msg = isset($payment_response['message']) ? $payment_response['message'] : 'Неизвестная ошибка';
    $error_details = "Статус: " . (isset($payment_response['status']) ? htmlspecialchars($payment_response['status']) : 'не указан');
    if (isset($payment_response['error'])) {
        $error_details .= "\nОшибка: " . htmlspecialchars($payment_response['error']);
    }
    if (isset($payment_response['details'])) {
        $error_details .= "\nДетали: " . htmlspecialchars($payment_response['details']);
    }
    showErrorPage(
        'Ошибка создания платежа',
        htmlspecialchars($error_msg),
        $error_details,
        $support_url
    );
}

if (!isset($payment_response['payment_id']) || !isset($payment_response['wallet_to_send']) || !isset($payment_response['amount'])) {
    $missing_fields = [];
    if (!isset($payment_response['payment_id'])) $missing_fields[] = 'payment_id';
    if (!isset($payment_response['wallet_to_send'])) $missing_fields[] = 'wallet_to_send';
    if (!isset($payment_response['amount'])) $missing_fields[] = 'amount';
    
    showErrorPage(
        'Неполные данные',
        'Платежная система вернула неполные данные. Попробуйте создать платеж заново.',
        'Отсутствующие поля: ' . implode(', ', $missing_fields),
        $support_url
    );
}

$payment_id = $payment_response['payment_id'];
$wallet_to_send = $payment_response['wallet_to_send'];
$amount_exact = floatval($payment_response['amount']);
$currency = isset($payment_response['currency']) ? strtolower($payment_response['currency']) : 'ton';
$transaction_uuid = isset($payment_response['transaction_uuid']) ? $payment_response['transaction_uuid'] : null;
$payment_created_at = isset($payment_response['time_recorded']) ? intval($payment_response['time_recorded']) : null;
$return_url = isset($payment_response['return_url']) ? $payment_response['return_url'] : null;
$existing_payment = isset($existing_payment) ? $existing_payment : false;

if ($transaction_uuid) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $redirect_url = $protocol . "://" . $host . $script . '?transaction_uuid=' . urlencode($transaction_uuid);
    header('Location: ' . $redirect_url);
    exit;
}

if (!isset($should_render) || $should_render) {
?>

<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата <?php echo htmlspecialchars(strtoupper($currency ?? 'TON')); ?></title>
    <link href="scripts/libs/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="scripts/libs/font-awesome/css/all.min.css" rel="stylesheet">
    <link href="scripts/css/custom.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@gh-pages/qrcode.min.js"></script>
    <script src="scripts/js/app.js"></script>
    <style>
        body {
            background: var(--ton-bg);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            padding: 0;
            margin: 0;
            color: var(--ton-text);
        }

        .payment-container {
            max-width: 440px;
            margin: 0 auto;
        }

        .payment-card {
            min-width: 300px;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            margin: 20px;
            backdrop-filter: blur(20px);
        }

        .payment-header {
            background: var(--ton-gradient);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .payment-amount {
            font-size: 2.8rem;
            font-weight: 700;
            margin: 10px 0;
        }

        .payment-currency {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 500;
        }

        .payment-body {
            padding: 30px;
        }

        .qr-section {
            text-align: center;
            margin: 0 0 25px 0;
        }

        .qr-container {
            display: inline-block;
            padding: 20px;
            background: var(--ton-bg);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            margin-bottom: 15px;
            width: 100%;
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wallet-section {
            margin: 20px 0;
        }

        .wallet-address {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
            color: var(--ton-text);
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .copy-btn {
            width: 100%;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: var(--ton-primary);
            border: none;
            color: white;
        }

        .copy-btn:hover {
            background: var(--ton-primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--ton-glow);
            color: white !important;
        }

        .status-section {
            text-align: center;
            margin: 15px 0 0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            justify-content: center;
            width: 100%;
        }

        .status-pending {
            background: rgba(234, 179, 8, 0.15);
            color: var(--ton-warning);
            border: 1px solid rgba(234, 179, 8, 0.3);
        }

        .status-success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--ton-success);
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-error {
            background: rgba(239, 68, 68, 0.15);
            color: var(--ton-error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .timer {
            font-size: 14px;
            color: var(--ton-text-secondary);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .info-note {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            padding: 16px;
            font-size: 13px;
            color: var(--ton-text-secondary);
            text-align: center;
            margin-top: 20px;
        }

        .loading-spinner {
            display: none;
        }

        .success-animation {
            animation: successPulse 2s ease-in-out;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 10px;
            margin: 10px 0;
            font-size: 14px;
            color: var(--ton-error);
        }

        .text-muted {
            color: var(--ton-text-secondary) !important;
        }

        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @media (max-width: 480px) {
            .payment-card {
                margin: 10px;
                border-radius: 20px;
            }

            .payment-header {
                padding: 25px 20px;
            }

            .payment-body {
                padding: 25px 20px;
            }

            .payment-amount {
                font-size: 2.4rem;
            }

            .qr-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
<div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
    <div class="payment-container">
        <div class="payment-card">
            <div class="payment-header">
                <div class="payment-amount">
                    <?php echo number_format($amount_exact, 2); ?>
                </div>
                <div class="payment-currency">
                    <?php echo htmlspecialchars(strtoupper($currency)); ?>
                </div>
            </div>

            <div class="payment-body">
                <div class="qr-section">
                    <div class="qr-container">
                        <div id="qrcode"></div>
                    </div>
                    <p class="text-muted mb-0">Отсканируйте для оплаты</p>
                </div>

                <div class="wallet-section">
                    <button class="btn btn-primary copy-btn" id="copyButton">
                        <i class="fas fa-copy me-2"></i>Копировать адрес
                    </button>
                </div>

                <div class="status-section">
                    <div class="timer" id="timer">
                        Осталось времени: <span id="timeLeft">20:00</span>
                    </div>
                    <div id="paymentStatus" class="status-badge status-pending">
                        <i class="fas fa-clock"></i>
                        <span>Ожидание оплаты</span>
                        <div class="loading-spinner ms-2" id="loadingSpinner">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="errorArea" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const PAYMENT_ID = <?php echo json_encode($payment_id); ?>;
    const CURRENCY = <?php echo json_encode($currency); ?>;
    const AMOUNT = <?php echo floatval($amount_exact); ?>;
    const WALLET_ADDRESS = <?php echo json_encode($wallet_to_send); ?>;
    const TRANSACTION_UUID = <?php echo json_encode($transaction_uuid ?? null); ?>;
    const IS_EXISTING_PAYMENT = <?php echo isset($existing_payment) && $existing_payment ? 'true' : 'false'; ?>;
    const RETURN_URL = <?php echo json_encode($return_url ?? null); ?>;
    
    const PAYMENT_TIMEOUT_MS = 20 * 60 * 1000;
    
    const PAYMENT_CREATED_AT_RAW = <?php echo isset($payment_created_at) && $payment_created_at ? $payment_created_at : 'null'; ?>;
    let PAYMENT_CREATED_AT = Date.now();
    
    if (PAYMENT_CREATED_AT_RAW !== null) {
        PAYMENT_CREATED_AT = PAYMENT_CREATED_AT_RAW;
    } else if (TRANSACTION_UUID) {
        const savedData = localStorage.getItem('payment_uuid_' + TRANSACTION_UUID);
        if (savedData) {
            try {
                const parsed = JSON.parse(savedData);
                if (parsed.timestamp) {
                    PAYMENT_CREATED_AT = parsed.timestamp;
                }
            } catch (e) {
                console.warn('Failed to parse saved payment data');
            }
        }
    }
    
    if (TRANSACTION_UUID) {
        localStorage.setItem('payment_uuid_' + TRANSACTION_UUID, JSON.stringify({
            payment_id: PAYMENT_ID,
            currency: CURRENCY,
            amount: AMOUNT,
            timestamp: PAYMENT_CREATED_AT
        }));
        
        const url = new URL(window.location.href);
        const cleanUrl = url.origin + url.pathname + '?transaction_uuid=' + encodeURIComponent(TRANSACTION_UUID);
        if (window.location.href !== cleanUrl) {
            window.history.replaceState({}, '', cleanUrl);
        }
    }

    let qrcode = null;
    let statusCheckAttempts = 0;
    const MAX_STATUS_ATTEMPTS = 3000;
    const STATUS_CHECK_INTERVAL = 5000;
    let statusCheckInterval = null;
    let timerInterval = null;

    function generateQRCode() {
        try {
            const qrcodeElement = document.getElementById('qrcode');
            qrcodeElement.innerHTML = '';

            let qrText;
            if (CURRENCY === 'ton') {
                const amountNano = Math.floor(AMOUNT * 1000000000);
                qrText = `ton://transfer/${WALLET_ADDRESS}?amount=${amountNano}`;
            } else {
                qrText = WALLET_ADDRESS;
            }

            const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            
            const qrContainer = qrcodeElement.closest('.qr-container');
            const computedStyle = window.getComputedStyle(qrContainer);
            const containerBg = computedStyle.backgroundColor;
            
            const colorDark = isDark ? "#ffffff" : "#000000";
            
            let colorLight;
            if (containerBg && containerBg !== 'rgba(0, 0, 0, 0)' && containerBg !== 'transparent') {
                const rgb = containerBg.match(/\d+/g);
                if (rgb && rgb.length >= 3) {
                    const r = parseInt(rgb[0]).toString(16).padStart(2, '0');
                    const g = parseInt(rgb[1]).toString(16).padStart(2, '0');
                    const b = parseInt(rgb[2]).toString(16).padStart(2, '0');
                    colorLight = `#${r}${g}${b}`;
                } else {
                    colorLight = isDark ? "#0a0a0a" : "#ffffff";
                }
            } else {
                colorLight = isDark ? "#0a0a0a" : "#ffffff";
            }
            
            qrcode = new QRCode(qrcodeElement, {
                text: qrText,
                width: 180,
                height: 180,
                colorDark: colorDark,
                colorLight: colorLight,
                correctLevel: QRCode.CorrectLevel.H
            });

        } catch (error) {
            console.error('QR code generation error:', error);
            document.getElementById('qrcode').innerHTML =
                '<div class="text-danger p-3">Ошибка генерации QR-кода</div>';
        }
    }

    function setupCopyButton() {
        const copyButton = document.getElementById('copyButton');
        if (!copyButton) return;

        copyButton.addEventListener('click', function() {
            if (typeof window.copyToClipboard === 'function') {
                window.copyToClipboard(WALLET_ADDRESS, copyButton);
            } else {
                navigator.clipboard.writeText(WALLET_ADDRESS).then(() => {
                    const originalHTML = copyButton.innerHTML;
                    copyButton.innerHTML = '<i class="fas fa-check me-2"></i>Скопировано!';
                    copyButton.classList.remove('btn-primary');
                    copyButton.classList.add('btn-success');
                    copyButton.disabled = true;
                    setTimeout(() => {
                        copyButton.innerHTML = originalHTML;
                        copyButton.classList.remove('btn-success');
                        copyButton.classList.add('btn-primary');
                        copyButton.disabled = false;
                    }, 2000);
                }).catch(() => {
                    showError('Не удалось скопировать адрес');
                });
            }
        });
    }

    async function checkPaymentStatus() {
        const elapsedTime = Date.now() - PAYMENT_CREATED_AT;
        if (elapsedTime > PAYMENT_TIMEOUT_MS) {
            showError('Время на оплату истекло. Платеж больше не проверяется.');
            updatePaymentStatus('expired');
            stopStatusChecking();
            return;
        }
        
        if (statusCheckAttempts >= MAX_STATUS_ATTEMPTS) {
            showError('Превышено количество попыток проверки статуса');
            stopStatusChecking();
            return;
        }

        statusCheckAttempts++;
        showLoading(true);

        try {
            const timestamp = new Date().getTime();
            const response = await fetch(
                `https://pay.whaile.ru:3000/payment_status/${encodeURIComponent(CURRENCY)}/${encodeURIComponent(PAYMENT_ID)}?t=${timestamp}`,
                {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache',
                        'Pragma': 'no-cache'
                    },
                    signal: AbortSignal.timeout(10000)
                }
            );

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (!data || typeof data !== 'object') {
                throw new Error('Invalid response format');
            }

            if (data.status === 'ok' && data.payment_status) {
                if (data.return_url && !RETURN_URL) {
                    window.RETURN_URL = data.return_url;
                }
                
                updatePaymentStatus(data.payment_status);

                if (['confirmed', 'success', 'completed', 'failed', 'error'].includes(data.payment_status.toLowerCase())) {
                    stopStatusChecking();
                }
            } else {
                throw new Error(data.message || 'Unknown error');
            }

        } catch (error) {
            console.error('Status check error:', error);

            if (statusCheckAttempts >= MAX_STATUS_ATTEMPTS) {
                showError('Не удается проверить статус платежа. Обновите страницу.');
                stopStatusChecking();
            } else {
                showTemporaryError(`Ошибка проверки (${statusCheckAttempts}/${MAX_STATUS_ATTEMPTS})`);
            }
        } finally {
            showLoading(false);
        }
    }

    function updatePaymentStatus(status) {
        const statusElement = document.getElementById('paymentStatus');
        const timerElement = document.getElementById('timer');

        if (!statusElement) return;

        statusElement.className = 'status-badge';

        const statusLower = status.toLowerCase();

        switch(statusLower) {
            case 'confirmed':
            case 'success':
            case 'completed':
                statusElement.classList.add('status-success');
                statusElement.innerHTML = '<i class="fas fa-check-circle"></i><span>Оплата подтверждена</span>';
                statusElement.classList.add('success-animation');
                
                const returnUrl = RETURN_URL || window.RETURN_URL;
                if (returnUrl) {
                    setTimeout(() => {
                        window.location.href = returnUrl;
                    }, 2000);
                }
                break;

            case 'failed':
            case 'error':
            case 'rejected':
                statusElement.classList.add('status-error');
                statusElement.innerHTML = '<i class="fas fa-times-circle"></i><span>Ошибка оплаты</span>';
                showError('Платеж не прошел. Попробуйте еще раз.');
                break;

            case 'expired':
                statusElement.classList.add('status-error');
                statusElement.innerHTML = '<i class="fas fa-clock"></i><span>Время истекло</span>';
                hideError();
                break;

            case 'pending':
            case 'waiting':
            default:
                statusElement.classList.add('status-pending');
                statusElement.innerHTML = '<i class="fas fa-clock"></i><span>Ожидание оплаты</span>';
                hideError();
        }

        if (timerElement && ['confirmed', 'success', 'completed', 'failed', 'error', 'rejected', 'expired'].includes(statusLower)) {
            timerElement.style.display = 'none';
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }
    }
    function startTimer() {
        updateCountdown();

        if (timerInterval) {
            clearInterval(timerInterval);
        }

        timerInterval = setInterval(() => {
            const elapsedTime = Date.now() - PAYMENT_CREATED_AT;
            if (elapsedTime > PAYMENT_TIMEOUT_MS) {
                checkPaymentStatus();
                return;
            }
            
            updateCountdown();
        }, 1000);
    }

    function resetTimer() {
        updateCountdown();
    }

    function updateCountdown() {
        const timeLeftElement = document.getElementById('timeLeft');
        if (timeLeftElement) {
            const elapsedTime = Date.now() - PAYMENT_CREATED_AT;
            const remainingTime = Math.max(0, PAYMENT_TIMEOUT_MS - elapsedTime);
            const minutes = Math.floor(remainingTime / 60000);
            const seconds = Math.floor((remainingTime % 60000) / 1000);
            timeLeftElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            const timerElement = document.getElementById('timer');
            if (timerElement) {
                if (remainingTime <= 0) {
                    timerElement.style.display = 'none';
                } else {
                    timerElement.style.display = 'block';
                }
            }
        }
    }

    function showLoading(show) {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.style.display = show ? 'inline-block' : 'none';
        }
    }

    function showError(message) {
        const errorArea = document.getElementById('errorArea');
        if (errorArea) {
            errorArea.innerHTML = `<div class="error-message"><i class="fas fa-exclamation-triangle me-2"></i>${message}</div>`;
            errorArea.style.display = 'block';
        }
    }

    function showTemporaryError(message) {
        showError(message);
        setTimeout(hideError, 5000);
    }

    function hideError() {
        const errorArea = document.getElementById('errorArea');
        if (errorArea) {
            errorArea.style.display = 'none';
        }
    }

    function stopStatusChecking() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
            statusCheckInterval = null;
        }
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initTheme === 'function') {
            initTheme();
        }
        
        generateQRCode();
        setupCopyButton();
        startTimer();

        statusCheckInterval = setInterval(checkPaymentStatus, STATUS_CHECK_INTERVAL);

        setTimeout(checkPaymentStatus, 5000);

        console.log('Payment initialized:', {
            paymentId: PAYMENT_ID,
            currency: CURRENCY,
            amount: AMOUNT,
            wallet: WALLET_ADDRESS
        });
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            checkPaymentStatus();
        }
    });

    window.addEventListener('focus', checkPaymentStatus);

    window.addEventListener('beforeunload', function() {
        stopStatusChecking();
    });
</script>

<script src="scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>