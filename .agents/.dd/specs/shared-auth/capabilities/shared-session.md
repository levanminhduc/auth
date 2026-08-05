## Purpose

Cung cấp một phiên đăng nhập dùng chung cho mọi dự án PHP cùng host, để user login một lần
là vào được tất cả (hiệu ứng SSO), và logout ở một nơi thì mất phiên ở tất cả.

## ADDED Requirements

### Requirement: Bootstrap phiên thống nhất, idempotent

Mọi dự án khởi tạo phiên qua một điểm chung duy nhất với cùng `session_name`, cookie `path=/`,
cùng thời hạn — gọi nhiều lần trong một request không gây lỗi.

#### Scenario: Hai dự án cùng host chia sẻ phiên
- **WHEN** user đã login ở `/auth/` rồi mở một dự án khác cùng host
- **THEN** dự án đó thấy phiên hợp lệ (cùng cookie `HTSSOSESSID`, `path=/`) và không hỏi login lại

#### Scenario: Gọi bootstrap hai lần trong một request
- **WHEN** một dự án legacy include bootstrap rồi vô tình gọi lại
- **THEN** không phát sinh lỗi/warning và phiên hiện tại được giữ nguyên

### Requirement: Thời hạn phiên chuẩn hoá

Phiên hết hạn sau 7 ngày kể từ thao tác cuối; cookie được gia hạn ở mỗi request.

#### Scenario: Gia hạn theo hoạt động
- **WHEN** user thao tác trong vòng 7 ngày kể từ lần trước
- **THEN** hạn cookie được đẩy tiếp 7 ngày, phiên không bị mất

### Requirement: Storage theo môi trường

Local Laragon dùng file save_path riêng của hệ thống auth; production dùng MySQL session handler.

#### Scenario: Chạy trên production
- **WHEN** ứng dụng chạy ở môi trường production
- **THEN** phiên được lưu qua MySQL session handler thay vì file mặc định

### Requirement: Logout lan toàn hệ thống

Hủy phiên ở trang logout chung làm mọi dự án cùng host mất đăng nhập.

#### Scenario: Logout một chỗ, mất phiên mọi nơi
- **WHEN** user bấm logout ở `/auth/logout.php`
- **THEN** phiên bị hủy + cookie bị xóa, và mọi dự án khác đều thấy chưa đăng nhập
