# Thiết kế: Thư mục auth chuẩn PHP dùng chung (`authV2`)

> Phương pháp: **brainstorm**, supported by skill `php-shared-auth`. Bản **design** (đã được duyệt).
> Đây là artifact bền vững của brainstorm. Bản gốc plan-mode: `~/.claude/plans/playful-seeking-seal.md`.

## Context — vì sao làm

Nhiều dự án PHP chạy cùng host trên Laragon (`C:\laragon\www\*`) mỗi cái tự làm login riêng.
Đã xác nhận qua audit code 2 dự án chủ chốt:

- **Cùng một identity store**: cả `lichkehoach` và `nhansuhtdb` đều query `mysqli`.`user`
  (`nhansuhtdb/controllers/auth_controller.php:331` truy vấn thẳng `` `mysqli`.`user` ``;
  `lichkehoach/public/login_action.php:15` `SELECT id,name,password FROM user`). → SSO khả thi.
- **Nhưng đang lệch nhau** ở 4 điểm chặn thực tế:
  1. `session_name` khác nhau (`LKHSESSID` vs `PHPSESSID` mặc định) → **hiện KHÔNG share session**.
  2. So password **plaintext** ở cả hai (`login_action.php:24` dùng `==`; `auth_controller.php:58` dùng `===`).
  3. Session keys xung đột: lichkehoach `$_SESSION['id']` vs nhansuhtdb `$_SESSION['user_id']`.
  4. Guard rải rác (`!isset($_SESSION['name'])` ~10 entry point ở lichkehoach) / cookie remember-me tự chế 30 ngày ở nhansuhtdb.

Mục tiêu: một repo `authV2` = **trang login duy nhất + package dùng chung**, để user login một lần vào mọi dự án,
password nâng cấp dần sang bcrypt mà không khoá tài khoản ai. `lichkehoach` làm **mẫu tích hợp chuẩn** để nhân rộng.

**Ranh giới (quan trọng, dễ phình scope):** chỉ gom **authentication** (danh tính: ai đang đăng nhập).
**Authorization** (role/`vai_tro`/`co_quyen_tao_yeu_cau`...) **ở lại từng dự án**. Package không quản quyền.

## Quyết định đã chốt (không mở lại)

Từ skill + trả lời của user:
- SSO bằng **shared session cùng host** (cookie `path=/` + `session_name` thống nhất), KHÔNG Keycloak/OIDC.
- **Một trang login** tại path riêng (`/auth/`); dự án xoá form login riêng, chỉ giữ guard redirect.
- **Password: lazy hash-on-login** (verify cả plaintext lẫn bcrypt, nâng cấp tại chỗ khi login đúng).
- Đóng gói code chung thành **Composer package**; local dev dùng path repository.
- `authV2` là **mono-repo**: package (`src/`) + app login (`public/`) chung một repo.
- Identity store = **một bảng `user` chung** (`mysqli`.`user`).

## Kiến trúc `authV2` (mono-repo)

```
authV2/
├── composer.json          # PSR-4 autoload cho package + require nội bộ
├── src/                   # === PACKAGE shared-auth (thư viện mọi dự án require) ===
│   ├── SessionBootstrap   # điểm DUY NHẤT gọi session_start toàn hệ thống
│   ├── CredentialVerifier # (conn, username, password) -> user|null; plaintext+bcrypt; lazy upgrade
│   ├── Guard              # check session, chưa login -> redirect /auth/?redirect=; whitelist public
│   ├── SessionContract    # định nghĩa các key session chuẩn một chỗ (user_id, name, logged_in_at...)
│   ├── RedirectSanitizer  # validate ?redirect= chỉ path nội bộ, chặn open redirect
│   ├── IdentitySync       # (tùy chọn) re-đọc name/role từ user table mỗi request
│   └── EnvConfig          # local (HTTP, file session) vs production (HTTPS, Redis)
├── public/                # === APP auth/ (trang login) ===
│   ├── index.php          # GET hiện form login, POST xử lý (dùng Verifier + RedirectSanitizer)
│   ├── logout.php         # hủy session + xóa cookie -> về login
│   └── portal.php         # (nên có) trang đích mặc định liệt kê link các dự án
├── views/login.php        # form (tách khỏi logic)
└── README.md              # HỢP ĐỒNG tích hợp: 2 điểm chạm + danh sách session key chuẩn
```

Nguyên tắc: **giữ package mỏng**. Mỗi thứ thêm vào `src/` là thứ mọi dự án phải nâng cấp cùng nhau.
Nghi ngờ thì để ở `public/` (app), không đưa vào package.

### Trách nhiệm từng thành phần (đối chiếu code hiện có)

| Thành phần | Trách nhiệm | Tổng quát hoá từ |
|---|---|---|
| SessionBootstrap | Thống nhất `session_name`, cookie `path=/`, `httponly`/`samesite`/`secure` theo env, save_path/Redis. **Idempotent** (gọi 2 lần vô hại) | `lichkehoach/src/session.php` (đã làm tốt — nâng thành chuẩn chung) |
| CredentialVerifier | Nhận connection từ ngoài, đọc bảng/cột cấu hình được (mặc định `user.name`/`user.password`); hiểu cả plaintext lẫn `$2y$`; login đúng mà thấy plaintext thì `password_hash` ghi đè tại chỗ | thay `login_action.php:24` và `auth_controller.php:58` |
| Guard | Chưa login → redirect `/auth/?redirect=<uri>`; nhận whitelist path public (webhook/api/cron); tùy chọn "xem read-only" như trang index lichkehoach | thay `!isset($_SESSION['name'])` rải rác + `nhansuhtdb/includes/auth_check.php` |
| SessionContract | Chốt tên key chuẩn một chỗ; **giải quyết xung đột `id` vs `user_id`** | hoà giải lichkehoach(`id`) và nhansuhtdb(`user_id`) |
| RedirectSanitizer | Chỉ nhận path nội bộ, fallback trang mặc định | tổng quát hoá `allowed_pages` trong `login_action.php:30` |
| IdentitySync | Re-đọc `name`/`role` từ `user` theo `user_id` mỗi request (nếu dự án cần role tươi) | `nhansuhtdb/helpers/session_auth_sync_helper.php` (đã có sẵn — bê thẳng ý tưởng) |
| EnvConfig | Một điểm phân biệt local/prod, đọc từ env | gộp cờ `require_https`/`secure` đang rải ở `login_config.php` |

## Hợp đồng tích hợp — mỗi dự án sau khi tích hợp chỉ còn 2 điểm chạm

1. `require` **SessionBootstrap** từ package (thay MỌI `session_start()` cũ).
2. Gọi **Guard** ở entry point (thay MỌI check `$_SESSION` rải rác).

Dự án **gỡ bỏ**: form login riêng, code so password, code logout, remember-me cookie tự chế.
Dự án **giữ lại**: logic phân quyền riêng (role → menu/permission), app-state session keys riêng
(prefix theo dự án để tránh đụng độ).

## Quyết định đã chốt (7 điểm)

| # | Quyết định | ĐÃ CHỐT |
|---|---|---|
| 1 | **Session key contract** (`id` vs `user_id`) | ✅ Chuẩn hoá **`$_SESSION['user_id']`** = giá trị cột `mysqli`.`user`.`id`. nhansuhtdb giữ nguyên; **lichkehoach** thêm shim để chỗ nào đọc `$_SESSION['id']` cũ vẫn chạy trong lúc chuyển. Đổi kèm `session_name` = **logout toàn hệ thống 1 lần** |
| 2 | **`session_name` thống nhất** | ✅ `HTSSOSESSID` cho mọi dự án |
| 3 | **Session lifetime** | ✅ **7 ngày** (idle timeout, gia hạn mỗi request — như lichkehoach) |
| 4 | **Session storage production** | ✅ **MySQL session handler** (chưa dùng Redis). Local Laragon giữ file save_path riêng. EnvConfig tách 2 môi trường |
| 5 | **Trang đích mặc định** | ✅ Portal riêng `authV2/public/portal.php` |
| 6 | **Tên + namespace package** | ✅ `hoatho/shared-auth`, PSR-4 `Hoatho\SharedAuth\` |
| 7 | **Phân phối package** | ✅ Local: composer **path repository**; Prod: **private VCS repo** trong composer.json |

## Thứ tự triển khai (rollout) — có điểm KHÔNG được đảo

**2 điểm bất biến:** (a) mọi điểm ĐỌC password phải hiểu cả 2 định dạng **trước** khi bất kỳ đâu GHI hash;
(b) cột `password` phải `VARCHAR(255)` **trước** lần ghi hash đầu tiên (hash bị cắt = khoá tài khoản vĩnh viễn).

- **Giai đoạn 0 — Chuẩn bị (chưa đụng production)**
  - Kiểm tra & `ALTER` `mysqli`.`user`.`password` lên `VARCHAR(255)` nếu đang < 60 (blocker, làm trước tiên).
  - Dựng `authV2` (package + app login) chạy được trên Laragon.
  - Chốt các quyết định ở bảng trên (đặc biệt #1, #2).
  - Test cục bộ: login ở `/auth/` → vào cả lichkehoach lẫn nhansuhtdb không bị hỏi lại; logout lan cả hai.

- **Giai đoạn 1 — Hợp nhất điểm đọc (chưa migrate gì)**
  - Tích hợp package vào **lichkehoach (mẫu chuẩn)** trước, rồi **nhansuhtdb**:
    bootstrap chung + Guard + Verifier (Verifier hiểu cả 2 định dạng, bật luôn nhánh upgrade-on-login
    vì không dự án nào còn tự so password nữa → thoả điểm bất biến (a)).
  - Deploy **đồng loạt** trong một cửa sổ (đổi `session_name` = logout toàn bộ 1 lần, báo trước nội bộ).
  - **Cổng kiểm tra** trước khi sang GĐ2: grep xác nhận không còn `== $db_password`,
    `=== $password`, `md5(`/`sha1(` quanh password ở bất kỳ dự án nào.

- **Giai đoạn 2 — Migration chạy tự nhiên**
  - User login → plaintext nâng cấp dần sang bcrypt.
  - **Chuyển MỌI điểm GHI password sang `password_hash` cùng đợt** (điểm bất biến): trang đổi mật khẩu,
    admin tạo user, script import — cần grep `UPDATE ... password` / `INSERT ... user` trước khi bắt đầu.
  - Theo dõi: đếm bản ghi `password` chưa có prefix `$2`.

- **Giai đoạn 3 — Chốt sổ**
  - Sau 2–4 tuần: số còn plaintext là user không hoạt động → force-hash nốt bằng script 1 lần (hoặc reset).
  - Bảng sạch 100% → gỡ nhánh so plaintext khỏi Verifier, bump version package.
  - Gỡ code chết: form login cũ, logout cũ, remember-me cookie tự chế ở nhansuhtdb.

## Rủi ro & phòng ngừa

| Rủi ro | Khi nào | Phòng ngừa |
|---|---|---|
| Khoá tài khoản do hash cắt cụt | Ghi hash khi cột `password` còn ngắn | GĐ0 `ALTER VARCHAR(255)` trước tiên |
| Login "lúc được lúc không" | Còn dự án so plaintext sau khi nơi khác đã hash | Cổng kiểm tra cuối GĐ1 (grep sạch) |
| Plaintext chui lại vào bảng | Sót điểm GHI password (admin tạo user, import) | Liệt kê điểm ghi ở GĐ2, chuyển cùng đợt |
| Webhook/cron/API bị Guard chặn | Quên whitelist path public | Lập danh sách public per-project trước khi gắn Guard |
| Logout toàn bộ bất ngờ | Deploy đổi `session_name` lệch nhịp | Deploy đồng loạt + thông báo nội bộ |
| Xung đột session key | `id` vs `user_id` (và role) giữa dự án | Chốt SessionContract (#1) trước khi gộp; app-state key prefix theo dự án |

## Phạm vi bản kế hoạch này

- **Trong scope**: thiết kế `authV2` (package + app); tích hợp **lichkehoach làm mẫu chuẩn**; đường đi cho nhansuhtdb.
- **Ngoài scope (đợt sau)**: audit + tích hợp các dự án còn lại trong `www` (chamcong, khsanxuat, baonangsuat...)
  — nhân rộng theo đúng mẫu lichkehoach; mỗi dự án chạy lại audit-checklist trước khi tích hợp.

## Xác minh (khi đã code, ngoài phạm vi brainstorm)

- Login tại `http://localhost/authV2/public/` → redirect về `?redirect=` hợp lệ.
- Mở lichkehoach + nhansuhtdb: không bị hỏi login lại (SSO), logout lan cả hai.
- Login bằng account đang plaintext → sau login, cột `password` trong `mysqli`.`user` mang prefix `$2y$`.
- Guard: truy cập trực tiếp trang cần login khi chưa đăng nhập → bị redirect về `/auth/`.
- `RedirectSanitizer`: thử `?redirect=http://evil.com` → bị chặn, về trang mặc định.
