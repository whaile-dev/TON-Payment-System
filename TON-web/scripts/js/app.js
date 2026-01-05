function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-bs-theme', savedTheme);
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const themeIcon = document.querySelector('.theme-icon');
    if (themeIcon) {
        themeIcon.className = theme === 'light' ? 'fas fa-sun theme-icon' : 'fas fa-moon theme-icon';
    }
}

function setCurrentYear() {
    const yearElement = document.getElementById('currentYear');
    if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
    }
}

function openAuth(type = 'login') {
    const userMenuBtn = document.getElementById('userMenuBtn');
    if (userMenuBtn) {
        window.location.href = '/dashboard.php';
        return;
    }
    
    const authModalElement = document.getElementById('authModal');
    if (!authModalElement) return;
    
    const authModal = new bootstrap.Modal(authModalElement);
    const titleElement = document.getElementById('authModalTitle');

    if (type === 'login') {
        titleElement.textContent = 'Вход в систему';
    } else {
        titleElement.textContent = 'Регистрация';
    }

    switchAuthTab(type);
    document.getElementById('authForm').reset();
    disableBodyScroll();
    authModal.show();
}

function switchAuthTab(tabName) {
    document.querySelectorAll('.auth-tab').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.auth-pane').forEach(pane => {
        pane.classList.remove('active');
        pane.style.display = 'none';
    });

    document.querySelector(`.auth-tab[data-tab="${tabName}"]`).classList.add('active');
    const activePane = document.getElementById(`${tabName}-pane`);
    activePane.classList.add('active');
    activePane.style.display = 'block';

    document.querySelectorAll('input[required]').forEach(input => {
        input.removeAttribute('required');
    });

    activePane.querySelectorAll('input').forEach(input => {
        if (input.type !== 'checkbox' || input.id === 'agreeTerms') {
            input.setAttribute('required', 'true');
        }
    });
    
}

function handleAuthForms() {
    const authForm = document.getElementById('authForm');
    if (!authForm) return;

    authForm.addEventListener('submit', async e => {
        e.preventDefault();

        const activePane = document.querySelector('.auth-pane.active');
        const isLogin = activePane.id === 'login-pane';
        const formData = new FormData();

        formData.append('page', isLogin ? 'login' : 'register');

        if (isLogin) {
            formData.append('login', activePane.querySelector('input[name="login_input"]').value);
            formData.append('password', activePane.querySelector('input[name="login_password"]').value);
            const rememberMe = activePane.querySelector('input[name="remember_me"]');
            if (rememberMe && rememberMe.checked) {
                formData.append('remember_me', '1');
            }
        } else {
            const password = activePane.querySelector('input[name="register_password"]').value;
            const passwordConfirm = activePane.querySelector('input[name="register_password_confirm"]').value;
            const agreeTerms = activePane.querySelector('input[name="agree_terms"]');

            if (!agreeTerms || !agreeTerms.checked) {
                showNotification('Необходимо согласиться с условиями использования', 'error');
                if (agreeTerms) agreeTerms.focus();
                return;
            }
            
            if (password !== passwordConfirm) {
                showNotification('Пароли не совпадают', 'error');
                activePane.querySelector('input[name="register_password_confirm"]').focus();
                return;
            }
            
            if (password.length < 8) {
                showNotification('Пароль должен содержать минимум 8 символов', 'error');
                activePane.querySelector('input[name="register_password"]').focus();
                return;
            }
            
            formData.append('email', activePane.querySelector('input[name="register_email"]').value);
            formData.append('password', password);
            formData.append('password_confirm', passwordConfirm);
            formData.append('agree_terms', '1');
        }

        const csrfToken = authForm.querySelector('input[name="csrf_token"]')?.value;
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        try {
            const response = await fetch('/core/events/listener.php', {
                method: 'POST',
                body: formData
            });

            let data;
            try {
                const text = await response.text();

                if (text.trim().startsWith('<') || text.includes('<br />')) {
                    console.error('Сервер вернул HTML вместо JSON:', text.substring(0, 500));
                    showNotification('Ошибка сервера: получен некорректный ответ', 'error');
                    return;
                }
                
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('Ошибка парсинга ответа:', parseError);
                try {
                    const errorText = await response.clone().text();
                    console.error('Ответ сервера:', errorText.substring(0, 500));
                } catch (e) {
                    console.error('Не удалось прочитать ответ сервера');
                }
                showNotification('Ошибка при обработке ответа сервера', 'error');
                return;
            }

            if (data.success) {
                showNotification(data.message, 'success');

                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('authModal'));
                    if (modal) modal.hide();

                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    }
                }, 1500);
            } else {
                const errorMessage = data.message || 'Произошла ошибка при регистрации';
                showNotification(errorMessage, 'error');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            showNotification('Произошла ошибка при отправке формы', 'error');
        }
    });
}

function logout() {
    const formData = new FormData();
    formData.append('page', 'logout');
    
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
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
                showNotification(data.message || 'Ошибка при выходе из системы', 'error');
            }
        })
        .catch(error => {
            console.error('Ошибка выхода:', error);
            showNotification('Ошибка при выходе из системы', 'error');
        });
}

function initAuthTabs() {
    document.querySelectorAll('.auth-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            switchAuthTab(tab.getAttribute('data-tab'));
        });
    });
}

function initParticles() {
    if (typeof particlesJS === 'undefined') return;
    particlesJS('particles-js', {
        particles: {
            number: { value: 80, density: { enable: true, value_area: 800 } },
            color: { value: "#0088cc" },
            shape: { type: "circle" },
            opacity: { value: 0.5, random: true },
            size: { value: 3, random: true },
            line_linked: { enable: true, distance: 150, color: "#0088cc", opacity: 0.2, width: 1 },
            move: { enable: true, speed: 2, random: true, out_mode: "out" }
        },
        interactivity: {
            detect_on: "canvas",
            events: { onhover: { enable: true, mode: "repulse" }, onclick: { enable: true, mode: "push" }, resize: true }
        }
    });
}

function initParallax() {
    const sphere = document.querySelector('.ton-sphere');
    if (!sphere) return;

    window.addEventListener('scroll', () => {
        const scrolled = window.pageYOffset;
        sphere.style.transform = `translate(-50%, -50%) translateY(${scrolled * -0.5}px)`;
    });
}

function initScrollAnimations() {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            } else {
                entry.target.style.opacity = '0';
                entry.target.style.transform = 'translateY(30px)';
            }
        });
    }, { threshold: 0.2 });

    document.querySelectorAll('.feature-card, .security-item, .cta-stat').forEach(el => {
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
}

function handleNavigation() {
    const navbar = document.querySelector('.glass-navbar');
    if (!navbar) return;
    window.addEventListener('scroll', () => {
        navbar.classList.toggle('scrolled', window.scrollY > 100);
    });
}

function handleAuthButtons() {
    const loginBtn = document.getElementById('loginBtn');
    const registerBtn = document.getElementById('registerBtn');
    const combinedBtn = document.getElementById('authCombinedBtn');
    const navbarActions = document.querySelector('.navbar-actions');
    
    if (!loginBtn || !registerBtn || !combinedBtn || !navbarActions) return;
    
    let currentState = null;

    function quickCheck() {
        const screenWidth = window.innerWidth;
        const navbarActionsRect = navbarActions.getBoundingClientRect();
        const containerWidth = navbarActionsRect.width;

        if (screenWidth < 400 || containerWidth < 300) {
            return 'combined';
        }

        if (containerWidth < 238) {
            return 'combined';
        }
        
        return 'separate';
    }

    function preciseCheck() {
        const screenWidth = window.innerWidth;
        if (screenWidth < 400) {
            return 'combined';
        }

        const navbarActionsRect = navbarActions.getBoundingClientRect();
        const containerWidth = navbarActionsRect.width;

        if (loginBtn.offsetParent !== null && registerBtn.offsetParent !== null) {
            const loginWidth = loginBtn.offsetWidth;
            const registerWidth = registerBtn.offsetWidth;
            const gap = 8; 
            const totalWidth = loginWidth + registerWidth + gap;

            const loginRect = loginBtn.getBoundingClientRect();
            const registerRect = registerBtn.getBoundingClientRect();
            const verticalOffset = Math.abs(loginRect.top - registerRect.top);
            const isVertical = verticalOffset > 15;
            
            if (isVertical || totalWidth > containerWidth) {
                return 'combined';
            }
        } else {
            const estimatedTotalWidth = 80 + 130 + 8;
            if (containerWidth < estimatedTotalWidth + 20) {
                return 'combined';
            }
        }
        
        return 'separate';
    }
    
    function applyState(state, smooth = true) {
        if (currentState === state) return;
        
        if (state === 'combined') {
            if (smooth) {
                loginBtn.classList.add('hidden');
                registerBtn.classList.add('hidden');
                combinedBtn.style.display = 'block';

                setTimeout(() => {
                    combinedBtn.classList.remove('hidden');
                }, 100);

                setTimeout(() => {
                    loginBtn.style.display = 'none';
                    registerBtn.style.display = 'none';
                }, 400);
            } else {
                loginBtn.style.display = 'none';
                registerBtn.style.display = 'none';
                combinedBtn.style.display = 'block';
                loginBtn.classList.remove('hidden');
                registerBtn.classList.remove('hidden');
                combinedBtn.classList.remove('hidden');
            }
        } else {
            if (smooth) {
                combinedBtn.classList.add('hidden');

                loginBtn.style.display = '';
                registerBtn.style.display = '';

                setTimeout(() => {
                    loginBtn.classList.remove('hidden');
                    registerBtn.classList.remove('hidden');
                }, 100);

                setTimeout(() => {
                    combinedBtn.style.display = 'none';
                }, 400);
            } else {
                loginBtn.style.display = '';
                registerBtn.style.display = '';
                combinedBtn.style.display = 'none';
                loginBtn.classList.remove('hidden');
                registerBtn.classList.remove('hidden');
                combinedBtn.classList.remove('hidden');
            }
        }
        
        currentState = state;
    }

    if (combinedBtn.style.display === 'block') {
        currentState = 'combined';
    } else if (loginBtn.style.display !== 'none' && registerBtn.style.display !== 'none') {
        currentState = 'separate';
    }

    if (!currentState) {
        const initialState = quickCheck();
        applyState(initialState);
    }

    const checkOnce = () => {
        setTimeout(() => {
            const preciseState = preciseCheck();
            if (preciseState !== currentState) {
                applyState(preciseState);
            }
        }, 100);
    };
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkOnce);
    } else {
        checkOnce();
    }

    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            const newState = preciseCheck();
            applyState(newState, true);
        }, 150);
    });
}

let notificationContainer = null;

function initNotificationContainer() {
    if (!notificationContainer) {
        notificationContainer = document.createElement('div');
        notificationContainer.id = 'notification-container';
        notificationContainer.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 400px;
            align-items: flex-end;
            pointer-events: none;
        `;

        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            notificationContainer.style.cssText += `
                left: 1rem;
                right: 1rem;
                max-width: calc(100% - 2rem);
            `;
        }
        
        document.body.appendChild(notificationContainer);
    }
    return notificationContainer;
}

function showNotification(message, type = 'success') {
    const container = initNotificationContainer();

    if (message && typeof message === 'object' && !Array.isArray(message)) {
        if (message.message && typeof message.message === 'string') {
            message = message.message;
        } else if (message.detail && typeof message.detail === 'string') {
            message = message.detail;
        } else if (message.error && typeof message.error === 'string') {
            message = message.error;
        } else {
            message = type === 'error' ? 'Произошла ошибка' : 'Операция выполнена';
        }
    }

    let displayMessage = '';

    if (message === null || message === undefined) {
        displayMessage = type === 'error' ? 'Произошла ошибка' : 'Операция выполнена';
    }
    else if (typeof message === 'string') {
        displayMessage = message;
        const trimmed = displayMessage.trim();
        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
            (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
            try {
                const parsed = JSON.parse(displayMessage);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    displayMessage = parsed.message || parsed.detail || parsed.error || 'Произошла ошибка';
                    if (typeof displayMessage !== 'string') {
                        displayMessage = 'Произошла ошибка';
                    }
                } else if (Array.isArray(parsed)) {
                    displayMessage = 'Произошла ошибка';
                }
            } catch (e) {
                if (displayMessage.length > 200) {
                    displayMessage = displayMessage.substring(0, 197) + '...';
                }
            }
        }
    }
    else if (message && typeof message === 'object') {
        displayMessage = message.message || message.detail || message.error || 'Произошла ошибка';
        if (typeof displayMessage !== 'string') {
            displayMessage = 'Произошла ошибка';
        }
    }
    else {
        displayMessage = String(message);
        const trimmed = displayMessage.trim();
        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
            (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
            try {
                const parsed = JSON.parse(displayMessage);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    displayMessage = parsed.message || parsed.detail || parsed.error || 'Произошла ошибка';
                    if (typeof displayMessage !== 'string') {
                        displayMessage = 'Произошла ошибка';
                    }
                } else if (Array.isArray(parsed)) {
                    displayMessage = 'Произошла ошибка';
                }
            } catch (ignored) {}
        }
    }

    if (typeof displayMessage === 'string' && displayMessage.length > 200) {
        displayMessage = displayMessage.substring(0, 197) + '...';
    }

    if (typeof displayMessage !== 'string') {
        displayMessage = type === 'error' ? 'Произошла ошибка' : 'Операция выполнена';
    }

    if (displayMessage.includes('"success"') && displayMessage.includes('"message"')) {
        try {
            const parsed = JSON.parse(displayMessage);
            if (parsed && typeof parsed === 'object') {
                displayMessage = parsed.message || 'Операция выполнена';
            }
        } catch (ignored) {}
    }

    const isMobile = window.innerWidth <= 768;
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;

    const notificationStyles = `
        background: var(--ton-card);
        border: 1px solid var(--ton-border);
        color: var(--ton-text);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        padding: 1rem 1.5rem;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        transition: all 0.3s ease;
        display: inline-block;
        max-width: 100%;
        min-width: fit-content;
        pointer-events: auto;
        opacity: 0;
        transform: ${isMobile ? 'translateY(-20px)' : 'translateX(100%)'};
        word-wrap: break-word;
        white-space: normal;
    `;
    
    notification.style.cssText = notificationStyles;
    notification.textContent = displayMessage;

    container.appendChild(notification);

    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translate(0, 0)';
    }, 10);

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = isMobile ? 'translateY(-20px)' : 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
            if (container.children.length === 0) {
                container.remove();
                notificationContainer = null;
            }
        }, 300);
    }, 5000);
}

function showConfirm(message, onConfirm, onCancel = null) {
    const modal = document.createElement('div');
    modal.className = 'confirm-modal-overlay';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(5px);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;
    
    const dialog = document.createElement('div');
    dialog.className = 'confirm-modal-dialog';
    dialog.style.cssText = `
        background: var(--ton-card);
        border: 1px solid var(--ton-border);
        border-radius: 16px;
        padding: 2rem;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        transform: scale(0.9);
        transition: transform 0.3s ease;
    `;
    
    const messageEl = document.createElement('div');
    messageEl.style.cssText = `
        color: var(--ton-text);
        font-size: 1.1rem;
        margin-bottom: 2rem;
        line-height: 1.6;
    `;
    messageEl.textContent = message;
    
    const buttons = document.createElement('div');
    buttons.style.cssText = `
        display: flex;
        gap: 1rem;
        justify-content: space-between;
    `;
    
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Отмена';
    cancelBtn.style.cssText = `
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        border: 1px solid var(--ton-border);
        background: transparent;
        color: var(--ton-text);
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
    `;
    cancelBtn.onmouseover = () => {
        cancelBtn.style.background = 'var(--ton-card-hover)';
    };
    cancelBtn.onmouseout = () => {
        cancelBtn.style.background = 'transparent';
    };
    
    const confirmBtn = document.createElement('button');
    confirmBtn.textContent = 'Подтвердить';
    confirmBtn.style.cssText = `
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        border: none;
        background: var(--ton-primary);
        color: white;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
    `;
    confirmBtn.onmouseover = () => {
        confirmBtn.style.background = 'var(--ton-primary-dark)';
    };
    confirmBtn.onmouseout = () => {
        confirmBtn.style.background = 'var(--ton-primary)';
    };
    
    const closeModal = () => {
        modal.style.opacity = '0';
        dialog.style.transform = 'scale(0.9)';
        setTimeout(() => {
            document.body.removeChild(modal);
            enableBodyScroll();
        }, 300);
    };
    
    cancelBtn.addEventListener('click', () => {
        closeModal();
        if (onCancel) onCancel();
    });
    
    confirmBtn.addEventListener('click', () => {
        closeModal();
        if (onConfirm) onConfirm();
    });
    
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal();
            if (onCancel) onCancel();
        }
    });
    
    buttons.appendChild(cancelBtn);
    buttons.appendChild(confirmBtn);
    dialog.appendChild(messageEl);
    dialog.appendChild(buttons);
    modal.appendChild(dialog);
    document.body.appendChild(modal);
    
    disableBodyScroll();
    
    setTimeout(() => {
        modal.style.opacity = '1';
        dialog.style.transform = 'scale(1)';
    }, 10);
}

function fixMobileLayout() {
    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
        document.body.style.overflowX = 'hidden';
    } else {
        document.body.style.overflowX = '';
    }

    const containers = document.querySelectorAll('.container, .container-fluid');
    containers.forEach(c => {
        if (isMobile) {
            c.style.maxWidth = '100%';
            c.style.overflowX = 'hidden';
        } else {
            c.style.maxWidth = '';
            c.style.overflowX = '';
        }
    });

    const rows = document.querySelectorAll('.row');
    rows.forEach(row => {
        if (isMobile) {
            row.style.marginLeft = '0';
            row.style.marginRight = '0';
            row.style.maxWidth = '100%';
        } else {
            row.style.marginLeft = '';
            row.style.marginRight = '';
            row.style.maxWidth = '';
        }
    });

    const authModal = document.getElementById('authModal');
    if (authModal) {
        if (isMobile) {
            authModal.style.overflowY = 'hidden';
        } else {
            authModal.style.overflowY = '';
        }
    }
}

function initLayout() {
    if (window.innerWidth > 768) {
        initParticles();
        initParallax();
    } else {
        fixMobileLayout();
    }
}

let resizeTimeout;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        fixMobileLayout();
        initLayout();
    }, 100);
});

function disableBodyScroll() {
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = scrollbarWidth + 'px';
}

function enableBodyScroll() {
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
}

function handleModalShow(event) { disableBodyScroll(); }
function handleModalShown(event) { disableBodyScroll(); }
function handleModalHide(event) {}
function handleModalHidden(event) { enableBodyScroll(); }

document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    setCurrentYear();
    initLayout();
    initScrollAnimations();
    handleNavigation();
    handleAuthForms();
    initAuthTabs();
    handleAuthButtons();

    document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);

    document.addEventListener('show.bs.modal', handleModalShow);
    document.addEventListener('shown.bs.modal', handleModalShown);
    document.addEventListener('hide.bs.modal', handleModalHide);
    document.addEventListener('hidden.bs.modal', handleModalHidden);

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', e => {
            const href = anchor.getAttribute('href');
            if (!href || href === '#' || href.length <= 1) {
                return;
            }
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});

window.copyToClipboard = function(text, buttonElement) {
    navigator.clipboard.writeText(text).then(() => {
        if (buttonElement) {
            const originalText = buttonElement.innerHTML;
            buttonElement.innerHTML = '<i class="fas fa-check me-2"></i> Скопировано!';
            buttonElement.style.background = 'var(--ton-success)';
            setTimeout(() => {
                buttonElement.innerHTML = originalText;
                buttonElement.style.background = '';
            }, 2000);
        }
        showNotification('Скопировано в буфер обмена', 'success');
    }).catch(err => {
        console.error('Ошибка копирования:', err);
        showNotification('Ошибка копирования', 'error');
    });
};

window.copyWebhookUrl = function(event) {
    const urlElement = document.getElementById('webhookUrl');
    if (!urlElement) return;
    
    const url = urlElement.textContent.trim();
    
    navigator.clipboard.writeText(url).then(() => {
        let btn = null;
        if (event && event.target) {
            btn = event.target.closest('.copy-btn');
        }
        if (!btn) {
            const webhookContainer = urlElement.closest('.sidebar-card');
            if (webhookContainer) {
                btn = webhookContainer.querySelector('.copy-btn');
            }
        }
        if (btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Скопировано!';
            setTimeout(() => {
                btn.innerHTML = originalText;
            }, 2000);
        }
        showNotification('URL скопирован в буфер обмена', 'success');
    }).catch(err => {
        console.error('Ошибка копирования:', err);
        showNotification('Не удалось скопировать URL', 'error');
    });
};

window.validateDecimalPlaces = function(value, maxPlaces = 2) {
    if (!value && value !== 0) return true;
    const str = value.toString();
    const decimalIndex = str.indexOf('.');
    if (decimalIndex === -1) return true;
    const decimalPart = str.substring(decimalIndex + 1);
    return decimalPart.length <= maxPlaces;
};

window.initUserDropdown = function() {
    const dropdownBtn = document.getElementById('userMenuBtn');
    const dropdownMenu = document.getElementById('userDropdownMenu');
    
    if (!dropdownBtn || !dropdownMenu) return;

    if (dropdownBtn.dataset.initialized === 'true') return;
    dropdownBtn.dataset.initialized = 'true';

    dropdownBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        e.preventDefault();
        const isExpanded = this.getAttribute('aria-expanded') === 'true';
        const newState = !isExpanded;
        this.setAttribute('aria-expanded', newState.toString());
        if (newState) {
            dropdownMenu.classList.add('show');
        } else {
            dropdownMenu.classList.remove('show');
        }
    });

    if (!window.userDropdownClickHandler) {
        window.userDropdownClickHandler = function(e) {
            const btn = document.getElementById('userMenuBtn');
            const menu = document.getElementById('userDropdownMenu');
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                btn.setAttribute('aria-expanded', 'false');
                menu.classList.remove('show');
            }
        };
        document.addEventListener('click', window.userDropdownClickHandler);
    }

    dropdownMenu.querySelectorAll('.user-dropdown-item').forEach(item => {
        item.addEventListener('click', function() {
            dropdownBtn.setAttribute('aria-expanded', 'false');
            dropdownMenu.classList.remove('show');
        });
    });
};

if (!window.logout) {
    window.logout = function() {
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
    };
}

window.initCustomSelects = function() {
    if (document.getElementById('cashierCategorySelect')) {
        window.initCustomSelect('cashierCategorySelect', 'cashierCategoryDropdown', 'cashierCategory');
    }

    if (document.getElementById('cashierCurrencySelect')) {
        window.initCustomSelect('cashierCurrencySelect', 'cashierCurrencyDropdown', 'cashierCurrency', function(value) {
            const jettonGroup = document.getElementById('jettonAddressGroup');
            if (jettonGroup) {
                if (value === 'jetton') {
                    jettonGroup.style.display = 'block';
                } else {
                    jettonGroup.style.display = 'none';
                    const jettonInput = document.getElementById('cashierJettonAddress');
                    if (jettonInput) jettonInput.value = '';
                }
            }
        });
    }
};

window.initCustomSelect = function(selectId, dropdownId, hiddenInputId, onChange = null) {
    const select = document.getElementById(selectId);
    const dropdown = document.getElementById(dropdownId);
    const hiddenInput = document.getElementById(hiddenInputId);

    if (!select || !dropdown || !hiddenInput) return;

    if (!select.dataset.initialized) {
        select.dataset.initialized = 'true';
        
        select.addEventListener('click', function(e) {
            e.stopPropagation();
            const isActive = select.classList.contains('active');
            
            document.querySelectorAll('.custom-select').forEach(s => {
                if (s !== select) {
                    s.classList.remove('active');
                    const nextDropdown = s.nextElementSibling;
                    if (nextDropdown && nextDropdown.classList.contains('custom-select-dropdown')) {
                        nextDropdown.classList.remove('show');
                    }
                }
            });
            
            select.classList.toggle('active');
            dropdown.classList.toggle('show', !isActive);
        });

        dropdown.addEventListener('click', function(e) {
            const option = e.target.closest('.custom-select-option');
            if (!option) return;
            
            e.stopPropagation();
            const value = option.getAttribute('data-value');
            const text = option.textContent.trim();
            
            const selectText = select.querySelector('.custom-select-text');
            if (selectText) {
                selectText.textContent = text;
            }
            hiddenInput.value = value;
   
            dropdown.querySelectorAll('.custom-select-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            option.classList.add('selected');

            select.classList.remove('active');
            dropdown.classList.remove('show');

            if (onChange && typeof onChange === 'function') {
                onChange(value);
            }
        });

        document.addEventListener('click', function(e) {
            if (!select.contains(e.target) && !dropdown.contains(e.target)) {
                select.classList.remove('active');
                dropdown.classList.remove('show');
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('userMenuBtn')) {
        window.initUserDropdown();
    }
    
    if (document.getElementById('cashierCategorySelect') || document.getElementById('cashierCurrencySelect')) {
        window.initCustomSelects();
    }
});
