## Purpose

Xác thực username/password dựa trên bảng `mysqli`.`user`, hiểu cả mật khẩu plaintext (hiện trạng)
lẫn bcrypt, và nâng cấp dần plaintext sang hash một cách tự nhiên khi user login đúng.

## ADDED Requirements

### Requirement: Verifier nhận connection từ ngoài

CredentialVerifier nhận (mysqli connection, username, password) và trả về user hoặc null;
đọc bảng/cột cấu hình được (mặc định `user.name` / `user.password`). Package không tự quản DB connection.

#### Scenario: Đăng nhập đúng
- **WHEN** username khớp `user.name` và password khớp giá trị lưu (bất kể định dạng)
- **THEN** trả về bản ghi user (gồm `id`) để phía gọi dựng phiên

#### Scenario: Đăng nhập sai
- **WHEN** không tìm thấy `user.name` hoặc password không khớp
- **THEN** trả về null; phía gọi báo "sai tên đăng nhập hoặc mật khẩu"

### Requirement: Hiểu cả plaintext lẫn bcrypt

Verifier xác thực đúng dù cột password đang lưu plaintext hay hash `$2y$`.

#### Scenario: Password đang là plaintext
- **WHEN** cột `password` của user là chuỗi plaintext và người dùng nhập đúng chuỗi đó
- **THEN** xác thực thành công

#### Scenario: Password đã là bcrypt
- **WHEN** cột `password` là hash `$2y$...` và người dùng nhập đúng
- **THEN** xác thực thành công qua `password_verify`

### Requirement: Nâng cấp hash khi login (lazy migration)

Khi login đúng mà password đang plaintext, verifier ghi đè hash bcrypt tại chỗ ngay trong request đó.

#### Scenario: Tự động nâng cấp
- **WHEN** user có password plaintext login thành công
- **THEN** cột `password` của user được cập nhật thành `password_hash($password)` ngay sau đó

#### Scenario: Cột password đủ dài trước khi ghi hash
- **WHEN** hệ thống chuẩn bị ghi hash lần đầu
- **THEN** cột `password` phải là `VARCHAR(255)` (đã ALTER ở Giai đoạn 0) để hash không bị cắt cụt
