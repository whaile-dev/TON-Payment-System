<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/http_client.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');

$config = getConfig();
$support_url = $config['support']['telegram_url'] ?? 'https://t.me/whaile_dev';
$site_url = $config['site']['url'] ?? 'https://pay.whaile.ru';
$api_port = $config['site']['api_port'] ?? 3000;
$api_base = $site_url . ':' . $api_port;

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
if ($transaction_uuid_raw && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $transaction_uuid_raw)) {
    $transaction_uuid = $transaction_uuid_raw;
}

$cashier_id = isset($_GET['cashier_id']) ? trim($_GET['cashier_id']) : null;
$amount = isset($_GET['amount']) ? trim($_GET['amount']) : null;
$wallet = isset($_GET['wallet']) ? trim($_GET['wallet']) : null;
$payload = isset($_GET['payload']) ? trim($_GET['payload']) : null;
if ($payload && strlen($payload) > 1000) {
    $payload = substr($payload, 0, 1000);
}
$return_url = isset($_GET['return_url']) ? trim($_GET['return_url']) : null;

$payment_id = null;
$wallet_to_send = null;
$amount_exact = null;
$currency = 'ton';
$payment_created_at = null;
$existing_payment = false;

if ($transaction_uuid) {
    $client = getHttpClient();
    $endpoint = '/payment_by_uuid/' . urlencode($transaction_uuid);
    $result = $client->get($endpoint);
    
    if (is_array($result) && isset($result['http_code']) && $result['http_code'] === 200 && isset($result['response']) && $result['response']) {
        $payment_response = json_decode($result['response'], true);
        if ($payment_response && isset($payment_response['status']) && $payment_response['status'] === 'ok') {
            $payment_status = $payment_response['payment_status'] ?? 'pending';
            
            if (in_array(strtolower($payment_status), ['confirmed', 'success', 'completed'])) {
                $return_url_from_api = $payment_response['return_url'] ?? null;
                if ($return_url_from_api && function_exists('validateReturnURL') && validateReturnURL($return_url_from_api)) {
                    header('Location: ' . $return_url_from_api);
                    exit;
                }
            }
            
            $payment_id = $payment_response['payment_id'] ?? null;
            $wallet_to_send = $payment_response['wallet_to_send'] ?? null;
            $amount_exact = isset($payment_response['amount']) ? floatval($payment_response['amount']) : null;
            $currency = isset($payment_response['currency']) ? strtolower($payment_response['currency']) : 'ton';
            $payment_created_at = isset($payment_response['time_recorded']) ? intval($payment_response['time_recorded']) : null;
            $return_url = $payment_response['return_url'] ?? $return_url;
            $existing_payment = true;
        }
    }
}

if (!$payment_id || !$wallet_to_send || !$amount_exact) {
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
            'Получено: ' . htmlspecialchars($cashier_id),
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
            'Получено: ' . htmlspecialchars($amount),
            $support_url
        );
    }
    
    $amount_float = floatval($amount);
    if ($amount_float <= 0 || $amount_float < 0.01) {
        showErrorPage(
            'Неверная сумма',
            'Сумма платежа должна быть больше или равна 0.01.',
            'Получено: ' . htmlspecialchars($amount),
            $support_url
        );
    }
    
    if (!$wallet || empty($wallet)) {
        showErrorPage(
            'Отсутствует обязательный параметр',
            'Не указан адрес кошелька получателя (wallet) в параметрах URL.',
            'Пример правильного URL: payment.php?cashier_id=1&amount=10.50&wallet=...',
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
                'Получено: ' . htmlspecialchars($return_url),
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
    
    $payment_data = [
        'cashier_id' => $cashier_id_int,
        'amount' => $amount_float,
        'wallet' => $wallet_clean
    ];
    
    if ($transaction_uuid) {
        $payment_data['transaction_uuid'] = $transaction_uuid;
    }
    
    if ($payload) {
        $payment_data['payload'] = $payload;
    }
    
    if ($return_url && !empty($return_url) && function_exists('validateReturnURL') && validateReturnURL($return_url)) {
        $payment_data['return_url'] = $return_url;
    }
    
    $paymentClient = getHttpClient();
    $result = $paymentClient->post('/create_payment', $payment_data);
    
    if (!is_array($result) || !isset($result['http_code']) || $result['http_code'] !== 200 || !isset($result['response']) || !$result['response']) {
        $error_details = 'HTTP код: ' . (isset($result['http_code']) ? $result['http_code'] : 'unknown');
        if (isset($result['error']) && $result['error']) {
            $error_details .= "\nОшибка: " . $result['error'];
        }
        showErrorPage(
            'Ошибка создания платежа',
            'Не удалось создать платеж. Пожалуйста, попробуйте позже.',
            $error_details,
            $support_url
        );
    }
    
    $payment_response = json_decode($result['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !$payment_response || !isset($payment_response['status']) || $payment_response['status'] !== 'ok') {
        $error_msg = isset($payment_response['message']) ? $payment_response['message'] : 'Неизвестная ошибка';
        showErrorPage(
            'Ошибка создания платежа',
            htmlspecialchars($error_msg),
            'Ошибка JSON: ' . json_last_error_msg(),
            $support_url
        );
    }
    
    if (!isset($payment_response['payment_id']) || !isset($payment_response['wallet_to_send']) || !isset($payment_response['amount'])) {
        showErrorPage(
            'Неполные данные',
            'Платежная система вернула неполные данные.',
            'Отсутствуют необходимые поля в ответе API.',
            $support_url
        );
    }
    
    $payment_id = $payment_response['payment_id'];
    $wallet_to_send = $payment_response['wallet_to_send'];
    $amount_exact = floatval($payment_response['amount']);
    $currency = isset($payment_response['currency']) ? strtolower($payment_response['currency']) : 'ton';
    $transaction_uuid = isset($payment_response['transaction_uuid']) ? $payment_response['transaction_uuid'] : null;
    $payment_created_at = isset($payment_response['time_recorded']) ? intval($payment_response['time_recorded']) : null;
    $return_url = isset($payment_response['return_url']) ? $payment_response['return_url'] : $return_url;
    
    if ($transaction_uuid) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'];
        $redirect_url = $protocol . "://" . $host . $script . '?transaction_uuid=' . urlencode($transaction_uuid);
        header('Location: ' . $redirect_url);
        exit;
    }
}

if (!$payment_id || !$wallet_to_send || !$amount_exact) {
    showErrorPage(
        'Ошибка данных',
        'Не удалось получить данные платежа.',
        'Отсутствуют необходимые данные для отображения страницы оплаты.',
        $support_url
    );
}

?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата <?php echo htmlspecialchars(strtoupper($currency)); ?></title>
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
    const RETURN_URL = <?php echo json_encode($return_url ?? null); ?>;
    const API_BASE = <?php echo json_encode($api_base); ?>;
    const PAYMENT_TIMEOUT_MS = 20 * 60 * 1000;
    const PAYMENT_CREATED_AT = <?php echo isset($payment_created_at) && $payment_created_at ? $payment_created_at : 'Date.now()'; ?>;
    
    let qrcode = null;
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
            qrcode = new QRCode(qrcodeElement, {
                text: qrText,
                width: 180,
                height: 180,
                colorDark: isDark ? "#ffffff" : "#000000",
                colorLight: isDark ? "#0a0a0a" : "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        } catch (error) {
            console.error('QR code generation error:', error);
        }
    }
    
    function setupCopyButton() {
        const copyButton = document.getElementById('copyButton');
        if (!copyButton) return;
        copyButton.addEventListener('click', function() {
            navigator.clipboard.writeText(WALLET_ADDRESS).then(() => {
                const originalHTML = copyButton.innerHTML;
                copyButton.innerHTML = '<i class="fas fa-check me-2"></i>Скопировано!';
                copyButton.classList.remove('btn-primary');
                copyButton.classList.add('btn-success');
                setTimeout(() => {
                    copyButton.innerHTML = originalHTML;
                    copyButton.classList.remove('btn-success');
                    copyButton.classList.add('btn-primary');
                }, 2000);
            }).catch(() => {
                alert('Не удалось скопировать адрес');
            });
        });
    }
    
    async function checkPaymentStatus() {
        try {
            const response = await fetch(`${API_BASE}/payment_status/${encodeURIComponent(CURRENCY)}/${encodeURIComponent(PAYMENT_ID)}?t=${Date.now()}`);
            if (!response.ok) return;
            const data = await response.json();
            if (data && data.status === 'ok' && data.payment_status) {
                updatePaymentStatus(data.payment_status);
                if (['confirmed', 'success', 'completed', 'failed', 'error'].includes(data.payment_status.toLowerCase())) {
                    stopStatusChecking();
                }
            }
        } catch (error) {
            console.error('Status check error:', error);
        }
    }
    
    function updatePaymentStatus(status) {
        const statusElement = document.getElementById('paymentStatus');
        const timerElement = document.getElementById('timer');
        if (!statusElement) return;
        statusElement.className = 'status-badge';
        const statusLower = status.toLowerCase();
        if (['confirmed', 'success', 'completed'].includes(statusLower)) {
            statusElement.classList.add('status-success');
            statusElement.innerHTML = '<i class="fas fa-check-circle"></i><span>Оплата подтверждена</span>';
            if (RETURN_URL) {
                setTimeout(() => {
                    window.location.href = RETURN_URL;
                }, 2000);
            }
        } else if (['failed', 'error', 'rejected'].includes(statusLower)) {
            statusElement.classList.add('status-error');
            statusElement.innerHTML = '<i class="fas fa-times-circle"></i><span>Ошибка оплаты</span>';
        } else if (statusLower === 'expired') {
            statusElement.classList.add('status-error');
            statusElement.innerHTML = '<i class="fas fa-clock"></i><span>Время истекло</span>';
        } else {
            statusElement.classList.add('status-pending');
            statusElement.innerHTML = '<i class="fas fa-clock"></i><span>Ожидание оплаты</span>';
        }
        if (timerElement && ['confirmed', 'success', 'completed', 'failed', 'error', 'rejected', 'expired'].includes(statusLower)) {
            timerElement.style.display = 'none';
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }
    }
    
    function updateCountdown() {
        const timeLeftElement = document.getElementById('timeLeft');
        if (timeLeftElement) {
            const elapsedTime = Date.now() - PAYMENT_CREATED_AT;
            const remainingTime = Math.max(0, PAYMENT_TIMEOUT_MS - elapsedTime);
            const minutes = Math.floor(remainingTime / 60000);
            const seconds = Math.floor((remainingTime % 60000) / 1000);
            timeLeftElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
    }
    
    function startTimer() {
        updateCountdown();
        timerInterval = setInterval(updateCountdown, 1000);
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
        statusCheckInterval = setInterval(checkPaymentStatus, 5000);
        setTimeout(checkPaymentStatus, 5000);
    });
    
    window.addEventListener('beforeunload', function() {
        stopStatusChecking();
    });
</script>
<script src="scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>

