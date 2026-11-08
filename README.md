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
├── config/db.php          # kết nối mysqli.user cho APP auth (không thuộc package); đường dẫn qua env
├── src/                   # === PACKAGE (mọi dự án require) ===
│   ├── EnvConfig.php          # local vs production, tên/URL/thời hạn phiên, thư mục lưu phiên
│   ├── SessionBootstrap.php   # điểm DUY NHẤT mở phiên (idempotent)
│   ├── SessionContract.php    # key session chuẩn: user_id, name, logged_in_at, shared_auth_csrf; trần tuyệt đối
│   ├── CredentialVerifier.php # verify plaintext+bcrypt, lazy hash-on-login
│   ├── AuthBackendException.php # lỗi DB khi xác thực → login trả 503
│   ├── PathNormalizer.php     # giải mã + rút gọn path, dùng chung cho Guard và RedirectSanitizer
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
├── database/auth_login_attempts.sql   # + migrations/ cho bảng đã tạo từ trước
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

`requireLogin()` tự mở phiên (hoặc gia hạn cookie nếu phiên đã mở) trước khi kiểm tra, nên dự án chạy
`auto_prepend_file` (chỉ `configure()`) vẫn có cookie trượt 30 ngày. `$publicPaths` được so khớp trên
path đã **giải mã một lượt, đổi `\` thành `/`, gộp `//`, rút gọn `.`/`..`**, không phân biệt hoa/thường
(`PathNormalizer`); path mơ hồ (chứa `\`, `%2f`, `%5c`, `%25`) không bao giờ là public.

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
| `SHARED_AUTH_ALLOWED_HOSTS`   | *(rỗng: chỉ cùng host)* | host tuyệt đối được phép trong `?redirect=`, cách nhau `,` |
| `SHARED_AUTH_SESSION_PATH`    | `<php.ini session.save_path>/htsso-sessions` | **BẮT BUỘC** — thư mục riêng, ngoài webroot, Apache ghi được |
| `SHARED_AUTH_ABSOLUTE_LIFETIME` | `2592000` (30 ngày; giây, `0` = tắt) | như local |
| `SHARED_AUTH_DB_CONFIG`       | `C:/xampp/config/db.php` | file cấu hình DB production của app login |

> **Allowlist redirect** `SHARED_AUTH_PROJECTS`: vừa là danh sách hiện ở portal, vừa là allowlist DUY NHẤT
> cho `?redirect=`. Tích hợp thêm dự án mới thì phải thêm prefix của nó vào đây, nếu không mọi redirect
> quay về dự án đó đều bị loại và user rơi về portal. Mặc định:
> `/lichkehoach/public/`, `/nhansuhtdb/`, `/sangkienct/`, `/khsanxuat/`. Ngược lại, mọi path dưới
> `SHARED_AUTH_BASE_URL` (chính authV2) đều bị loại, **trừ `portal.php`** (không phân biệt hoa/thường, không PATH_INFO);
> path mơ hồ (`\`, `%2f`, `%5c`, `%25`) bị từ chối hẳn.

> **Công tắc migration** `SHARED_AUTH_UPGRADE_ON_LOGIN`: **để TẮT trong suốt quá trình tích hợp** nhiều
> dự án. Khi tắt, authV2 verify được cả plaintext lẫn bcrypt nhưng **không ghi hash** → không mật khẩu nào
> đổi → mọi dự án CHƯA tích hợp vẫn so plaintext bình thường. Chỉ đặt `=1` khi **mọi** dự án đã redirect qua
> authV2 và không nơi nào còn tự so password (Giai đoạn 2) — lúc đó bật để password nâng cấp dần sang bcrypt.

- **Local:** cookie không Secure khi request qua HTTP; phiên lưu file tại `<session.save_path của php.ini>/htsso-sessions`
  (đọc bằng `get_cfg_var`, không phụ thuộc biến `TEMP`; tự tạo thư mục nếu chưa có). Đặt `SHARED_AUTH_SESSION_PATH`
  để ghi đè (không tạo/ghi được thư mục thì cũng dừng như production). Thư mục `storage/sessions/` cũ **không còn dùng**
  (nằm trong webroot nên từng truy cập được qua web; file phiên cũ đã xoá).
- **Production:** cookie **luôn** mang cờ `Secure` (`EnvConfig::cookieSecure()`), bất kể request HTTP hay header
  `X-Forwarded-Proto` → production **phải chạy HTTPS**, truy cập qua `http://` sẽ không đăng nhập được;
  phiên lưu file ở thư mục **riêng** `SHARED_AUTH_SESSION_PATH`.
  Thiếu env, hoặc thư mục không tồn tại/không ghi được → **fail-fast**: `RuntimeException`, và
  `prepend.php` trả HTTP 500 trên SAPI web (CLI chạy tiếp). Tuyệt đối không rơi về `save_path` mặc định
  của php.ini, vì thư mục đó dùng chung với mọi vhost khác và GC của app khác sẽ xoá phiên SSO.
  Không dùng MySQL session handler: package không tự mở DB connection và hợp đồng 2 điểm chạm không có
  chỗ truyền handler (xem `auth-hardening/design.md`, quyết định 1). Chỉ cần handler khi lên nhiều máy chủ.

**Thời hạn phiên:** cookie + `gc_maxlifetime` trượt **30 ngày** từ thao tác cuối (gia hạn mỗi request).
Ngoài ra có **trần tuyệt đối** tính từ `logged_in_at` (mặc định 30 ngày, `SHARED_AUTH_ABSOLUTE_LIFETIME`,
`0` = tắt): quá trần thì **xoá sạch `$_SESSION`** (cả key riêng của dự án) và login lại có `?reason=expired`.
Kiểm ở `SessionBootstrap::start()` và mỗi lần gọi `SessionContract::isLoggedIn()`/`userId()`/`name()`. Phiên thiếu
`logged_in_at` luôn bị coi là chưa đăng nhập; khi trần bật, giá trị không phải số nguyên dương cũng bị xoá.
Giới hạn còn lại: dự án chạy `prepend.php` (nhansuhtdb, khsanxuat) mà có route đọc thẳng
`$_SESSION['user_id']` trước khi gọi Guard/`SessionContract` (vd `birthday.today` của nhansuhtdb) vẫn thấy phiên
quá trần cho tới request kế tiếp có gọi tới package — chấp nhận vì trần chỉ là chốt chặn 30 ngày.

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

Script pin version trong `$Version` và **SHA256** của `tailwindcss.exe` trong `$ExeSha256`; nếu chưa có
`tools/tailwindcss.exe` (hoặc hash không khớp) thì tải từ GitHub releases về file tạm, kiểm hash rồi mới
dùng — hash sai thì dừng, không chạy file lạ (thư mục `tools/` bị gitignore). Tải thất bại thì fallback
`npx @tailwindcss/cli@4.1.18`. Đổi `$Version` thì phải cập nhật `$ExeSha256`.

Tailwind v4 dùng `@property`, `color-mix()`, cascade layers → cần **Chrome/Edge ≥ 111**. Máy nào
cũ hơn thì báo IT. Không polyfill ở đợt này.

## Chống brute-force

Chạy `database/auth_login_attempts.sql` trên DB `mysqli` (local và production). Bảng đã tạo từ bản cũ
(`identifier VARCHAR(190)`) thì chạy `database/migrations/2026-10-17_auth_login_attempts_hardening.sql`
(idempotent): đổi cột `identifier` sang `VARCHAR(330) utf8mb4_unicode_ci` và thêm `idx_attempted_at`. Cần
InnoDB `ROW_FORMAT=DYNAMIC` (mặc định MySQL 5.7+/MariaDB 10.2+), nếu không khoá chỉ mục 330 ký tự sẽ vượt
giới hạn 767 byte.

Chính sách **lai** (ngưỡng ở `config/login_config.php` → `throttle`), mỗi lần POST đều đếm cả ba khoá:

| Khoá                        | Ngưỡng sai / cửa sổ | Khoá     | Mục đích |
|-----------------------------|---------------------|----------|----------|
| `p:<username>\|<ip>`        | 5 / 15 phút         | 15 phút  | chặn dò mật khẩu một tài khoản từ một IP; IP lạ **không** khoá được tài khoản của người khác |
| `ip:<ip>`                   | 30 / 15 phút        | 15 phút  | chặn một IP quét nhiều tài khoản |
| `u:<username>` (trần)       | 50 / 60 phút        | 30 phút  | chặn dò phân tán từ nhiều IP; khoá này từ chối **cả mật khẩu đúng**, kèm `error_log` cảnh báo khi chạm trần |

Vì sao lai: chỉ đếm theo cặp thì không có giới hạn theo từng tài khoản, kẻ dò đổi IP là qua; chỉ đếm
theo `u:` thì ai cũng khoá được tài khoản của người khác chỉ bằng vài lần gõ sai. Cặp chặn dò thường,
`u:` chỉ là trần cao để dò phân tán phải tốn trên 50 lần (đánh đổi đã biết: ai đó vẫn khoá được một
tài khoản trong 30 phút nếu chịu gửi 50 lần sai).

- `begin()` ghi lần thử **trước** rồi mới đếm (N request song song thấy nhau, không cùng vượt ngưỡng);
  khoá kéo dài đúng `lockout` phút kể từ lần sai chạm ngưỡng, không phụ thuộc cửa sổ. Lần thử bị từ chối
  vì đang khoá **không** được lưu nên không kéo dài khoá.
- Đang bị khoá → HTTP 429 *"Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau N phút."*, kể cả mật khẩu đúng.
- `succeed()` (login đúng) xoá bộ đếm `p:` và `u:` của username; với khoá IP chỉ bỏ bản ghi của chính lần thử này,
  lịch sử sai trước đó của IP vẫn còn.
- Khoá `identifier` dùng collation `utf8mb4_unicode_ci` giống `user.name`, nên `Admin`/`admin` chung một bộ đếm.
- Username sai UTF-8 hoặc dài quá 255 ký tự, mật khẩu quá 1024 byte: `public/index.php` từ chối ngay bằng thông báo
  chung *"Sai tên đăng nhập hoặc mật khẩu."*, không chạm throttle hay DB.
- Bảng không tồn tại hoặc query lỗi → **fail-open** (vẫn cho đăng nhập) + `error_log`. Tắt bằng
  `throttle.enabled` trong `config/login_config.php`.

**Cảnh báo:** production đứng sau proxy/NAT thì mọi user chung một `REMOTE_ADDR` → 30 lần sai trong 15 phút
của bất kỳ ai khoá toàn bộ login 15 phút cho cả 4 dự án (khoá cặp `p:` thì không lan sang người khác). Khi đó
cấu hình proxy truyền IP thật — package **không** đọc `X-Forwarded-For` vì header đó giả được. Đặt
`by_ip` = false thì bỏ khoá `ip:` và `p:` rút về chỉ theo username (ai cũng khoá được tài khoản người khác
trong 15 phút), nên chỉ tắt khi thật sự không còn cách nào.

## Đăng xuất

`public/logout.php` vẫn là **GET** (các dự án đang trỏ link vào đây) nhưng không còn tin mọi request:

- `Sec-Fetch-Site` khác `same-origin`/`none` (tức `same-site`, `cross-site`, giá trị lạ), hoặc `Sec-Fetch-Dest`
  khác `document` (thẻ `<img>`, `<iframe>`, `fetch`...) → **từ chối**: giữ nguyên phiên, trả 303 về trang login.
- Trình duyệt không gửi `Sec-Fetch-*` mà `Origin`/`Referer` khác **đúng origin** của request (scheme + host +
  port), hoặc là `null` → từ chối như trên.
- Không có header nào → cho phép (curl, trình duyệt cũ).
- Hệ quả: dự án ở cổng/subdomain/site khác (kể cả có trong `SHARED_AUTH_ALLOWED_HOSTS`) không đăng xuất bằng
  link được; 4 dự án cùng host + cổng (mô hình hiện tại) không bị ảnh hưởng.

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

Trang login còn có các hành vi cần biết khi sửa view: ô tên đăng nhập được focus khi trống, ngược lại
focus ô mật khẩu; `aria-invalid` chỉ đặt trên ô gây lỗi; banner `?reason=expired|denied` chỉ hiện ở GET.
Lỗi DB khi xác thực → HTTP 503 *"Hệ thống đang bận, vui lòng thử lại."*; username khớp hơn một dòng ở
`user` bị từ chối (và ghi `error_log`).

## Deploy production (XAMPP)

Không có bước nào dưới đây tự chạy; thiếu cấu hình thì login/`prepend.php` **dừng với lỗi** (HTTP 500),
không chạy tiếp ở trạng thái không an toàn. Kiểm hết trước cửa sổ deploy đồng loạt.

1. **Biến môi trường** (Apache `SetEnv` hoặc env hệ thống, phải thấy được từ mọi vhost dùng `prepend.php`):
   - `SHARED_AUTH_ENV=production`
   - `SHARED_AUTH_SESSION_PATH=` thư mục **ngoài webroot**, Apache ghi được, **không dùng chung với app khác**
     (vd `C:/xampp/htsso-sessions`; tự tạo mode 0700 nếu cha ghi được)
   - `SHARED_AUTH_DB_CONFIG=` đường dẫn file cấu hình DB (file `return ['host','username','password','database']`);
     không còn fallback `root`/mật khẩu rỗng, thiếu hoặc sai khoá là dừng
   - `SHARED_AUTH_BASE_URL`, `SHARED_AUTH_PROJECTS` nếu khác mặc định; `SHARED_AUTH_UPGRADE_ON_LOGIN` để TẮT
2. **DB:** kiểm InnoDB row format rồi chạy migration:
   ```sql
   SELECT TABLE_NAME, ROW_FORMAT FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_login_attempts';
   ```
   Phải là `Dynamic` (hoặc `Compressed`). Chưa có bảng → chạy `database/auth_login_attempts.sql`; đã có bảng cũ →
   chạy `database/migrations/2026-10-17_auth_login_attempts_hardening.sql`.
3. **Apache: chỉ `public/` được phục vụ.** Đặt vào một file conf riêng (đường dẫn thật của docroot), nạp sau
   các `<Directory>` của vhost; `DirectoryMatch` được áp dụng sau mọi khối `<Directory>`, nên không bị
   `Require all granted` của vhost đè:
   ```apache
   <DirectoryMatch "(?i)^C:/xampp/htdocs/authV2(?!/public(/|$))">
       Require all denied
   </DirectoryMatch>
   <DirectoryMatch "(?i)^C:/xampp/htdocs/authV2/public(/|$)">
       Options -Indexes
   </DirectoryMatch>
   ```
   Local (Laragon) đã có bản tương đương ở `C:/laragon/etc/apache2/sites-enabled/zz-authv2-hardening.conf`
   (ngoài repo; tên **không** được bắt đầu bằng `auto.` vì Laragon sinh lại các file đó).
4. **Kiểm bằng curl** (host/đường dẫn theo thực tế; mỗi lệnh phải ra `403`, cuối cùng là `200`):
   ```
   curl -s -o NUL -w "%{http_code}\n" https://<host>/authV2/.git/HEAD
   curl -s -o NUL -w "%{http_code}\n" https://<host>/authV2/storage/
   curl -s -o NUL -w "%{http_code}\n" https://<host>/authV2/tests/run.php
   curl -s -o NUL -w "%{http_code}\n" https://<host>/authV2/public/index.php    # 200
   ```
5. **Điều kiện trước khi bật code mới** (thứ tự quan trọng):
   - Migration ở bước 2 chạy **trước** khi deploy code: code mới không cắt identifier về 190 ký tự, cột cũ
     `VARCHAR(190)` làm key `p:`/`u:` của username dài ghi lỗi và bị bỏ qua (chỉ còn khoá `ip:`).
   - Kiểm tên trùng theo collation, có dòng nào thì xử lý trước — username khớp hơn một dòng bị từ chối đăng nhập:
     `SELECT name COLLATE utf8mb3_unicode_ci k, COUNT(*) n FROM user GROUP BY k HAVING n > 1;`
   - Mọi biến `SHARED_AUTH_*` đặt **giống hệt nhau cho mọi vhost** (authV2 + 4 dự án). Lệch
     `SHARED_AUTH_SESSION_PATH`/`SESSION_NAME` là mỗi bên đọc một thư mục phiên → vòng lặp login ↔ dự án.
   - Đổi thư mục phiên làm **mọi người đăng xuất một lần** lúc deploy.
6. Sau deploy: đăng nhập một lần, kiểm file phiên xuất hiện trong `SHARED_AUTH_SESSION_PATH` (không phải trong
   thư mục tạm của php.ini) và cookie `HTSSOSESSID` có `Secure; HttpOnly; SameSite=Lax` qua HTTPS.

## Chạy test

```
php tests/run.php          # toàn bộ
php tests/run.php unit     # chỉ unit
php tests/run.php http     # chỉ smoke HTTP
```

PHP thuần, không cần cài gì thêm. Exit code khác 0 khi có test hỏng. Chỉ chạy được bằng CLI: SAPI khác
trả 404 (tránh lộ test qua web). Trạng thái gần nhất: **166/166 pass** trên cả PHP 8.2 (XAMPP CLI) lẫn 8.3 (PHP mà Apache Laragon chạy) — chạy trên cả hai vì hành vi `password_hash` với byte NUL khác nhau.

Test cần MySQL sẽ **SKIP** thay vì fail nếu không kết nối được; chỉnh bằng `AUTHV2_TEST_DB_HOST`,
`AUTHV2_TEST_DB_USER`, `AUTHV2_TEST_DB_PASS`, `AUTHV2_TEST_DB_NAME` (mặc định `localhost`/`root`/rỗng/`mysqli`).

Test xác thực chạy trên bảng tạm `_authv2_test_user`, không đụng bảng `user`. Riêng smoke HTTP thêm
một user tạm `__authv2_http_test__` vào `user` rồi xoá ở cuối, vì `public/index.php` cố định bảng `user`.

Nhiều khẳng định trong bộ test được đánh dấu `BASELINE` — chúng chốt hành vi **hiện tại** kể cả khi
hành vi đó sai, để các task của change `auth-hardening` lật chúng một cách có chủ ý. Test `BASELINE`
chuyển từ xanh sang đỏ là dấu hiệu tốt khi đúng task đó đang được làm, và là dấu hiệu xấu ở mọi lúc khác.
Đã lật hết: `sessionSavePath` (2.1), cookie `Secure` ở production (2.2), phiên 10 năm (3.8), `Guard::isPublic`
với `..`/`%2e%2e` (3.2), `IdentitySync` (3.3, 3.4), `CredentialVerifier` cột thừa (3.6) và tiền tố `$2` (3.5).

## Điểm bất biến khi migrate password (đừng đảo thứ tự)

1. Mọi điểm ĐỌC password phải hiểu cả plaintext lẫn bcrypt **trước** khi bất kỳ đâu GHI hash.
   → Cụ thể: giữ `SHARED_AUTH_UPGRADE_ON_LOGIN` TẮT cho tới khi MỌI dự án đã tích hợp (không còn nơi nào
   tự so plaintext). Môi trường nhiều dự án không cắt đồng loạt được — tích hợp dần, hash bật sau cùng.
2. Cột `user.password` phải `VARCHAR(255)` **trước** lần ghi hash đầu tiên (đã xác nhận: hiện là `varchar(255)`).
