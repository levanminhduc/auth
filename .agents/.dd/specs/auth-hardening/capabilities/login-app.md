## ADDED Requirements

### Requirement: Trang login gửi security header bằng HTTP header

Các chỉ thị bảo vệ trang login được gửi bằng HTTP response header, không bằng thẻ `<meta>` — vì thẻ
`<meta>` bỏ qua `frame-ancestors`, đúng chỉ thị chống nhúng iframe mà trang login cần nhất.

Tối thiểu: chính sách nguồn nội dung có `frame-ancestors`, chính sách referrer, và chỉ thị chống đoán
kiểu nội dung.

#### Scenario: Nhúng trang login vào iframe
- **WHEN** một trang khác cố nhúng `/auth/` vào iframe
- **THEN** trình duyệt từ chối hiển thị

#### Scenario: Rò đường dẫn qua referrer
- **WHEN** người dùng từ trang login đi tiếp sang nơi khác
- **THEN** referrer gửi đi bị giới hạn theo chính sách, không mang nguyên URL kèm `?redirect=`

### Requirement: Tài nguyên trang login phải kiểm chứng được

Mọi tài nguyên CSS/JS trang login nạp phải là tài nguyên nội bộ, hoặc nếu nạp từ nguồn ngoài thì phải
kèm giá trị toàn vẹn để trình duyệt từ chối nội dung bị đổi.

#### Scenario: Nguồn ngoài bị đổi nội dung
- **WHEN** một tài nguyên nạp từ nguồn ngoài trả về nội dung khác với lúc khai báo
- **THEN** trình duyệt không thực thi tài nguyên đó

### Requirement: Cấu hình kết nối DB thiếu thì dừng, không đoán

Trang login lấy thông tin kết nối từ cấu hình được chỉ định qua môi trường. Khi thiếu cấu hình, hệ
thống dừng với lỗi rõ ràng thay vì thử kết nối bằng giá trị mặc định.

#### Scenario: Máy chưa có file cấu hình
- **WHEN** file cấu hình DB không tồn tại ở đường dẫn đã khai báo
- **THEN** request dừng với thông báo nêu rõ thiếu cấu hình, và không có lần thử kết nối nào bằng tài
  khoản mặc định

### Requirement: Giao diện login không hứa thứ không có

Trang login không hiển thị chức năng chưa tồn tại, và nhãn của các tuỳ chọn phải mô tả đúng việc
chúng làm.

#### Scenario: Chức năng quên mật khẩu chưa có
- **WHEN** hệ thống chưa có quy trình lấy lại mật khẩu
- **THEN** trang login không hiện liên kết "Quên mật khẩu?" dẫn tới chỗ trống

#### Scenario: Tuỳ chọn ghi nhớ
- **WHEN** tuỳ chọn ghi nhớ chỉ lưu lại tên đăng nhập ở trình duyệt
- **THEN** nhãn nói đúng điều đó, không gợi ý rằng nó kéo dài phiên đăng nhập
