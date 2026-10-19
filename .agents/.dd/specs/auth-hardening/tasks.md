# Tasks — auth-hardening (authV2)

> Nguồn sự thật để thực thi. Thứ tự KHÔNG được đảo: nhóm 1 (nền kiểm thử) chốt hành vi hiện tại
> **trước** khi nhóm 2–5 sửa bất cứ thứ gì; nhóm 2 xong mới chạy được `shared-auth` task 2.8; nhóm 3
> xong mới nên chạy `shared-auth` task 3.2 và 5.1.
>
> Bất biến giữ nguyên: package nhận `mysqli` từ ngoài, và hợp đồng tích hợp vẫn đúng 2 điểm chạm —
> `lichkehoach` + `nhansuhtdb` không phải sửa một dòng nào trong toàn bộ change này.

## 1. Nền kiểm thử — làm trước tiên

- [x] 1.1 `tests/run.php` chạy bằng `php tests/run.php`, PHP thuần, không thêm dependency; cấu trúc
      `tests/unit/` + `tests/http/`; exit code khác 0 khi có test hỏng. Lọc bằng `php tests/run.php unit|http`
- [x] 1.2 Unit test chốt **hành vi hiện tại** của `RedirectSanitizer` (14), `EnvConfig` (9),
      `Guard` (6), `SessionContract` (6), `CredentialVerifier` (9), `IdentitySync` (3) — 47 test.
      Test cần DB chạy trên bảng tạm `_authv2_test_user`, SKIP nếu không có MySQL
- [x] 1.3 Smoke HTTP qua `php -S` (10 test): form + CSRF, thiếu CSRF bị từ chối, sai mật khẩu, login
      đúng → 302 + đổi session id, cờ cookie, portal, đã-login-bỏ-form (SSO), logout huỷ phiên,
      `?redirect=` hợp lệ và bị loại, redirect-back, DB không bị ghi khi công tắc nâng cấp tắt
- [x] 1.4 README: mục "Chạy test" + giải thích quy ước `BASELINE`
- [x] 1.5 **Cổng**: 57 pass / 0 fail / 0 skip, exit 0. Không có test nào đỏ ngoài dự kiến.
      DB sau khi chạy: 149 user, 0 bcrypt — không sót user tạm, không sót bảng tạm

> **Quy ước `BASELINE`**: 7 khẳng định trong bộ test chốt hành vi hiện tại *dù hành vi đó sai*, mỗi
> chỗ ghi rõ task nào sẽ lật nó. Danh sách ban đầu: `Guard::isPublic` lọt `..` và lọt `%2e%2e` (task 3.2),
> `EnvConfig::isSecure` ở production + HTTP ra false (2.2), `sessionSavePath` production trả null (2.1),
> `SessionContract::isLoggedIn` nhận phiên 10 năm tuổi (3.8), `CredentialVerifier` trả cả cột thừa (3.6)
> và hiểu nhầm plaintext bắt đầu bằng `$2` là hash (3.5), `IdentitySync` mã hoá HTML lúc ghi phiên (3.4)
> và cờ `static $done` dùng chung mọi instance (3.3).
>
> **Đã lật có chủ ý:** `sessionSavePath` production trả null → ném lỗi (2.1); `isLoggedIn` nhận phiên
> 10 năm → từ chối quá trần (3.8); `Guard::isPublic` với `..` và `%2e%2e` → không còn public (3.2).
> **Còn mở:** `isSecure` (2.2), `CredentialVerifier` cột thừa (3.6) và tiền tố `$2` (3.5), `IdentitySync`
> (3.3, 3.4) — các test này phải còn xanh cho tới khi đúng task đó được làm.

## 2. Nhóm A — chặn `shared-auth` task 2.8 (deploy production)

- [x] 2.1 `EnvConfig`: thêm `SHARED_AUTH_SESSION_PATH`; production dùng thư mục riêng ngoài webroot.
      Production mà thư mục thiếu/không ghi được → **dừng với lỗi rõ ràng**, tuyệt đối không rơi về
      `save_path` mặc định của php.ini (design.md, quyết định 1)
      → `EnvConfig::sessionSavePath()`: local mặc định `<session.save_path của php.ini>/htsso-sessions` (đọc bằng
      `get_cfg_var`, không phụ thuộc `TEMP`); production thiếu env hoặc thư mục không ghi được → `RuntimeException`.
      `prepend.php` bắt lỗi, `error_log` và trả HTTP 500 trên SAPI web (CLI chạy tiếp). `storage/sessions` cũ bỏ
      (từng truy cập được qua web, file đã xoá); Apache chặn mọi thứ ngoài `public/` bằng conf ngoài repo
- [ ] 2.2 `SessionBootstrap`: cờ cookie `Secure` quyết theo môi trường (`isProduction() || isSecure()`),
      không để header client hạ được cờ
- [x] 2.3 `Guard::requireLogin()` tự gọi `SessionBootstrap::start()` trước khi đọc trạng thái — chặn
      vòng lặp 302 ở dự án chạy `auto_prepend_file` chỉ `configure()`
      → phiên chưa mở thì `start()`, đã mở thì `SessionBootstrap::renewCookie()` (không gửi `Set-Cookie` trùng
      trong cùng request); nhờ vậy cookie trượt 30 ngày cũng chạy ở dự án chế độ prepend
- [x] 2.4 Bảng `auth_login_attempt` (username, ip, số lần, khoá tới) + lớp đếm/khoá theo cặp
      (username, IP), nhận `mysqli` từ ngoài. Ngưỡng + thời gian khoá qua env, mặc định 10 lần / 15 phút
      → làm ở change `login-redesign`. bảng `auth_login_attempts`, 5 lần/15 phút theo Product.md, cấu hình qua `login_config.php` thay vì env; audit log 2.5 chưa làm
      → **sau đó đổi sang chính sách lai** (xem `capabilities/login-protection.md`): khoá cặp `p:<user>|<ip>`
      5/15→15 phút, khoá `ip:<ip>` 30/15→15, trần `u:<user>` 50/60→30. Bảng `identifier VARCHAR(330)
      utf8mb4_unicode_ci` + `idx_attempted_at`, migration `database/migrations/2026-10-17_auth_login_attempts_hardening.sql`
      (cần InnoDB `ROW_FORMAT=DYNAMIC`)
- [ ] 2.5 Bảng `auth_audit_log` + lớp ghi vết: thành công / sai mật khẩu / không có tài khoản / bị khoá
      / đăng xuất, kèm `user_id` khi có, IP, thời điểm. **Không bao giờ ghi mật khẩu.** Lỗi ghi log đi
      vào `error_log` và không chặn login
- [x] 2.6 Nối 2.4 + 2.5 vào `public/index.php` và `public/logout.php`; đang bị khoá thì từ chối kể cả
      khi mật khẩu đúng, và thông báo không tiết lộ tài khoản có tồn tại hay không
      → làm ở change `login-redesign` (phần throttle). bảng `auth_login_attempts`, 5 lần/15 phút theo Product.md, cấu hình qua `login_config.php` thay vì env; audit log 2.5 chưa làm
      → `logout.php` vẫn GET nhưng từ chối (giữ phiên, 303 về login) khi `Sec-Fetch-Site: cross-site` hoặc
      `Sec-Fetch-Dest` ≠ `document`; thiếu `Sec-Fetch-*` thì Origin/Referer lạ/`null` bị từ chối; không header nào → cho phép.
      Dự án khác *site* (kể cả trong `SHARED_AUTH_ALLOWED_HOSTS`) không đăng xuất cross-site bằng link được
- [x] 2.7 Sửa `README.md` + `specs/shared-auth/capabilities/shared-session.md` cho khớp quyết định 1
      (bỏ lời hứa MySQL session handler, thay bằng save_path riêng + fail-fast)
      → README mục "Môi trường" + checklist "Deploy production (XAMPP)" và `shared-session.md` đã sửa.
      Còn sót lời hứa cũ ở `specs/shared-auth/{design,tasks}.md` (quyết định #4, task 1.4) — là artifact của
      change trước, để nguyên làm lịch sử
- [ ] 2.8 **Cổng**: test nhóm 1 xanh + test mới cho 2.1–2.6; thử tay trên local: dò sai quá ngưỡng bị
      khoá, IP khác vẫn vào được, audit log có đủ 5 loại sự kiện

## 3. Nhóm B — đúng đắn của package (chặn `shared-auth` task 3.2 và 5.1)

- [x] 3.1 Tách phần rút gọn `.`/`..` khỏi `RedirectSanitizer` thành một lớp nội bộ dùng chung;
      `RedirectSanitizer` gọi lớp đó, hành vi không đổi (test 1.2 phải vẫn xanh)
      → `src/PathNormalizer.php` (`normalize()`: giải mã đúng một lượt, `\`→`/`, gộp `//`, rút gọn `.`/`..`;
      `isAmbiguous()`: `\`, `%2f`, `%5c`, `%25`). Hành vi `RedirectSanitizer` có đổi, chủ ý (T8): mọi path dưới
      `SHARED_AUTH_BASE_URL` bị loại trừ `portal.php` (không phân biệt hoa/thường, không PATH_INFO), path mơ hồ bị từ chối
- [x] 3.2 `Guard::isPublic()` giải mã percent-encoding + rút gọn path trước khi so allowlist; thêm test
      cho `/duan/api/../trang-can-login.php` và bản percent-encoded — **đây là lỗ bypass auth**
      → `isPublic()` chuẩn hoá bằng `PathNormalizer`, so không phân biệt hoa/thường (filesystem Windows); path mơ hồ
      (`\`, `%2f`, `%5c`, `%25`) thì không public. `currentPath()` cắt `?`/`#` thủ công thay `parse_url` (`//host/x`
      bị hiểu là authority). Hai BASELINE `..` và `%2e%2e` đã lật
- [ ] 3.3 `IdentitySync`: bỏ `static $done` trong method non-static, đổi thành cờ theo từng instance
- [ ] 3.4 `IdentitySync::normalize()`: thôi `htmlspecialchars` lúc ghi vào phiên. **Trước khi sửa,
      grep `nhansuhtdb` xem chỗ nào đang hiển thị các key được sync mà không tự escape** — nếu có thì
      sửa chỗ hiển thị trong cùng bước, không tách ra
- [ ] 3.5 `CredentialVerifier::looksHashed()` dùng `password_get_info()` thay tiền tố `$2` cứng; thêm
      `password_needs_rehash` chịu chung công tắc `SHARED_AUTH_UPGRADE_ON_LOGIN` (đang TẮT → không ghi gì)
- [ ] 3.6 `CredentialVerifier` chỉ SELECT cột cần (`id`, `name`, `password`) thay `SELECT *`; trả về
      đúng `id` + `name`
- [x] 3.7 Thông báo lỗi login giống hệt nhau giữa "sai mật khẩu" và "không có tài khoản"
      → làm ở change `login-redesign` (đã đúng từ trước, có test)
- [x] 3.8 Trần tuyệt đối: `SHARED_AUTH_ABSOLUTE_LIFETIME` (mặc định 30 ngày, `0` = tắt), kiểm trong
      `SessionContract::isLoggedIn()` dựa trên `logged_in_at`
      → `EnvConfig::absoluteLifetime()` (giây; giá trị không phải số nguyên không âm rơi về mặc định);
      `isLoggedIn()` từ chối khi thiếu `logged_in_at`, hoặc (khi trần bật) `logged_in_at` không phải số nguyên dương
      hoặc đã quá trần. Hạn trượt vẫn là 30 ngày (`SESSION_LIFETIME`), không phải 7 ngày như spec cũ
- [ ] 3.9 **Cổng**: test xanh + chạy lại kiểm thử chéo thật như task 2.5/2.7 của `shared-auth`
      (lichkehoach ↔ nhansuhtdb: Guard chặn, SSO chéo, redirect-back, logout lan, DB vẫn plaintext)

## 4. Nhóm C — trang login & cấu hình

- [x] 4.1 Gửi security header bằng HTTP header thay `<meta http-equiv>`: CSP có `frame-ancestors`,
      `Referrer-Policy`, `X-Content-Type-Options`; giữ `login_config.php` làm nơi khai báo
      → làm ở change `login-redesign`. làm theo hướng khác: CSP hằng cứng trong `index.php`, bỏ config `csp` theo Product.md mục 8
- [x] 4.2 Bootstrap + FontAwesome: self-host vào `public/assets/vendor/` (ưu tiên) hoặc thêm
      `integrity` + `crossorigin`; cập nhật CSP cho khớp lựa chọn
      → làm ở change `login-redesign`. chọn gỡ hẳn thay vì self-host
- [x] 4.3 `config/db.php`: đường dẫn cấu hình qua env, bỏ hardcode `C:/xampp/config/db.php`; thiếu
      cấu hình thì dừng với lỗi rõ ràng, bỏ fallback `root` / mật khẩu rỗng
      → env `SHARED_AUTH_DB_CONFIG` (mặc định vẫn `C:/xampp/config/db.php`); không còn fallback `root`/mật khẩu rỗng;
      file thiếu/khoá `host,username,password,database` sai kiểu → `error_log` chi tiết + HTTP 500 thông báo chung;
      `mysqli_report` strict đặt tường minh
- [x] 4.4 Gỡ liên kết "Quên mật khẩu?" (`href="#"`); đổi nhãn "Ghi nhớ đăng nhập" cho đúng việc nó làm
      (chỉ nhớ tên đăng nhập trên trình duyệt này)
      → làm ở change `login-redesign`
- [ ] 4.5 **Cổng**: mở trang login kiểm header thật trả về, thử nhúng iframe bị chặn, test 1.3 vẫn xanh

## 5. Nhóm D — dọn dẹp

- [ ] 5.1 `RedirectSanitizer` ghi `error_log` khi loại một `?redirect=`, nêu giá trị bị loại — để
      onboard dự án mới mà quên khai báo `SHARED_AUTH_PROJECTS` không còn im lặng
- [ ] 5.2 Chốt chính sách dọn file phiên: xác nhận `session.gc_probability`/`gc_divisor` thực sự chạy
      trên save_path riêng, thêm dọn định kỳ nếu không. Khách vãng lai vẫn sinh phiên (CSRF cần), nên
      đây là việc dọn chứ không phải việc chặn
- [ ] 5.3 Drop cột chết `user.remember_token` + `user.token_expiry` (tàn dư remember-me đã gỡ ở
      `shared-auth` task 2.7). **Grep toàn bộ `C:\laragon\www\*` trước khi drop** — dự án chưa tích hợp
      có thể còn đọc
- [ ] 5.4 Ghi việc gom danh tính vào `$_SESSION['_auth']` sang `specs/shared-auth/tasks.md` nhóm 4
      (chốt sổ), kèm ghi chú: chỉ làm cùng một cửa sổ deploy đồng loạt, không làm riêng

## 6. Chốt sổ change

- [ ] 6.1 Cập nhật `specs/shared-auth/tasks.md`: ghi task 2.8 đã hết bị chặn, dẫn sang change này
- [ ] 6.2 Rà `proposal.md` + `design.md` so với code thực tế; chỗ nào lệch thì sửa spec, không để trôi
