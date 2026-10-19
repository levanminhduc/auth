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

Trang login lấy thông tin kết nối từ file cấu hình được chỉ định qua env `SHARED_AUTH_DB_CONFIG` (mặc định
`C:/xampp/config/db.php`). Khi file thiếu, không đọc được, hoặc thiếu/sai kiểu một khoá `host`, `username`,
`password`, `database`, hệ thống dừng với HTTP 500 và thông báo chung (chi tiết vào `error_log`) thay vì thử kết nối
bằng giá trị mặc định như `root` / mật khẩu rỗng. Kết nối thất bại cũng dừng như vậy; `mysqli_report` được đặt
tường minh ở chế độ strict.

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

### Requirement: Đăng xuất bằng GET không bị trang ngoài kích hoạt

`logout.php` giữ phương thức GET (các dự án đang link vào). Request bị từ chối — giữ nguyên phiên, trả 303 về trang
login — khi `Sec-Fetch-Site: cross-site` hoặc `Sec-Fetch-Dest` khác `document`. Trình duyệt không gửi `Sec-Fetch-*` thì
Origin/Referer thuộc host lạ hoặc `null` cũng bị từ chối; không có header nào thì cho phép.

#### Scenario: Thẻ ảnh của trang ngoài trỏ tới logout
- **WHEN** một trang khác nhúng `<img src=".../logout.php">` và user đang đăng nhập duyệt trang đó
- **THEN** phiên không bị huỷ

#### Scenario: Bấm link đăng xuất ở dự án cùng host
- **WHEN** user bấm link `logout.php` từ một dự án cùng host
- **THEN** phiên bị huỷ, cookie bị xoá, user về trang login

### Requirement: Lỗi hạ tầng xác thực không bị hiểu thành sai mật khẩu

Lỗi DB khi xác thực trả HTTP 503 với thông báo "Hệ thống đang bận, vui lòng thử lại." thay vì thông báo sai mật khẩu.

#### Scenario: Truy vấn xác thực lỗi
- **WHEN** truy vấn `user` ném lỗi DB
- **THEN** người dùng thấy thông báo hệ thống bận (HTTP 503), không phải "sai tên đăng nhập hoặc mật khẩu"

### Requirement: Focus ô nhập theo trạng thái form

Ô tên đăng nhập được focus khi trống, ngược lại focus ô mật khẩu; `aria-invalid` chỉ đặt trên ô gây lỗi. Banner
`?reason=` chỉ hiện ở GET.

#### Scenario: Sai mật khẩu
- **WHEN** user nhập sai mật khẩu và form hiện lại với username được giữ
- **THEN** ô mật khẩu được focus và mang `aria-invalid`, ô tên đăng nhập không mang `aria-invalid`

#### Scenario: Username bị bỏ do không hợp lệ
- **WHEN** username gửi lên sai UTF-8 hoặc quá dài nên không được giữ lại
- **THEN** ô tên đăng nhập trống, được focus và mang `aria-invalid`
