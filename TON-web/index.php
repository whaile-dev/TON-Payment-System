<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/core/core.php');
$config = getConfig();
$site_name = $config['site']['name'] ?? 'TonPay';
?>

<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_name); ?> | Глобальная платежная система для Telegram</title>
    <link rel="icon" type="image/svg+xml" href="scripts/img/logo.svg">
    <link rel="apple-touch-icon" href="scripts/img/logo.svg">
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
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
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

<section class="hero-section">
    <div class="ton-particles" id="particles-js"></div>
    <div class="hero-gradient"></div>

    <div class="container">
        <div class="row align-items-center min-vh-100">
            <div class="col-lg-6">
                <div class="hero-badge">
                    <span class="badge-glow">💎 ПЛАТЕЖИ В TON И JETTON</span>
                </div>

                <h1 class="hero-title">
                    <span class="gradient-text">Прием платежей</span>
                    <br>в криптовалюте TON
                </h1>

                <p class="hero-subtitle">
                    Простая и надежная система для приема платежей в TON и JETTON токенах.
                    Создайте кассу за минуту и начните принимать платежи через API.
                </p>

                <div class="hero-actions">
                    <button class="btn btn-hero-primary" onclick="handleCreateCashier()">
                        <span>Создать кассу</span>
                        <div class="btn-shine"></div>
                    </button>
                    <a href="/docs" class="btn btn-hero-secondary">
                        <i class="btn-icon">📚</i>
                        Документация API
                    </a>
                </div>

                <div class="hero-stats">
                    <div class="stat-item">
                        <div class="stat-value">TON</div>
                        <div class="stat-label">Нативный блокчейн</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">JETTON</div>
                        <div class="stat-label">Поддержка токенов</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">API</div>
                        <div class="stat-label">Простая интеграция</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="hero-visual">
                    <div class="visual-container">
                        <div class="ton-sphere">
                            <div class="sphere-core"></div>
                            <div class="orbit orbit-1"></div>
                            <div class="orbit orbit-2"></div>
                            <div class="orbit orbit-3"></div>
                        </div>

                        <div class="compact-card payment-card">
                            <div class="compact-card-header">
                                <div class="compact-icon"><i class="fas fa-wallet"></i></div>
                                <div class="compact-title">Платеж получен</div>
                                <div class="compact-badge success">+10.5 TON</div>
                            </div>
                            <div class="compact-content">
                                <div class="compact-from">Касса #123</div>
                                <div class="compact-time">Только что</div>
                            </div>
                            <div class="compact-chart mini-chart">
                                <div class="mini-bar" style="height: 60%"></div>
                                <div class="mini-bar" style="height: 80%"></div>
                                <div class="mini-bar" style="height: 45%"></div>
                                <div class="mini-bar" style="height: 90%"></div>
                            </div>
                        </div>

                        <div class="compact-card stats-card">
                            <div class="compact-card-header">
                                <div class="compact-icon"><i class="fas fa-chart-bar"></i></div>
                                <div class="compact-title">Статистика</div>
                            </div>
                            <div class="compact-stats">
                                <div class="compact-stat">
                                    <div class="stat-label">Транзакций</div>
                                    <div class="stat-value">24</div>
                                </div>
                                <div class="compact-stat">
                                    <div class="stat-label">Баланс</div>
                                    <div class="stat-value">156 TON</div>
                                </div>
                            </div>
                            <div class="compact-progress">
                                <div class="progress-bar" style="width: 75%"></div>
                            </div>
                        </div>

                        <div class="compact-card revenue-card">
                            <div class="compact-card-header">
                                <div class="compact-icon"><i class="fas fa-coins"></i></div>
                                <div class="compact-title">Доходы</div>
                            </div>
                            <div class="compact-revenue">
                                <div class="revenue-amount">1,234 TON</div>
                                <div class="revenue-change positive">Активно</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="scroll-arrow">
        <div class="arrow"></div>
    </div>
</section>

<section class="trust-section">
    <div class="container">
        <div class="trust-label">Надежная инфраструктура для вашего бизнеса</div>
        <div class="trust-logos">
            <div class="logo-item">TON Blockchain</div>
            <div class="logo-item">Webhook API</div>
            <div class="logo-item">Безопасность</div>
            <div class="logo-item">24/7 Работа</div>
            <div class="logo-item">Простая интеграция</div>
        </div>
    </div>
</section>

<section id="features" class="features-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Возможности системы</h2>
            <p class="section-subtitle">Все необходимое для приема платежей в TON и JETTON</p>
        </div>

        <div class="row g-5">
            <div class="col-lg-4">
                <div class="feature-card premium">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-bolt"></i>
                        </div>
                    </div>
                    <h3>Быстрые транзакции</h3>
                    <p>Платежи обрабатываются быстро благодаря блокчейну TON. Среднее время подтверждения 5-10 секунд</p>
                    <div class="feature-badge">TON Blockchain</div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="feature-card premium">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-globe"></i>
                        </div>
                    </div>
                    <h3>TON и JETTON</h3>
                    <p>Принимайте платежи в нативной валюте TON и любых JETTON токенах. Поддержка всех стандартных токенов</p>
                    <div class="feature-badge">Мультивалютность</div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="feature-card premium">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>
                    <h3>Безопасность</h3>
                    <p>Все транзакции проходят через блокчейн TON. Средства хранятся в вашем кошельке, доступ только у вас</p>
                    <div class="feature-badge">Блокчейн</div>
                </div>
            </div>
        </div>

        <div class="row g-5 mt-3">
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <h4>Низкие комиссии</h4>
                    <p>Комиссия блокчейна TON минимальна. Вы платите только за транзакции в сети</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-code"></i>
                        </div>
                    </div>
                    <h4>REST API</h4>
                    <p>Простой REST API для создания платежей, проверки статуса и вывода средств</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <h4>Webhook уведомления</h4>
                    <p>Мгновенные уведомления о платежах через webhook. Надежная доставка событий</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <h4>Статистика и аналитика</h4>
                    <p>Детальная статистика по кассам, транзакциям и балансам. Экспорт в CSV и HTML</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="security" class="security-section">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="security-visual">
                    <div class="security-shield">
                        <div class="shield-core"></div>
                        <div class="shield-layer layer-1"></div>
                        <div class="shield-layer layer-2"></div>
                        <div class="shield-layer layer-3"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="security-content">
                    <h2 class="section-title">Безопасность и надежность</h2>
                    <p class="section-subtitle">Все транзакции проходят через блокчейн TON. Ваши средства под вашим контролем</p>

                    <div class="security-features">
                        <div class="security-item">
                            <div class="security-icon"><i class="fas fa-lock"></i></div>
                            <div>
                                <h5>Блокчейн TON</h5>
                                <p>Все транзакции записываются в блокчейн. Прозрачность и неизменность данных</p>
                            </div>
                        </div>

                        <div class="security-item">
                            <div class="security-icon"><i class="fas fa-shield-alt"></i></div>
                            <div>
                                <h5>Ваш контроль</h5>
                                <p>Средства хранятся в вашем кошельке. Только вы имеете доступ к приватным ключам</p>
                            </div>
                        </div>

                        <div class="security-item">
                            <div class="security-icon"><i class="fas fa-clipboard-check"></i></div>
                            <div>
                                <h5>Проверка транзакций</h5>
                                <p>Автоматическая проверка всех платежей на блокчейне. Гарантия получения средств</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="cta-section">
    <div class="container">
        <div class="cta-card">
            <div class="cta-content">
                <h2>Готовы начать?</h2>
                <p>Создайте кассу и начните принимать платежи в TON уже сегодня</p>

                <div class="cta-actions">
                    <button class="btn btn-cta-primary" onclick="handleCreateAccount()">
                        Создать кассу
                    </button>
                    <a href="/docs" class="btn btn-cta-secondary">
                        Документация API
                    </a>
                </div>
            </div>

            <div class="cta-stats">
                <div class="cta-stat">
                    <div class="stat-number">30с</div>
                    <div class="stat-label">Регистрация</div>
                </div>
                <div class="cta-stat">
                    <div class="stat-number">0₽</div>
                    <div class="stat-label">На старте</div>
                </div>
                <div class="cta-stat">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Поддержка</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once('core/blocks/footer.php') ?>

<?php require_once('core/blocks/auth-modal.php'); ?>

<script src="scripts/libs/bootstrap/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
<script src="scripts/js/app.js"></script>
<script>
    function handleCreateCashier() {
        const userMenuBtn = document.getElementById('userMenuBtn');
        if (userMenuBtn) {
            window.location.href = '/dashboard.php';
        } else {
            openAuth('register');
        }
    }
    
    function handleCreateAccount() {
        const userMenuBtn = document.getElementById('userMenuBtn');
        if (userMenuBtn) {
            window.location.href = '/dashboard.php';
        } else {
            openAuth('register');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.initUserDropdown === 'function') {
            window.initUserDropdown();
        }
    });
</script>
</body>
</html>
