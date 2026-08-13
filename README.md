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
├── bootstrap.php          # nạp autoload + SessionBootstrap::start()  (dùng cho app auth)
├── config/db.php          # kết nối mysqli.user cho APP auth (không thuộc package)
├── src/                   # === PACKAGE (mọi dự án require) ===
│   ├── EnvConfig.php          # local vs production, tên/URL/thời hạn phiên
│   ├── SessionBootstrap.php   # điểm DUY NHẤT mở phiên (idempotent)
│   ├── SessionContract.php    # key session chuẩn: user_id, name, logged_in_at
│   ├── CredentialVerifier.php # verify plaintext+bcrypt, lazy hash-on-login
│   ├── RedirectSanitizer.php  # ?redirect= chỉ path nội bộ, chặn open redirect
│   ├── Guard.php              # chưa login → redirect /auth/?redirect=; whitelist public
│   └── IdentitySync.php       # (tùy chọn) re-đọc name/role từ user mỗi request
├── public/                # === APP login ===
│   ├── index.php              # GET form + POST verify (SSO: đã login thì bỏ qua form)
│   ├── logout.php             # huỷ phiên toàn hệ thống
│   └── portal.php             # trang đích liệt kê dự án
└── views/login.php        # form (tách khỏi logic)
```

## Hợp đồng tích hợp — mỗi dự án chỉ còn 2 điểm chạm

Sau khi require package (Composer path repo, hoặc autoload trực tiếp `src/`):

**1. Mở phiên** — thay MỌI `session_start()` cũ:

```php
\Hoatho\SharedAuth\SessionBootstrap::start();
```

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
| `user_id`       | Khoá danh tính (= `user.id`) | `SessionContract::set()`  |
| `name`          | Tên đăng nhập                | `SessionContract::set()`  |
| `logged_in_at`  | Thời điểm login (unix)       | `SessionContract::set()`  |

Đọc danh tính qua `SessionContract::userId()` / `::name()` / `::isLoggedIn()`, không đụng thẳng `$_SESSION`.

> **Shim cho lichkehoach:** code cũ đọc `$_SESSION['id']`. Sau khi tích hợp, thêm một dòng
> `$_SESSION['id'] = SessionContract::userId();` sau bootstrap để chỗ đọc cũ vẫn chạy, gỡ dần sau.

## Môi trường

Điều khiển qua biến môi trường (không đặt = mặc định `local`):

| Biến                          | Mặc định (local)   | Production        |
|-------------------------------|--------------------|-------------------|
| `SHARED_AUTH_ENV`             | `local`            | `production`      |
| `SHARED_AUTH_BASE_URL`        | `/authV2/public`   | `/auth`           |
| `SHARED_AUTH_SESSION_NAME`    | `HTSSOSESSID`      | `HTSSOSESSID`     |
| `SHARED_AUTH_UPGRADE_ON_LOGIN`| *(tắt)*            | `1` **chỉ ở GĐ2** |

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
