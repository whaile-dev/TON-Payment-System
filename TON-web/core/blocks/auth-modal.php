<div class="modal fade" id="authModal" tabindex="-1" style="overflow-y: hidden">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-modal">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="authModalTitle">Вход в систему</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="authForm" novalidate>
                    <?php 
                    require_once($_SERVER['DOCUMENT_ROOT'] . '/core/utils/security.php');
                    $csrf_token = generateCSRFToken();
                    ?>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="auth-tabs">
                        <button type="button" class="auth-tab active" data-tab="login">Вход</button>
                        <button type="button" class="auth-tab" data-tab="register">Регистрация</button>
                    </div>

                    <div class="auth-content">
                        <div class="auth-pane active" id="login-pane">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="text" class="form-control auth-input" placeholder="your@email.com" name="login_input" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Пароль</label>
                                <input type="password" class="form-control auth-input" placeholder="••••••••" name="login_password" required>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="rememberLogin" name="remember_me">
                                <label class="form-check-label" for="rememberLogin">Запомнить меня</label>
                            </div>
                            <button type="submit" class="btn btn-ton-primary w-100 mb-3">Войти в систему</button>
                        </div>

                        <div class="auth-pane" id="register-pane" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control auth-input" placeholder="your@email.com" name="register_email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Пароль</label>
                                <input type="password" class="form-control auth-input" placeholder="Минимум 8 символов" name="register_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Подтвердите пароль</label>
                                <input type="password" class="form-control auth-input" placeholder="••••••••" name="register_password_confirm" required>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="agreeTerms" name="agree_terms" required>
                                <label class="form-check-label" for="agreeTerms">
                                    Я согласен с <a href="#" class="auth-link">условиями использования</a>
                                </label>
                            </div>
                            <button type="submit" class="btn btn-ton-primary w-100">Создать аккаунт</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

