## MODIFIED Requirements

### Requirement: Storage theo môi trường

Phiên luôn lưu trong một thư mục save_path **riêng của hệ thống auth**, cấu hình qua
`SHARED_AUTH_SESSION_PATH`; không dùng MySQL session handler (package không tự mở DB connection, và hợp
đồng 2 điểm chạm không có chỗ truyền handler). Local mặc định là `<php.ini session.save_path>/htsso-sessions`
(không phụ thuộc biến `TEMP`); production **bắt buộc** khai báo, thư mục nằm ngoài webroot, Apache ghi
được, không dùng chung với ứng dụng khác.

Khi thư mục thiếu hoặc không ghi được (production không khai báo env cũng tính là thiếu), hệ thống phải
**dừng và báo lỗi** — request web trả HTTP 500 — không được âm thầm rơi về `save_path` mặc định của
php.ini: đó là save_path dùng chung với mọi ứng dụng khác trên máy, GC của app khác sẽ xoá phiên SSO.

#### Scenario: Chạy trên production
- **WHEN** ứng dụng chạy ở môi trường production với `SHARED_AUTH_SESSION_PATH` hợp lệ
- **THEN** phiên được lưu thành file trong thư mục đó, không phải save_path của php.ini

#### Scenario: Production thiếu hoặc sai thư mục phiên
- **WHEN** môi trường là production nhưng `SHARED_AUTH_SESSION_PATH` không đặt, hoặc thư mục không tồn tại/không ghi được
- **THEN** request dừng với lỗi rõ ràng (HTTP 500 trên SAPI web, lỗi vào `error_log`), không có phiên nào được tạo trên save_path dùng chung

#### Scenario: Thư mục phiên không nằm trong webroot
- **WHEN** hệ thống được triển khai
- **THEN** file phiên không truy cập được qua web; Apache chỉ phục vụ `public/`, mọi đường dẫn khác của authV2 trả 403

#### Scenario: App login dùng đúng storage của môi trường
- **WHEN** trang login chạy ở production
- **THEN** phiên nó tạo nằm ở cùng thư mục với phiên mà các dự án khác đọc

### Requirement: Thời hạn phiên chuẩn hoá

Phiên hết hạn sau **30 ngày** kể từ thao tác cuối (`EnvConfig::SESSION_LIFETIME`); cookie được gia hạn ở
mỗi request — kể cả ở dự án chạy `auto_prepend_file`, vì `Guard::requireLogin()` tự mở/gia hạn phiên. Ngoài
hạn theo hoạt động đó còn một **trần tuyệt đối** tính từ `logged_in_at`: mặc định 30 ngày
(`SHARED_AUTH_ABSOLUTE_LIFETIME`, đơn vị giây), quá trần thì phải đăng nhập lại dù vẫn đang hoạt động. Đặt `0` là
tắt. Phiên thiếu `logged_in_at` không bao giờ được coi là đã đăng nhập; khi trần bật, `logged_in_at` không phải
số nguyên dương cũng bị từ chối.

#### Scenario: Gia hạn theo hoạt động
- **WHEN** user thao tác trong vòng 30 ngày kể từ lần trước
- **THEN** hạn cookie được đẩy tiếp 30 ngày, phiên không bị mất (trong giới hạn trần tuyệt đối)

#### Scenario: Vượt trần tuyệt đối
- **WHEN** phiên đã quá trần tuyệt đối kể từ `logged_in_at`, dù user vẫn dùng đều
- **THEN** phiên bị coi là chưa đăng nhập và user được đưa về trang login

#### Scenario: Tắt trần tuyệt đối
- **WHEN** trần được cấu hình bằng `0`
- **THEN** chỉ hạn 30 ngày theo hoạt động có hiệu lực, như hành vi trước đây
## ADDED Requirements

### Requirement: Cookie phiên quyết định theo môi trường, không theo từng request

Ở production, cookie phiên luôn mang cờ `Secure`, bất kể request đang đến qua giao thức nào. Header
do client gửi không được phép hạ cờ này xuống.

#### Scenario: Request HTTP lọt vào production
- **WHEN** một request HTTP (không TLS) tới hệ thống đang chạy production
- **THEN** cookie phiên vẫn mang cờ `Secure`, không có phiên nào đi trần qua HTTP

#### Scenario: Header giả mạo giao thức
- **WHEN** client gửi `X-Forwarded-Proto` không đúng với thực tế
- **THEN** cờ `Secure` vẫn theo môi trường đã cấu hình

### Requirement: Phiên phải mở trước khi ai đó đọc danh tính

Mọi điểm đọc danh tính dùng chung phải chắc chắn phiên đã được mở, kể cả ở dự án chạy chế độ
`auto_prepend_file` chỉ cấu hình phiên chứ không mở.

#### Scenario: Entry point quên mở phiên
- **WHEN** một trang của dự án đã tích hợp không tự gọi mở phiên rồi đi thẳng vào chốt chặn đăng nhập
- **THEN** phiên hiện có được nhận ra bình thường, không phát sinh vòng lặp chuyển hướng giữa dự án
  và trang login

### Requirement: Đồng bộ danh tính ghi giá trị thô, mỗi bộ cấu hình chạy độc lập

Bộ đồng bộ danh tính ghi vào phiên **giá trị thô đọc từ DB**, không mã hoá HTML — việc mã hoá thuộc
về nơi hiển thị. Cơ chế "chỉ chạy một lần mỗi request" tính riêng cho từng bộ cấu hình, không dùng
chung một cờ cho mọi bộ.

#### Scenario: Giá trị có ký tự đặc biệt
- **WHEN** một cột được đồng bộ chứa ký tự như `&` hoặc `'`
- **THEN** giá trị trong phiên khớp đúng giá trị trong DB, đối chiếu với cột khác của DB vẫn bằng nhau

#### Scenario: Hai bộ đồng bộ khác nhau trong một request
- **WHEN** một request dùng hai bộ đồng bộ với bản đồ cột khác nhau
- **THEN** cả hai đều chạy và ghi đủ phần của mình vào phiên

#### Scenario: Cùng một bộ gọi nhiều lần
- **WHEN** cùng một bộ đồng bộ được gọi nhiều lần trong một request
- **THEN** chỉ truy vấn DB một lần
