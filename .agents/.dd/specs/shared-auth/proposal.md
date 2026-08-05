## Why

Các dự án PHP nội bộ chạy cùng host Laragon (`C:\laragon\www\*`) mỗi cái tự làm login,
so password **plaintext**, dùng `session_name` khác nhau nên **không share được session**,
và đặt session key lệch nhau (`id` vs `user_id`). Hệ quả: user phải login lại ở từng dự án,
password không được bảo vệ, và mỗi lần sửa auth phải sửa n chỗ.

Đã xác nhận qua audit: `lichkehoach` và `nhansuhtdb` cùng dùng identity store `mysqli`.`user`,
nên hợp nhất authentication là khả thi. Chi tiết motivation + hiện trạng: xem `design.md`.

## What Changes

- Dựng repo mono `authV2` = **Composer package `hoatho/shared-auth`** (`src/`) + **app trang login `/auth/`** (`public/`).
- Mỗi dự án tiêu thụ package qua **2 điểm chạm**: SessionBootstrap thay mọi `session_start()`,
  Guard thay mọi check `$_SESSION` rải rác. Gỡ form login riêng, code so password, logout, remember-me tự chế.
- **Lazy password migration**: verify cả plaintext lẫn bcrypt, nâng cấp hash khi login đúng.
- Chuẩn hoá `session_name = HTSSOSESSID`, session key `$_SESSION['user_id']`, lifetime 7 ngày.
- Tích hợp `lichkehoach` làm **mẫu chuẩn**, rồi `nhansuhtdb`.
- **Ranh giới:** chỉ gom authentication; authorization (role/quyền) ở lại từng dự án.

## Capabilities

### New Capabilities
- `shared-session`: một phiên đăng nhập dùng chung cùng host (hiệu ứng SSO), thống nhất tên/thời hạn/storage, logout lan mọi dự án.
- `credential-verification`: xác thực username/password hiểu cả plaintext lẫn bcrypt, tự nâng cấp hash khi login (lazy migration).
- `access-guard`: chặn truy cập chưa đăng nhập, redirect về `/auth/` giữ `?redirect=` an toàn, whitelist path public.
- `login-app`: trang login/logout/portal duy nhất; đã có session thì redirect đi ngay (tạo hiệu ứng SSO).

### Modified Capabilities
<!-- Không có capability spec sẵn có nào bị đổi requirement; các dự án tiêu thụ được mô tả trong tasks. -->

## Impact

- **Mới**: repo `authV2` (package + app login). Namespace PSR-4 `Hoatho\SharedAuth\`.
- **DB**: `ALTER mysqli.user.password VARCHAR(255)` (blocker trước khi hash); về sau cột password chuyển dần sang `$2y$`.
- **lichkehoach**: thay `src/session.php`, `public/login_action.php`, ~10 điểm guard inline, logout; thêm shim `id`→`user_id`.
- **nhansuhtdb**: thay `session_start()` + `includes/auth_check.php` + `auth_controller.php`; gỡ remember-me cookie.
- **Sự kiện 1 lần**: đổi `session_name` = logout toàn hệ thống, cần deploy đồng loạt + thông báo nội bộ.
- **Đợt sau (ngoài scope)**: nhân rộng cho các dự án www còn lại theo mẫu lichkehoach.
