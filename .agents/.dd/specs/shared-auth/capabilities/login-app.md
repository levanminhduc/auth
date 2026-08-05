## Purpose

Trang login/logout/portal duy nhất tại `/auth/`. Là nơi user nhập danh tính một lần;
nếu đã có phiên thì đưa đi ngay (không hiện lại form) — chính là hành vi tạo hiệu ứng SSO.

## ADDED Requirements

### Requirement: Form login xử lý GET/POST

`public/index.php` hiện form khi GET, xác thực khi POST bằng CredentialVerifier, dựng phiên chuẩn
qua SessionContract (`user_id`, `name`, `logged_in_at`).

#### Scenario: Login thành công
- **WHEN** user POST username/password đúng kèm CSRF token hợp lệ
- **THEN** `session_regenerate_id`, set `$_SESSION['user_id']` = `user.id`, rồi redirect theo `?redirect=` đã sanitize

#### Scenario: CSRF không hợp lệ
- **WHEN** POST thiếu/sai CSRF token
- **THEN** từ chối, quay lại form với thông báo lỗi, không dựng phiên

### Requirement: Đã đăng nhập thì bỏ qua form (hiệu ứng SSO)

Vào `/auth/` khi đã có phiên hợp lệ thì redirect đi luôn thay vì hiện form.

#### Scenario: Vào lại trang login khi đã login
- **WHEN** user đã có phiên mở `/auth/?redirect=<path nội bộ>`
- **THEN** được redirect thẳng tới path đó, không thấy form login

### Requirement: Logout và portal

`logout.php` hủy phiên + xóa cookie rồi về login. `portal.php` là trang đích mặc định liệt kê link các dự án.

#### Scenario: Vào /auth/ không có redirect
- **WHEN** user đã login mở `/auth/` mà không kèm `?redirect=`
- **THEN** được đưa tới portal liệt kê các dự án
