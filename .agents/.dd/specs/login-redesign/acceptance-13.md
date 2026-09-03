# Cổng nghiệm thu task 13 — login-redesign

## 13.1 php tests/run.php
`75 pass, 0 fail, 0 skip (75 test)` (baseline 57 + 18 test mới).

## 13.2 php -l
No syntax errors: `src/LoginThrottle.php`, `src/SessionContract.php`, `src/Guard.php`, `src/CredentialVerifier.php`, `public/index.php`, `views/login.php`, `views/partials/icon.php`, `config/login_config.php`, `tests/unit/login_throttle_test.php`, `tests/unit/guard_test.php`, `tests/unit/session_contract_test.php`, `tests/http/login_flow_test.php`, `tests/run.php`.

## 13.3 Grep
- Task 11.2 (trừ Product.md, .agents/, .claude/): rỗng (`bootstrap@`, `cdn.jsdelivr`, `cdnjs`, `font-awesome`, `fa-`, `login.css`, `alert_auto_dismiss`, `$_SESSION['_csrf']`). `portalUrl` chỉ còn method package (`EnvConfig::portalUrl`), không còn biến chết trong `public/index.php`. `'csp'` không còn trong config.
- Task 9.13 class mồ côi: rỗng (`.hoatho-logo`, `.login-page`, `.login-wrapper`, `.login-box`, `form-control`, `btn btn-`, `fa-`, `me-2`, `mb-3`).

## 13.4 Kích thước
- `public/assets/css/app.css`: 15694 bytes (minified, < 25KB)
- `public/assets/js/login.js`: 4184 bytes (< 5KB, không minify)
- Tổng: 19878 bytes (< 30KB)

## 13.5 Contrast (WCAG, script `relative luminance`)
| Cặp | Tỉ lệ | AA |
|---|---|---|
| trắng / brand-600 (nút) | 5.22:1 | AA |
| trắng / brand-700 (hover nút) | 6.90:1 | AA |
| trắng / brand-800 (active nút) | 8.63:1 | AA |
| brand-700 / trắng (link) | 6.90:1 | AA |
| ink-900 / trắng (h1) | 14.76:1 | AA |
| ink-700 / trắng (body, label) | 9.49:1 | AA |
| ink-700 / brand-50 (alert-info) | 8.49:1 | AA |
| ink-500 / trắng (hint) | 5.43:1 | AA |
| ink-500 / slate-50 (footer) | 5.19:1 | AA |
| danger-ink / danger-bg | 7.60:1 | AA |
| warn-ink / warn-bg | 6.84:1 | AA |
| ink-500 / #f8f9fa | 5.15:1 | AA |

`brand-500` không dùng cho chữ trên trắng (chỉ gradient/ring).

## 13.6 DB sau test
- `SELECT COUNT(*) FROM user WHERE name LIKE '__authv2%'` = 0
- bảng `_authv2_test_*`: 0
- `auth_login_attempts`: 0 dòng (test trỏ `_authv2_test_attempts`; đã `CREATE TABLE IF NOT EXISTS` bảng thật, trống)

## 13.7 Thủ công (Laragon `http://localhost/authV2/public/index.php` + agent-browser)
- (a) Network: chỉ `app.css`, `logoht.png`, `login.js`, `favicon.ico` trên localhost — 0 domain ngoài.
- (b) Sai mật khẩu: username còn, `activeElement=password`, alert "Sai tên đăng nhập hoặc mật khẩu." còn sau 6s.
- (c) Sau POST thất bại: nút `aria-busy=false`, không disabled, chữ "Đăng nhập". **Chưa kiểm** bfcache Back sau 302 (agent-browser không giữ history 302 ổn định); handler `pageshow` + `delete dataset.submitting` đã có trong JS.
- (d) Focus username: `outline: none`, ring 3px (oklab brand), không chồng outline.
- (e) Toggle mắt: `type` password→text, `aria-pressed` false→true, `aria-label` "Hiện mật khẩu"→"Ẩn mật khẩu".
- (f) Tắt JS: **chưa mở trình duyệt tắt JS**. HTTP test POST không chạy JS vẫn login được. Nút mắt nằm trong `<template>` nên không hiện khi JS tắt.
- (g) iframe cùng origin: `contentDocument` null. HTTP header `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'`. **Chưa** mở file HTML ngoài nhúng iframe (DevTools MCP bị chiếm profile).
- (h) Rule `prefers-reduced-motion` có trong stylesheet. **Chưa emulate** media query nên không xác nhận animation dừng trên UI.
- (i) 375×800: card width 375, `border-radius: 0`, `box-shadow: none`, shell padding 0, footer `bottom=800` = đáy viewport.
- (j) POST user không tồn tại vs sai plaintext (10 lần, trung vị): 40.43 ms vs 39.80 ms, chênh **0.63 ms** (< 20ms).

## 13.8 git status
Đúng hướng Product.md mục 4 + extras. `tools/` không hiện (gitignore). File rác `requireLogin()`.Kết` còn. WIP sẵn (EnvConfig 30 ngày + 4 dự án, env_config_test, shared-auth/tasks.md, README/CLAUDE một phần) còn.

### File change này sửa/thêm/xoá
Sửa: `.gitignore`, `CLAUDE.md`, `README.md`, `config/login_config.php`, `public/assets/js/login.js`, `public/index.php`, `src/CredentialVerifier.php`, `src/Guard.php`, `src/SessionContract.php`, `tests/http/login_flow_test.php`, `tests/run.php` (`ob_start` để `session_start` CLI sau dấu `.`), `tests/unit/guard_test.php`, `tests/unit/session_contract_test.php`, `views/login.php`, `.agents/.dd/specs/auth-hardening/tasks.md`.
Thêm: `build-css.ps1`, `database/auth_login_attempts.sql`, `public/assets/css/app.css`, `resources/css/app.css`, `src/LoginThrottle.php`, `tests/unit/login_throttle_test.php`, `views/partials/icon.php`.
Xoá: `public/assets/css/login.css`.
Không đụng: `SessionBootstrap.php`, `RedirectSanitizer.php`, `IdentitySync.php`, `EnvConfig.php` (WIP sẵn), `prepend.php`, `bootstrap.php`, `autoload.php`, `portal.php`, `logout.php`, `config/db.php`, ngoài repo authV2. Không commit/push.

Tailwind pin **v4.1.18**.
