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

Phiên hết hạn sau 30 ngày kể từ thao tác cuối; cookie được gia hạn ở mỗi request. (Ban đầu spec ghi 7 ngày;
đã đổi thành 30 ngày kèm trần tuyệt đối 30 ngày tính từ lúc đăng nhập — xem change `auth-hardening`,
`capabilities/shared-session.md`.)

#### Scenario: Gia hạn theo hoạt động
- **WHEN** user thao tác trong vòng 30 ngày kể từ lần trước
- **THEN** hạn cookie được đẩy tiếp 30 ngày, phiên không bị mất (trong giới hạn trần tuyệt đối)

### Requirement: Storage theo môi trường

Cả local và production dùng file trong một thư mục save_path **riêng** của hệ thống auth, không phải save_path
chung của php.ini. Local mặc định `<php.ini session.save_path>/htsso-sessions`; production bắt buộc khai báo
`SHARED_AUTH_SESSION_PATH` (thư mục ngoài webroot, không dùng chung với app khác). Thiếu hoặc không ghi được
thì dừng với lỗi (fail-fast), không rơi về save_path mặc định. Không dùng MySQL session handler (package không
tự quản DB connection; hợp đồng 2 điểm chạm không có chỗ truyền handler) — quyết định 1 của change `auth-hardening`.

#### Scenario: Chạy trên production
- **WHEN** ứng dụng chạy ở môi trường production với `SHARED_AUTH_SESSION_PATH` hợp lệ
- **THEN** phiên được lưu thành file trong thư mục riêng đó thay vì save_path mặc định

#### Scenario: Production thiếu thư mục phiên
- **WHEN** production không đặt `SHARED_AUTH_SESSION_PATH` hoặc thư mục không ghi được
- **THEN** request dừng với lỗi rõ ràng (HTTP 500 trên SAPI web), không tạo phiên trên save_path chung

### Requirement: Logout lan toàn hệ thống

Hủy phiên ở trang logout chung làm mọi dự án cùng host mất đăng nhập.

#### Scenario: Logout một chỗ, mất phiên mọi nơi
- **WHEN** user bấm logout ở `/auth/logout.php`
- **THEN** phiên bị hủy + cookie bị xóa, và mọi dự án khác đều thấy chưa đăng nhập
