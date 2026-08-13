## Context

Package chạy dưới hai bất biến đã có từ change `shared-auth`, và mọi quyết định dưới đây phải sống
chung với chúng:

- **Package nhận `mysqli` từ ngoài, không tự quản DB connection.**
- **Hợp đồng tích hợp chỉ 2 điểm chạm** (`SessionBootstrap::start()` + `Guard::requireLogin()`).
  Thêm một tham số bắt buộc vào một trong hai là phá hợp đồng của mọi dự án đã tích hợp.

Ngoài ra: mọi thứ chạy trên **một máy** (Laragon local, XAMPP production), `lichkehoach` và
`nhansuhtdb` đã tích hợp xong, và `SHARED_AUTH_UPGRADE_ON_LOGIN` đang TẮT suốt rollout.

## Goals / Non-Goals

**Goals:**
- Task 2.8 (deploy đồng loạt) chạy được một cách an toàn.
- Đóng các lỗi đúng-sai trong package trước khi nhân rộng ra dự án thứ ba.
- Có lưới hồi quy trước task 3.2 (đổi mọi điểm ghi password sang hash).

**Non-Goals:**
- Không đụng authorization. Không thêm role, không thêm quyền.
- Không chặn tài khoản nghỉ việc (đã chốt — xem Rủi ro).
- Không đổi hợp đồng 2 điểm chạm. Mọi dự án đã tích hợp không phải sửa code.
- Không đổi luồng migration password đang chạy dở.

## Decisions

### 1. Storage phiên production: thư mục save_path riêng, KHÔNG phải MySQL handler

README và spec `shared-auth` hứa MySQL session handler. Giữ đúng lời hứa đó thì kẹt: handler cần một
connection, mà package không được tự mở connection, còn bắt mỗi dự án truyền handler vào
`SessionBootstrap::start()` thì phá hợp đồng 2 điểm chạm — đúng thứ vừa mất công dựng xong.

**Quyết:** production dùng `session_save_path` trỏ một thư mục **riêng của authV2, nằm ngoài
webroot**, quyền hẹp, cấu hình qua `SHARED_AUTH_SESSION_PATH`. Không kết nối DB nào cho việc lưu
phiên. Nếu thư mục không tồn tại hoặc không ghi được **và** môi trường là production thì dừng với lỗi
rõ ràng, thay vì rơi về `save_path` mặc định của php.ini như hiện nay.

Cái này giải đúng vấn đề thật: mối nguy không phải "file thì chậm", mà là **phiên nằm chung
save_path với mọi vhost khác trên máy**. Một thư mục riêng đã cắt hẳn mối nguy đó, với 0 dòng DB code
và 0 thay đổi hợp đồng.

Đánh đổi: không dùng được cho nhiều web server. Khi nào thật sự lên nhiều máy thì mới cần handler DB,
và lúc đó package sẽ phải tự mở một connection riêng từ env — một ngoại lệ có chủ ý với bất biến
"không tự quản DB connection", chỉ dành cho storage phiên. Ghi lại đây để lần sau không phải cãi lại
từ đầu. **Đồng thời phải sửa README + spec `shared-auth` cho khớp**, thay vì để lời hứa cũ nằm đó.

### 2. Chống dò mật khẩu và audit log: hai bảng DB, chỉ trang login đụng tới

Bộ đếm không thể đặt trong phiên (kẻ dò chỉ cần xoá cookie) và không nên đặt trong file (đua ghi).
Đặt trong DB: `auth_login_attempt` (username, ip, số lần, khoá tới lúc nào) và `auth_audit_log`.

Chỗ này **không** phá bất biến nào: trang login đã có sẵn `mysqli` từ `config/db.php`, và nó là nơi
duy nhất cần hai bảng này. Các dự án tích hợp không biết gì về chúng. Lớp mới trong `src/` nhận
`mysqli` từ ngoài đúng như `CredentialVerifier` đang làm.

Khoá theo cặp (username, IP) như đã chốt. Ghi log không được làm hỏng login: mọi lỗi ghi đi vào
`error_log` rồi đi tiếp.

Đánh đổi đã biết: nhiều người dùng chung một IP NAT nội bộ thì một người gõ sai nhiều lần có thể đẩy
bộ đếm của **cặp của chính họ**, không ảnh hưởng người khác — nên ngưỡng đặt đủ rộng (mặc định đề
xuất 10 lần / 15 phút) chứ không siết như hệ thống công khai.

### 3. Trần tuyệt đối 30 ngày, cấu hình được, `0` là tắt

Hiện `logged_in_at` được ghi nhưng giá trị không bao giờ được đọc; hạn duy nhất là 7 ngày *kể từ
thao tác cuối*, gia hạn mỗi request. Người dùng hàng ngày thì phiên sống vô hạn, nên một cookie bị
lấy cắp cũng sống vô hạn.

**Quyết:** thêm trần tính từ `logged_in_at`, mặc định 30 ngày, env `SHARED_AUTH_ABSOLUTE_LIFETIME`,
đặt `0` để tắt hoàn toàn. Rẻ (một phép so trong `isLoggedIn()`), và làm `logged_in_at` có nghĩa thật
thay vì chỉ là dấu ấn.

Đánh đổi: mỗi người phải đăng nhập lại 30 ngày một lần. Việc này **rải đều** theo thời điểm login của
từng người, không dồn thành một sự kiện toàn hệ thống như lần đổi `session_name`.

### 4. Rút gọn path thành một chỗ dùng chung

`RedirectSanitizer::normalizePath()` đã làm đúng việc Guard đang thiếu. Tách nó ra một lớp nội bộ
dùng chung thay vì chép sang Guard — hai bản sao của một quy tắc bảo mật là cách nó lệch nhau về sau.
`Guard::isPublic()` giải mã percent-encoding rồi rút gọn trước khi so.

### 5. Test viết bằng PHP thuần, không thêm dependency

Dự án không có test runner nào. Thêm PHPUnit là đưa tool mới vào dự án, việc đó không tự quyết được.
Bộ test sẽ là PHP thuần chạy bằng `php tests/run.php`, gồm unit cho các lớp trong `src/` và smoke
HTTP qua `php -S` như đợt kiểm thử ở task 1.13 đã làm. Nếu muốn PHPUnit thì đó là quyết định của bạn,
và cấu trúc test viết ra sẽ chuyển sang PHPUnit được mà không phải viết lại phần khẳng định.

### 6. Namespace `$_SESSION['_auth']` — KHÔNG làm trong đợt này

Gom key danh tính vào một namespace là đúng về lâu dài, nhưng nó đổi hình dạng phiên → cần một cửa sổ
deploy đồng loạt thứ hai, đúng thứ đang cố tránh. Nếu làm, phải làm **cùng cửa sổ với task 2.8** hoặc
không làm. Đợt này chỉ ghi nhận; nhóm D giữ lại phần dọn dẹp không đụng hình dạng phiên.

## Risks / Trade-offs

- **Tài khoản nghỉ việc vẫn đăng nhập được vào mọi dự án** (đã chốt không xử lý ở đây). Trước SSO,
  mỗi dự án còn có thể tự chặn; sau SSO, một lần đăng nhập là vào tất cả. Việc chặn nằm ở authz của
  từng dự án, nghĩa là **mọi dự án đều phải làm**, và không có gì trong authV2 bảo đảm điều đó. Đây
  là rủi ro mở, cần theo dõi ở đợt nhân rộng (task 5.1).
- **Khoá theo (username, IP) lách được bằng cách đổi IP.** Chấp nhận: audit log là lớp phát hiện bù
  lại. Nếu log cho thấy dò phân tán thật thì nâng lên chính sách kết hợp.
- **Fail-fast khi thiếu cấu hình** (storage phiên production, cấu hình DB) biến một lỗi âm thầm thành
  một sự cố nhìn thấy được. Đó là chủ ý, nhưng nghĩa là **triển khai sai sẽ làm sập trang login** thay
  vì chạy tiếp trong trạng thái không an toàn. Phải kiểm cấu hình trước cửa sổ deploy 2.8.
- **Trần 30 ngày** sẽ khiến một số người bị hỏi login lại vào lúc họ không mong đợi. Nếu không muốn,
  đặt `SHARED_AUTH_ABSOLUTE_LIFETIME=0`.
- **Sửa README/spec `shared-auth`** theo quyết định 1 là sửa một artifact của change trước. Làm rõ
  ràng trong task list chứ không sửa lén.
