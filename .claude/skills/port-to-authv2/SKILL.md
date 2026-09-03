---
name: port-to-authv2
description: >
  Port một dự án PHP trên Laragon (C:\laragon\www\*) sang dùng authV2 / package hoatho/shared-auth,
  để mọi dự án cùng host chia sẻ một phiên đăng nhập (SSO nội bộ): quét dự án, thay session_start +
  form login + logout riêng bằng SessionBootstrap và Guard, dựng lại bối cảnh session, đăng ký vào
  allowlist redirect, smoke test HTTP thật, cập nhật spec. Dùng skill này bất cứ khi nào người dùng
  nói tới việc cho một dự án dùng auth chung — "port/tích hợp/đồng bộ <dự án> sang authV2", "cho
  <dự án> dùng AuthV2", "gom login về một chỗ", "SSO nội bộ", "dự án X vẫn còn login riêng" — kể cả
  khi họ không gọi tên authV2, và cả khi họ chỉ nhờ sửa một mẩu code auth trong dự án www nào đó.
  Khác với skill php-shared-auth (chỉ phân tích và định hướng kiến trúc), skill này THỰC THI việc port.
---

# Port một dự án sang authV2

authV2 = package `Hoatho\SharedAuth\` (`C:\laragon\www\authV2\src\`) + một trang login duy nhất.
Port xong, dự án không còn tự xác thực nữa: nó chỉ mở phiên chung và hỏi "ai đang đăng nhập".

Ranh giới quan trọng: package lo **authentication** (ai đang đăng nhập). **Authorization**
(role → menu, quyền duyệt, `vai_tro`) ở lại từng dự án — chép nguyên sang, đừng nhân tiện sửa. Trộn
hai thứ này là cách nhanh nhất để vừa vỡ quyền vừa không biết vỡ ở đâu.

## Ba bất biến không được phá

1. **`SHARED_AUTH_UPGRADE_ON_LOGIN` để TẮT** cho tới khi dự án CUỐI đã port. Khi tắt, authV2 verify
   được cả plaintext lẫn bcrypt nhưng không ghi hash → mật khẩu không đổi → những dự án chưa port
   vẫn so plaintext bình thường. Bật sớm là làm hỏng đăng nhập của các dự án chưa port.
2. **Mọi điểm ĐỌC password phải hiểu cả hai định dạng trước khi bất kỳ đâu GHI hash.** Đây là lý do
   tồn tại của bất biến 1.
3. **Không xoá file ở repo không có git mà chưa sao lưu.** Script audit in ra dự án có git hay không
   ngay dòng đầu; không có git thì copy ra thư mục scratchpad trước khi xoá bất cứ thứ gì.

## Quy trình

### Bước 1 — Đọc bối cảnh, quét dự án

Đọc `authV2/.agents/.dd/specs/shared-auth/tasks.md` (nguồn thực thi, cho biết đang ở giai đoạn nào và
dự án nào đã port) rồi chạy:

```bash
php C:/laragon/www/authV2/.claude/skills/port-to-authv2/scripts/audit.php C:/laragon/www/<du-an>
```

Script chỉ đọc, không sửa. Nó trả về: số điểm mở phiên và gợi ý nhóm, các chỗ so/ghi mật khẩu,
remember-me, form login, endpoint JSON, các chỗ chặn truy cập hiện có, và **bảng khoá `$_SESSION`**
— cột "CẦN dựng lại" chính là hợp đồng ngầm mà bước 4 phải đáp ứng.

Kết quả của regex là điểm khởi đầu để đọc code, không phải kết luận. Mở các file trong mục "SO mật
khẩu" và "chặn truy cập" ra đọc trước khi sửa gì.

### Bước 2 — Chọn nhóm

- **Nhóm 1** (≲10 `session_start()`, hoặc có sẵn một file session/auth tập trung): sửa trực tiếp.
  Có file tập trung thì biến nó thành shim — rẻ nhất, mọi include cũ chạy y nguyên.
- **Nhóm 2** (session_start rải rác khắp nơi): `auto_prepend_file` trỏ `authV2/prepend.php`. Sửa tay
  30 chỗ vừa lâu vừa chắc chắn sót.

Mẫu code cho cả hai: đọc `references/patterns.md` mục 1.

### Bước 3 — Sửa code

Theo `references/patterns.md` (mục 2–3, 6). Trình tự an toàn: mở phiên → chặn truy cập → forwarder
login/logout → gỡ code xác thực cũ. Bốn dự án đã port là tham chiếu sống, đọc thẳng chúng khi phân
vân: `lichkehoach` (nhóm 1 có shim), `sangkienct` (nhóm 1 trực tiếp), `nhansuhtdb` + `khsanxuat`
(nhóm 2).

### Bước 4 — Dựng lại bối cảnh session

Đây là chỗ hỏng nhiều nhất và hỏng im lặng: trang vẫn trả 200, chỉ có navbar trống và menu theo
quyền biến mất. authV2 chỉ ghi `user_id`/`name`/`logged_in_at`; mọi khoá khác mà login cũ dựng phải
được dựng lại **mỗi request** từ DB. Với từng khoá "CẦN dựng lại" ở bước 1, tìm trong login cũ xem
nó lấy từ bảng/cột nào rồi tái tạo. Chi tiết + hai cách làm: `references/patterns.md` mục 4.

### Bước 5 — Đăng ký vào allowlist redirect

Thêm prefix URL của dự án vào `EnvConfig::projects()` (và `tests/unit/env_config_test.php`). Bỏ qua
bước này thì login xong người dùng rơi về portal thay vì quay lại dự án, không kèm lỗi nào.

### Bước 6 — Smoke test HTTP thật

```bash
php C:/laragon/www/authV2/.claude/skills/port-to-authv2/scripts/smoke_test.php <du-an>/<path-can-login> [<du-an>/<path-public>]
```

Gõ path **không có dấu `/` ở đầu** khi chạy qua Git Bash (nó tự dịch `/abc` thành đường dẫn Windows).

Kiểm 14 điều: Guard chặn, redirect mang đúng path, path public còn mở, login → quay lại đúng chỗ,
trang hiện trạng thái đã đăng nhập, SSO chéo sang các dự án khác, portal liệt kê dự án, logout huỷ
phiên chung, DB chưa bị ghi hash. Chạy `php tests/run.php` ở authV2 nữa.

Một thứ script không tự kiểm được: **tên/phòng ban/vai trò trên navbar có ĐÚNG không**. Tự đối chiếu
với DB — đây chính là bước 4 có làm đủ hay không.

### Bước 7 — Cổng kiểm tra và ghi lại

```bash
grep -rn "== \$db_password\|=== \$password\|password_verify\|user_login\|remember_token" --include=*.php <du-an>
grep -rn "session_start" --include=*.php <du-an>
```

Còn sót chỗ nào thì giải thích được vì sao nó vô hại (vd: file đã thành code chết), nếu không thì sửa.

Rồi cập nhật `tasks.md` (mục 5 — nhân rộng): đã sửa gì, kết quả test, code chết còn tồn, và dự án có
điểm GHI `mysqli`.`user`.`password` không (đầu vào cho task 3.2). Thêm tên dự án vào `CLAUDE.md`.

## Báo cáo cho người dùng

Dẫn bằng kết quả, không dẫn bằng danh sách file đã sửa. Nêu rõ:

- Kết quả smoke test (số pass/fail) và bối cảnh có dựng lại đúng không.
- **Người dùng dự án sẽ bị đăng xuất một lần** (đổi `session_name` sang `HTSSOSESSID`) và remember-me
  cũ mất tác dụng — đây là thứ cần báo trước cho nội bộ, không phải chi tiết kỹ thuật vặt.
- Code chết còn lại và thứ cố ý không đụng (kèm lý do).
- Việc cần người quyết: DROP cột không còn dùng, gỡ code chết ở repo khác, cửa sổ deploy.
