document.addEventListener('DOMContentLoaded', function () {
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const rememberCheckbox = document.getElementById('remember');
    const loginForm = document.getElementById('loginForm');
    const loginBtn = document.getElementById('loginBtn');
    const togglePassword = document.getElementById('togglePassword');

    const savedUsername = localStorage.getItem('saved_username');
    if (savedUsername && usernameInput) {
        usernameInput.value = savedUsername;
        if (rememberCheckbox) {
            rememberCheckbox.checked = true;
        }
        if (passwordInput) {
            passwordInput.focus();
        }
    }

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            const icon = this.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    }

    if (rememberCheckbox) {
        rememberCheckbox.addEventListener('change', function () {
            if (!this.checked) {
                localStorage.removeItem('saved_username');
            }
        });
    }

    if (loginForm && loginBtn) {
        loginForm.addEventListener('submit', function () {
            if (rememberCheckbox && rememberCheckbox.checked) {
                localStorage.setItem('saved_username', usernameInput.value);
            } else {
                localStorage.removeItem('saved_username');
            }

            loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Đang xử lý...';
            loginBtn.disabled = true;
        });
    }

    const alertElement = document.querySelector('.alert');
    if (alertElement) {
        const seconds = parseInt(alertElement.dataset.dismissSeconds, 10);
        if (seconds > 0) {
            setTimeout(function () {
                alertElement.style.transition = 'opacity 0.5s ease';
                alertElement.style.opacity = '0';
                setTimeout(function () {
                    alertElement.remove();
                }, 500);
            }, seconds * 1000);
        }
    }
});
