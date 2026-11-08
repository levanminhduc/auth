# Mẫu code theo từng tình huống

Bốn dự án đã port là nguồn tham chiếu sống, đọc thẳng chúng khi phân vân:
`lichkehoach` (nhóm 1, có file session tập trung), `sangkienct` (nhóm 1, sửa trực tiếp),
`nhansuhtdb` (nhóm 2, auto_prepend_file), `khsanxuat` (nhóm 2, auto_prepend_file + `bootstrap.php` sẵn có).

## 1. Mở phiên

### Nhóm 1 — có sẵn file session/auth tập trung

Biến chính file đó thành shim ủy quyền cho package, giữ nguyên tên hàm cũ. Đây là cách rẻ nhất:
mọi `require_once .../session.php` rải rác trong dự án chạy y nguyên, không phải sửa file nào khác.

```php
// <du-an>/src/session.php  — xem bản thật ở lichkehoach/src/session.php
require_once __DIR__ . '/../../authV2/bootstrap.php';   // autoload + SessionBootstrap::start()

if (!function_exists('khoiTaoSession')) {
    function khoiTaoSession(): void { \Hoatho\SharedAuth\SessionBootstrap::start(); }
}
if (!function_exists('huyPhienDangNhap')) {
    function huyPhienDangNhap(): void { \Hoatho\SharedAuth\SessionBootstrap::destroy(); }
}
```

### Nhóm 1 — không có file tập trung

Tạo `<du-an>/includes/auth.php` (xem `sangkienct/includes/auth.php`) rồi require nó ở đầu mỗi entry
point, thay cho `session_start()`.

### Nhóm 2 — quá nhiều `session_start()` rải rác

Đừng sửa tay 30 chỗ. Dùng `auto_prepend_file` để PHP tự nạp package trước mọi request:

```apache
# <du-an>/.htaccess  (mod_php — kiểm tra bằng php_sapi_name() === 'apache2handler')
php_value auto_prepend_file "C:/laragon/www/authV2/prepend.php"
```

`prepend.php` gọi `SessionBootstrap::configure()` — cấu hình phiên nhưng KHÔNG start, để code cũ tự
`session_start()` như nó vẫn làm mà không sinh notice. Nếu host chạy PHP-FPM/CGI (không phải mod_php)
thì `php_value` vô tác dụng, phải dùng `.user.ini` với `auto_prepend_file=...`.

## 2. Chặn truy cập

```php
(new \Hoatho\SharedAuth\Guard($publicPaths))->requireLogin();
```

`$publicPaths` là các path bỏ qua login — webhook, cron, trang xem read-only. Khớp chính xác, hoặc
khớp prefix nếu entry kết thúc bằng `/`. Ví dụ: `['/lichkehoach/public/index.php', '/lichkehoach/public/api/']`.

Nếu dự án có sẵn hàm kiểu `checkAuth()` gọi ở nhiều route, giữ tên hàm và cho nó ủy quyền:

```php
function checkAuth() { (new \Hoatho\SharedAuth\Guard())->requireLogin(); }
```

### Endpoint gọi qua XHR/API

Guard trả 302 — client XHR sẽ nhận HTML trang login rồi vỡ khi parse JSON. Ở các file này chỉ đổi
điều kiện, giữ nguyên nhánh trả 403:

```php
if (!\Hoatho\SharedAuth\SessionContract::isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}
```

## 3. Forwarder cho login/logout cũ

Giữ file cũ làm forwarder 302 thay vì xoá — bookmark, form POST cũ, link trong email nội bộ vẫn còn
trỏ tới. Xoá thì thành 404 khó hiểu; forwarder thì đưa người dùng về đúng chỗ.

```php
// <du-an>/login.php
require_once __DIR__ . '/includes/auth.php';

$redirect = $_GET['redirect'] ?? '';
if ($redirect === '') {
    $redirect = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/') . '/index.php';
}
header('Location: ' . \Hoatho\SharedAuth\EnvConfig::loginUrl() . '?redirect=' . rawurlencode($redirect), true, 302);
exit;
```

```php
// <du-an>/logout.php — logout lan ra MỌI dự án cùng host, không chỉ dự án này
header('Location: ' . \Hoatho\SharedAuth\EnvConfig::logoutUrl($returnTo), true, 302);
exit;
```

Với `?logout=1` xử lý ngay trong `index.php`, mang theo URL hiện tại (đã bỏ `logout`) làm `$returnTo`
để sau khi đăng nhập lại người dùng quay về đúng trang đang xem:

```php
$returnQuery = $_GET;
unset($returnQuery['logout']);
$returnTo = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
if ($returnQuery !== []) {
    $returnTo .= '?' . http_build_query($returnQuery);
}
header('Location: ' . \Hoatho\SharedAuth\EnvConfig::logoutUrl($returnTo));
exit;
```

## 4. Dựng lại bối cảnh session — chỗ dễ hỏng nhất

authV2 chỉ ghi `user_id`, `name`, `logged_in_at`. Mọi khoá khác mà login cũ dựng (`vai_tro`, `ho_ten`,
`phong_ban_ten`, `signature_image`...) sẽ TRỐNG sau khi port. Triệu chứng: đăng nhập xong trang vẫn
200 nhưng navbar trống, menu theo quyền biến mất. Vì login giờ xảy ra ở dự án khác, không còn "một
lần lúc login" nữa — phải dựng lại MỖI REQUEST.

### Cách A — chỉ cần vài cột từ bảng `user`

```php
(new \Hoatho\SharedAuth\IdentitySync($conn_auth, [
    'columns' => [
        'name'            => ['name', 'ma_nv'],   // một cột DB → nhiều khoá session
        'role'            => ['role', 'vai_tro'],
        'signature_image' => 'signature_image',
    ],
]))->sync();
```

`IdentitySync` chạy một lần/request (mỗi instance) và ghi giá trị **thô** (chỉ `trim`). Chỗ nào của dự án
in thẳng `$_SESSION[...]` ra HTML thì phải tự `htmlspecialchars` khi in — rà các chỗ đó trước khi dùng.

### Cách B — cần join bảng khác, hoặc phải giữ giá trị thô

Viết helper riêng trong dự án (xem `sangkienct/includes/auth.php`,
`nhansuhtdb/helpers/session_auth_sync_helper.php`). Lưu ý hai điều đã cắn cả hai lần:

- Dùng `name` **thô** (chưa escape) khi đem đi khớp `nhan_vien.ma_nv`, không thì không tìm ra bản ghi.
- Cache bằng `static $done` để không truy vấn lại nhiều lần trong một request.

Suy ra `vai_tro` từ chức danh là **authorization** — chép nguyên luật cũ sang, đừng nhân tiện sửa.

## 5. Đăng ký dự án vào allowlist redirect

`EnvConfig::projects()` vừa là danh sách hiện ở portal, vừa là allowlist DUY NHẤT cho `?redirect=`.
Quên bước này thì mọi thứ vẫn "chạy" nhưng đăng nhập xong người dùng rơi về portal thay vì quay lại
dự án — và không có thông báo lỗi nào.

```php
// authV2/src/EnvConfig.php — nhánh mặc định của projects()
return [
    '/lichkehoach/public/' => 'Lịch kế hoạch',
    '/nhansuhtdb/'         => 'Nhân sự HTĐB',
    '/sangkienct/'         => 'Sáng kiến cải tiến',
    '/khsanxuat/'          => 'Kế hoạch sản xuất',
    '/<du-an-moi>/'        => '<Nhãn hiển thị>',
];
```

Sửa mặc định thì cập nhật luôn `tests/unit/env_config_test.php` (có assert danh sách mặc định).
Nếu production dùng env thì đồng thời cập nhật `SHARED_AUTH_PROJECTS`.

## 6. Những gì PHẢI gỡ khỏi dự án

| Gỡ | Vì sao |
|---|---|
| Form login riêng, code so password | Còn một chỗ so plaintext = còn đường vòng qua authV2, và sẽ vỡ khi bật hash ở GĐ2 |
| Remember-me cookie tự chế + cột token | Thay bằng phiên 7 ngày trượt của authV2; hai cơ chế song song sẽ đá nhau |
| `session_name()` / cookie params riêng | Tên phiên khác nhau = không dùng chung được phiên |
| Trang debug dump bảng `user` | Rủi ro lộ dữ liệu, thường nằm ngay cạnh code login cũ |
| `ALTER TABLE` ngầm chạy mỗi request | Di sản của remember-me, không còn tác dụng |

Giữ lại: logic phân quyền (role → menu/permission), khoá session app-state riêng của dự án
(flash message, CSRF, giỏ dữ liệu tạm).
