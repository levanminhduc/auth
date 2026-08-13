## Purpose

Bảo vệ trang login duy nhất khỏi dò mật khẩu, và ghi vết mọi lần đăng nhập/đăng xuất để truy được
ai vào lúc nào từ đâu. Gom auth về một chỗ cũng gom điểm tấn công về một chỗ; capability này là cái
giá phải trả cho việc gom.

## ADDED Requirements

### Requirement: Đếm và chặn đăng nhập sai theo (username, IP)

Mỗi lần POST login sai làm tăng bộ đếm gắn với cặp (username, IP). Vượt ngưỡng thì cặp đó bị từ chối
trong một khoảng thời gian, kể cả khi mật khẩu đúng. Đăng nhập thành công xoá bộ đếm của cặp đó.

Ngưỡng và thời gian khoá cấu hình được qua env; khoá theo cặp chứ không theo username, để người ngoài
không khoá được tài khoản của người khác.

#### Scenario: Vượt ngưỡng thì bị khoá tạm
- **WHEN** một cặp (username, IP) sai mật khẩu quá số lần cho phép
- **THEN** các lần thử tiếp theo của cặp đó bị từ chối cho tới khi hết thời gian khoá, và thông báo
  không tiết lộ tài khoản có tồn tại hay không

#### Scenario: Mật khẩu đúng trong lúc đang bị khoá
- **WHEN** cặp (username, IP) đang bị khoá và người dùng nhập đúng mật khẩu
- **THEN** vẫn bị từ chối, không tạo phiên, và lần thử này được ghi vào audit log

#### Scenario: Người khác không bị vạ lây
- **WHEN** một IP dò mật khẩu của user A tới mức bị khoá
- **THEN** user A đăng nhập từ IP khác vẫn vào bình thường

#### Scenario: Đăng nhập đúng xoá bộ đếm
- **WHEN** một cặp (username, IP) sai vài lần rồi nhập đúng khi chưa vượt ngưỡng
- **THEN** bộ đếm của cặp đó về 0

### Requirement: Ghi vết đăng nhập, đăng xuất và lần thử thất bại

Mọi sự kiện xác thực được ghi bền vững: thời điểm, username đã nhập, `user_id` khi có, IP, kết quả
(thành công / sai mật khẩu / không có tài khoản / bị khoá / đăng xuất). Ghi vết KHÔNG bao giờ chứa
mật khẩu, dù đúng hay sai.

Việc ghi vết thất bại (mất kết nối DB, bảng chưa có) không được làm hỏng luồng đăng nhập.

#### Scenario: Đăng nhập thành công để lại vết
- **WHEN** một user đăng nhập đúng
- **THEN** audit log có một bản ghi thành công kèm `user_id`, IP và thời điểm

#### Scenario: Thử tài khoản không tồn tại
- **WHEN** ai đó POST login với username không có trong `user`
- **THEN** audit log ghi lần thử thất bại kèm username đã nhập, và phản hồi cho người dùng giống hệt
  trường hợp sai mật khẩu

#### Scenario: Không rò mật khẩu vào log
- **WHEN** bất kỳ sự kiện xác thực nào được ghi
- **THEN** không có trường nào trong bản ghi chứa mật khẩu người dùng nhập

#### Scenario: Log hỏng không chặn đăng nhập
- **WHEN** ghi audit log thất bại
- **THEN** người dùng vẫn đăng nhập được và lỗi ghi log đi vào `error_log`
