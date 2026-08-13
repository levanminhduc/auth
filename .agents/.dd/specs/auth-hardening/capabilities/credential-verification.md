## MODIFIED Requirements

### Requirement: Hiểu cả plaintext lẫn bcrypt

Verifier xác thực đúng dù cột password đang lưu plaintext hay hash. Việc nhận biết "đã là hash" dựa
trên khả năng nhận diện thuật toán của PHP, không dựa trên tiền tố ký tự cứng — để hash sinh bởi
`password_hash()` luôn được nhận ra kể cả khi thuật toán mặc định của PHP đổi.

#### Scenario: Password đang là plaintext
- **WHEN** cột `password` của user là chuỗi plaintext và người dùng nhập đúng chuỗi đó
- **THEN** xác thực thành công

#### Scenario: Password đã là bcrypt
- **WHEN** cột `password` là hash `$2y$...` và người dùng nhập đúng
- **THEN** xác thực thành công qua `password_verify`

#### Scenario: Thuật toán hash mặc định đổi
- **WHEN** cột `password` chứa hash do `password_hash()` sinh bằng thuật toán không phải bcrypt
- **THEN** verifier vẫn coi đó là hash và xác thực qua `password_verify`, không đem so như plaintext

### Requirement: Verifier nhận connection từ ngoài

CredentialVerifier nhận (mysqli connection, username, password) và trả về user hoặc null; đọc
bảng/cột cấu hình được (mặc định `user.name` / `user.password`). Package không tự quản DB connection.
Verifier chỉ đọc những cột nó cần cho việc xác thực và dựng danh tính, không lấy về cả bản ghi.

#### Scenario: Đăng nhập đúng
- **WHEN** username khớp `user.name` và password khớp giá trị lưu (bất kể định dạng)
- **THEN** trả về `id` và `name` để phía gọi dựng phiên

#### Scenario: Đăng nhập sai
- **WHEN** không tìm thấy `user.name` hoặc password không khớp
- **THEN** trả về null; phía gọi báo "sai tên đăng nhập hoặc mật khẩu"

#### Scenario: Không mang cột thừa ra khỏi verifier
- **WHEN** bảng `user` có thêm cột không liên quan tới xác thực
- **THEN** giá trị các cột đó không nằm trong kết quả trả về

## ADDED Requirements

### Requirement: Làm mới hash khi tham số đã lỗi thời

Khi login đúng bằng một hash được sinh với tham số khác tham số hiện hành, verifier ghi lại hash mới.
Việc này chịu chung công tắc với nâng cấp plaintext→hash, nên trong lúc rollout thì không ghi gì.

#### Scenario: Hash cũ tham số lỗi thời
- **WHEN** user login đúng, hash trong DB cần rehash, và công tắc nâng cấp đang BẬT
- **THEN** cột `password` được ghi lại bằng hash theo tham số hiện hành

#### Scenario: Công tắc nâng cấp đang tắt
- **WHEN** user login đúng, hash cần rehash, và công tắc nâng cấp đang TẮT
- **THEN** không có thao tác ghi nào lên bảng `user`

### Requirement: Không phân biệt được tài khoản có tồn tại hay không

Phản hồi cho người dùng khi sai mật khẩu và khi không có tài khoản phải giống nhau về nội dung.

#### Scenario: Thử username không tồn tại
- **WHEN** ai đó POST login với username không có trong `user`
- **THEN** thông báo trả về giống hệt trường hợp sai mật khẩu
