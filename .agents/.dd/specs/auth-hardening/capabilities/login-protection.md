## Purpose

Bảo vệ trang login duy nhất khỏi dò mật khẩu, và ghi vết mọi lần đăng nhập/đăng xuất để truy được
ai vào lúc nào từ đâu. Gom auth về một chỗ cũng gom điểm tấn công về một chỗ; capability này là cái
giá phải trả cho việc gom.

## ADDED Requirements

### Requirement: Đếm và chặn đăng nhập sai theo chính sách lai

Mỗi lần POST login được ghi nhận cho ba khoá và đếm theo từng chính sách riêng:

| Khoá | Ngưỡng sai / cửa sổ | Thời gian khoá |
|---|---|---|
| cặp `p:<username>\|<ip>` | 5 / 15 phút | 15 phút |
| `ip:<ip>` | 30 / 15 phút | 15 phút |
| trần `u:<username>` | 50 / 60 phút | 30 phút |

Vượt ngưỡng của **bất kỳ** khoá nào thì lần thử đó bị từ chối kể cả khi mật khẩu đúng; thời gian khoá là
`lockout` phút kể từ lần sai chạm ngưỡng, không phụ thuộc độ dài cửa sổ. Lần thử bị từ chối vì đang khoá
không được lưu (không kéo dài khoá). Việc ghi nhận lần thử diễn ra **trước** khi đếm, để các request song
song thấy nhau và không cùng vượt ngưỡng. Đăng nhập thành công xoá bộ đếm `p:` và `u:` của username, và chỉ
bỏ bản ghi `ip:` của chính lần thử đó. Ngưỡng cấu hình ở `config/login_config.php`.

Vì sao lai: chỉ khoá theo cặp thì không có giới hạn theo từng tài khoản — dò phân tán qua nhiều IP không
bao giờ chạm ngưỡng của cặp nào. Chỉ khoá toàn cục theo `u:` ở ngưỡng thấp thì bất kỳ ai cũng khoá được
tài khoản của người khác. Cặp chặn dò thường, `ip:` chặn một nguồn quét nhiều tài khoản, `u:` là trần cao
chống dò phân tán (chạm trần ghi `error_log` cảnh báo).

Username sai UTF-8 hoặc dài quá 255 ký tự, và mật khẩu dài quá 1024 byte, bị từ chối với thông báo chung
trước khi chạm throttle hay DB. Bảng/DB lỗi thì throttle **fail-open** và ghi `error_log`.

#### Scenario: Vượt ngưỡng cặp thì bị khoá tạm
- **WHEN** một cặp (username, IP) sai mật khẩu 5 lần trong 15 phút
- **THEN** các lần thử tiếp theo của cặp đó bị từ chối (HTTP 429) trong 15 phút, và thông báo không tiết lộ
  tài khoản có tồn tại hay không

#### Scenario: Mật khẩu đúng trong lúc đang bị khoá
- **WHEN** một khoá đang bị khoá và người dùng nhập đúng mật khẩu
- **THEN** vẫn bị từ chối, không tạo phiên (lần thử này không được lưu vào bộ đếm)

#### Scenario: Người khác không bị vạ lây
- **WHEN** một IP dò mật khẩu của user A tới mức khoá cặp
- **THEN** user A đăng nhập từ IP khác vẫn vào bình thường

#### Scenario: Dò phân tán chạm trần theo tài khoản
- **WHEN** nhiều IP khác nhau cùng dò user A, tổng 50 lần sai trong 60 phút
- **THEN** mọi lần thử vào user A, kể cả mật khẩu đúng, bị từ chối 30 phút và có dòng `error_log` cảnh báo

#### Scenario: Một IP quét nhiều tài khoản
- **WHEN** một IP sai 30 lần trong 15 phút qua nhiều username
- **THEN** IP đó bị khoá 15 phút dù không cặp (username, IP) nào chạm ngưỡng

#### Scenario: Đăng nhập đúng xoá bộ đếm
- **WHEN** một cặp (username, IP) sai vài lần rồi nhập đúng khi chưa vượt ngưỡng
- **THEN** bộ đếm `p:` và `u:` của username về 0

#### Scenario: Đầu vào không hợp lệ không chạm throttle
- **WHEN** username không phải UTF-8 hợp lệ hoặc dài quá 255 ký tự
- **THEN** bị từ chối bằng thông báo "sai tên đăng nhập hoặc mật khẩu" chung, không ghi bộ đếm và không truy vấn DB
### Requirement: Ghi vết đăng nhập, đăng xuất và lần thử thất bại

> Chưa làm (task 2.5). Hiện chỉ có `error_log` khi chạm trần `u:` và khi throttle/DB lỗi.

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
