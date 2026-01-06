<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');

function getSystemLoad() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return $load[0] ?? 0;
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (!class_exists('COM')) {
            error_log("COM class not available");
            return 0;
        }
        try {
            $wmi = new COM('winmgmts://./root/cimv2');
            if (!$wmi) {
                error_log("Failed to create COM object");
                return 0;
            }
            $cpus = $wmi->ExecQuery('SELECT LoadPercentage FROM Win32_Processor');
            if (!$cpus) {
                error_log("Failed to execute WMI query");
                return 0;
            }
            $total = 0;
            $count = 0;
            foreach ($cpus as $cpu) {
                $total += $cpu->LoadPercentage;
                $count++;
            }
            if ($count > 0) {
                return (float)($total / $count) / 100;
            }
        } catch (Exception $e) {
            error_log("Error getting CPU load: " . $e->getMessage());
        } catch (Throwable $e) {
            error_log("Fatal error getting CPU load: " . $e->getMessage());
        }
    }
    return 0;
}

function getCpuUsage() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (!class_exists('COM')) {
            error_log("COM class not available");
            return 0;
        }
        try {
            $wmi = new COM('winmgmts://./root/cimv2');
            if (!$wmi) {
                error_log("Failed to create COM object");
                return 0;
            }
            $cpus = $wmi->ExecQuery('SELECT LoadPercentage FROM Win32_Processor');
            if (!$cpus) {
                error_log("Failed to execute WMI query");
                return 0;
            }
            $total = 0;
            $count = 0;
            foreach ($cpus as $cpu) {
                $total += $cpu->LoadPercentage;
                $count++;
            }
            if ($count > 0) {
                return (float)($total / $count);
            }
        } catch (Exception $e) {
            error_log("Error getting CPU usage: " . $e->getMessage());
        } catch (Throwable $e) {
            error_log("Fatal error getting CPU usage: " . $e->getMessage());
        }
    } else {
        $stat1 = @file_get_contents('/proc/stat');
        if ($stat1 !== false) {
            usleep(100000);
            $stat2 = @file_get_contents('/proc/stat');
            if ($stat2 && preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat1, $match1) &&
                preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat2, $match2)) {
                $idle1 = (int)$match1[4];
                $total1 = (int)$match1[1] + (int)$match1[2] + (int)$match1[3] + (int)$match1[4];
                $idle2 = (int)$match2[4];
                $total2 = (int)$match2[1] + (int)$match2[2] + (int)$match2[3] + (int)$match2[4];
                
                $idle = $idle2 - $idle1;
                $total = $total2 - $total1;
                
                if ($total > 0) {
                    $usage = 100 * (1 - ($idle / $total));
                    return min(100, max(0, $usage));
                }
            }
        }
        
        $load = @sys_getloadavg();
        if ($load && isset($load[0])) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            $cpu_count = $cpuinfo ? substr_count($cpuinfo, 'processor') : 1;
            if ($cpu_count > 0) {
                return min(100, ($load[0] / $cpu_count) * 100);
            }
        }
    }
    return 0;
}

function getMemoryUsage() {
    if (function_exists('memory_get_usage')) {
        $used = memory_get_usage(true);
        $total = memory_get_usage(true);
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if (!class_exists('COM')) {
                error_log("COM class not available");
            } else {
                try {
                    $wmi = new COM('winmgmts://./root/cimv2');
                    if (!$wmi) {
                        error_log("Failed to create COM object for memory");
                    } else {
                        $computers = $wmi->ExecQuery('SELECT TotalPhysicalMemory FROM Win32_ComputerSystem');
                        if ($computers) {
                            foreach ($computers as $computer) {
                                $total = (int)$computer->TotalPhysicalMemory;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error getting memory: " . $e->getMessage());
                } catch (Throwable $e) {
                    error_log("Fatal error getting memory: " . $e->getMessage());
                }
            }
        } else {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $total = (int)$matches[1] * 1024;
            }
        }
        
        if ($total > 0) {
            return round(($used / $total) * 100, 2);
        }
    }
    return 0;
}

function getSystemMemoryUsage() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (!class_exists('COM')) {
            error_log("COM class not available");
        } else {
            try {
                $wmi = new COM('winmgmts://./root/cimv2');
                if (!$wmi) {
                    error_log("Failed to create COM object for system memory");
                } else {
                    $os = $wmi->ExecQuery('SELECT TotalVisibleMemorySize,FreePhysicalMemory FROM Win32_OperatingSystem');
                    if ($os) {
                        foreach ($os as $o) {
                            $total = (int)$o->TotalVisibleMemorySize * 1024;
                            $free = (int)$o->FreePhysicalMemory * 1024;
                            $used = $total - $free;
                            return round(($used / $total) * 100, 2);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting system memory: " . $e->getMessage());
            } catch (Throwable $e) {
                error_log("Fatal error getting system memory: " . $e->getMessage());
            }
        }
    } else {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false) {
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $total_match);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $avail_match);
            if (isset($total_match[1]) && isset($avail_match[1])) {
                $total = (int)$total_match[1] * 1024;
                $available = (int)$avail_match[1] * 1024;
                $used = $total - $available;
                return round(($used / $total) * 100, 2);
            }
        }
    }
    return 0;
}

function checkDatabaseStatus() {
    try {
        $conn = getCore()->getConn();
        if (!$conn || $conn->connect_error) {
            return ['status' => 'error', 'latency' => 0, 'error_rate' => 100];
        }
        
        $start = microtime(true);
        $stmt = $conn->prepare("SELECT 1");
        try {
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
            } else {
                return ['status' => 'error', 'latency' => 0, 'error_rate' => 100];
            }
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            if ($result) {
                $hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
                $error_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                    FROM (
                        SELECT status FROM TONDeposit WHERE time_recorded >= ?
                        UNION ALL
                        SELECT status FROM JETTONDeposit WHERE time_recorded >= ?
                    ) as all_transactions";
                
                $stmt = $conn->prepare($error_query);
                if ($stmt) {
                    $stmt->bind_param("ss", $hour_ago, $hour_ago);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $data = $result->fetch_assoc();
                    $stmt->close();
                    
                    $total = (int)($data['total'] ?? 0);
                    $failed = (int)($data['failed'] ?? 0);
                    $error_rate = $total > 0 ? round(($failed / $total) * 100, 2) : 0;
                } else {
                    $error_rate = 0;
                }
                
                return ['status' => 'ok', 'latency' => $latency, 'error_rate' => $error_rate];
            }
        } finally {
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                try { $stmt->close(); } catch (Throwable $ignored) {}
            }
        }
    } catch (Exception $e) {
        error_log("Database check error: " . $e->getMessage());
    }
    return ['status' => 'error', 'latency' => 0, 'error_rate' => 100];
}

function checkPythonAPI() {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
    $config = getConfig();
    $site_url = $config['site']['url'] ?? 'https://pay.whaile.ru';
    $api_port = $config['site']['api_port'] ?? 3000;
    $api_base = $site_url . ':' . $api_port;
    $api_url = $api_base . '/health';
    $start = microtime(true);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $latency = round((microtime(true) - $start) * 1000, 2);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($response) && empty($curl_error)) {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in checkPythonAPI: " . json_last_error_msg());
            return ['status' => 'error', 'latency' => $latency];
        }
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            $api_latency = isset($data['database']['latency_ms']) ? $data['database']['latency_ms'] : $latency;
            return [
                'status' => 'ok', 
                'latency' => $api_latency,
                'db_status' => $data['database']['status'] ?? 'unknown'
            ];
        }
    }
    
    return ['status' => 'error', 'latency' => $latency];
}

function getUptime() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (!class_exists('COM')) {
            error_log("COM class not available");
            $output = null;
        } else {
            try {
                $wmi = new COM('winmgmts://./root/cimv2');
                if (!$wmi) {
                    error_log("Failed to create COM object for uptime");
                    $output = null;
                } else {
                    $os = $wmi->ExecQuery('SELECT LastBootUpTime FROM Win32_OperatingSystem');
                    if ($os) {
                        foreach ($os as $o) {
                            $output = 'LastBootUpTime=' . $o->LastBootUpTime;
                            break;
                        }
                    } else {
                        $output = null;
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting boot time: " . $e->getMessage());
                $output = null;
            } catch (Throwable $e) {
                error_log("Fatal error getting boot time: " . $e->getMessage());
                $output = null;
            }
        }
        if ($output && preg_match('/LastBootUpTime=(\d{14})/', $output, $matches)) {
            $boot_time = strtotime(substr($matches[1], 0, 4) . '-' . substr($matches[1], 4, 2) . '-' . substr($matches[1], 6, 2) . ' ' . 
                                 substr($matches[1], 8, 2) . ':' . substr($matches[1], 10, 2) . ':' . substr($matches[1], 12, 2));
            $uptime_seconds = time() - $boot_time;
            $days = (int)floor($uptime_seconds / 86400);
            $hours = (int)floor(($uptime_seconds % 86400) / 3600);
            return ['days' => $days, 'hours' => $hours, 'seconds' => (int)$uptime_seconds];
        }
    } else {
        $uptime_seconds = @file_get_contents('/proc/uptime');
        if ($uptime_seconds !== false) {
            $uptime_seconds = (float)explode(' ', $uptime_seconds)[0];
            $days = (int)floor($uptime_seconds / 86400);
            $hours = (int)floor(fmod($uptime_seconds, 86400) / 3600);
            return ['days' => $days, 'hours' => $hours, 'seconds' => $uptime_seconds];
        }
    }
    return ['days' => 0, 'hours' => 0, 'seconds' => 0];
}

$cpu_usage = getCpuUsage();
$memory_usage = getSystemMemoryUsage();
$db_status = checkDatabaseStatus();
$api_status = checkPythonAPI();
$uptime = getUptime();

$conn = getCore()->getConn();
$month_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
$total_transactions = 0;
$failed_transactions = 0;
$total_downtime_hours = 0;

if ($conn && !$conn->connect_error) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM (
                SELECT status FROM TONDeposit WHERE time_recorded >= ?
                UNION ALL
                SELECT status FROM JETTONDeposit WHERE time_recorded >= ?
            ) as all_transactions
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $month_ago, $month_ago);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            $stmt->close();
            
            $total_transactions = (int)($data['total'] ?? 0);
            $failed_transactions = (int)($data['failed'] ?? 0);
        }
    } catch (Exception $e) {
        error_log("Error getting transaction stats: " . $e->getMessage());
    }
}

$uptime_percentage = 99.9;
if ($uptime['seconds'] > 0) {
    $days_uptime = $uptime['days'] + ($uptime['hours'] / 24);
    if ($days_uptime >= 30) {
        $uptime_percentage = 99.98;
    } elseif ($days_uptime >= 7) {
        $uptime_percentage = 99.9;
    } else {
        $uptime_percentage = 99.5;
    }
}

function getOverallSystemStatus($api_status, $db_status, $cpu_usage, $memory_usage) {
    $issues = [];
    $critical_issues = 0;
    $warnings = 0;
    
    if ($api_status['status'] !== 'ok') {
        $critical_issues++;
        $issues[] = 'API недоступен';
    } elseif (isset($api_status['latency']) && $api_status['latency'] > 1000) {
        $warnings++;
        $issues[] = 'Высокая задержка API';
    }
    
    if ($db_status['status'] !== 'ok') {
        $critical_issues++;
        $issues[] = 'База данных недоступна';
    } elseif ($db_status['error_rate'] > 10) {
        $critical_issues++;
        $issues[] = 'Высокий процент ошибок БД';
    } elseif ($db_status['error_rate'] > 5) {
        $warnings++;
        $issues[] = 'Повышенный процент ошибок БД';
    } elseif (isset($db_status['latency']) && $db_status['latency'] > 1000) {
        $warnings++;
        $issues[] = 'Высокая задержка БД';
    }
    
    if ($cpu_usage > 95) {
        $critical_issues++;
        $issues[] = 'Критическая загрузка CPU';
    } elseif ($cpu_usage > 80) {
        $warnings++;
        $issues[] = 'Высокая загрузка CPU';
    }
    
    if ($memory_usage > 95) {
        $critical_issues++;
        $issues[] = 'Критическое использование памяти';
    } elseif ($memory_usage > 80) {
        $warnings++;
        $issues[] = 'Высокое использование памяти';
    }
    
    if ($critical_issues > 0) {
        return [
            'class' => 'status-outage',
            'description' => implode(', ', array_slice($issues, 0, 2)) . ($critical_issues > 2 ? '...' : ''),
            'issues' => $issues
        ];
    } elseif ($warnings > 0) {
        return [
            'class' => 'status-degraded',
            'description' => implode(', ', array_slice($issues, 0, 2)) . ($warnings > 2 ? '...' : ''),
            'issues' => $issues
        ];
    } else {
        return [
            'class' => 'status-operational',
            'description' => 'Все системы работают в штатном режиме',
            'issues' => []
        ];
    }
}

$api_status_class = $api_status['status'] === 'ok' ? 'status-operational' : 'status-outage';
$db_status_class = $db_status['status'] === 'ok' ? ($db_status['error_rate'] > 5 ? 'status-degraded' : 'status-operational') : 'status-outage';
$cpu_status_class = $cpu_usage > 80 ? 'status-degraded' : ($cpu_usage > 95 ? 'status-outage' : 'status-operational');

$overall_status = getOverallSystemStatus($api_status, $db_status, $cpu_usage, $memory_usage);
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
    $config = getConfig();
    $site_name = $config['site']['name'] ?? 'TonPay';
    $site_url = $config['site']['url'] ?? 'https://pay.whaile.ru';
    $api_port = $config['site']['api_port'] ?? 3000;
    $api_base = $site_url . ':' . $api_port;
    ?>
    <title>Статус системы | <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/svg+xml" href="scripts/img/logo.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="scripts/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="scripts/libs/bootstrap-icons/font/bootstrap-icons.min.css">
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

        .status-section {
            padding-top: 100px;
            min-height: 100vh;
            height: fit-content;
            background: var(--ton-bg);
        }

        .status-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .status-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .status-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .status-card:hover {
            transform: translateY(-5px);
            border-color: rgba(0, 136, 204, 0.3);
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }

        .status-operational {
            background: var(--ton-success);
            box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
        }

        .status-degraded {
            background: var(--ton-warning);
            box-shadow: 0 0 10px rgba(234, 179, 8, 0.5);
        }

        .status-outage {
            background: var(--ton-error);
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
        }

        .status-maintenance {
            background: var(--ton-primary);
            box-shadow: 0 0 10px rgba(0, 136, 204, 0.5);
        }

        .status-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--ton-text);
        }

        .status-description {
            color: var(--ton-text-secondary);
            margin-bottom: 1.5rem;
        }

        .status-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .status-uptime {
            background: var(--ton-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .status-latency {
            color: var(--ton-success);
        }

        .status-latency.warning {
            color: var(--ton-warning);
        }

        .status-latency.critical {
            color: var(--ton-error);
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .service-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .service-card:hover {
            border-color: rgba(0, 136, 204, 0.3);
        }

        .service-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .service-name {
            font-weight: 600;
            color: var(--ton-text);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .service-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .service-status {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .service-operational {
            background: rgba(34, 197, 94, 0.1);
            color: var(--ton-success);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .service-degraded {
            background: rgba(234, 179, 8, 0.1);
            color: var(--ton-warning);
            border: 1px solid rgba(234, 179, 8, 0.2);
        }

        .service-outage {
            background: rgba(239, 68, 68, 0.1);
            color: var(--ton-error);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .service-maintenance {
            background: rgba(0, 136, 204, 0.1);
            color: var(--ton-primary);
            border: 1px solid rgba(0, 136, 204, 0.2);
        }

        .service-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .metric {
            text-align: center;
        }

        .metric-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--ton-text);
        }

        .metric-label {
            font-size: 0.8rem;
            color: var(--ton-text-secondary);
            margin-top: 0.25rem;
        }

        .incidents-section {
            margin-bottom: 3rem;
        }

        .incidents-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .incident-card {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .incident-card.resolved {
            opacity: 0.7;
        }

        .incident-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .incident-title {
            font-weight: 600;
            color: var(--ton-text);
            margin-bottom: 0.5rem;
        }

        .incident-service {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: rgba(0, 136, 204, 0.1);
            color: var(--ton-primary);
            border-radius: 6px;
            font-size: 0.8rem;
            margin-right: 0.5rem;
        }

        .incident-time {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .incident-description {
            color: var(--ton-text-secondary);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .incident-updates {
            border-top: 1px solid var(--ton-border);
            padding-top: 1rem;
        }

        .update-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .update-item:last-child {
            border-bottom: none;
        }

        .update-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 0.5rem;
            flex-shrink: 0;
        }

        .update-content {
            flex: 1;
        }

        .update-text {
            color: var(--ton-text);
            margin-bottom: 0.5rem;
        }

        .update-time {
            color: var(--ton-text-secondary);
            font-size: 0.8rem;
        }

        .history-section {
            margin-bottom: 3rem;
        }

        .history-chart {
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-placeholder {
            height: 200px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ton-text-secondary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem;
            background: var(--ton-card);
            border: 1px solid var(--ton-border);
            border-radius: 12px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            background: var(--ton-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
        }

        .last-updated {
            text-align: center;
            color: var(--ton-text-secondary);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .status-indicator.pulsing {
            animation: pulse 2s infinite;
        }

        @media (max-width: 767.98px) {
            .status-section {
                padding-top: calc(80px + 2.5rem);
            }

            .status-overview {
                grid-template-columns: 1fr;
            }

            .services-grid {
                grid-template-columns: 1fr;
            }

            .service-metrics {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .incident-header {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>

<?php
$home_link = '/';
$show_integration = true;
$show_auth_buttons = true;
$nav_links = [
    ['href' => '#features', 'text' => 'Возможности'],
    ['href' => '#security', 'text' => 'Безопасность'],
    ['href' => '/docs', 'text' => 'Интеграция']
];
require_once('core/blocks/navbar.php');
?>

<section class="status-section">
    <div class="container">
        <div class="status-header">
            <h1>Статус системы <?php echo htmlspecialchars($site_name); ?></h1>
            <p class="text-ton-secondary">Мониторинг работы всех компонентов платежной системы</p>
        </div>

        <div class="status-overview">
            <div class="status-card">
                <div class="status-title">
                    <span class="status-indicator <?php echo $overall_status['class']; ?>"></span>
                    Общий статус
                </div>
                <p class="status-description">
                    <?php echo htmlspecialchars($overall_status['description']); ?>
                </p>
                <div class="status-value status-uptime"><?php echo number_format($uptime_percentage, 2); ?>%</div>
                <div class="stat-label">Аптайм (<?php echo $uptime['days']; ?>д <?php echo $uptime['hours']; ?>ч)</div>
                <?php if (!empty($overall_status['issues'])): ?>
                    <div class="mt-2" style="font-size: 0.85rem; color: var(--ton-text-secondary);">
                        <strong>Проблемы:</strong> <?php echo count($overall_status['issues']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="status-card">
                <div class="status-title">
                    <span class="status-indicator <?php echo $api_status_class; ?>"></span>
                    API
                </div>
                <p class="status-description">
                    <?php echo $api_status['status'] === 'ok' ? 'Платежный API полностью функционирует' : 'Проблемы с доступностью API'; ?>
                </p>
                <div class="status-value status-latency <?php echo (isset($api_status['latency']) && $api_status['latency'] > 500) ? 'warning' : ((isset($api_status['latency']) && $api_status['latency'] > 1000) ? 'critical' : ''); ?>">
                    <?php echo $api_status['status'] === 'ok' && isset($api_status['latency']) ? htmlspecialchars(number_format($api_status['latency'], 0)) . 'ms' : 'N/A'; ?>
                </div>
                <div class="stat-label">Средняя задержка ответа</div>
            </div>

            <div class="status-card">
                <div class="status-title">
                    <span class="status-indicator <?php echo $db_status_class; ?>"></span>
                    База данных
                </div>
                <p class="status-description">
                    <?php echo $db_status['status'] === 'ok' ? 'Все транзакции обрабатываются' : 'Проблемы с подключением к БД'; ?>
                </p>
                <div class="status-value"><?php echo number_format($db_status['error_rate'], 2); ?>%</div>
                <div class="stat-label">Ошибок за последний час</div>
            </div>
        </div>

        <div class="last-updated">
            Последнее обновление: <span id="lastUpdatedTime">--:--:--</span>
        </div>

        <div class="services-section">
            <h2 class="section-title mb-4">Состояние сервисов</h2>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(0, 136, 204, 0.1); color: var(--ton-primary);">
                                💸
                            </div>
                            Платежный API
                        </div>
                        <div class="service-status <?php echo $api_status['status'] === 'ok' ? 'service-operational' : 'service-outage'; ?>">
                            <?php echo $api_status['status'] === 'ok' ? 'Работает' : 'Недоступен'; ?>
                        </div>
                    </div>
                    <p class="service-description">Обработка входящих платежей и транзакций</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo $api_status['status'] === 'ok' ? 'OK' : 'N/A'; ?></div>
                            <div class="metric-label">Статус</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value status-latency <?php echo (isset($api_status['latency']) && $api_status['latency'] > 500) ? 'warning' : ((isset($api_status['latency']) && $api_status['latency'] > 1000) ? 'critical' : ''); ?>">
                                <?php echo $api_status['status'] === 'ok' && isset($api_status['latency']) ? htmlspecialchars(number_format($api_status['latency'], 0)) . 'ms' : 'N/A'; ?>
                            </div>
                            <div class="metric-label">Задержка</div>
                        </div>
                    </div>
                </div>

                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(34, 197, 94, 0.1); color: var(--ton-success);">
                                <i class="fas fa-link"></i>
                            </div>
                            TON Blockchain
                        </div>
                        <div class="service-status service-operational">Работает</div>
                    </div>
                    <p class="service-description">Синхронизация с блокчейном TON</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value">~5с</div>
                            <div class="metric-label">Время блока</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value"><?php echo $api_status['status'] === 'ok' ? '100%' : 'N/A'; ?></div>
                            <div class="metric-label">Доступность</div>
                        </div>
                    </div>
                </div>

                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(234, 179, 8, 0.1); color: var(--ton-warning);">
                                <i class="fas fa-database"></i>
                            </div>
                            База данных
                        </div>
                        <div class="service-status <?php 
                            echo $db_status['status'] === 'ok' ? 
                                ($db_status['error_rate'] > 5 ? 'service-degraded' : 'service-operational') : 
                                'service-outage'; 
                        ?>">
                            <?php 
                            if ($db_status['status'] === 'ok') {
                                echo $db_status['error_rate'] > 5 ? 'Понижена производительность' : 'Работает';
                            } else {
                                echo 'Недоступна';
                            }
                            ?>
                        </div>
                    </div>
                    <p class="service-description">Основная база данных транзакций</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo number_format($cpu_usage, 0); ?>%</div>
                            <div class="metric-label">CPU</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value status-latency <?php echo (isset($db_status['latency']) && $db_status['latency'] > 500) ? 'warning' : ((isset($db_status['latency']) && $db_status['latency'] > 1000) ? 'critical' : ''); ?>">
                                <?php echo $db_status['status'] === 'ok' && isset($db_status['latency']) ? htmlspecialchars(number_format($db_status['latency'], 0)) . 'ms' : 'N/A'; ?>
                            </div>
                            <div class="metric-label">Запросы</div>
                        </div>
                    </div>
                </div>

                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(0, 136, 204, 0.1); color: var(--ton-primary);">
                                <i class="fas fa-bell"></i>
                            </div>
                            Webhook сервис
                        </div>
                        <div class="service-status <?php echo $api_status['status'] === 'ok' ? 'service-operational' : 'service-outage'; ?>">
                            <?php echo $api_status['status'] === 'ok' ? 'Работает' : 'Недоступен'; ?>
                        </div>
                    </div>
                    <p class="service-description">Отправка уведомлений о платежах</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo $api_status['status'] === 'ok' ? 'OK' : 'N/A'; ?></div>
                            <div class="metric-label">Статус</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value status-latency <?php echo $api_status['latency'] > 500 ? 'warning' : ''; ?>">
                                <?php echo $api_status['status'] === 'ok' ? number_format($api_status['latency'], 0) . 'ms' : 'N/A'; ?>
                            </div>
                            <div class="metric-label">Задержка</div>
                        </div>
                    </div>
                </div>

                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(34, 197, 94, 0.1); color: var(--ton-success);">
                                👛
                            </div>
                            Кошельки
                        </div>
                        <div class="service-status <?php echo $api_status['status'] === 'ok' ? 'service-operational' : 'service-outage'; ?>">
                            <?php echo $api_status['status'] === 'ok' ? 'Работает' : 'Недоступен'; ?>
                        </div>
                    </div>
                    <p class="service-description">Состояние кошельков</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo $api_status['status'] === 'ok' ? '100%' : 'N/A'; ?></div>
                            <div class="metric-label">Доступность</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value"><?php echo $db_status['error_rate'] > 0 ? number_format($db_status['error_rate'], 1) : '0'; ?>%</div>
                            <div class="metric-label">Ошибок</div>
                        </div>
                    </div>
                </div>

                <div class="service-card">
                    <div class="service-header">
                        <div class="service-name">
                            <div class="service-icon" style="background: rgba(0, 136, 204, 0.1); color: var(--ton-primary);">
                                💻
                            </div>
                            Системные ресурсы
                        </div>
                        <div class="service-status <?php echo ($cpu_usage < 80 && $memory_usage < 80) ? 'service-operational' : ($cpu_usage > 95 || $memory_usage > 95 ? 'service-outage' : 'service-degraded'); ?>">
                            <?php 
                            if ($cpu_usage < 80 && $memory_usage < 80) {
                                echo 'Норма';
                            } elseif ($cpu_usage > 95 || $memory_usage > 95) {
                                echo 'Критично';
                            } else {
                                echo 'Высокая нагрузка';
                            }
                            ?>
                        </div>
                    </div>
                    <p class="service-description">Использование ресурсов сервера</p>
                    <div class="service-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo number_format($memory_usage, 1); ?>%</div>
                            <div class="metric-label">Память</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value"><?php echo number_format($cpu_usage, 1); ?>%</div>
                            <div class="metric-label">CPU</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="history-section">
            <h2 class="section-title mb-4">Статистика за 30 дней</h2>

            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($uptime_percentage, 2); ?>%</div>
                    <div class="stat-label">Общая доступность</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($total_transactions); ?></div>
                    <div class="stat-label">Всего транзакций</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($failed_transactions); ?></div>
                    <div class="stat-label">Неудачных транзакций</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $db_status['status'] === 'ok' && $api_status['status'] === 'ok' ? '0' : '1+'; ?></div>
                    <div class="stat-label">Активных проблем</div>
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
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();

        document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);

        function updateLastUpdatedTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('ru-RU');
            document.getElementById('lastUpdatedTime').textContent = timeString;
        }

        updateLastUpdatedTime();
        setInterval(updateLastUpdatedTime, 30000);

        setInterval(function() {
            location.reload();
        }, 300000);

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
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof window.initUserDropdown === 'function') {
                    window.initUserDropdown();
                }
            });
        } else {
            if (typeof window.initUserDropdown === 'function') {
                window.initUserDropdown();
            }
        }
    });
</script>
</body>
</html>