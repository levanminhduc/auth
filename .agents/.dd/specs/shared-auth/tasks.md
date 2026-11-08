# Tasks — shared-auth (authV2)

> Nguồn sự thật để thực thi. 2 điểm KHÔNG được đảo: (a) mọi điểm ĐỌC password hiểu cả 2 định dạng
> TRƯỚC khi bất kỳ đâu GHI hash; (b) `ALTER user.password VARCHAR(255)` TRƯỚC lần ghi hash đầu tiên.

## 1. Giai đoạn 0 — Hạ tầng & khung authV2 (chưa đụng production)

- [x] 1.1 Kiểm tra kiểu cột `mysqli`.`user`.`password` — ĐÃ là `varchar(255)`, KHÔNG cần ALTER (blocker đã sạch)
- [x] 1.2 Baseline migration: 149 user, 100% plaintext (0 bcrypt, 0 rỗng), max length hiện tại = 6 ký tự
- [x] 1.3 `authV2/composer.json` PSR-4 `Hoatho\SharedAuth\` → `src/`; khung `src/ public/ views/ config/ storage/`; autoload sinh (8 class) + fallback autoloader trong `bootstrap.php`
- [x] 1.4 `EnvConfig` — local (HTTP, file save_path riêng) vs production (HTTPS, MySQL handler); tên/URL/thời hạn phiên; override qua env
- [x] 1.5 `SessionBootstrap` — idempotent; `session_name=HTSSOSESSID`, cookie `path=/`/httponly/samesite/secure-theo-env, lifetime 7 ngày (gia hạn mỗi request); `destroy()`. Tổng quát hoá `lichkehoach/src/session.php`
- [x] 1.6 `SessionContract` — key chuẩn `user_id`(=`user.id`)/`name`/`logged_in_at`; set/userId/name/isLoggedIn/clear
- [x] 1.7 `CredentialVerifier` — (conn, username, password); bảng/cột cấu hình được; verify plaintext + bcrypt; lazy hash-on-login tại chỗ; không trả cột password
- [x] 1.8 `RedirectSanitizer` — chỉ path nội bộ (chặn `http://`, `//`, backslash, non-`/`), fallback portal. Tổng quát hoá `allowed_pages`
- [x] 1.9 `Guard` — chưa login → redirect `loginUrl?redirect=<uri>`; whitelist path public (exact + prefix `/`)
- [x] 1.10 `IdentitySync` (tùy chọn) — re-đọc cột cấu hình (map DB→session key) theo `user_id` mỗi request, once-per-request. Tổng quát hoá sync helper nhansuhtdb
- [x] 1.11 App login: `public/index.php` (GET form + POST verify/sanitize + đã-login-redirect + session_regenerate), `logout.php`, `portal.php`, `views/login.php`, CSRF
- [x] 1.12 `README.md` — hợp đồng 2 điểm chạm + bảng session key chuẩn + shim `id` lichkehoach + bảng env
- [~] 1.13 ĐÃ test app auth end-to-end qua HTTP (built-in server): form+CSRF, Guard chặn, login đúng→302+cookie `HTSSOSESSID path=/ 7d HttpOnly`, portal sau login, đã-login-bỏ-form (SSO), logout huỷ phiên, lazy-upgrade plaintext→bcrypt. 20/20 unit + 7/7 HTTP pass. **Còn lại (→ GĐ1):** SSO chéo ≥2 dự án thật cần tích hợp package vào lichkehoach + nhansuhtdb trước

## 2. Giai đoạn 1 — Tích hợp (chưa migrate gì; **upgrade-on-login TẮT** suốt rollout)

> **Sửa plan (môi trường thật có NHIỀU dự án):** không cắt đồng loạt được. Giữ
> `SHARED_AUTH_UPGRADE_ON_LOGIN` TẮT → authV2 verify cả plaintext+bcrypt nhưng KHÔNG ghi hash →
> mọi dự án chưa tích hợp vẫn so plaintext, không vỡ. Tích hợp dần từng dự án; chỉ bật hash ở GĐ2
> khi dự án CUỐI đã tích hợp. Đã thêm công tắc: `EnvConfig::upgradeOnLogin()` + option `upgradeOnLogin`.
- [x] 2.0 Công tắc rollout `SHARED_AUTH_UPGRADE_ON_LOGIN` (mặc định TẮT) trong `EnvConfig`/`CredentialVerifier`; login app truyền vào; README + bất biến (a) cập nhật
- [x] 2.0b Chốt an toàn rollout: `SessionContract::isLoggedIn()` chỉ công nhận phiên có dấu ấn `logged_in_at` (do authV2 ghi). Phiên cũ/kế thừa chỉ có `user_id` → CHƯA login → buộc login lại qua authV2. Khi deploy authV2, ai chưa qua authV2 đều bị đăng xuất (kết hợp đổi session_name). Unit 4/4 + re-verify lichkehoach SSO còn nguyên

- [x] 2.1 Path public lichkehoach: `index.php` (xem read-only, toggle inline giữ nguyên) + `api/load_tasks.php` (feed cho view đó, đọc mềm `$_SESSION['name'] ?? ''`). Còn lại (`addsum/checked/del/delstt/edit/xacnhan_phancong/xoa_anh`) = redirect-guard → `Guard::requireLogin()`; `get_data.php` = API trả 403 JSON (giữ nhánh riêng, chỉ đổi check sang `SessionContract::isLoggedIn()`, KHÔNG dùng Guard-302 vì gọi qua XHR). `$_SESSION['id']` KHÔNG có nơi nào đọc → không cần shim
- [x] 2.2 lichkehoach: `src/session.php` → shim mỏng require `authV2/bootstrap.php` + giữ hàm tên cũ (khoiTaoSession/lkhGiaHanCookieSession/huyPhienDangNhap) ủy quyền package → 15 chỗ include khỏi đổi. session_name LKHSESSID→HTSSOSESSID. `$_SESSION['id']` không nơi nào đọc → không cần shim
- [x] 2.3 lichkehoach: `login.php`+`login_action.php` → forwarder 302 sang authV2 login (mang ?redirect=); **xóa nhánh so `$pass == $db_password`**
- [x] 2.4 lichkehoach: 6 file điều hướng (addsum/checked/delstt/edit/xacnhan_phancong/del) → `(new Guard())->requireLogin()`; 2 file API (get_data/xoa_anh) → `SessionContract::isLoggedIn()` giữ 403; index.php giữ public + toggle read-only
- [x] 2.5 lichkehoach: logout.php chạy qua shim (`huyPhienDangNhap()`=`SessionBootstrap::destroy()`), redirect `login.php`→forwarder→authV2. Test HTTP thật: Guard chặn✓ SSO chéo✓ redirect-back✓ logout lan✓ DB vẫn plaintext✓ (13 file lint sạch)
- [x] 2.6 nhansuhtdb: KHÔNG sửa 30+ `session_start()` rải rác — dùng `auto_prepend_file` (mod_php, đã xác nhận SAPI=apache2handler) trong `.htaccess` trỏ `authV2/prepend.php` → gọi `SessionBootstrap::configure()` (mới: cấu hình phiên nhưng KHÔNG start, để app tự start, không notice). Tách `authV2/autoload.php` khỏi `bootstrap.php`. `index.php`: `$logged_in`→`SessionContract::isLoggedIn()`, bỏ ép `$page='login'`, thêm `Guard::requireLogin()`, `?logout`→authV2 logout. `includes/auth_check.php` GIỮ NGUYÊN (là authz — ngoài ranh giới package)
- [x] 2.7 nhansuhtdb: `auth_controller.php` → forwarder mỏng sang authV2 (login+logout); **gỡ so plaintext `=== $password`, gỡ remember-me `user_login`, gỡ trang `?action=debug` dump bảng user**. Bối cảnh nhân viên (ho_ten/vi_tri_ten/phong_ban/...) mà login cũ dựng → chuyển vào `session_auth_sync_helper.php` (`syncEmployeeContextFromNhanSu`, dựng lại từ user_id mỗi request, dùng name THÔ khớp `nhan_vien.ma_nv`). Test HTTP thật 7/7: login→302, bối cảnh repopulated đúng (NGUYỄN THẢO/Công Nhân/KỸ THUẬT PHÒNG), index 200, SSO chéo lichkehoach 200, logout lan, DB 149/0 plaintext
- [ ] 2.8 **Deploy đồng loạt trong một cửa sổ** (đổi `session_name` = logout toàn hệ thống 1 lần) + thông báo nội bộ trước. ⚠️ Production XAMPP: sửa path trong `.htaccess` (`C:/xampp/htdocs/authV2/prepend.php`); nếu là PHP-FPM/CGI (không phải mod_php) thì thay `php_value` bằng `.user.ini` (`auto_prepend_file=...`)
- [x] 2.9 **Cổng kiểm tra**: grep sạch ở lichkehoach + nhansuhtdb — không còn `== $db_password`/`=== $password`/`md5(`/`sha1(` quanh password lúc LOGIN, không còn setter `user_login`. Còn tồn (→ GĐ2/3): `views/login.php` (đọc cookie `user_login`) nay là code chết do Guard redirect; `UserAccountService`/trang `user_management` vẫn GHI password plaintext = điểm ghi phải chuyển `password_hash` ở task 3.1/3.2

## 3. Giai đoạn 2 — Migration password chạy tự nhiên

- [ ] 3.1 Grep & liệt kê MỌI điểm GHI password: trang đổi mật khẩu, admin tạo user, script import (`UPDATE ... password`, `INSERT ... user`)
- [ ] 3.2 Chuyển TẤT CẢ điểm ghi ở 3.1 sang `password_hash` **cùng đợt** (sót một điểm = plaintext chui lại)
- [ ] 3.3 Theo dõi định kỳ: đếm bản ghi `user.password` chưa mang prefix `$2`; đứng yên → còn điểm ghi plaintext sót

## 4. Giai đoạn 3 — Chốt sổ

- [ ] 4.1 Sau 2–4 tuần: phần còn plaintext (user không hoạt động) → force-hash bằng script 1 lần, hoặc reset + thông báo
- [ ] 4.2 Bảng sạch 100% → gỡ nhánh so plaintext khỏi `CredentialVerifier`; bump version package
- [ ] 4.3 Gỡ code chết: form login cũ, logout cũ, remember-me cookie ở nhansuhtdb (`views/login.php` còn
  đọc cookie `user_login`). sangkienct đã dọn ở 5.1b.
  Cột `mysqli`.`user`.`remember_token`/`token_expiry` giờ KHÔNG dự án nào đọc/ghi → cân nhắc DROP (đổi
  schema dùng chung, cần chốt riêng)

## 5. Nhân rộng

> Thêm dự án = thêm prefix vào `EnvConfig::projects()` (mặc định) hoặc `SHARED_AUTH_PROJECTS`. Đây là
> allowlist DUY NHẤT của `?redirect=`; quên bước này thì login xong user rơi về portal chứ không quay lại.

- [x] 5.1 **sangkienct** — tích hợp theo mẫu lichkehoach (nhóm 2: sửa trực tiếp, chỉ 8 `session_start()`).
  - `includes/auth.php` (mới): require `authV2/bootstrap.php` + dựng lại bối cảnh mỗi request từ
    `mysqli`.user (name/ma_nv/full_name/role/signature_image) và `quan_ly_nhan_su`.nhan_vien
    (ho_ten/vi_tri/phong_ban → `vai_tro`, `co_quyen_tao_yeu_cau`) — thay đoạn auth_controller cũ dựng
    một lần lúc login. Lưu giá trị THÔ (view sangkienct tự escape), prepared statement thay nối chuỗi.
  - `index.php`: bỏ `session_start()` + auto-login qua cookie; `checkAuth()` → `Guard::requireLogin()`
    (trang public home/view/add giữ nguyên không cần login); `?logout` → `EnvConfig::logoutUrl()`.
  - `login.php` / `logout.php` / `controllers/auth_controller.php` / `controllers/auth_controller1.php`
    → forwarder 302. **Gỡ**: so plaintext `$row['password'] === $password`, remember-me token +
    cookie `user_login`/`remember_token`, `ALTER TABLE user` ngầm mỗi request, `error_log` in mật khẩu.
  - `statistics.php`, `database/upload_handler.php` → `session_start()` → bootstrap chung;
    `api/convert_video.php` giữ 403 JSON (XHR), chỉ đổi check sang `SessionContract::isLoggedIn()`.
  - `EnvConfig::projects()` + `views/detail.php` (link `../auth/login.php` hỏng → `login.php`).
  - Test HTTP thật: Guard 302✓ login→302 về đúng `?action=stats`✓ bối cảnh dựng lại khớp DB
    (ĐẶNG THỊ THU MỸ - Quản Đốc - QUẢN LÝ XƯỞNG TRƯỚC)✓ SSO chéo nhansuhtdb+lichkehoach✓ portal có
    sangkienct✓ logout lan ra mọi dự án✓ DB vẫn 149/0 plaintext✓. Unit 57/57 pass.
  - sangkienct KHÔNG có điểm GHI `mysqli`.`user`.`password` (các INSERT user là bảng `user` của DB
    `sangkienct`, khác bảng auth) → không phải sửa gì ở task 3.2.
- [x] 5.1b Dọn code chết sangkienct (không tham chiếu ở đâu, đã xác nhận bằng grep trước khi xoá):
  `views/login.php` + `login_1.php` (form login cũ), `controllers/auth_controller1.php` (bản login cũ
  thứ hai, chưa từng có form nào trỏ tới), `index_nhansu.php` (require `auth.php` không tồn tại — hỏng
  từ trước), `db_add_token_columns.php` (script thêm cột remember-me). GIỮ `controllers/auth_controller.php`
  làm forwarder cho POST/bookmark cũ (đúng mẫu `login_action.php` của lichkehoach). Smoke test lại 10/10 pass.
  Không đụng: `views/header_new.php` và `logincu.rar` (chết nhưng ngoài phạm vi auth, có thể là bản nháp/sao lưu).
- [x] 5.2 **khsanxuat** — tích hợp theo mẫu nhansuhtdb (nhóm 2: 25 `session_start()` rải rác 23 file).
  - `.htaccess`: `php_value auto_prepend_file "C:/laragon/www/authV2/prepend.php"` → mọi `session_start()`
    cũ tham gia phiên `HTSSOSESSID` mà không phải sửa 23 file.
  - `bootstrap.php` (điểm neo có sẵn, ~60 file require): `session_start()` → `authV2/bootstrap.php`,
    rồi gọi `khsxSyncSessionContext($connect)`.
  - `includes/security/session_context.php` (mới): dựng lại mỗi request từ `mysqli`.`user`
    (`username`, `full_name`) và `mysqli`.`user_app_role` (`app_role`, luật `app IN ('khsanxuat','*')`,
    `'*'` thắng — chép nguyên từ login cũ). Giá trị THÔ (header.php + activity_logger tự escape).
    User bị xoá giữa phiên → xoá sạch bối cảnh + quyền. **Đổi hành vi có chủ ý:** role trước kia nạp
    1 lần lúc login (thu hồi quyền chỉ hiệu lực sau khi login lại), nay đọc mỗi request → hiệu lực ngay.
  - `includes/security/auth-helper.php`: `isLoggedIn()` → `SessionContract::isLoggedIn()`,
    `requireLogin()` → `Guard::requireLogin()` (bỏ `$_SESSION['redirect_url']`). `userCan`/`requireFeature`/
    `$app_role_features` GIỮ NGUYÊN (authz — ngoài ranh giới package).
  - `account/login.php` + `login_action.php` + `logout.php` → forwarder 302. **Gỡ**: so plaintext
    `$pass == $db_password`, form login, `$_SESSION['id']` (không nơi nào đọc), cơ chế `redirect_url`.
    logout.php giữ nguyên check CSRF của link GET rồi mới chuyển sang logout chung.
  - `pages/theodoi.php` + `api/view_activity_logs.php`: check `$_SESSION['user_id']` thô → `requireLogin()`.
  - `account/change_password_action.php`: điểm ĐỌC password → hiểu cả plaintext lẫn bcrypt (bất biến a).
    Điểm GHI vẫn plaintext → **khsanxuat CÓ 2 điểm ghi `mysqli`.`user`.`password`**:
    `change_password_action.php:49` (UPDATE) và `register_action.php:46` (INSERT) → đầu vào task 3.2.
  - `EnvConfig::projects()` + `tests/unit/env_config_test.php` += `/khsanxuat/`.
  - Test HTTP thật 15/15 pass: Guard 302✓ redirect-back đúng path✓ `index.php` public còn mở✓ navbar
    khớp DB (Lê Văn Minh Đức)✓ SSO chéo lichkehoach+nhansuhtdb+sangkienct✓ portal có khsanxuat✓
    logout lan ra mọi dự án✓ DB vẫn 149/0 plaintext✓. Authz còn nguyên: user không có role → 403 ở
    `pages/settings.php`, admin → 200. Unit authV2 58/58, `dev-tools/tests/test_auth_helper.php` ALL PASS.
  - Code chết đã gỡ: `dev-tools/test_login_redirect.php` (test quy tắc redirect của login.php cũ; luật
    này nay ở `RedirectSanitizer` và đã có unit test riêng). Còn tồn: `dev-tools/backups/*` (bản sao lưu
    cũ, có `session_start()` + đọc `$_SESSION['username']`) — thư mục `dev-tools/` bị `.htaccess` chặn
    và nằm trong `.gitignore`, ngoài phạm vi auth.
- [x] 5.3a baonangsuat (nhóm 1, `classes/Auth.php` + `csrf.php` thành shim, 2026-11-08):
  - `session_start()` chỉ ở 2 điểm → thay bằng `require authV2/bootstrap.php`. `Auth.php` dựng lại bối cảnh
    (role, nhân viên, LINE) MỖI request từ DB; khoá session do dự án ghi có tiền tố `bns_` (`bns_line`,
    `bns_csrf_token`) vì phiên giờ dùng chung. `index.php` thành điểm vào (Guard) + hộp chọn LINE; API giữ JSON 401.
  - Gỡ: `Auth::login/logout`, `ALLOW_PLAINTEXT_PASSWORD`, rate-limit login riêng, form login, remember-username.
    Đăng xuất là điều hướng trang tới `logout.php` của authV2.
  - **baonangsuat KHÔNG có điểm GHI `mysqli`.`user`.`password`** (chỉ từng có 1 điểm đọc/so ở `Auth::login`, đã gỡ) → không ảnh hưởng task 3.2.
  - `EnvConfig::projects()` + `tests/unit/env_config_test.php` += `/baonangsuat/`.
  - Test: smoke_test 16/16 pass (user có LINE), authV2 unit 166/166, `tests/php/run.php` của baonangsuat 12/12.
    Production: thêm `/baonangsuat/|Báo năng suất` vào `SHARED_AUTH_PROJECTS`; chạy
    `SELECT name FROM user WHERE name <> UPPER(TRIM(name))` trước khi bật (`ma_nv` giờ luôn in hoa).
- [ ] 5.3 Mỗi dự án www còn lại (chamcong, ...): chạy `audit-checklist` → tích hợp theo đúng mẫu lichkehoach (nhóm 2), rồi vào chung tiến trình migration
