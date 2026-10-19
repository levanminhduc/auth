## MODIFIED Requirements

### Requirement: Whitelist path public

Guard nhận danh sách path public (webhook/API/cron) và bỏ qua kiểm tra login cho chúng. Path của
request phải được **giải mã và rút gọn `.`/`..` trước khi so** với whitelist, để path đối chiếu là
path mà web server thực sự phục vụ chứ không phải chuỗi thô người gọi gửi lên.

Chuẩn hoá (`PathNormalizer`) giải mã percent-encoding **đúng một lượt**, đổi `\` thành `/`, gộp `//`, rồi rút gọn
`.`/`..`; việc so khớp không phân biệt hoa/thường vì filesystem Windows không phân biệt.

Khi không chắc, Guard chặn: path mơ hồ — chứa `\`, `%2f`, `%5c` hoặc `%25`, nên độ sâu thật phụ thuộc cấu hình
server/proxy — thì coi như không public.

#### Scenario: Webhook bên thứ ba
- **WHEN** một endpoint nằm trong whitelist bị gọi mà không có phiên
- **THEN** Guard không redirect, request được xử lý bình thường

#### Scenario: Dùng `..` để chui qua whitelist dạng prefix
- **WHEN** whitelist có prefix `/duan/api/` và request là `/duan/api/../trang-can-login.php`
- **THEN** Guard KHÔNG coi là public và vẫn chặn, vì path rút gọn nằm ngoài prefix

#### Scenario: Che path bằng percent-encoding
- **WHEN** request dùng percent-encoding để chuỗi thô trông khớp whitelist còn path thật thì không
- **THEN** Guard so trên path đã giải mã và vẫn chặn

#### Scenario: Path mơ hồ
- **WHEN** request chứa `\`, `%2f`, `%5c` hoặc `%25` trong path (vd `/duan/api%2f..%2ftrang.php`)
- **THEN** Guard KHÔNG coi là public, kể cả khi chuỗi trông khớp prefix whitelist

#### Scenario: Khác hoa/thường
- **WHEN** whitelist có `/duan/api/` và request là `/DuAn/API/webhook.php`
- **THEN** Guard coi là public, vì web server Windows phục vụ cùng một file

## ADDED Requirements

### Requirement: Guard tự bảo đảm phiên đã mở

Trước khi đọc trạng thái đăng nhập, Guard tự mở phiên qua điểm bootstrap chung (`SessionBootstrap::start()`),
hoặc — nếu phiên đã mở — gia hạn cookie (`renewCookie()`, không gửi `Set-Cookie` trùng), để dự án chạy chế độ
chỉ-cấu-hình-không-mở vẫn được bảo vệ đúng và vẫn có cookie trượt như dự án gọi `start()` trực tiếp.

#### Scenario: Trang chưa mở phiên gọi thẳng Guard
- **WHEN** một trang không tự mở phiên gọi `requireLogin()` trong khi user đang có phiên hợp lệ
- **THEN** Guard nhận ra là đã đăng nhập và cho đi tiếp, không đá về trang login

#### Scenario: Không tạo vòng lặp chuyển hướng
- **WHEN** một trang không tự mở phiên bị Guard chặn rồi user đăng nhập xong quay lại
- **THEN** trang hiển thị bình thường, không lặp vô hạn giữa dự án và `/auth/`

### Requirement: Redirect bị loại phải để lại dấu

Khi `?redirect=` bị loại vì không khớp allowlist dự án, sự việc được ghi vào `error_log` kèm giá trị
bị loại. Người dùng vẫn được đưa về portal như trước.

#### Scenario: Dự án mới chưa khai báo trong allowlist
- **WHEN** một dự án vừa tích hợp nhưng chưa được thêm vào danh sách dự án và Guard đá user về login
- **THEN** user về portal, và `error_log` có dòng nêu path bị loại để người triển khai biết thiếu khai báo

### Requirement: Allowlist redirect loại trừ chính authV2

`?redirect=` chỉ nhận path thuộc prefix của một dự án đã khai báo (`SHARED_AUTH_PROJECTS`). Mọi path dưới
`SHARED_AUTH_BASE_URL` (chính authV2) bị từ chối **trừ `portal.php`** (không phân biệt hoa/thường, không PATH_INFO) —
allowlist chứ không phải blocklist, vì Apache trên Windows không phân biệt hoa/thường và chấp nhận PATH_INFO.
Path được chuẩn hoá bằng `PathNormalizer` trước khi so; path mơ hồ bị từ chối.

#### Scenario: Redirect vào trang xử lý của authV2
- **WHEN** `?redirect=` trỏ tới `logout.php`, `Logout.php`, `logout.php/x`, `assets/...` hoặc `public/x/../logout.php` dưới base
- **THEN** redirect bị loại và user về portal

#### Scenario: Redirect về portal
- **WHEN** `?redirect=` trỏ tới `portal.php` dưới base
- **THEN** được chấp nhận
