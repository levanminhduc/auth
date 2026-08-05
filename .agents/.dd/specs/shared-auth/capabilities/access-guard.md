## Purpose

Chặn truy cập trang cần đăng nhập khi chưa có phiên, đưa user về trang login chung và quay lại
đúng chỗ sau khi login, đồng thời chừa ra các path public (webhook/api/cron) không cần login.

## ADDED Requirements

### Requirement: Chặn và redirect khi chưa đăng nhập

Guard kiểm tra phiên; nếu chưa login thì redirect về `/auth/?redirect=<uri hiện tại>` rồi dừng.

#### Scenario: Truy cập trang cần login khi chưa đăng nhập
- **WHEN** user chưa có phiên mở trực tiếp một trang được Guard bảo vệ
- **THEN** bị redirect về `/auth/?redirect=<uri>` và không thấy nội dung trang

#### Scenario: Quay lại đúng chỗ sau login
- **WHEN** user login thành công với `?redirect=` là một path nội bộ hợp lệ
- **THEN** được đưa về đúng path đó

### Requirement: Redirect chỉ nhận path nội bộ

RedirectSanitizer chỉ chấp nhận path nội bộ; giá trị `?redirect=` ngoài miền/tuyệt đối bị loại,
fallback về trang mặc định (portal).

#### Scenario: Chặn open redirect
- **WHEN** `?redirect=http://evil.com` hoặc `//evil.com`
- **THEN** giá trị bị loại và user được đưa về portal mặc định

### Requirement: Whitelist path public

Guard nhận danh sách path public (webhook/API/cron) và bỏ qua kiểm tra login cho chúng.

#### Scenario: Webhook bên thứ ba
- **WHEN** một endpoint nằm trong whitelist bị gọi mà không có phiên
- **THEN** Guard không redirect, request được xử lý bình thường
