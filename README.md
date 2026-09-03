# hoatho/shared-auth — Xác thực & phiên đăng nhập dùng chung

Một trang login duy nhất + package dùng chung để mọi dự án PHP cùng host (Laragon
`C:\laragon\www\*`) chia sẻ một phiên đăng nhập (SSO nội bộ). Password nâng cấp dần
từ plaintext sang bcrypt khi user login (lazy migration), không khoá tài khoản ai.

Ranh giới: package chỉ lo **authentication** (ai đang đăng nhập). **Authorization**
(role/quyền) ở lại từng dự án.

## Bố cục

```
authV2/
├── composer.json          # PSR-4 Hoatho\SharedAuth\ → src/
├── autoload.php           # chỉ nạp autoload, không mở phiên
├── bootstrap.php          # autoload + SessionBootstrap::start()
├── prepend.php            # autoload + SessionBootstrap::configure() — dùng cho auto_prepend_file
├── config/db.php          # kết nối mysqli.user cho APP auth (không thuộc package)
├── src/                   # === PACKAGE (mọi dự án require) ===
│   ├── EnvConfig.php          # local vs production, tên/URL/thời hạn phiên
│   ├── SessionBootstrap.php   # điểm DUY NHẤT mở phiên (idempotent)
│   ├── SessionContract.php    # key session chuẩn: user_id, name, logged_in_at, shared_auth_csrf
│   ├── CredentialVerifier.php # verify plaintext+bcrypt, lazy hash-on-login
│   ├── RedirectSanitizer.php  # ?redirect= chỉ path nội bộ, chặn open redirect
│   ├── Guard.php              # chưa login → redirect /auth/?redirect=; whitelist public
│   ├── LoginThrottle.php      # chống brute-force (fail-open)
│   └── IdentitySync.php       # (tùy chọn) re-đọc name/role từ user mỗi request
├── public/                # === APP login ===
│   ├── index.php              # GET form + POST verify (SSO: đã login thì bỏ qua form)
│   ├── logout.php             # huỷ phiên toàn hệ thống
│   ├── portal.php             # trang đích liệt kê dự án
│   └── assets/css/app.css     # CSS đã build (commit vào repo)
├── views/
│   ├── login.php              # form (chỉ đọc $viewData)
│   └── partials/icon.php      # SVG icon inline
├── resources/css/app.css  # nguồn Tailwind v4
├── database/auth_login_attempts.sql
├── build-css.ps1          # tải/pin Tailwind standalone, build CSS
└── tools/                 # gitignore — tailwindcss.exe
```

## Hợp đồng tích hợp — mỗi dự án chỉ còn 2 điểm chạm

Sau khi require package (Composer path repo, hoặc autoload trực tiếp `src/`):

**1. Mở phiên** — thay MỌI `session_start()` cũ:

```php
\Hoatho\SharedAuth\SessionBootstrap::start();
```

Dự án còn quá nhiều `session_start()` rải rác để sửa tay: trỏ `auto_prepend_file` tới
`authV2/prepend.php` (gọi `SessionBootstrap::configure()` — cấu hình phiên nhưng KHÔNG mở, để các
`session_start()` cũ tự mở và tham gia phiên chung). Đã dùng ở `nhansuhtdb`, `khsanxuat`.

**2. Chặn truy cập** — thay MỌI check `$_SESSION` rải rác, đặt ở entry point:

```php
(new \Hoatho\SharedAuth\Guard($publicPaths))->requireLogin();
// $publicPaths: path webhook/API/cron cần bỏ qua login (mảng, có thể rỗng)
```

Dự án **gỡ bỏ**: form login riêng, code so password, code logout, remember-me cookie tự chế.
Dự án **giữ lại**: logic phân quyền (role → menu/permission), app-state session key riêng
(nên prefix theo dự án để tránh đụng độ).

## Danh sách session key CHUẨN (SessionContract)

| Key             | Ý nghĩa                      | Nguồn                     |
|-----------------|------------------------------|---------------------------|
| `user_id`           | Khoá danh tính (= `user.id`) | `SessionContract::set()`  |
| `name`              | Tên đăng nhập                | `SessionContract::set()`  |
| `logged_in_at`      | Thời điểm login (unix)       | `SessionContract::set()`  |
| `shared_auth_csrf`  | Token CSRF form login        | `public/index.php`        |

Đọc danh tính qua `SessionContract::userId()` / `::name()` / `::isLoggedIn()`, không đụng thẳng `$_SESSION`.

> **Shim khoá danh tính cũ:** dự án nào còn chỗ ĐỌC khoá cũ (vd `$_SESSION['id']`) thì thêm một dòng
> `$_SESSION['id'] = SessionContract::userId();` sau bootstrap để chỗ đọc cũ vẫn chạy, gỡ dần sau.
> Đã kiểm tra: lichkehoach và khsanxuat không nơi nào đọc `$_SESSION['id']` → không đặt shim.

## Môi trường

Điều khiển qua biến môi trường (không đặt = mặc định `local`):

| Biến                          | Mặc định (local)   | Production        |
|-------------------------------|--------------------|-------------------|
| `SHARED_AUTH_ENV`             | `local`            | `production`      |
| `SHARED_AUTH_BASE_URL`        | `/authV2/public`   | `/auth`           |
| `SHARED_AUTH_SESSION_NAME`    | `HTSSOSESSID`      | `HTSSOSESSID`     |
| `SHARED_AUTH_UPGRADE_ON_LOGIN`| *(tắt)*            | `1` **chỉ ở GĐ2** |
| `SHARED_AUTH_PROJECTS`        | *(4 dự án đã tích hợp)* | khai báo `prefix\|nhãn`, cách nhau `,` |

> **Allowlist redirect** `SHARED_AUTH_PROJECTS`: vừa là danh sách hiện ở portal, vừa là allowlist DUY NHẤT
> cho `?redirect=`. Tích hợp thêm dự án mới thì phải thêm prefix của nó vào đây, nếu không mọi redirect
> quay về dự án đó đều bị loại và user rơi về portal. Mặc định:
> `/lichkehoach/public/`, `/nhansuhtdb/`, `/sangkienct/`, `/khsanxuat/`.

> **Công tắc migration** `SHARED_AUTH_UPGRADE_ON_LOGIN`: **để TẮT trong suốt quá trình tích hợp** nhiều
> dự án. Khi tắt, authV2 verify được cả plaintext lẫn bcrypt nhưng **không ghi hash** → không mật khẩu nào
> đổi → mọi dự án CHƯA tích hợp vẫn so plaintext bình thường. Chỉ đặt `=1` khi **mọi** dự án đã redirect qua
> authV2 và không nơi nào còn tự so password (Giai đoạn 2) — lúc đó bật để password nâng cấp dần sang bcrypt.

- **Local:** cookie không Secure, phiên lưu file tại `storage/sessions/` (cách ly khỏi save_path chung).
- **Production:** cookie Secure (HTTPS), phiên lưu qua MySQL session handler — truyền
  `SessionHandlerInterface` vào `SessionBootstrap::start($handler)` (wiring làm ở Giai đoạn triển khai prod).

## Chạy cục bộ (Laragon)

```
composer dump-autoload      # sinh vendor/autoload.php (không bắt buộc — có fallback autoloader)
```

Mở `http://localhost/authV2/public/` → đăng nhập bằng tài khoản trong `mysqli.user`.
Đã đăng nhập rồi mở lại sẽ được đưa thẳng tới portal (hiệu ứng SSO).

`Guard::requireLogin()` thêm `?reason=expired` khi đủ cả ba: request còn cookie phiên, session đã
`PHP_SESSION_ACTIVE`, và `$_SESSION` rỗng (file phiên bị GC). Dự án nào ghi key riêng vào session
**trước** `requireLogin()` sẽ không bao giờ ra `expired` — chấp nhận. `denied` không do Guard phát;
dự án tự redirect thủ công nếu cần. Giá trị `reason` lạ trên form login bị bỏ qua.

## Build CSS

CSS trang login build từ `resources/css/app.css` bằng `build-css.ps1` (Tailwind CSS **v4.1.18**
standalone). Output `public/assets/css/app.css` **commit vào repo** — server production không chạy
build. Sửa `views/` hoặc `public/assets/js/login.js` xong phải chạy lại:

```
powershell -ExecutionPolicy Bypass -File .\build-css.ps1
powershell -ExecutionPolicy Bypass -File .\build-css.ps1 -Watch
```

Script pin version trong `$Version`; nếu chưa có `tools/tailwindcss.exe` thì tải từ GitHub releases
(thư mục `tools/` bị gitignore). Tải thất bại thì fallback `npx @tailwindcss/cli@4.1.18`.

Tailwind v4 dùng `@property`, `color-mix()`, cascade layers → cần **Chrome/Edge ≥ 111**. Máy nào
cũ hơn thì báo IT. Không polyfill ở đợt này.

## Chống brute-force

Chạy **một lần** file `database/auth_login_attempts.sql` trên DB `mysqli` (local và production).

Chính sách: 5 lần sai / cửa sổ 15 phút → khoá 15 phút, theo cả `u:<username>` và `ip:<REMOTE_ADDR>`
(`by_ip` mặc định bật). Login đúng thì xoá bộ đếm của username, **không** xoá khoá IP. Bảng không
tồn tại hoặc query lỗi → **fail-open** (vẫn cho đăng nhập) + `error_log`. Tắt bằng
`throttle.enabled` trong `config/login_config.php`.

**Cảnh báo:** production đứng sau proxy/NAT thì mọi user chung một `REMOTE_ADDR` → 5 lần sai của
bất kỳ ai khoá toàn bộ login 15 phút cho cả 4 dự án. Khi đó tắt `by_ip` hoặc cấu hình proxy truyền
IP thật — package **không** đọc `X-Forwarded-For` vì header đó giả được.

## Security header

`public/index.php` gửi header (không còn config `csp`, không còn `<meta http-equiv>`):

```
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: same-origin
Cache-Control: no-store, no-cache, must-revalidate
```

`form-action 'self'` chặn cả 302 sau submit sang host khác → nếu sau này dùng
`SHARED_AUTH_ALLOWED_HOSTS` phải nới directive này.

## Chạy test

```
php tests/run.php          # toàn bộ
php tests/run.php unit     # chỉ unit
php tests/run.php http     # chỉ smoke HTTP
```

PHP thuần, không cần cài gì thêm. Exit code khác 0 khi có test hỏng.

Test cần MySQL sẽ **SKIP** thay vì fail nếu không kết nối được; chỉnh bằng `AUTHV2_TEST_DB_HOST`,
`AUTHV2_TEST_DB_USER`, `AUTHV2_TEST_DB_PASS`, `AUTHV2_TEST_DB_NAME` (mặc định `localhost`/`root`/rỗng/`mysqli`).

Test xác thực chạy trên bảng tạm `_authv2_test_user`, không đụng bảng `user`. Riêng smoke HTTP thêm
một user tạm `__authv2_http_test__` vào `user` rồi xoá ở cuối, vì `public/index.php` cố định bảng `user`.

Nhiều khẳng định trong bộ test được đánh dấu `BASELINE` — chúng chốt hành vi **hiện tại** kể cả khi
hành vi đó sai, để các task của change `auth-hardening` lật chúng một cách có chủ ý. Test `BASELINE`
chuyển từ xanh sang đỏ là dấu hiệu tốt khi đúng task đó đang được làm, và là dấu hiệu xấu ở mọi lúc khác.

## Điểm bất biến khi migrate password (đừng đảo thứ tự)

1. Mọi điểm ĐỌC password phải hiểu cả plaintext lẫn bcrypt **trước** khi bất kỳ đâu GHI hash.
   → Cụ thể: giữ `SHARED_AUTH_UPGRADE_ON_LOGIN` TẮT cho tới khi MỌI dự án đã tích hợp (không còn nơi nào
   tự so plaintext). Môi trường nhiều dự án không cắt đồng loạt được — tích hợp dần, hash bật sau cùng.
2. Cột `user.password` phải `VARCHAR(255)` **trước** lần ghi hash đầu tiên (đã xác nhận: hiện là `varchar(255)`).
