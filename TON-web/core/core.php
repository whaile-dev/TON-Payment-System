<?php

$isProduction = !file_exists($_SERVER['DOCUMENT_ROOT'] . '/.dev') && 
                (!isset($_SERVER['HTTP_HOST']) || strpos($_SERVER['HTTP_HOST'], 'localhost') === false);

if (!$isProduction) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', '1');
    ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/php_errors.log');
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        http_response_code(500);
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Ошибка</title>";
        echo "<style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
            .error-container { max-width: 800px; margin: 50px auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .error-header { background: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .error-header h2 { margin: 0; font-size: 24px; }
            .error-body { padding: 20px; }
            .error-item { margin: 15px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #dc3545; border-radius: 4px; }
            .error-item strong { display: block; color: #495057; margin-bottom: 5px; font-size: 12px; text-transform: uppercase; }
            .error-item code { display: block; color: #212529; font-size: 14px; word-break: break-all; }
            .error-trace { margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; }
            .error-trace pre { margin: 0; font-size: 12px; overflow-x: auto; }
        </style></head><body>";
        echo "<div class='error-container'>";
        echo "<div class='error-header'><h2><i class='fas fa-exclamation-triangle'></i> Критическая ошибка PHP</h2></div>";
        echo "<div class='error-body'>";
        
        $isProduction = !file_exists($_SERVER['DOCUMENT_ROOT'] . '/.dev');
        
        if ($isProduction) {
            echo "<div class='error-item'><strong>Ошибка</strong><code>Произошла внутренняя ошибка сервера. Пожалуйста, попробуйте позже или обратитесь в поддержку.</code></div>";
            error_log("Critical PHP error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        } else {
            echo "<div class='error-item'><strong>Файл</strong><code>" . htmlspecialchars($error['file']) . "</code></div>";
            echo "<div class='error-item'><strong>Строка</strong><code>" . $error['line'] . "</code></div>";
            echo "<div class='error-item'><strong>Тип ошибки</strong><code>" . $error['type'] . "</code></div>";
            echo "<div class='error-item'><strong>Сообщение</strong><code>" . htmlspecialchars($error['message']) . "</code></div>";
        }
        
        if (!$isProduction && function_exists('debug_backtrace')) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            if (!empty($trace)) {
                echo "<div class='error-trace'><strong>Трассировка:</strong><pre>";
                foreach ($trace as $i => $frame) {
                    $file = $frame['file'] ?? 'unknown';
                    $line = $frame['line'] ?? 'unknown';
                    $function = $frame['function'] ?? 'unknown';
                    echo "#{$i} {$file}({$line}): {$function}()\n";
                }
                echo "</pre></div>";
            }
        }
        
        echo "</div></div></body></html>";
        exit;
    }
});

set_exception_handler(function($exception) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(500);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Ошибка</title>";
    echo "<style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .error-container { max-width: 800px; margin: 50px auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error-header { background: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .error-header h2 { margin: 0; font-size: 24px; }
        .error-body { padding: 20px; }
        .error-item { margin: 15px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #dc3545; border-radius: 4px; }
        .error-item strong { display: block; color: #495057; margin-bottom: 5px; font-size: 12px; text-transform: uppercase; }
        .error-item code { display: block; color: #212529; font-size: 14px; word-break: break-all; }
        .error-trace { margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; }
        .error-trace pre { margin: 0; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
    </style></head><body>";
    $isProduction = !file_exists($_SERVER['DOCUMENT_ROOT'] . '/.dev');
    
    echo "<div class='error-container'>";
    echo "<div class='error-header'><h2><i class='fas fa-exclamation-triangle'></i> Необработанное исключение</h2></div>";
    echo "<div class='error-body'>";
    
    if ($isProduction) {
        echo "<div class='error-item'><strong>Ошибка</strong><code>Произошла внутренняя ошибка сервера. Пожалуйста, попробуйте позже или обратитесь в поддержку.</code></div>";
        error_log("Unhandled exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
        if ($exception->getTrace()) {
            error_log("Trace: " . $exception->getTraceAsString());
        }
    } else {
        echo "<div class='error-item'><strong>Класс</strong><code>" . get_class($exception) . "</code></div>";
        echo "<div class='error-item'><strong>Сообщение</strong><code>" . htmlspecialchars($exception->getMessage()) . "</code></div>";
        echo "<div class='error-item'><strong>Файл</strong><code>" . htmlspecialchars($exception->getFile()) . "</code></div>";
        echo "<div class='error-item'><strong>Строка</strong><code>" . $exception->getLine() . "</code></div>";
        
        if ($exception->getTrace()) {
            echo "<div class='error-trace'><strong>Трассировка:</strong><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></div>";
        }
    }
    
    echo "</div></div></body></html>";
    exit;
});

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/core/HDWcore.php');
    require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');

    global $core;

    $core = new Core();
    $core->init();
    $core->startSession();
    
    setSecurityHeaders();
    $core->loadPHP();
} catch (Exception $e) {
    echo "<div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px;'>";
    echo "<h3>Ошибка инициализации</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Файл:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><strong>Строка:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
    exit;
}

function getCore(): Core { global $core; return $core; }
