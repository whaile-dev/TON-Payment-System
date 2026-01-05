<?php
$config_path = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
if (file_exists($config_path)) {
    require_once($config_path);
}
$config = getConfig();
$support_url = $config['support']['telegram_url'] ?? 'https://t.me/whaile_dev';
?>
<footer class="footer">
    <div class="container" style="height: unset;">
        <div class="row">
            <div class="col-lg-4">
                <div class="footer-brand">
                    <div class="brand-logo">
                        <img src="scripts/img/logo.svg" alt="TON Pay" class="brand-logo-img me-2" style="height: 32px; width: 32px;">
                        <span class="ton-glow">TON</span>Pay
                    </div>
                    <p>Глобальная платежная система нового поколения</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4">
                <h6>Продукт</h6>
                <ul>
                    <li><a href="/#features">Возможности</a></li>
                    <li><a href="/docs">Документация</a></li>
                    <li><a href="/docs#idocs_getting-started">Интеграция</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-md-4">
                <h6>Ресурсы</h6>
                <ul>
                    <li><a href="/docs">API Документация</a></li>
                    <li><a href="/status">Статус системы</a></li>
                    <li><a href="https://whaile.ru/" target="_blank">Разработчик</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-md-4">
                <h6>Поддержка</h6>
                <ul>
                    <li><a href="<?php echo htmlspecialchars($support_url); ?>" target="_blank">Техподдержка</a></li>
                    <li><a href="/docs#idocs_errors">Обработка ошибок</a></li>
                    <li><a href="/status">Мониторинг</a></li>
                </ul>
            </div>

            <div class="col-lg-2">
                <h6>Контакты</h6>
                <div class="footer-contacts">
                    <div class="social-links">
                        <a href="<?php echo htmlspecialchars($support_url); ?>" 
                        target="_blank"
                        style="display: flex; align-items: center; justify-content: center"
                        class="social-link bi bi-telegram"
                        title="Telegram поддержка"></a>

                        <a href="https://whaile.ru/" 
                        target="_blank"
                        style="display: flex; align-items: center; justify-content: center"
                        class="social-link bi bi-globe"
                        title="Сайт разработчика"></a>

                        <a href="https://github.com/whaile-dev/TON-Payment-System"
                        target="_blank"
                        style="display: flex; align-items: center; justify-content: center"
                        class="social-link bi bi-github"
                        title="GitHub репозиторий"></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="row">
                <div class="col-md-6">
                    <p>&copy; <span id="currentYear"></span> TON Pay. Все права защищены.</p>
                </div>
                <div class="col-md-6 text-end">
                    <div class="footer-links">
                        <a href="https://whaile.ru/">by _whaile_</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</footer>