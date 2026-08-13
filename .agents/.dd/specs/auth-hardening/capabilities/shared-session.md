## MODIFIED Requirements

### Requirement: Storage theo môi trường

Local Laragon dùng file save_path riêng của hệ thống auth; production lưu phiên qua MySQL session
handler. Handler production phải được nối sẵn trong đường bootstrap chung, không để chỗ gọi tự truyền.

Khi môi trường là production mà không dựng được handler, hệ thống phải **dừng và báo lỗi**, không
được âm thầm rơi về `save_path` mặc định của php.ini — đó là save_path dùng chung với mọi ứng dụng
khác trên máy.

#### Scenario: Chạy trên production
- **WHEN** ứng dụng chạy ở môi trường production
- **THEN** phiên được lưu qua MySQL session handler thay vì file mặc định

#### Scenario: Không dựng được handler ở production
- **WHEN** môi trường là production nhưng không kết nối được nơi lưu phiên
- **THEN** request dừng với lỗi rõ ràng, không có phiên nào được tạo trên save_path dùng chung

#### Scenario: App login dùng đúng storage của môi trường
- **WHEN** trang login chạy ở production
- **THEN** phiên nó tạo nằm ở cùng storage với phiên mà các dự án khác đọc

### Requirement: Thời hạn phiên chuẩn hoá

Phiên hết hạn sau 7 ngày kể từ thao tác cuối; cookie được gia hạn ở mỗi request. Ngoài hạn theo hoạt
động đó còn một **trần tuyệt đối** tính từ `logged_in_at`: quá trần thì phải đăng nhập lại dù vẫn
đang hoạt động. Trần cấu hình được, đặt `0` là tắt.

#### Scenario: Gia hạn theo hoạt động
- **WHEN** user thao tác trong vòng 7 ngày kể từ lần trước
- **THEN** hạn cookie được đẩy tiếp 7 ngày, phiên không bị mất

#### Scenario: Vượt trần tuyệt đối
- **WHEN** phiên đã quá trần tuyệt đối kể từ `logged_in_at`, dù user vẫn dùng đều
- **THEN** phiên bị coi là chưa đăng nhập và user được đưa về trang login

#### Scenario: Tắt trần tuyệt đối
- **WHEN** trần được cấu hình bằng `0`
- **THEN** chỉ hạn 7 ngày theo hoạt động có hiệu lực, như hành vi trước đây

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
