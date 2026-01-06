<?php
$home_link = $home_link ?? '/';
$show_integration = $show_integration ?? true;
$nav_links = $nav_links ?? [];
$container_class = $container_class ?? 'container';
$container_style = ($container_class === 'container') ? 'style="width: calc(100% - var(--bs-gutter-x,.75rem))"' : '';
$show_auth_buttons = $show_auth_buttons ?? false;
$is_auth = getCore()->isAuth();
$config_path = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
if (file_exists($config_path)) {
    require_once($config_path);
}
$config = getConfig();
$site_name = $config['site']['name'] ?? 'TonPay';
?>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top glass-navbar">
    <div class="<?php echo htmlspecialchars($container_class); ?>" <?php echo $container_style; ?>>
        <a class="navbar-brand" href="<?php echo htmlspecialchars($home_link); ?>">
            <div class="brand-logo">
                <img src="scripts/img/logo.svg" alt="<?php echo htmlspecialchars($site_name); ?>" class="brand-logo-img me-2" style="height: 32px; width: 32px;">
                <span class="ton-glow"><?php echo htmlspecialchars($site_name); ?></span>
            </div>
        </a>

        <div class="navbar-nav ms-auto d-none d-lg-flex">
            <?php foreach ($nav_links as $link): ?>
                <a class="nav-link" href="<?php echo htmlspecialchars($link['href']); ?>">
                    <?php echo htmlspecialchars($link['text']); ?>
                </a>
            <?php endforeach; ?>
            
            <?php if (empty($nav_links)): ?>
                <a class="nav-link" href="<?php echo ($home_link === '/' ? '#' : $home_link . '#'); ?>features">Возможности</a>
                <a class="nav-link" href="<?php echo ($home_link === '/' ? '#' : $home_link . '#'); ?>security">Безопасность</a>
                <?php if ($show_integration): ?>
                    <a class="nav-link" href="/docs">Интеграция</a>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="theme-switcher nav-link">
                <button class="theme-btn" id="themeToggle">
                    <i class="fas fa-moon theme-icon"></i>
                </button>
            </div>
        </div>

        <?php if ($is_auth || $show_auth_buttons): ?>
            <div class="navbar-actions">
                <?php if ($is_auth): ?>
                    <?php $user_email = $_SESSION['email']; ?>
                    <div class="user-dropdown">
                        <button class="user-dropdown-btn" type="button" id="userMenuBtn" aria-expanded="false">
                            <i class="fas fa-user me-2"></i>
                            <span><?php echo htmlspecialchars($user_email); ?></span>
                        </button>
                        <div class="user-dropdown-menu" id="userDropdownMenu">
                            <a class="user-dropdown-item" href="/dashboard.php">
                                <i class="fas fa-chart-line me-2"></i> Кабинет
                            </a>
                            <a class="user-dropdown-item" href="/">
                                <i class="fas fa-home me-2"></i> Главная
                            </a>
                            <hr class="user-dropdown-divider">
                            <button class="user-dropdown-item text-danger" type="button" onclick="logout()">
                                <i class="fas fa-sign-out-alt me-2"></i> Выйти
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($show_auth_buttons): ?>
                        <button class="btn btn-ton-outline me-2 auth-btn-separate" id="loginBtn" onclick="openAuth('login')" data-initial-state="visible">
                            Войти
                        </button>
                        <button class="btn btn-ton-primary auth-btn-separate" id="registerBtn" onclick="openAuth('register')" data-initial-state="visible">
                            Регистрация
                        </button>
                        <button class="btn btn-ton-primary auth-btn-combined" id="authCombinedBtn" onclick="openAuth('login')" style="display: none;" data-initial-state="hidden">
                            Войти / Регистрация
                        </button>
                        <script>
    
                        (function() {
                            const loginBtn = document.getElementById('loginBtn');
                            const registerBtn = document.getElementById('registerBtn');
                            const combinedBtn = document.getElementById('authCombinedBtn');
                            const navbarActions = document.querySelector('.navbar-actions');
                            
                            if (!loginBtn || !registerBtn || !combinedBtn || !navbarActions) return;
             
                            function quickInit() {
                                const screenWidth = window.innerWidth;
                   
                                if (screenWidth < 400) {
                                    loginBtn.style.display = 'none';
                                    registerBtn.style.display = 'none';
                                    combinedBtn.style.display = 'block';
                                    return;
                                }

                                const navbarActionsRect = navbarActions.getBoundingClientRect();
                                const containerWidth = navbarActionsRect.width || window.innerWidth;

                                const estimatedLoginWidth = screenWidth < 768 ? 90 : 80;
                                const estimatedRegisterWidth = screenWidth < 768 ? 150 : 130;
                                const gap = 8;
                                const estimatedTotalWidth = estimatedLoginWidth + estimatedRegisterWidth + gap;

                                if (containerWidth < estimatedTotalWidth + 30 || screenWidth < 500) {
                                    loginBtn.style.display = 'none';
                                    registerBtn.style.display = 'none';
                                    combinedBtn.style.display = 'block';
                                    loginBtn.classList.remove('hidden');
                                    registerBtn.classList.remove('hidden');
                                    combinedBtn.classList.remove('hidden');
                                } else {
                                    loginBtn.style.display = '';
                                    registerBtn.style.display = '';
                                    combinedBtn.style.display = 'none';
                                    loginBtn.classList.remove('hidden');
                                    registerBtn.classList.remove('hidden');
                                    combinedBtn.classList.remove('hidden');
                                }
                            }

                            quickInit();

                            if (window.requestAnimationFrame) {
                                requestAnimationFrame(quickInit);
                            } else {
                                setTimeout(quickInit, 0);
                            }
                        })();
                        </script>
                    <?php else: ?>
                        <a href="/" class="btn btn-ton-outline me-2">Войти</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</nav>
