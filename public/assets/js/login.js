'use strict';

(function () {
    var form = document.getElementById('loginForm');
    var user = document.getElementById('username');
    var pass = document.getElementById('password');
    var remember = document.getElementById('remember');
    var hint = document.getElementById('rememberHint');
    var btn = document.getElementById('loginBtn');
    var KEY = 'saved_username';
    var spinner = null;

    function storeGet() {
        try {
            var raw = localStorage.getItem(KEY);
            if (!raw) return null;
            var data = JSON.parse(raw);
            var days = form ? parseInt(form.getAttribute('data-remember-days') || '30', 10) : 30;
            if (!data || typeof data.u !== 'string' || typeof data.t !== 'number' || Date.now() - data.t > days * 86400000) {
                localStorage.removeItem(KEY);
                return null;
            }
            return data.u;
        } catch (e) {
            try { localStorage.removeItem(KEY); } catch (e2) {}
            return null;
        }
    }
    function storeSet(name) {
        try { localStorage.setItem(KEY, JSON.stringify({ u: name, t: Date.now() })); } catch (e) {}
    }
    function storeClear() {
        try { localStorage.removeItem(KEY); } catch (e) {}
    }

    var saved = storeGet();
    if (saved && remember) remember.checked = true;
    if (saved && user && user.value === '') {
        user.value = saved;
        var active = document.activeElement;
        if (pass && (active === document.body || active === user)) pass.focus();
    }

    function syncHint() {
        if (!hint || !remember) return;
        hint.classList.toggle('hidden', !remember.checked);
    }
    syncHint();
    if (remember) {
        remember.addEventListener('change', function () {
            if (!this.checked) storeClear();
            syncHint();
        });
    }

    var tpl = document.getElementById('togglePasswordTemplate');
    if (tpl && pass && pass.parentElement) {
        pass.parentElement.appendChild(tpl.content.cloneNode(true));
        var toggle = pass.parentElement.querySelector('button[data-label-show]');
        if (toggle) {
            var icons = toggle.querySelectorAll('svg');
            toggle.addEventListener('click', function () {
                var show = pass.getAttribute('type') === 'password';
                pass.setAttribute('type', show ? 'text' : 'password');
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                toggle.setAttribute('aria-label', show ? toggle.getAttribute('data-label-hide') : toggle.getAttribute('data-label-show'));
                if (icons[0]) icons[0].classList.toggle('hidden', show);
                if (icons[1]) icons[1].classList.toggle('hidden', !show);
            });
        }
    }

    function setBusy(busy) {
        if (!btn || !form) return;
        btn.disabled = busy;
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        var label = btn.querySelector('[data-label]');
        if (label) label.textContent = busy ? 'Đang xử lý…' : 'Đăng nhập';
        if (busy) {
            if (!spinner) {
                spinner = document.createElement('span');
                spinner.className = 'inline-block w-4 h-4 mr-2 border-2 border-white border-t-transparent rounded-full animate-spin motion-reduce:hidden';
                spinner.setAttribute('aria-hidden', 'true');
            }
            btn.insertBefore(spinner, label || btn.firstChild);
            form.dataset.submitting = '1';
        } else {
            if (spinner && spinner.parentNode) spinner.parentNode.removeChild(spinner);
            delete form.dataset.submitting;
        }
    }

    setBusy(false);
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) setBusy(false);
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.submitting === '1') {
                e.preventDefault();
                return;
            }
            if (remember && remember.checked && user) storeSet(user.value);
            else storeClear();
            setBusy(true);
        });
    }
})();
