# Tasks — login-redesign (authV2)

> Kế hoạch thực thi chi tiết cho `Product.md` (làm lại trang login: bỏ Bootstrap/FA, Tailwind v4
> self-host, 26 lỗi). Đây là nguồn sự thật cho worker implement. Mỗi task có: file đụng, hợp đồng
> code cụ thể, test phải thêm, tiêu chí xong. **Đọc mục 0 trước khi gõ dòng code nào.**
> Bản này đã qua một vòng review độc lập (4 chặn + 13 nên sửa + 12 nhỏ đều đã gộp vào).

## 0. Ràng buộc cho người implement

- **Chuẩn ưu tiên:** `Product.md` thắng, **trừ** các chỗ tài liệu này đánh dấu `[SỬA SPEC]` (khi đó
  làm theo tài liệu này). `specs/auth-hardening/*` xếp sau cả hai; khi mâu thuẫn (tên bảng throttle,
  ngưỡng, self-host Bootstrap) → theo Product.md.
- **Sự thật về máy này (đã đo):** PHP 8.2.12 CLI; `php tests/run.php` baseline = **57 pass / 0 fail / 0 skip**;
  `php.ini` đặt `date.timezone=Europe/Berlin` còn MySQL `time_zone=SYSTEM` = giờ Việt Nam → `NOW()` của DB
  lệch **+5h** so với `time()` của PHP. Hệ quả: **mọi phép so thời gian liên quan DB phải tính trong SQL**,
  không trộn `time()`/`strtotime()` với `attempted_at`. `session.cache_limiter=nocache` (PHP đã tự gửi
  `Cache-Control: no-store...` khi `session_start()`).
- **Working tree:** đang có WIP chưa commit (EnvConfig 30 ngày + 4 dự án, login.js pageshow, config bỏ
  auto-dismiss, README, CLAUDE.md, tasks.md shared-auth). **Làm tiếp trên working tree hiện tại; KHÔNG
  `git stash`/`checkout`/`revert`/`reset` bất cứ file nào.** Ở gốc repo có một file rác 0 byte tên
  `requireLogin()`.Kết` — **bỏ qua, không xoá, không đụng**.
- **Không đụng:** `SessionBootstrap.php`, `RedirectSanitizer.php`, `IdentitySync.php`, `EnvConfig.php`
  (`SESSION_LIFETIME` 30 ngày là đúng hiện trạng; Product.md ghi 7 ngày là lỗi thời; view **không được in
  thời hạn phiên** ra chữ), `prepend.php`, `bootstrap.php`, `autoload.php`, `portal.php`, `logout.php`,
  `config/db.php`, bất kỳ file nào ngoài repo `authV2`.
- **Hợp đồng tích hợp không đổi:** 4 dự án đã port (`lichkehoach`, `nhansuhtdb`, `sangkienct`,
  `khsanxuat`) không phải sửa dòng nào. `Guard::requireLogin()` chỉ được *thêm* query param, không đổi
  chữ ký, không đổi `EnvConfig::loginUrl()`.
- **Tên field HTML giữ nguyên:** `username`, `password`, `_csrf`, `redirect`. Test HTTP và
  `.claude/skills/port-to-authv2/scripts/smoke_test.php` regex theo `name="_csrf" value="..."` và
  `name="redirect" value="..."` — **thứ tự attribute bắt buộc** cho hidden: `name` rồi `value`. Với ô
  nhập: `id` phải đứng **trước** `value`/`autofocus`/`aria-invalid` trong cùng thẻ (test 12.2 regex theo
  thứ tự này).
- **Code style (CLAUDE.md):** không comment trừ bất biến bảo mật/lý do WHY; không docblock nhắc lại
  tham số; không header trang trí. Ngoại lệ duy nhất: `views/login.php` mở đầu bằng docblock liệt kê
  khoá `$viewData` (Product.md mục 9 yêu cầu).
- **PHP 8.2, `declare(strict_types=1)` mọi file PHP.** mysqli ở PHP ≥ 8.1 mặc định ném
  `mysqli_sql_exception` — mọi chỗ "fail-open" phải `try/catch (\Throwable)`, không chỉ kiểm `=== false`.
- **Chuỗi tiếng Việt có dấu, UTF-8, LF.** Không CRLF trong file mới.
- **Không commit, không push.** Để thay đổi ở working tree; coordinator sẽ review rồi quyết.
- **Test là cổng:** `php tests/run.php` phải `0 fail, 0 skip`. Máy có MySQL local (`root`, không mật khẩu,
  DB `mysqli`) nên test DB/HTTP **không được SKIP**. Chạy `php tests/run.php unit` / `php tests/run.php http`
  tách riêng khi debug.
- **Tìm code:** nếu có MCP `codebase-retrieval` thì dùng nó trước khi grep/đọc file.
- **Bí:** dùng `orca orchestration ask` hỏi coordinator, không tự đổi hợp đồng.

## 1. Pipeline Tailwind v4 (Product.md mục 3, 7.1, 7.6)

- [ ] 1.1 `.gitignore` += dòng `/tools/`.
- [ ] 1.2 `build-css.ps1` ở gốc repo:
  - Biến `$Version` đầu script, **pin một phiên bản 4.1.x cụ thể** (worker xác nhận phiên bản 4.1 mới nhất
    tại trang releases lúc làm, ghi số đó vào script và README). Nếu thiếu `tools/tailwindcss.exe` → tải
    `https://github.com/tailwindlabs/tailwindcss/releases/download/$Version/tailwindcss-windows-x64.exe`
    về `tools/tailwindcss.exe` (`Invoke-WebRequest`, tạo thư mục nếu chưa có).
  - Chạy `tools/tailwindcss.exe -i resources/css/app.css -o public/assets/css/app.css --minify`.
    In `tools/tailwindcss.exe --help | Select -First 1` (phiên bản) trước khi build.
  - Fallback nếu tải thất bại: `npx --yes @tailwindcss/cli@<cùng version> -i ... -o ... --minify`.
  - Tham số `-Watch` → thêm `--watch`. Thoát mã ≠ 0 khi build lỗi. In kích thước output (byte) ở cuối.
- [ ] 1.3 `resources/css/app.css` (nguồn):
  ```css
  @import "tailwindcss" source(none);
  @source "../../views";
  @source "../../public/assets/js";

  @theme {
    --font-sans: ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif;
    --color-brand-50: #eef2ff;  --color-brand-100: #e0e7ff;
    --color-brand-500: #5b73e8; --color-brand-600: #4a62d7; --color-brand-700: #3d51b5;
    --color-brand-800: #33449a;
    --color-accent: #44c4fa;
    --color-ink-900: #1f2933;   --color-ink-700: #3d4465;   --color-ink-500: #5f6b7a;
    --color-line: #e4e7eb;
    --color-danger-bg: #fef2f2; --color-danger-line: #fecaca; --color-danger-ink: #991b1b;
    --color-warn-bg: #fffbeb;   --color-warn-line: #fde68a;   --color-warn-ink: #92400e;
    --radius-card: 12px; --radius-field: 8px;
    --shadow-card: 0 10px 30px rgb(0 0 0 / 0.10);
  }
  ```
  `source(none)` bắt buộc: không có nó Tailwind v4 quét cả cwd (README, Product.md, `.agents/`, tests)
  và sinh class rác, làm task 9.13 mất ý nghĩa. Đường dẫn `@source` tương đối theo file CSS.

  `[SỬA SPEC]` **Màu (đã tính WCAG bằng script, không ước lượng):** trắng trên `brand-500` chỉ **4.15:1**
  → trượt AA cho chữ 16px/600 (Product.md 7.1 ghi "≈4.6 ✓" là sai). Vì vậy:
  - `.btn-primary`: nền `brand-600` (5.22:1), hover `brand-700` (6.90:1), active `brand-800`, ring
    focus-visible `brand-700/40`.
  - Link ("Quên mật khẩu?"): màu `brand-700`, không dùng `brand-500` trên trắng.
  - `brand-500` chỉ còn dùng cho gradient nền (trang trí) và viền/ring focus của input.
  - `ink-500` = `#5f6b7a`: 5.43 trên trắng, 5.15 trên `#f8f9fa`, 5.19 trên `slate-50` (giá trị cũ
    `#6b7280` cũng đạt 4.59 nhưng sát biên; lấy giá trị mới cho dư).
  - Cặp còn lại đã đạt: ink-700/brand-50 8.49; danger-ink/danger-bg 7.60; warn-ink/warn-bg 6.84.
  Worker vẫn phải chạy lại script tính contrast cho **mọi** cặp thực dùng trong view và dán bảng vào
  báo cáo (task 13.5).
  - `@layer base`: rule `prefers-reduced-motion` đúng nguyên văn Product.md 7.6. Không thêm
    `text-size-adjust` (preflight v4 đã có).
  - `@layer components`: đúng 3 class + 1 class layout, **không** tạo thêm:
    - `.login-shell` — `display:flex; flex-direction:column; align-items:center; justify-content:center;
      min-height:100vh; min-height:100dvh; padding:0` và `@media (min-width:640px){ padding:16px }`
      (fallback `100vh` ghi TRƯỚC `100dvh`). **Cấm** `position:absolute` + `inset:0`.
    - `.field-input` — 16px/400, `rounded-field`, viền `line`, `focus-visible:outline-none` **đi kèm**
      viền `brand-500` + ring 3px `brand-500/25` (preflight v4 không bỏ outline mặc định nên phải tắt để
      không chồng ring; Product.md chỉ cấm `outline:none` trần không có ring thay thế);
      `[aria-invalid="true"]` viền `danger-line`; `:disabled` nền xám nhạt + `cursor:not-allowed`.
    - `.btn-primary` — 16px/600, màu theo bảng trên, `focus-visible:outline-none` + ring 3px
      `brand-700/40`, `:disabled` opacity + `cursor:not-allowed`; transition ≤ 150ms.
    - `.alert` — 14px/400, flex, gap, icon + chữ; biến thể `.alert-danger` (`danger-*`), `.alert-warn`
      (`warn-*`), `.alert-info` (`brand-50` nền, `brand-100` viền, `ink-700` chữ).
  - Mọi thứ khác dùng utility thẳng trong markup. Tên gradient v4: `bg-linear-to-br from-... to-...`
    (không dùng alias cũ `bg-gradient-to-br`).
- [ ] 1.4 Chạy `build-css.ps1` → `public/assets/css/app.css` **commit cùng repo** (không gitignore).
  Kiểm: file tồn tại, minified, kích thước < 25KB.
- [ ] 1.5 Rủi ro trình duyệt: Tailwind v4 dùng `@property`, `color-mix()`, cascade layers → cần Chrome ≥ 111
  (v4.1 có fallback một phần cho máy cũ hơn). Ghi vào README mục build: "máy nào Chrome/Edge < 111 thì
  báo IT". Không làm gì thêm ở đợt này.

## 2. Icon partial (Product.md 7.7)

- [ ] 2.1 `views/partials/icon.php`:
  - Bọc `if (!function_exists('icon'))`. Hàm `icon(string $name, string $class = ''): string`.
  - Mảng tĩnh 5 icon: `user`, `lock`, `eye`, `eye-off`, `alert-triangle` — path SVG kiểu Feather/Lucide
    (`viewBox="0 0 24 24"`, `fill="none"`, `stroke="currentColor"`, `stroke-width="2"`,
    `stroke-linecap="round"`, `stroke-linejoin="round"`).
  - Output: `<svg class="{class đã escape}" aria-hidden="true" focusable="false" ...>...</svg>`.
    Tên lạ → trả `''` (không ném lỗi, không in gì).
  - Không dùng `style=""` (CSP cấm inline style). Kích thước qua class `w-4 h-4` / `w-5 h-5`.

## 3. `SessionContract::CSRF` (Product.md 5.4)

- [ ] 3.1 `src/SessionContract.php`: thêm `public const CSRF = 'shared_auth_csrf';`. `clear()` unset thêm
  khoá này. Không đổi `set()`/`isLoggedIn()`.
- [ ] 3.2 `tests/unit/session_contract_test.php`: thêm test `clear` xoá `shared_auth_csrf`; sửa test
  "clear: xoá đúng 3 key" → nay 4 key (giữ khẳng định key dự án `lkh_bo_loc` còn nguyên).

## 4. `Guard` gửi `?reason=expired` (Product.md 6.6)

- [ ] 4.1 `src/Guard.php`:
  - Tách phần dựng URL thành `public function loginLocation(): string` (testable, không `header()`/`exit`).
    `requireLogin()` gọi nó.
  - `loginLocation()` = `EnvConfig::loginUrl() . '?redirect=' . rawurlencode(currentUri())`, nối thêm
    `&reason=expired` khi **đủ ba**: (1) request mang cookie phiên `isset($_COOKIE[EnvConfig::sessionName()])`,
    (2) `session_status() === PHP_SESSION_ACTIVE` (dự án chạy `prepend.php` chỉ `configure()` mà chưa
    `session_start()` thì không được đoán), (3) `$_SESSION === []` (phiên bị GC → PHP cấp phiên rỗng mới;
    phiên khách chỉ có CSRF thì không rỗng → không phải "hết hạn"; đã logout thì cookie đã bị xoá).
    Heuristic, ghi 1 comment WHY ngắn tại chỗ. Giới hạn (ghi README): dự án nào ghi key riêng vào
    session **trước** `requireLogin()` sẽ không bao giờ ra `expired` — chấp nhận.
  - `denied` **không** do Guard phát; để dành cho dự án tự redirect thủ công. Không thêm code cho nó.
  - `reason` chỉ đọc từ GET ở trang login; sau khi POST sai mật khẩu, banner vàng biến mất và thay bằng
    alert lỗi — **chấp nhận**, không mang `reason` qua hidden field.
- [ ] 4.2 `tests/unit/guard_test.php`: thêm test cho `loginLocation()`. Dùng `with_default_env`; đặt
  `$_SERVER['REQUEST_URI']`, `$_COOKIE`, `$_SESSION` và trả về nguyên trạng trong `finally`. `Guard` giữ
  `final` (không tách hook để subclass). Unit chạy CLI nên `session_status()` mặc định là
  `PHP_SESSION_NONE`; để kiểm điều kiện (2), test gọi `session_save_path(sys_get_temp_dir())` rồi
  `@session_start()` thật (CLI không gửi header), rồi `session_write_close()` + `$_SESSION = []` trong
  `finally`. Bốn trường hợp:
  - không cookie → chỉ `?redirect=`;
  - có cookie + session active + `$_SESSION = []` → có `&reason=expired`;
  - có cookie + `$_SESSION = ['shared_auth_csrf' => 'x']` → không có reason;
  - có cookie + session **chưa** active → không có reason.
  Các test `isPublic` hiện có (kể cả 2 BASELINE) **giữ nguyên, vẫn xanh**.

## 5. `LoginThrottle` (Product.md 5.1)

- [ ] 5.1 `database/auth_login_attempts.sql`: đúng DDL Product.md 5.1 nhưng `CREATE TABLE IF NOT EXISTS`,
  và **đúng một statement** (helper test chạy bằng `$conn->query()`, không `multi_query`). README ghi: chạy
  file này 1 lần trên DB `mysqli` (local + production).
- [ ] 5.2 `src/LoginThrottle.php` (`final class`, namespace `Hoatho\SharedAuth`):
  - `__construct(mysqli $conn, array $options = [])`. Options + mặc định: `table` = `auth_login_attempts`,
    `maxAttempts` = 5, `windowMinutes` = 15, `lockoutMinutes` = 15, `byIp` = true,
    `cleanupProbability` = 0.05 (float 0..1), `retentionHours` = 24.
  - Khoá: luôn `u:<username>`; thêm `ip:<ip>` chỉ khi `byIp && $ip !== ''`. `identifier` cắt 190 ký tự
    (`mb_substr`). Username so nguyên văn sau `trim()` (caller đã trim), không lowercase.
  - `public function lockedMinutes(string $username, string $ip): ?int` — với từng khoá, **tính hoàn toàn
    trong SQL** (lý do: múi giờ PHP ≠ DB trên máy này, xem mục 0):
    ```sql
    SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? MINUTE) AS remaining
    FROM `t` WHERE identifier = ? AND attempted_at >= NOW() - INTERVAL ? MINUTE
    ORDER BY attempted_at DESC LIMIT 1 OFFSET ?
    ```
    bind: `lockoutMinutes`, identifier, `windowMinutes`, `maxAttempts - 1`. Có dòng và `remaining > 0` →
    `(int) ceil(remaining / 60)`. Trả max của các khoá, không có → `null`. Giá trị trả về **không bao giờ
    vượt `lockoutMinutes`** (unit test kiểm để bắt lỗi trộn múi giờ).
  - `public function recordFailure(string $username, string $ip): void` — INSERT một dòng cho mỗi khoá với
    `attempted_at = NOW()`. Sau đó với mỗi khoá, `SELECT COUNT(*) ... WHERE identifier = ? AND
    attempted_at >= NOW() - INTERVAL ? MINUTE`; **đúng bằng** `maxAttempts` → `error_log("LoginThrottle:
    khoá {identifier} sau {n} lần sai, ip={ip}, user={username}")`. Cuối cùng `maybeCleanup()`.
  - `public function clear(string $username): void` — `DELETE WHERE identifier = 'u:<username>'`.
    **Không** xoá khoá `ip:` (IP dùng chung; login đúng của người này không xoá dấu vết dò của người khác).
  - `private function maybeCleanup(): void` — `$p >= 1.0 || mt_rand() / mt_getrandmax() < $p` →
    `DELETE WHERE attempted_at < NOW() - INTERVAL ? HOUR`.
  - **Fail-open:** mọi method public bọc `try { ... } catch (\Throwable $e) { error_log('LoginThrottle: '
    . $e->getMessage()); return null|void; }`. Bảng không tồn tại, lỗi prepare (`=== false`), lỗi execute
    → không ném, không chặn login.
  - Không dùng `$_SERVER` bên trong lớp; caller truyền IP.
- [ ] 5.3 `tests/unit/login_throttle_test.php` (bảng tạm `_authv2_test_attempts`, SKIP nếu không DB, tạo
  bằng DDL giống 5.1, DROP ở `finally`). Helper `seed(conn, identifier, minutesAgo)` chèn bằng
  `attempted_at = NOW() - INTERVAL ? MINUTE` (không dùng `date()` của PHP). Test:
  - dưới ngưỡng (4 lần) → `lockedMinutes` = null;
  - 5 lần `recordFailure` → trả int trong `[1, 15]` **và** `<= lockoutMinutes`; lần thứ 5 có ghi
    `error_log` (lưu `ini_get('error_log')`, `ini_set` sang file tạm, đọc file, khôi phục trong `finally`);
  - chỉ khoá `ip:` đủ 5 (5 username khác nhau, cùng IP) → vẫn khoá; `byIp => false` → không khoá;
  - `clear(username)` → khoá `u:` mất, khoá `ip:` còn;
  - 5 bản ghi cũ 16 phút → không khoá (cửa sổ trượt); 5 bản ghi cũ 14 phút → khoá và phút còn lại = 1;
  - `cleanupProbability = 1.0` + bản ghi 25h → bị xoá, bản ghi 1h còn;
  - `table` = `_authv2_khong_ton_tai` → `lockedMinutes` null, `recordFailure`/`clear` không ném.

## 6. Timing-safe trong `CredentialVerifier` (Product.md 5.5)

- [ ] 6.1 `src/CredentialVerifier.php`: thêm `private const DUMMY_HASH = '<bcrypt cost 10 của một chuỗi
  ngẫu nhiên, sinh 1 lần bằng php -r "echo password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);">'`.
  `[SỬA SPEC]` Product.md 5.5 chỉ nói nhánh "không tìm thấy user", nhưng DB đang **100% plaintext** nên
  nhánh "sai mật khẩu plaintext" (`hash_equals`) cũng nhanh → nếu chỉ làm chậm nhánh user-không-tồn-tại thì
  oracle còn **rõ hơn** hiện tại. Vì vậy: **mọi nhánh trả `null` mà chưa chạy `password_verify` thật**
  (row null, thiếu cột password, plaintext không khớp) đều gọi `password_verify($password, self::DUMMY_HASH)`
  ngay trước `return null`. Nhánh bcrypt sai đã chạy `password_verify` thật → không gọi thêm. Comment WHY
  1 dòng. Các test BASELINE hiện có của lớp này phải xanh nguyên.
- [ ] 6.2 Không viết test đo thời gian (flaky). Tiêu chí "chênh < 20ms" kiểm thủ công ở task 13.7.

## 7. `config/login_config.php` (Product.md mục 8)

- [ ] 7.1 Thay toàn bộ bằng cấu trúc Product.md mục 8: `app` (logo 88×88, `logo_alt` = `'Logo Hoà Thọ'`),
  `ui` (`show_forgot_password` = false, `forgot_password_url` = `''`, `show_remember_me` = true,
  `show_copyright` = true, `remember_days` = 30), `throttle` (`enabled` = true, `max_attempts` 5,
  `window_minutes` 15, `lockout_minutes` 15, **`by_ip` = true**, **`table` =
  `getenv('AUTHV2_THROTTLE_TABLE') ?: 'auth_login_attempts'`** — để bộ test HTTP trỏ sang bảng tạm, không
  bao giờ khoá `127.0.0.1` thật của máy dev). **Xoá khối `csp`**, không còn `alert_auto_dismiss_seconds`.

## 8. `public/index.php` (Product.md 5.2, 5.6, 6.1, 6.6, 9)

- [ ] 8.1 Ngay sau `require bootstrap.php`, **trước mọi output và trước nhánh redirect**, gửi header:
  ```
  Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
  Referrer-Policy: same-origin
  Cache-Control: no-store, no-cache, must-revalidate
  Pragma: no-cache
  ```
  Chuỗi CSP là hằng cứng trong index.php, không đọc từ config. (`session_start()` với `cache_limiter=nocache`
  đã gửi Cache-Control/Pragma — vẫn gửi tường minh vì php.ini production có thể khác.)
- [ ] 8.2 CSRF: mọi `$_SESSION['_csrf']` → `$_SESSION[SessionContract::CSRF]`. Field HTML vẫn `_csrf`.
- [ ] 8.3 `$reason`: `$_GET['reason']` → whitelist cứng `in_array($v, ['expired', 'denied'], true)` else `null`.
- [ ] 8.4 `$oldUsername = ''`; sau **mọi** POST thất bại (CSRF, thiếu trường, khoá, sai mật khẩu) → gán
  `trim((string) ($_POST['username'] ?? ''))`. Không bao giờ giữ password.
- [ ] 8.5 `$credentialError = false` (chỉ true khi lỗi "Sai tên đăng nhập…" hoặc thiếu trường — view dùng
  để đặt `aria-invalid`; lỗi CSRF/429 không đánh dấu ô nhập là sai). Luồng POST (thứ tự BẮT BUỘC):
  1. CSRF sai → `$error = 'Phiên nhập liệu đã hết hạn, vui lòng thử lại.'` (giữ nguyên chữ — HTTP test
     assert chuỗi này).
  2. username/password rỗng → `$error = 'Vui lòng nhập tên đăng nhập và mật khẩu.'`, `$credentialError = true`.
  3. `$conn = require config/db.php` (chỉ tới đây mới mở DB — Product.md 5.6).
  4. `$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '')`. Nếu `config['throttle']['enabled']`: tạo
     `LoginThrottle($conn, ['table', 'maxAttempts', 'windowMinutes', 'lockoutMinutes', 'byIp' từ config])`;
     `$lockedMinutes = $throttle->lockedMinutes($username, $ip)`; nếu ≠ null →
     `http_response_code(429)`, `$error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$lockedMinutes} phút."`,
     **không** verify, **không** `recordFailure` (tránh kéo dài khoá vô hạn với người dùng thật).
  5. Verify. Sai → `$error = 'Sai tên đăng nhập hoặc mật khẩu.'` (giữ chữ), `$credentialError = true`,
     `recordFailure` (nếu throttle bật).
  6. Đúng → `clear($username)` (nếu throttle bật) rồi đúng chuỗi PRG hiện có: `session_regenerate_id(true)`
     → `renewCookie()` → `unset($_SESSION[SessionContract::CSRF])` → `SessionContract::set($user)` → 302.
- [ ] 8.6 Xoá `$portalUrl`. Xây `$viewData` đúng Product.md mục 9 (8 khoá) **+ `credentialError` (bool)**
  `[SỬA SPEC]`. `require views/login.php`. Không còn biến rời nào khác được view dùng.
- [ ] 8.7 HTTP status của GET/POST-thất-bại thường vẫn 200; chỉ khoá mới 429.

## 9. `views/login.php` viết lại (Product.md 6.x, 7.x, 9)

- [ ] 9.1 Đầu file: docblock liệt kê 9 khoá `$viewData`; `require __DIR__ . '/partials/icon.php'`;
  `$e = static fn (?string $v): string => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');` gán các
  biến cục bộ từ `$viewData` (không `extract()`). **Không** `require` config, **không** CSP.
- [ ] 9.2 `<head>`: charset, viewport, `<title>`, **chỉ** `<link rel="stylesheet" href="assets/css/app.css">`.
  Không có `<style>`, không `style=""` ở bất kỳ đâu, không `<meta http-equiv>`, không CDN.
- [ ] 9.3 `<body class="bg-white sm:bg-linear-to-br sm:from-brand-500 sm:to-accent text-ink-700 font-sans">`
  → `<main class="login-shell">` → card `<section class="w-full max-w-[400px] bg-white flex flex-col
  min-h-screen min-h-dvh sm:min-h-0 sm:rounded-card sm:shadow-card">` (mobile < 640px: card full-width,
  cao tối thiểu bằng viewport để `footer mt-auto` xuống đáy, không bo/không shadow; shell không padding ở
  mobile — task 1.3). Tuân bảng padding Product.md 7.3.
- [ ] 9.4 Header: `<img>` có `src`, `alt`, `width`, `height` từ config (preflight v4 đặt `img{height:auto;
  max-width:100%}` và trình duyệt suy aspect-ratio từ attribute → không CLS; **không** hardcode class
  `w-[88px]`). `<h1 class="text-[20px] sm:text-[22px] font-semibold text-ink-900">`.
- [ ] 9.5 Thứ tự trong body card: (a) alert lỗi `role="alert"` `.alert .alert-danger` + `icon('alert-triangle')`
  chỉ khi `$error`; (b) banner ngữ cảnh `role="status"` theo `$reason`/`$originLabel`:
  | reason | originLabel | Nội dung |
  |---|---|---|
  | null | có | `.alert-info` "Đăng nhập để tiếp tục vào **{label}**" |
  | null | null | không render |
  | expired | có | `.alert-warn` + icon "Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại để tiếp tục vào **{label}**." |
  | expired | null | `.alert-warn` + icon "Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại." |
  | denied | * | `.alert-warn` "Bạn cần đăng nhập để truy cập trang này." |
  `[SỬA SPEC]` hai hàng "null" là bổ sung cho trường hợp Product.md không nói (redirect về portal).
- [ ] 9.6 Form `method="post" action="" id="loginForm" data-remember-days="<?= (int) $config['ui']['remember_days'] ?>"`.
  Không đặt `novalidate` (giữ validation trình duyệt khi tắt JS). Hidden `_csrf`, `redirect` (thứ tự
  attribute `name` → `value`).
- [ ] 9.7 Ô username: `<label for="username" class="text-[14px] font-medium">` có `icon('user')` + chữ;
  `<input type="text" id="username" name="username" autocomplete="username" required class="field-input"
  value="<?= $e($oldUsername) ?>"` (+ `autofocus` khi `$oldUsername === '' && $error === null`;
  + `aria-invalid="true"` khi `$credentialError`). Thứ tự: `id` trước `value`/`autofocus`. Khi
  `$credentialError`: thêm `icon('alert-triangle', 'w-4 h-4 text-danger-ink')` cạnh label (Product.md 7.5:
  invalid có icon, không chỉ viền đỏ).
- [ ] 9.8 Ô password: label như 9.7 với `icon('lock')`; wrapper `relative`; `<input type="password"
  id="password" name="password" autocomplete="current-password" required class="field-input pr-11"`
  (+ `autofocus` khi `$oldUsername !== '' || $error !== null`; + `aria-invalid` khi `$credentialError`).
  **Không** có nút toggle trong HTML; đặt `<template id="togglePasswordTemplate">` chứa `<button
  type="button" class="absolute right-2 top-1/2 -translate-y-1/2 ..." aria-label="Hiện mật khẩu"
  aria-pressed="false" data-label-show="Hiện mật khẩu" data-label-hide="Ẩn mật khẩu">` với 2 SVG
  `icon('eye', 'w-5 h-5')` và `icon('eye-off', 'w-5 h-5 hidden')` — JS clone template và chèn vào wrapper
  (Product.md 6.8: JS tắt thì không thấy nút; SVG vẫn do PHP sinh, một nguồn icon).
- [ ] 9.9 Hàng tuỳ chọn: checkbox `id="remember"` nhãn **"Ghi nhớ tên đăng nhập"** + `<p id="rememberHint"
  class="text-[13px] text-ink-500 hidden">Chỉ lưu tên đăng nhập trên máy này.</p>` (JS bỏ `hidden` khi
  tick); chỉ render khi `ui.show_remember_me`. Link "Quên mật khẩu?" chỉ khi `show_forgot_password &&
  forgot_password_url !== ''`, `href` = `$e(url)`, class `text-brand-700 focus-visible:underline
  focus-visible:outline-none focus-visible:ring-3 ...` (Product.md 7.5: link focus = underline + ring),
  **không** `tabindex="-1"`, không `href="#"`.
- [ ] 9.10 Nút submit: `<div aria-live="polite"><button type="submit" id="loginBtn" class="btn-primary
  w-full" aria-busy="false"><span data-label>Đăng nhập</span></button></div>`. Spinner: JS thêm
  `<span class="... animate-spin motion-reduce:hidden" aria-hidden="true">` + đổi chữ "Đang xử lý…";
  reduced-motion → chỉ chữ (Product.md 7.6).
- [ ] 9.11 Footer (`show_copyright`): `<footer class="bg-slate-50 text-ink-500 text-[13px] text-center
  mt-auto ...">© {năm} – {name} – {company}</footer>`.
- [ ] 9.12 `<script src="assets/js/login.js" defer></script>` cuối body. Không Bootstrap JS.
- [ ] 9.13 Grep hai chiều sau khi xong: mọi class trong view/JS có trong `app.css` đã build (utility
  Tailwind tự sinh khi thấy chuỗi tĩnh — không ghép class động bằng PHP/JS); không còn `.hoatho-logo`,
  `.login-page`, `.login-wrapper`, `.login-box`, `form-control`, `btn btn-`, `fa-`, `me-2`, `mb-3`.

## 10. `public/assets/js/login.js` viết lại (Product.md 6.3, 6.4, 6.5, 6.8, 6.10)

- [ ] 10.1 Vanilla, `'use strict'`, bọc IIFE hoặc `DOMContentLoaded`. Không `innerHTML` với dữ liệu người
  dùng. Không `setAttribute('style', ...)` (CSP không có `unsafe-inline` chặn nó); CSSOM `el.style.x`
  không bị CSP chặn nhưng **vẫn cấm** để nhất quán — toggle bằng class `hidden`.
- [ ] 10.2 Ghi nhớ username: key `saved_username`, giá trị JSON `{"u":"<name>","t":<ms epoch>}`. Khi đọc:
  parse lỗi/thiếu/`Date.now() - t > days*86400000` → `removeItem`, coi như không có. `days` đọc từ
  `data-remember-days` trên `<form>`. Bỏ tick → `removeItem` ngay. Submit: tick → `setItem` với
  `t = Date.now()`; không tick → `removeItem`. Bọc mọi truy cập `localStorage` trong `try/catch`.
- [ ] 10.3 Focus (Product.md 6.5, `[SỬA SPEC]`): server đã đặt `autofocus`. Trường hợp 2 (có username lưu):
  JS chỉ điền `username.value` và `password.focus()` khi **`username.value === ''`** (chưa có `oldUsername`,
  user chưa gõ) **và** `document.activeElement` là `body` **hoặc chính ô username** (vì `autofocus` đã
  đặt focus vào username nên điều kiện "chỉ body" của Product.md không bao giờ đúng). Trường hợp 1 và 3
  JS không gọi `.focus()`.
- [ ] 10.4 Toggle password: clone `#togglePasswordTemplate`, chèn vào wrapper `relative` sau `#password`.
  Click: đổi `type`, đổi class `hidden` giữa 2 SVG, `aria-pressed`, `aria-label` từ `data-label-*`. Nút
  nằm trong tab order (không `tabindex=-1`).
- [ ] 10.5 Submit busy: `setBusy(true)` → `disabled`, `aria-busy="true"`, chữ "Đang xử lý…", chèn spinner
  span (class có `motion-reduce:hidden`), `form.dataset.submitting = '1'`. `setBusy(false)` làm ngược lại
  **và `delete form.dataset.submitting`** (nếu quên, sau Back form không submit được nữa). Gọi
  `setBusy(false)` ngay khi script chạy **và** trong `window.addEventListener('pageshow', e => { if
  (e.persisted) setBusy(false); })`. Chống double-submit: `submit` handler thấy `dataset.submitting === '1'`
  → `preventDefault()`.
- [ ] 10.6 Hint "Chỉ lưu tên đăng nhập trên máy này." hiện/ẩn theo `checked` (bỏ/thêm `hidden`), cập nhật
  ngay lúc load nếu checkbox đã tick do có username lưu.
- [ ] 10.7 Kích thước JS < 5KB. Không minify.

## 11. Xoá & dọn tham chiếu (Product.md mục 10 — Code)

- [ ] 11.1 `git rm public/assets/css/login.css`.
- [ ] 11.2 Grep toàn repo (trừ `Product.md`, `.agents/`, `.claude/`): `bootstrap@`, `cdn.jsdelivr`,
  `cdnjs`, `font-awesome`, `fa-`, `login.css`, `portalUrl` trong `public/index.php`, `$_SESSION['_csrf']`,
  `alert_auto_dismiss`, `'csp'`. Kết quả phải rỗng (README có thể còn nhắc lịch sử — sửa cho khớp hiện trạng).
- [ ] 11.3 README.md: cập nhật cây thư mục (`resources/css/`, `public/assets/css/app.css`,
  `views/partials/`, `database/`, `build-css.ps1`, `tools/` gitignore); mục "Build CSS" (lệnh, phiên bản
  Tailwind đã pin, khi nào build, commit output, Chrome ≥ 111); mục "Chống brute-force" (chạy SQL 1 lần,
  chính sách 5/15/15, fail-open, `throttle.enabled`, `by_ip`, **cảnh báo:** production đứng sau
  proxy/NAT thì mọi user chung một `REMOTE_ADDR` → 5 lần sai của bất kỳ ai khoá toàn bộ login 15 phút cho
  cả 4 dự án; khi đó tắt `by_ip` hoặc cấu hình proxy truyền IP thật — package không đọc
  `X-Forwarded-For` vì header đó giả được); mục "Security header" (gửi từ `index.php`, không còn config
  `csp`; `form-action 'self'` chặn cả 302 sau submit sang host khác → nếu sau này dùng
  `SHARED_AUTH_ALLOWED_HOSTS` phải nới directive này); `?reason=expired|denied` + giới hạn heuristic (task 4.1).
- [ ] 11.4 `CLAUDE.md` (repo): thêm 1 dòng: "CSS trang login build từ `resources/css/app.css` bằng
  `build-css.ps1` (Tailwind v4 standalone, pin version trong script); output `public/assets/css/app.css`
  commit vào repo — sửa view/JS xong phải build lại."
- [ ] 11.5 `.agents/.dd/specs/auth-hardening/tasks.md`: đánh dấu `[x]` kèm ghi chú "→ làm ở change
  `login-redesign`" cho: 4.1 (ghi rõ "làm theo hướng khác: CSP hằng cứng trong `index.php`, bỏ config `csp`
  theo Product.md mục 8"), 4.2 (chọn gỡ hẳn thay vì self-host), 4.4, 3.7 (đã đúng từ trước, có test),
  2.4 + 2.6 phần throttle ("bảng `auth_login_attempts`, 5 lần/15 phút theo Product.md, cấu hình qua
  `login_config.php` thay vì env; audit log 2.5 chưa làm"). Không đụng task khác.

## 12. Test HTTP mới (`tests/http/login_flow_test.php`)

Thêm vào cuối file, dùng helper sẵn có. **Không bao giờ đụng bảng `auth_login_attempts` thật:**
- `http_env()` truyền thêm `'AUTHV2_THROTTLE_TABLE' => '_authv2_test_attempts'` vào env của `proc_open`
  (config 7.1 đọc env này). Trong `http_env()` sau khi có `$conn`: `DROP TABLE IF EXISTS` rồi tạo bằng
  nội dung `database/auth_login_attempts.sql` với tên bảng thay bằng `_authv2_test_attempts`
  (`str_replace` tên bảng; file SQL là một statement — 5.1). `register_shutdown_function` hiện có thêm
  `DROP TABLE IF EXISTS _authv2_test_attempts`.
- Helper `http_clear_attempts(): void` = `DELETE FROM _authv2_test_attempts` — gọi ở **đầu và trong
  `finally`** của 12.4, 12.5.

- [ ] 12.1 `HTTP: security header đầy đủ, không còn meta CSP/inline style/CDN`: GET → header
  `content-security-policy` chứa `frame-ancestors 'none'`, `form-action 'self'`, `style-src 'self'` và
  **không** chứa `unsafe-inline`; `x-frame-options` = `DENY`; `x-content-type-options` = `nosniff`;
  `referrer-policy` = `same-origin`; `cache-control` chứa `no-store`. Body không chứa `http-equiv`,
  `<style`, ` style="`, `cdn.jsdelivr`, `cdnjs.cloudflare`, `bootstrap`, `font-awesome`.
- [ ] 12.2 `HTTP: sai mật khẩu giữ username và focus vào ô mật khẩu`: body khớp
  `/id="username"[^>]*value="__authv2_http_test__"/` và `/id="password"[^>]*autofocus/`; **không** khớp
  `/id="username"[^>]*autofocus/`; không chứa `sai-mat-khau`.
- [ ] 12.3 `HTTP: ?reason=expired hiện banner, reason lạ bị bỏ qua`: `?reason=expired` → chứa "Phiên làm
  việc đã hết hạn"; `?reason=khong-hop-le` (giá trị ngoài bảng hex, tránh trùng CSRF token) → không chứa
  chuỗi đó và không chứa `alert-warn`.
- [ ] 12.4 `HTTP: sai 5 lần → 429, đúng mật khẩu vẫn bị chặn`: `http_clear_attempts()`; 5 POST sai (mỗi
  lần lấy CSRF từ GET cùng cookie) đều 200; POST thứ 6 **với mật khẩu đúng** → status 429, body chứa
  "thử lại sau" và một số phút trong `[1,15]`; `sessionCookie()` của response là `null` hoặc **bằng**
  cookie cũ (mọi response đều có Set-Cookie do `renewCookie()`; điều kiện là *không đổi id*); portal với
  cookie đó → 302. `finally` → `http_clear_attempts()`.
- [ ] 12.5 `HTTP: login đúng xoá bộ đếm của username`: clear; 2 POST sai rồi 1 POST đúng → `COUNT(*)
  WHERE identifier='u:__authv2_http_test__'` = 0; `identifier='ip:127.0.0.1'` = 2; `finally` clear.
- [ ] 12.6 `HTTP: CSRF session key là shared_auth_csrf`: sau GET, đọc `storage/sessions/sess_<id>`
  (`EnvConfig::sessionSavePath()`; PHP ghi file ở shutdown, `php -S` đóng socket sau đó nhưng vẫn retry
  đọc tối đa 10 lần × 50ms cho chắc). Nội dung: `str_contains($data, 'shared_auth_csrf|')` = true **và**
  `preg_match('/(?<![A-Za-z0-9_])_csrf\|/', $data) === 0` (không dùng `str_contains('_csrf|')` vì
  `shared_auth_csrf|` chứa chuỗi con đó). Không đọc được file → `fail_test`, không skip.
- [ ] 12.7 Các test hiện có phải xanh nguyên, đặc biệt "POST thiếu CSRF" (chuỗi lỗi), "sai mật khẩu ở lại
  form" (chuỗi lỗi), "login đúng → 302", "?redirect=" (regex hidden field).

## 13. Cổng nghiệm thu (worker tự làm rồi ghi kết quả vào `worker_done`)

- [ ] 13.1 `php tests/run.php` → `N pass, 0 fail, 0 skip` (baseline 57 + số test mới). Dán dòng tổng kết.
- [ ] 13.2 `php -l` mọi file PHP đã sửa/thêm.
- [ ] 13.3 Grep task 11.2 rỗng; grep class mồ côi task 9.13 rỗng.
- [ ] 13.4 Kích thước: `public/assets/css/app.css` + `login.js` < 30KB (ghi số byte).
- [ ] 13.5 Bảng contrast (task 1.3) — ghi từng cặp thực dùng và tỉ lệ, tính bằng script.
- [ ] 13.6 DB sau test: `SELECT COUNT(*) FROM user WHERE name LIKE '__authv2%'` = 0; không sót bảng
  `_authv2_test_*` (kể cả `_authv2_test_attempts`); bảng `auth_login_attempts` thật **không có dòng nào do
  test sinh** (test không được trỏ vào nó).
- [ ] 13.7 Kiểm thủ công trên Laragon (`http://localhost/authV2/public/index.php`), trình duyệt thật hoặc
  Playwright/CDP nếu có: (a) Network 0 request ra domain ngoài; (b) sai mật khẩu → username còn, focus ở
  password, alert không tự mất; (c) submit rồi Back → nút không kẹt, submit lại được; (d) Tab đi hết form
  thấy ring, không thấy outline chồng ring; (e) toggle mắt đổi `aria-pressed`/`aria-label`; (f) tắt JS:
  không có nút mắt, submit vẫn được; (g) nhúng `<iframe src=...>` từ file HTML khác → trống; (h) emulate
  `prefers-reduced-motion` → không animation; (i) thu hẹp < 640px → card full, footer đáy, cuộn được;
  (j) đo thời gian POST user không tồn tại vs sai mật khẩu plaintext (10 lần mỗi loại, trung vị) — chênh
  < 20ms. Mục nào không kiểm được thì ghi "chưa kiểm" + lý do, không bỏ trống.
- [ ] 13.8 `git status` liệt kê đúng file dự kiến (mục 4 Product.md + `database/`, `.gitignore`, README,
  CLAUDE.md, spec auth-hardening, test, `public/assets/css/app.css`); `tools/` không xuất hiện; file rác
  `requireLogin()`.Kết` vẫn còn nguyên (không phải việc của change này); WIP có sẵn vẫn còn nguyên.

## 14. Thứ tự làm (bám Product.md mục 12, đã chỉnh cho có test sớm)

1. Task 3 (CSRF const + test) → `php tests/run.php unit` xanh.
2. Task 4 (Guard + test) → unit xanh.
3. Task 5 (SQL + LoginThrottle + test) → unit xanh.
4. Task 6 (timing-safe) → unit xanh.
5. Task 1 (pipeline + `app.css` nguồn), task 2 (icon).
6. Task 7 (config) → task 8 (index.php) → task 9 (view) → task 10 (JS) → build CSS (1.4).
7. Task 11 (xoá/dọn/docs) → task 12 (HTTP test) → `php tests/run.php` toàn bộ.
8. Task 13 (cổng) → gửi `worker_done` với báo cáo theo đúng 8 mục của task 13.
