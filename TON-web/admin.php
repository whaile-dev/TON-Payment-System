<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');

if (!getCore()->isAuth()) {
    header('Location: /');
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/cold_wallet_config.php');
$config = getConfig();
$admins = $config['admins'] ?? [];
$admin_emails = is_array($admins) ? array_map('strval', $admins) : [];
$current_email = isset($_SESSION['email']) ? trim((string)$_SESSION['email']) : '';

if (!in_array($current_email, $admin_emails, true)) {
    header('Location: /dashboard.php');
    exit();
}

$conn = getCore()->getConn();
$cold_wallet = getColdWalletConfig($conn);
$month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
$current_month_start = date('Y-m-01 00:00:00');

$stmt = $conn->query("SELECT COUNT(*) as cnt FROM Users");
$total_users = $stmt ? (int)$stmt->fetch_assoc()['cnt'] : 0;
$stmt->close();

$stmt = $conn->query("SELECT COUNT(*) as cnt FROM Cashiers");
$total_cashiers = $stmt ? (int)$stmt->fetch_assoc()['cnt'] : 0;
$stmt->close();
$stmt = $conn->query("SELECT COUNT(*) as cnt FROM Cashiers WHERE status = 'active'");
$active_cashiers = $stmt ? (int)$stmt->fetch_assoc()['cnt'] : 0;
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
        COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as volume
    FROM (
        SELECT price, status FROM TONDeposit WHERE time_recorded >= ?
        UNION ALL
        SELECT price, status FROM JETTONDeposit WHERE time_recorded >= ?
    ) t
");
$stmt->bind_param('ss', $month_ago, $month_ago);
$stmt->execute();
$deposits_month = $stmt->get_result()->fetch_assoc();
$stmt->close();
$dep_total = $deposits_month ? (int)$deposits_month['total'] : 0;
$dep_success = $deposits_month ? (int)$deposits_month['successful'] : 0;
$dep_success_rate = $dep_total > 0 ? round(($dep_success / $dep_total) * 100, 1) : 0;

$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM TONDeposit WHERE time_recorded >= ?");
$stmt->bind_param('s', $month_ago);
$stmt->execute();
$dep_volume_ton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();
$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM JETTONDeposit WHERE time_recorded >= ?");
$stmt->bind_param('s', $month_ago);
$stmt->execute();
$dep_volume_jetton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM TONDeposit WHERE time_recorded >= ?");
$stmt->bind_param('s', $current_month_start);
$stmt->execute();
$current_month_dep_ton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();
$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM JETTONDeposit WHERE time_recorded >= ?");
$stmt->bind_param('s', $current_month_start);
$stmt->execute();
$current_month_dep_jetton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
        COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as volume
    FROM (
        SELECT price, status FROM TONWithdraw WHERE time_recorded >= ?
        UNION ALL
        SELECT price, status FROM JETTONWithdraw WHERE time_recorded >= ?
    ) t
");
$stmt->bind_param('ss', $month_ago, $month_ago);
$stmt->execute();
$withdraws_month = $stmt->get_result()->fetch_assoc();
$stmt->close();
$wd_total = $withdraws_month ? (int)$withdraws_month['total'] : 0;
$wd_success = $withdraws_month ? (int)$withdraws_month['successful'] : 0;
$wd_success_rate = $wd_total > 0 ? round(($wd_success / $wd_total) * 100, 1) : 0;

$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM TONWithdraw WHERE time_recorded >= ?");
$stmt->bind_param('s', $month_ago);
$stmt->execute();
$wd_volume_ton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();
$stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'success' THEN price ELSE 0 END), 0) as vol FROM JETTONWithdraw WHERE time_recorded >= ?");
$stmt->bind_param('s', $month_ago);
$stmt->execute();
$wd_volume_jetton = (float)($stmt->get_result()->fetch_assoc()['vol'] ?? 0);
$stmt->close();

$stmt = $conn->query("SELECT COALESCE(SUM(price), 0) as vol FROM TONDeposit WHERE status = 'success'");
$total_dep_ton = $stmt ? (float)$stmt->fetch_assoc()['vol'] : 0;
$stmt->close();
$stmt = $conn->query("SELECT COALESCE(SUM(price), 0) as vol FROM JETTONDeposit WHERE status = 'success'");
$total_dep_jetton = $stmt ? (float)$stmt->fetch_assoc()['vol'] : 0;
$stmt->close();

$stmt = $conn->query("SELECT COALESCE(SUM(price), 0) as vol FROM TONWithdraw WHERE status = 'success'");
$total_wd_ton = $stmt ? (float)$stmt->fetch_assoc()['vol'] : 0;
$stmt->close();
$stmt = $conn->query("SELECT COALESCE(SUM(price), 0) as vol FROM JETTONWithdraw WHERE status = 'success'");
$total_wd_jetton = $stmt ? (float)$stmt->fetch_assoc()['vol'] : 0;
$stmt->close();

$site_name = $config['site']['name'] ?? 'TonPay';
$cold_enabled = !empty($cold_wallet['enabled']);
$cold_address = trim((string)($cold_wallet['address'] ?? ''));
$cold_label = trim((string)($cold_wallet['label'] ?? 'SafePal S1'));
$cold_threshold = isset($cold_wallet['large_withdraw_threshold_ton']) ? (float)$cold_wallet['large_withdraw_threshold_ton'] : 1000.0;
$pending_cold = [];
if ($cold_enabled) {
    $conn->query("CREATE TABLE IF NOT EXISTS PendingColdWithdraw (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        cashier_id INT NOT NULL,
        amount DECIMAL(20,9) NOT NULL,
        wallet VARCHAR(100) NOT NULL,
        currency VARCHAR(20) NOT NULL DEFAULT 'TON',
        api_token VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        executed_at DATETIME NULL
    )");
    $r = $conn->query("SELECT id, cashier_id, amount, wallet, currency, created_at FROM PendingColdWithdraw WHERE status = 'pending' ORDER BY created_at DESC");
    if ($r) {
        while ($row = $r->fetch_assoc()) $pending_cold[] = $row;
        $r->free();
    }
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Админ-панель | <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/svg+xml" href="scripts/img/logo.svg"
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/scripts/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/scripts/libs/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="/scripts/libs/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/scripts/css/custom.css" rel="stylesheet">
    <style>
        .navbar-actions { position: relative; overflow: visible !important; }
        .navbar .container { overflow: visible !important; }
        .glass-navbar { overflow: visible !important; }
        .navbar { overflow: visible !important; }
        .user-dropdown { position: relative; z-index: 1050; }
        .user-dropdown-btn {
            background: var(--ton-card); border: 1px solid var(--ton-border); color: var(--ton-text);
            padding: 0.5rem 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s ease; font-weight: 500; cursor: pointer;
        }
        .user-dropdown-btn:hover { background: var(--ton-card-hover); border-color: var(--ton-primary); }
        .user-dropdown-btn::after { content: '▼'; font-size: 0.7rem; margin-left: 0.5rem; transition: transform 0.3s ease; }
        .user-dropdown-btn[aria-expanded="true"]::after { transform: rotate(180deg); }
        .user-dropdown-menu {
            position: absolute; top: calc(100% + 0.5rem); right: 0;
            background: #1e1e2e; border: 1px solid var(--ton-border); border-radius: 12px;
            min-width: 200px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); z-index: 1050;
            opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s ease; padding: 0.5rem 0;
        }
        [data-bs-theme="light"] .user-dropdown-menu { background: #ffffff; }
        .user-dropdown-menu.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .user-dropdown-item {
            display: block; padding: 0.75rem 1.5rem; color: var(--ton-text); text-decoration: none;
            transition: all 0.2s ease; border: none; background: none; width: 100%; text-align: left; cursor: pointer; font-size: 0.95rem;
        }
        .user-dropdown-item:hover { background: var(--ton-card-hover); color: var(--ton-primary); }
        .user-dropdown-item.text-danger { color: var(--ton-error, #ef4444) !important; }
        .user-dropdown-divider { margin: 0.25rem 0; border-color: var(--ton-border); }
        .admin-section { padding-top: 120px; min-height: 100vh; background: var(--ton-bg); }
        .admin-header { margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--ton-border); }
        .admin-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; background: var(--ton-primary); color: #000; border-radius: 4px; margin-left: 0.5rem; }
        .admin-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .admin-card { background: var(--ton-card-bg); border: 1px solid var(--ton-border); border-radius: 12px; padding: 1.25rem; }
        .admin-card .value { font-size: 1.5rem; font-weight: 700; color: var(--ton-text); }
        .admin-card .label { font-size: 0.85rem; color: var(--ton-text-secondary); margin-top: 0.25rem; }
        .admin-card.success .value { color: var(--ton-success, #22c55e); }
        .admin-card.warning .value { color: var(--ton-warning, #eab308); }
        .admin-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; }
        .admin-block { background: var(--ton-card-bg); border: 1px solid var(--ton-border); border-radius: 12px; padding: 1.5rem; }
        .admin-block h3 { font-size: 1rem; margin-bottom: 1rem; color: var(--ton-text-secondary); }
        .admin-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--ton-border); }
        .admin-row:last-child { border-bottom: none; }
        .admin-section { padding-bottom: 4rem; }
        .admin-block .form-control, .admin-block .form-check-input { background: var(--ton-card); border-color: var(--ton-border); color: var(--ton-text); }
        .admin-block .form-control:focus { background: var(--ton-card-hover); border-color: var(--ton-primary); color: var(--ton-text); }
    </style>
</head>
<body>
<?php
$container_class = 'container';
$nav_links = [
    ['href' => '/dashboard.php', 'text' => 'Кабинет'],
    ['href' => '/#features', 'text' => 'Возможности'],
    ['href' => '/docs', 'text' => 'Интеграция']
];
require_once('core/blocks/navbar.php');
?>

<section class="admin-section">
    <div class="container">
        <div class="admin-header d-flex flex-wrap align-items-center gap-2">
            <h1 class="mb-0">Показатели платежной системы</h1>
            <span class="admin-badge">Админ</span>
            <p class="w-100 mb-0 mt-2 text-ton-secondary">Глобальная статистика по депозитам, выводам и кассам</p>
        </div>

        <div class="admin-stats">
            <div class="admin-card">
                <div class="value"><?php echo number_format($total_users, 0, ',', ' '); ?></div>
                <div class="label">Пользователей</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo $active_cashiers; ?> / <?php echo $total_cashiers; ?></div>
                <div class="label">Активных / всего касс</div>
            </div>
            <div class="admin-card success">
                <div class="value"><?php echo $dep_success_rate; ?>%</div>
                <div class="label">Успешность депозитов (месяц)</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo number_format($dep_volume_ton, 2); ?> / <?php echo number_format($dep_volume_jetton, 2); ?></div>
                <div class="label">Депозиты за месяц TON / JETTON</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo number_format($dep_total, 0, ',', ' '); ?></div>
                <div class="label">Депозитов за месяц</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo $wd_success_rate; ?>%</div>
                <div class="label">Успешность выводов (месяц)</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo number_format($wd_volume_ton, 2); ?> / <?php echo number_format($wd_volume_jetton, 2); ?></div>
                <div class="label">Выводы за месяц TON / JETTON</div>
            </div>
            <div class="admin-card">
                <div class="value"><?php echo number_format($wd_total, 0, ',', ' '); ?></div>
                <div class="label">Выводов за месяц</div>
            </div>
        </div>

        <div class="admin-grid-2">
            <div class="admin-block">
                <h3><i class="fas fa-chart-line me-2"></i>За текущий месяц</h3>
                <div class="admin-row">
                    <span>Депозиты TON (успешные)</span>
                    <strong><?php echo number_format($current_month_dep_ton, 2); ?> TON</strong>
                </div>
                <div class="admin-row">
                    <span>Депозиты JETTON (успешные)</span>
                    <strong><?php echo number_format($current_month_dep_jetton, 2); ?> JETTON</strong>
                </div>
            </div>
            <div class="admin-block">
                <h3><i class="fas fa-database me-2"></i>За всё время</h3>
                <div class="admin-row">
                    <span>Всего депозитов TON (успешные)</span>
                    <strong><?php echo number_format($total_dep_ton, 2); ?> TON</strong>
                </div>
                <div class="admin-row">
                    <span>Всего депозитов JETTON (успешные)</span>
                    <strong><?php echo number_format($total_dep_jetton, 2); ?> JETTON</strong>
                </div>
                <div class="admin-row">
                    <span>Всего выводов TON (успешные)</span>
                    <strong><?php echo number_format($total_wd_ton, 2); ?> TON</strong>
                </div>
                <div class="admin-row">
                    <span>Всего выводов JETTON (успешные)</span>
                    <strong><?php echo number_format($total_wd_jetton, 2); ?> JETTON</strong>
                </div>
            </div>
        </div>

        <?php if ($cold_enabled && !empty($pending_cold)): ?>
        <div class="admin-block mt-4" style="max-width: 100%;">
            <h3><i class="fas fa-clock me-2"></i>Ожидают подтверждения холодным кошельком (≥<?php echo (int)$cold_threshold; ?> TON)</h3>
            <div class="table-responsive">
                <table class="table table-dark table-borderless mb-0">
                    <thead><tr><th>Дата</th><th>Касса</th><th>Сумма</th><th>Кошелёк</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pending_cold as $p): ?>
                        <tr data-pending-id="<?php echo (int)$p['id']; ?>">
                            <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($p['created_at']))); ?></td>
                            <td><?php echo (int)$p['cashier_id']; ?></td>
                            <td><strong><?php echo number_format((float)$p['amount'], 2); ?> <?php echo htmlspecialchars($p['currency']); ?></strong></td>
                            <td><code class="small"><?php echo htmlspecialchars(substr($p['wallet'], 0, 20)); ?>…</code></td>
                            <td><button type="button" class="btn btn-ton-primary btn-sm btn-confirm-cold" data-id="<?php echo (int)$p['id']; ?>">Подтвердить и выполнить</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="coldConfirmMsg" class="mt-2 small" style="display:none;"></div>
        </div>
        <?php endif; ?>
        <div class="admin-block mt-4" style="max-width: 100%;">
            <h3><i class="fas fa-shield-halved me-2"></i>SafePal S1 — холодный кошелёк</h3>
            <form id="coldWalletForm" class="mt-3">
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="coldEnabled" <?php echo $cold_enabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="coldEnabled">Включено</label>
                </div>
                <div class="mb-3">
                    <label for="coldAddress" class="form-label">Адрес</label>
                    <input type="text" class="form-control" id="coldAddress" value="<?php echo htmlspecialchars($cold_address); ?>" placeholder="EQ...">
                </div>
                <div class="mb-3">
                    <label for="coldLabel" class="form-label">Подпись</label>
                    <input type="text" class="form-control" id="coldLabel" value="<?php echo htmlspecialchars($cold_label); ?>" placeholder="SafePal S1">
                </div>
                <div class="mb-3">
                    <label for="coldThreshold" class="form-label">Порог подтверждения, TON</label>
                    <input type="number" class="form-control" id="coldThreshold" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string)$cold_threshold); ?>">
                </div>
                <button type="submit" class="btn btn-ton-primary" id="coldSaveBtn">Сохранить</button>
                <span id="coldSaveMsg" class="ms-3 small" style="display:none;"></span>
            </form>
        </div>
    </div>
</section>

<?php require_once('core/blocks/footer.php'); ?>
<script src="/scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/scripts/assets/js/theme.js"></script>
<script src="/scripts/js/app.js"></script>
<script>
(function(){
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var token = csrf ? csrf.getAttribute('content') : '';
    var form = document.getElementById('coldWalletForm');
    if (form) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var btn = document.getElementById('coldSaveBtn');
            var msg = document.getElementById('coldSaveMsg');
            btn.disabled = true;
            msg.style.display = 'inline';
            msg.className = 'ms-3 small text-secondary';
            msg.textContent = '…';
            fetch('/core/api/save-cold-wallet.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({
                    enabled: document.getElementById('coldEnabled').checked,
                    address: document.getElementById('coldAddress').value.trim(),
                    label: document.getElementById('coldLabel').value.trim() || 'SafePal S1',
                    threshold_ton: parseFloat(document.getElementById('coldThreshold').value) || 1000
                })
            }).then(function(r){ return r.json(); }).then(function(d){
                msg.className = 'ms-3 small ' + (d.success ? 'text-success' : 'text-danger');
                msg.textContent = d.message || (d.success ? 'Сохранено' : 'Ошибка');
                btn.disabled = false;
            }).catch(function(){
                msg.className = 'ms-3 small text-danger';
                msg.textContent = 'Ошибка сети';
                btn.disabled = false;
            });
        });
    }
})();
</script>
<?php if ($cold_enabled && !empty($pending_cold)): ?>
<script>
(function(){
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var token = csrf ? csrf.getAttribute('content') : '';
    document.querySelectorAll('.btn-confirm-cold').forEach(function(btn){
        btn.addEventListener('click', function(){
            var id = parseInt(this.getAttribute('data-id'), 10);
            if (!id) return;
            this.disabled = true;
            var row = document.querySelector('tr[data-pending-id="' + id + '"]');
            var msg = document.getElementById('coldConfirmMsg');
            fetch('/core/api/execute-cold-withdraw.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ id: id })
            }).then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(o){
                msg.style.display = 'block';
                msg.className = 'mt-2 small ' + (o.data.success ? 'text-success' : 'text-danger');
                msg.textContent = o.data.message || (o.data.success ? 'Выполнено' : 'Ошибка');
                if (o.data.success && row) row.remove();
            }).catch(function(){
                msg.style.display = 'block';
                msg.className = 'mt-2 small text-danger';
                msg.textContent = 'Ошибка сети';
            });
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
