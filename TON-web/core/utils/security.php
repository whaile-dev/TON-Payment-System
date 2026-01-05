<?php

function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken(string $token): bool {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCSRFToken(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
        $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }
    
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE' || $method === 'PATCH') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !verifyCSRFToken($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }
}

function setSecurityHeaders(): void {
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self' https://pay.whaile.ru https://pay.whaile.ru:3000 https://tonapi.io https://testnet.toncenter.com; frame-ancestors 'none';";
        header("Content-Security-Policy: $csp");
    }
}

function validateURL(string $url, array $allowedDomains = []): bool {
    if (empty($url)) {
        return false;
    }
    
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }
    
    if ($parsed['scheme'] !== 'https') {
        return false;
    }
    
    $host = $parsed['host'];
    
    $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
    if (in_array(strtolower($host), $blockedHosts)) {
        return false;
    }
    
    $ip = gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    
    if (preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|0\.0\.0\.0)/i', $host)) {
        return false;
    }
    
    if (!empty($allowedDomains)) {
        $allowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }
    }
    
    return true;
}

function validateWebhookURL(string $url): bool {
    return validateURL($url);
}

function validateReturnURL(string $url): bool {
    $allowedDomains = ['pay.whaile.ru', 'whaile.ru'];
    return validateURL($url, $allowedDomains);
}

function sanitizeInput(string $input, int $maxLength = 1000): string {
    $input = trim($input);
    if (strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }
    return $input;
}

function validateTONAddress(string $address): bool {
    if (empty($address)) {
        return false;
    }
    
    $address = trim($address);
    
    if (strlen($address) < 20 || strlen($address) > 100) {
        return false;
    }
    
    if (preg_match('/^(EQ|UQ|0Q)[A-Za-z0-9_-]{46}$/', $address)) {
        return true;
    }
    
    if (preg_match('/^0:[0-9a-fA-F]{64}$/', $address)) {
        return true;
    }
    
    if (preg_match('/^[0-9a-fA-F]{64}$/', $address)) {
        return true;
    }
    
    if (strpos($address, ':') !== false) {
        return preg_match('/^0:[0-9a-fA-F]{64}$/', $address);
    }
    
    return false;
}

function logSecurityEvent(string $event, array $data = []): void {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docRoot = realpath($docRoot);
    if ($docRoot === false || empty($docRoot)) {
        error_log("Failed to get valid DOCUMENT_ROOT for security log");
        return;
    }
    
    $logFile = $docRoot . '/logs/security.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            error_log("Failed to create log directory: $logDir");
            return;
        }
    }
    
    $filteredData = filterSensitiveData($data);
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'data' => $filteredData
    ];
    
    if (!is_writable($logDir)) {
        error_log("Security log directory is not writable: $logDir");
        return;
    }
    
    $result = file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log("Failed to write to security log: $logFile");
    }
}

function filterSensitiveData(array $data): array {
    $sensitiveKeys = ['password', 'password_hash', 'api_token', 'token', 'secret', 'key', 'private_key'];
    $filtered = [];
    
    foreach ($data as $key => $value) {
        $keyLower = strtolower($key);
        $isSensitive = false;
        
        foreach ($sensitiveKeys as $sensitiveKey) {
            if (strpos($keyLower, $sensitiveKey) !== false) {
                $isSensitive = true;
                break;
            }
        }
        
        if ($isSensitive) {
            $filtered[$key] = '[REDACTED]';
        } elseif (is_array($value)) {
            $filtered[$key] = filterSensitiveData($value);
        } else {
            $filtered[$key] = $value;
        }
    }
    
    return $filtered;
}

function checkLoginAttempts(string $email, string $ip): bool {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docRoot = realpath($docRoot);
    if ($docRoot === false || empty($docRoot)) {
        return true;
    }
    
    $cacheDir = $docRoot . '/cache/login_attempts';
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return true;
        }
    }
    
    $maxAttempts = 5;
    $lockoutTime = 900;
    
    $emailKey = md5('email_' . $email);
    $emailFile = $cacheDir . '/' . $emailKey . '.json';
    
    if (file_exists($emailFile)) {
        $data = @json_decode(@file_get_contents($emailFile), true);
        if ($data && isset($data['count']) && isset($data['locked_until'])) {
            if ($data['locked_until'] > time()) {
                return false; // Заблокировано
            }
            // Время блокировки истекло, сбрасываем счетчик
            if ($data['locked_until'] <= time()) {
                @unlink($emailFile);
            }
        }
    }
    
    $ipKey = md5('ip_' . $ip);
    $ipFile = $cacheDir . '/' . $ipKey . '.json';
    
    if (file_exists($ipFile)) {
        $data = @json_decode(@file_get_contents($ipFile), true);
        if ($data && isset($data['count']) && isset($data['locked_until'])) {
            if ($data['locked_until'] > time()) {
                return false;
            }
            if ($data['locked_until'] <= time()) {
                @unlink($ipFile);
            }
        }
    }
    
    return true;
}

function recordFailedLoginAttempt(string $email, string $ip): void {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docRoot = realpath($docRoot);
    if ($docRoot === false || empty($docRoot)) {
        return;
    }
    
    $cacheDir = $docRoot . '/cache/login_attempts';
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }
    }
    
    $maxAttempts = 5;
    $lockoutTime = 900;
    
    $emailKey = md5('email_' . $email);
    $emailFile = $cacheDir . '/' . $emailKey . '.json';
    
    $data = ['count' => 1, 'locked_until' => 0];
    if (file_exists($emailFile)) {
        $existing = @json_decode(@file_get_contents($emailFile), true);
        if ($existing && isset($existing['count'])) {
            $data['count'] = $existing['count'] + 1;
            if (isset($existing['locked_until']) && $existing['locked_until'] > time()) {
                $data['locked_until'] = $existing['locked_until'];
            }
        }
    }
    
    if ($data['count'] >= $maxAttempts) {
        $data['locked_until'] = time() + $lockoutTime;
    }
    
    @file_put_contents($emailFile, json_encode($data), LOCK_EX);
    
    $ipKey = md5('ip_' . $ip);
    $ipFile = $cacheDir . '/' . $ipKey . '.json';
    
    $data = ['count' => 1, 'locked_until' => 0];
    if (file_exists($ipFile)) {
        $existing = @json_decode(@file_get_contents($ipFile), true);
        if ($existing && isset($existing['count'])) {
            $data['count'] = $existing['count'] + 1;
            if (isset($existing['locked_until']) && $existing['locked_until'] > time()) {
                $data['locked_until'] = $existing['locked_until'];
            }
        }
    }
    
    if ($data['count'] >= $maxAttempts) {
        $data['locked_until'] = time() + $lockoutTime;
    }
    
    @file_put_contents($ipFile, json_encode($data), LOCK_EX);
}

/**
 * Очищает счетчик неудачных попыток при успешном входе
 * @param string $email Email пользователя
 * @param string $ip IP адрес
 */
function clearFailedLoginAttempts(string $email, string $ip): void {
    // Безопасное получение DOCUMENT_ROOT
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $docRoot = realpath($docRoot);
    if ($docRoot === false || empty($docRoot)) {
        return;
    }
    
    $cacheDir = $docRoot . '/cache/login_attempts';
    
    $emailKey = md5('email_' . $email);
    $emailFile = $cacheDir . '/' . $emailKey . '.json';
    if (file_exists($emailFile)) {
        @unlink($emailFile);
    }
    
    $ipKey = md5('ip_' . $ip);
    $ipFile = $cacheDir . '/' . $ipKey . '.json';
    if (file_exists($ipFile)) {
        @unlink($ipFile);
    }
}

class RateLimiter {
    private static $limits = [];
    private static $cacheDir = null;
    
    public static function init(): void {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $docRoot = realpath($docRoot);
        if ($docRoot === false || empty($docRoot)) {
            error_log("Failed to get valid DOCUMENT_ROOT for rate limit cache");
            return;
        }
        
        self::$cacheDir = $docRoot . '/cache/ratelimit';
        if (!is_dir(self::$cacheDir)) {
            if (!mkdir(self::$cacheDir, 0755, true) && !is_dir(self::$cacheDir)) {
                error_log("Failed to create rate limit cache directory: " . self::$cacheDir);
            }
        }
    }
    
    public static function check(string $key, int $maxRequests = 60, int $windowSeconds = 60): bool {
        self::init();
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $cacheKey = md5($key . '_' . $ip);
        $cacheFile = self::$cacheDir . '/' . $cacheKey . '.json';
        
        $data = ['count' => 0, 'reset' => time() + $windowSeconds];
        if (file_exists($cacheFile)) {
            $fileContent = file_get_contents($cacheFile);
            if ($fileContent !== false) {
                $cached = json_decode($fileContent, true);
                if (json_last_error() === JSON_ERROR_NONE && $cached && isset($cached['reset']) && $cached['reset'] > time()) {
                    $data = $cached;
                }
            } else {
                error_log("Failed to read rate limit cache file: $cacheFile");
            }
        }
        
        if ($data['reset'] <= time()) {
            $data = ['count' => 0, 'reset' => time() + $windowSeconds];
        }
        
        if ($data['count'] >= $maxRequests) {
            logSecurityEvent('rate_limit_exceeded', ['key' => $key, 'ip' => $ip]);
            return false;
        }
        
        $data['count']++;
        $writeResult = file_put_contents($cacheFile, json_encode($data), LOCK_EX);
        if ($writeResult === false) {
            error_log("Failed to write rate limit cache file: $cacheFile");
        }
        
        return true;
    }
}

RateLimiter::init();

/**
 * Безопасно читает и декодирует JSON из php://input с проверкой размера
 * @param int $maxSize Максимальный размер в байтах (по умолчанию 1MB)
 * @return array|null Декодированные данные или null при ошибке
 */
function safeJsonDecode(int $maxSize = 1048576): ?array {
    // Проверка размера тела запроса
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > $maxSize) {
        error_log("Request body too large: {$contentLength} bytes (max: {$maxSize})");
        return null;
    }
    
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        error_log("Failed to read request body");
        return null;
    }
    
    // Дополнительная проверка размера прочитанных данных
    if (strlen($rawInput) > $maxSize) {
        error_log("Read data too large: " . strlen($rawInput) . " bytes (max: {$maxSize})");
        return null;
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

