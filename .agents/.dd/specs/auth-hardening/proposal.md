## Why

Đợt đánh giá authV2 ngày 13/08/2026 (đọc toàn bộ `src/`, `public/`, `views/`, `config/`,
`bootstrap.php`, `prepend.php`) tìm ra 21 tồn đọng. Kiến trúc và ranh giới authn/authz là đúng;
chỗ hụt nằm ở **độ sẵn sàng production** và ở vài lỗi đúng-sai trong package.

Ba lý do phải xử lý **trước** khi chạy tiếp lộ trình ở `specs/shared-auth/tasks.md`:

- **Task 2.8 (deploy đồng loạt) sẽ hỏng hoặc không an toàn.** `EnvConfig::sessionSavePath()` trả
  `null` khi production → rơi về `save_path` mặc định của php.ini, dùng chung với mọi vhost khác
  trên máy; MySQL session handler mà README hứa thì chưa có dòng nào. Cookie `Secure` lại bám theo
  từng request chứ không theo env, nên một request HTTP lọt vào là cookie SSO đi trần.
- **Gom về một trang login = gom về một điểm tấn công duy nhất**, mà trang đó không có rate-limit,
  không khoá tài khoản, không log gì. Baseline là 149 user plaintext dài tối đa 6 ký tự.
- **Task 3.2 (chuyển mọi điểm ghi password sang `password_hash` cùng một đợt)** là bước rủi ro nhất
  còn lại, và bộ test được khai "20/20 unit + 7/7 HTTP pass" ở tasks.md:20 **không có trong repo** —
  không có `tests/`. Bước đó sẽ chạy mà không có lưới hồi quy nào.

## What Changes

Một change riêng, không trộn vào `specs/shared-auth/tasks.md` (đó là lộ trình migration password
GĐ0–3 đang chạy dở). Change này **chặn task 2.8**, và nhóm B chặn task 3.2 + 5.1.

**Nhóm A — chặn deploy production**
- Session storage production: wire handler thật (`SessionHandlerInterface` lưu MySQL) vào đường
  bootstrap của chính app login, thay vì `start()` trần ở `bootstrap.php:7`.
- Cookie `Secure` quyết theo môi trường: `isProduction() || isSecure()` (SessionBootstrap.php:49).
- Chống brute-force ở `public/index.php`: đếm lần sai theo (username, IP), khoá tạm/tăng delay.
- Audit log đăng nhập: thành công + thất bại + logout, có IP và thời điểm.
- `Guard::requireLogin()` tự gọi `SessionBootstrap::start()` (đã idempotent) — nếu không, một entry
  point ở nhansuhtdb quên `session_start()` sẽ tạo **vòng lặp 302** giữa project và trang login.

**Nhóm B — đúng đắn của package**
- `Guard::isPublic()` normalize path trước khi so allowlist (Guard.php:46-51). Với entry dạng prefix,
  `/proj/api/../admin.php` khớp allowlist trong khi Apache phục vụ file khác → **bypass auth**.
  `RedirectSanitizer::normalizePath()` đã làm đúng việc này; Guard dùng lại.
- `IdentitySync::sync()` bỏ `static $done` trong method non-static (IdentitySync.php:26-29) — cờ dùng
  chung mọi instance, instance thứ hai im lặng no-op.
- `IdentitySync::normalize()` thôi `htmlspecialchars` lúc GHI vào session (IdentitySync.php:73);
  escape thuộc tầng output. Chính task 2.7 đã phải né bằng "name THÔ".
- `CredentialVerifier::looksHashed()` dùng `password_get_info()` thay prefix `$2` cứng
  (CredentialVerifier.php:71-74), thêm `password_needs_rehash`.
- `CredentialVerifier` chỉ SELECT cột cần thay `SELECT *` (CredentialVerifier.php:33, 67-68).
- **Trạng thái tài khoản — ĐÃ CHỐT: không làm.** `mysqli`.`user` không có cột trạng thái (đã kiểm:
  id, name, password, signature_image, full_name, role, remember_token, token_expiry, nhan_vien_ma).
  Giữ đúng ranh giới package: chặn nhân sự nghỉ việc là authz của từng dự án. Rủi ro đã biết được
  ghi ở `design.md`.
- Commit lại bộ test đã viết (unit + HTTP smoke) vào repo, chạy được bằng một lệnh.

**Nhóm C — trang login & cấu hình**
- Security header đặt bằng header PHP thay `<meta http-equiv>` (views/login.php:26): `frame-ancestors`
  bị meta bỏ qua hoàn toàn → trang login iframe được. Thêm `Referrer-Policy`,
  `X-Content-Type-Options`, `X-Frame-Options`.
- Bootstrap + FontAwesome nạp từ CDN không SRI (views/login.php:29-30, 123) trên đúng trang giá trị
  cao nhất hệ thống: self-host hoặc `integrity`+`crossorigin`.
- `config/db.php` bỏ hardcode `C:/xampp/config/db.php` + fallback root/mật-khẩu-rỗng (config/db.php:5-13):
  thiếu config thì fail rõ ràng, không âm thầm nối bằng root.
- "Quên mật khẩu?" là `href="#"` (views/login.php:102) — ẩn nút hoặc trỏ vào quy trình thật.
  "Ghi nhớ đăng nhập" chỉ lưu username vào localStorage → đổi nhãn cho đúng.

**Nhóm D — dọn dẹp, gom cuối**
- Chốt chính sách phiên: cookie gia hạn mỗi request nhưng `logged_in_at` được ghi mà **không bao giờ
  được kiểm tra** → phiên vô hạn khi còn hoạt động. Quyết có absolute lifetime hay không.
- Khách vãng lai cũng sinh session file (start ở mọi request) với gc 7 ngày → file tích tụ.
- `error_log` khi `RedirectSanitizer` loại một redirect — onboard dự án mới mà quên thêm vào
  `SHARED_AUTH_PROJECTS` hiện tại là im lặng đá về portal.
- Cột chết trong DB: `user.remember_token` + `user.token_expiry` là tàn dư remember-me đã gỡ ở task 2.7.
- Cân nhắc gom danh tính vào một namespace session (`$_SESSION['_auth']`) — hiện mọi dự án cùng host
  đọc/ghi được `$_SESSION` của nhau, chỉ có quy ước prefix trong README chứ không có gì cưỡng chế.

## Capabilities

### New Capabilities
- `login-protection`: chống dò mật khẩu ở trang login (đếm lần sai, khoá tạm) và ghi vết đăng
  nhập/đăng xuất để truy được ai vào lúc nào từ đâu.

### Modified Capabilities
- `shared-session`: requirement "Storage theo môi trường" hiện chưa có hiện thực production; thêm
  requirement cookie `Secure` quyết theo môi trường, và bảo đảm phiên đã mở trước khi Guard đọc.
- `access-guard`: allowlist path public phải so trên path đã normalize.
- `credential-verification`: nhận diện hash theo `password_get_info` thay prefix cứng; không trả về
  cột thừa.
- `login-app`: security header gửi bằng HTTP header; tài nguyên ngoài phải toàn vẹn được kiểm chứng;
  cấu hình DB fail rõ ràng.

## Impact

- **Package `src/`**: `SessionBootstrap`, `EnvConfig`, `Guard`, `CredentialVerifier`, `IdentitySync`
  đều đổi; thêm một session handler MySQL và một lớp chống dò mật khẩu + audit log.
- **App login**: `public/index.php`, `views/login.php`, `config/db.php`, `bootstrap.php`.
- **Mới**: `tests/` (chạy được bằng một lệnh) — hiện chưa tồn tại.
- **DB**: bảng lưu session production; bảng audit log; bảng đếm lần đăng nhập sai; drop 2 cột chết
  `remember_token`/`token_expiry`. KHÔNG đụng cấu trúc `user` ngoài 2 cột chết đó.
- **Dự án đã tích hợp**: lichkehoach + nhansuhtdb **không phải sửa** — mọi thay đổi nằm sau hợp đồng
  2 điểm chạm. Ngoại lệ duy nhất là nếu chốt namespace session `$_SESSION['_auth']` (nhóm D), lúc đó
  cần một đợt deploy đồng loạt nữa; đó là lý do nhóm D để cuối và có thể cắt bỏ.
- **Thứ tự**: nhóm A xong mới chạy được task 2.8. Nhóm B xong mới nên chạy task 3.2 và 5.1.

## Đã chốt (13/08/2026)

1. **Phạm vi**: cả 4 nhóm A–D.
2. **Tài khoản nghỉ việc**: authV2 KHÔNG chặn. Giữ ranh giới authn/authz; rủi ro ghi ở `design.md`.
3. **Chống dò mật khẩu**: khoá theo cặp (username, IP) — không cho người ngoài khoá tài khoản người
   khác. Đánh đổi: kẻ tấn công đổi IP thì lách được; bù bằng audit log để phát hiện.
4. **Absolute session lifetime**: tôi tự quyết (bạn có thể lật lại) — thêm trần tuyệt đối 30 ngày
   tính từ `logged_in_at`, cấu hình được, đặt `0` là tắt. Lý do và đánh đổi ở `design.md`.
