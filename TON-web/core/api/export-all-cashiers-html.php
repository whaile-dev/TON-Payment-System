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

if (!RateLimiter::check('export_all_cashiers_html', 10, 60)) {
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
    ORDER BY td.time_recorded ASC
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
    ORDER BY jd.time_recorded ASC
");
$stmt->bind_param("i", $user_id_int);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_transactions[] = $row;
}
$stmt->close();

usort($all_transactions, function($a, $b) {
    return strtotime($a['time_recorded']) - strtotime($b['time_recorded']);
});

$daily_data = [];
$status_counts = ['success' => 0, 'pending' => 0, 'failed' => 0, 'other' => 0];
$total_amount = 0;
$successful_amount = 0;
$cashier_stats = [];

foreach ($cashiers as $cashier) {
    $cashier_stats[$cashier['id']] = [
        'name' => $cashier['name'],
        'transactions' => 0,
        'amount' => 0,
        'successful' => 0
    ];
}

foreach ($all_transactions as $tx) {
    $date = date('Y-m-d', strtotime($tx['time_recorded']));
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['count' => 0, 'amount' => 0];
    }
    $daily_data[$date]['count']++;
    $daily_data[$date]['amount'] += floatval($tx['price']);
    
    $status = $tx['status'] ?? 'other';
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    } else {
        $status_counts['other']++;
    }
    
    $total_amount += floatval($tx['price']);
    if ($status === 'success') {
        $successful_amount += floatval($tx['price']);
    }
    
    $cashier_id = $tx['cashier_id'] ?? 0;
    if (isset($cashier_stats[$cashier_id])) {
        $cashier_stats[$cashier_id]['transactions']++;
        $cashier_stats[$cashier_id]['amount'] += floatval($tx['price']);
        if ($status === 'success') {
            $cashier_stats[$cashier_id]['successful']++;
        }
    }
}

$chart_labels = [];
$chart_counts = [];
$chart_amounts = [];
$cumulative_amount = 0;

foreach ($daily_data as $date => $data) {
    $chart_labels[] = date('d.m.Y', strtotime($date));
    $chart_counts[] = $data['count'];
    $cumulative_amount += $data['amount'];
    $chart_amounts[] = $cumulative_amount;
}

$cashier_chart_labels = [];
$cashier_chart_amounts = [];
foreach ($cashier_stats as $cashier_id => $stats) {
    $cashier_chart_labels[] = $stats['name'] . ' (ID: ' . $cashier_id . ')';
    $cashier_chart_amounts[] = $stats['amount'];
}

$filename = 'all_cashiers_report_' . date('Y-m-d_His') . '.html';

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчет по всем кассам</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #0088cc;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #0088cc;
        }
        .info-card-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .info-card-value {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        .chart-container {
            margin: 30px 0;
            position: relative;
            height: 400px;
        }
        .chart-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #0088cc;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status-success {
            color: #28a745;
            font-weight: bold;
        }
        .status-pending {
            color: #ffc107;
            font-weight: bold;
        }
        .status-failed {
            color: #dc3545;
            font-weight: bold;
        }
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #0088cc;
            color: white;
            border: none;
            padding: 15px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .print-btn:hover {
            background: #006699;
        }
        @media print {
            .print-btn {
                display: none;
            }
            body {
                background: white;
                padding: 0;
            }
            .container {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Отчет по всем кассам</h1>
        <div class="subtitle">Сгенерировано: <?php echo date('d.m.Y H:i:s'); ?></div>
        
        <div class="info-grid">
            <div class="info-card">
                <div class="info-card-label">Всего касс</div>
                <div class="info-card-value"><?php echo count($cashiers); ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Активных касс</div>
                <div class="info-card-value"><?php echo count(array_filter($cashiers, function($c) { return ($c['status'] ?? 'inactive') === 'active'; })); ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Всего транзакций</div>
                <div class="info-card-value"><?php echo count($all_transactions); ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Успешных</div>
                <div class="info-card-value"><?php echo $status_counts['success']; ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Общая сумма</div>
                <div class="info-card-value"><?php echo number_format($total_amount, 2, '.', ' '); ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Успешная сумма</div>
                <div class="info-card-value"><?php echo number_format($successful_amount, 2, '.', ' '); ?></div>
            </div>
        </div>

        <?php if (count($chart_labels) > 0): ?>
        <div class="chart-container">
            <div class="chart-title">Динамика транзакций по дням (все кассы)</div>
            <canvas id="transactionsChart"></canvas>
        </div>

        <div class="chart-container">
            <div class="chart-title">Накопительная сумма транзакций</div>
            <canvas id="amountChart"></canvas>
        </div>

        <?php if (count($cashier_chart_labels) > 0): ?>
        <div class="chart-container">
            <div class="chart-title">Распределение по кассам (сумма транзакций)</div>
            <canvas id="cashierChart"></canvas>
        </div>
        <?php endif; ?>

        <div class="chart-container" style="height: 300px;">
            <div class="chart-title">Распределение по статусам</div>
            <canvas id="statusChart"></canvas>
        </div>
        <?php endif; ?>

        <h2 style="margin-top: 40px; margin-bottom: 20px;">Сводка по кассам</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Валюта</th>
                    <th>Статус</th>
                    <th>Баланс</th>
                    <th>Транзакций</th>
                    <th>Успешных</th>
                    <th>Сумма</th>
                    <th>Создана</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cashiers as $cashier): 
                    $cashier_id = $cashier['id'];
                    $stats = $cashier_stats[$cashier_id] ?? ['transactions' => 0, 'amount' => 0, 'successful' => 0];
                ?>
                <tr>
                    <td><?php echo $cashier_id; ?></td>
                    <td><?php echo htmlspecialchars($cashier['name'] ?? 'Без названия'); ?></td>
                    <td><?php echo strtoupper($cashier['currency'] ?? 'TON'); ?></td>
                    <td><?php echo ($cashier['status'] ?? 'inactive') === 'active' ? 'Активна' : 'Неактивна'; ?></td>
                    <td><?php echo number_format(floatval($cashier['balance'] ?? 0), 2, '.', ' '); ?></td>
                    <td><?php echo $stats['transactions']; ?></td>
                    <td><?php echo $stats['successful']; ?></td>
                    <td><?php echo number_format($stats['amount'], 2, '.', ' '); ?></td>
                    <td><?php echo isset($cashier['created_at']) ? date('d.m.Y H:i', strtotime($cashier['created_at'])) : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="print-btn" onclick="window.print()"><i class="fas fa-print me-2"></i>Печать / Сохранить PDF</button>

    <?php if (count($chart_labels) > 0): ?>
    <script>
        const ctx1 = document.getElementById('transactionsChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Количество транзакций',
                    data: <?php echo json_encode($chart_counts); ?>,
                    borderColor: 'rgb(0, 136, 204)',
                    backgroundColor: 'rgba(0, 136, 204, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1200,
                    easing: 'easeOutCubic'
                },
                animations: {
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
                    colors: {
                        duration: 800,
                        easing: 'easeOutCubic'
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const ctx2 = document.getElementById('amountChart').getContext('2d');
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Накопительная сумма',
                    data: <?php echo json_encode($chart_amounts); ?>,
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1200,
                    easing: 'easeOutCubic'
                },
                animations: {
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
                    colors: {
                        duration: 800,
                        easing: 'easeOutCubic'
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        <?php if (count($cashier_chart_labels) > 0): ?>
        const ctx3 = document.getElementById('cashierChart').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($cashier_chart_labels); ?>,
                datasets: [{
                    label: 'Сумма транзакций',
                    data: <?php echo json_encode($cashier_chart_amounts); ?>,
                    backgroundColor: 'rgba(0, 136, 204, 0.8)',
                    borderColor: 'rgb(0, 136, 204)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1200,
                    easing: 'easeOutCubic'
                },
                animations: {
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
                    colors: {
                        duration: 800,
                        easing: 'easeOutCubic'
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>

        const ctx4 = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx4, {
            type: 'doughnut',
            data: {
                labels: ['Успешно', 'Ожидание', 'Отменено', 'Другие'],
                datasets: [{
                    data: [
                        <?php echo $status_counts['success']; ?>,
                        <?php echo $status_counts['pending']; ?>,
                        <?php echo $status_counts['failed']; ?>,
                        <?php echo $status_counts['other']; ?>
                    ],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(108, 117, 125, 0.8)'
                    ],
                    borderColor: [
                        'rgb(40, 167, 69)',
                        'rgb(255, 193, 7)',
                        'rgb(220, 53, 69)',
                        'rgb(108, 117, 125)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1500,
                    easing: 'easeInOutQuart'
                },
                animations: {
                    colors: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    },
                    x: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    },
                    y: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
<?php exit(); ?>

