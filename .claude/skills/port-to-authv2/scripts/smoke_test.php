<?php
/**
 * Smoke test một dự án vừa port sang authV2, qua HTTP THẬT (Laragon/Apache đang chạy).
 *
 *   php smoke_test.php /<du-an>/index.php?action=abc      # path cần đăng nhập
 *   php smoke_test.php /<du-an>/index.php /<du-an>/        # + path public (nếu dự án có trang mở)
 *
 * Vì sao phải test qua HTTP chứ không chỉ unit test: phần dễ sai nhất khi port không nằm trong logic
 * mà nằm ở đường đi — cookie phiên có dùng chung không, ?redirect= có sống qua allowlist không,
 * bối cảnh session có được dựng lại sau khi đăng nhập không. Chỉ request thật mới lộ ra.
 *
 * Env tuỳ chọn: SMOKE_BASE (mặc định http://localhost), SMOKE_USER (tên đăng nhập cụ thể).
 * Script đọc mật khẩu từ DB dev để đăng nhập được như người thật, nhưng KHÔNG in nó ra.
 */

declare(strict_types=1);

require __DIR__ . '/../../../../autoload.php';

use Hoatho\SharedAuth\EnvConfig;

/**
 * Nhận cả `sangkienct/index.php` lẫn `/sangkienct/index.php`. Git Bash trên Windows tự dịch tham số
 * bắt đầu bằng `/` thành đường dẫn Windows (`C:/Program Files/...`), nên dạng KHÔNG có `/` đầu là
 * dạng an toàn để gõ; ở đây bắt luôn trường hợp đã bị dịch để báo cho rõ thay vì fail khó hiểu.
 */
function normalizePath(string $arg): string
{
    if (preg_match('#^[A-Za-z]:[\\\\/]#', $arg)) {
        fwrite(STDERR, "Tham số đã bị Git Bash dịch thành đường dẫn Windows: $arg\n"
            . "Gõ lại KHÔNG có dấu / ở đầu (vd: sangkienct/index.php), hoặc đặt MSYS_NO_PATHCONV=1.\n");
        exit(1);
    }
    return '/' . ltrim($arg, '/');
}

$guarded = isset($argv[1]) && $argv[1] !== '' ? normalizePath($argv[1]) : '';
$public  = isset($argv[2]) && $argv[2] !== '' ? normalizePath($argv[2]) : null;
if ($guarded === '') {
    fwrite(STDERR, "Dùng: php smoke_test.php <du-an>/<path-can-login> [<du-an>/<path-public>]\n");
    exit(1);
}

$base = rtrim(getenv('SMOKE_BASE') ?: 'http://localhost', '/');
$host = strtolower((string) parse_url($base, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Từ chối: smoke test chỉ chạy trên máy local (đang trỏ $host).\n");
    exit(1);
}

$prefix = '/' . explode('/', ltrim($guarded, '/'))[0] . '/';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? " — $detail" : '') . "\n";
}

$jar = sys_get_temp_dir() . '/authv2_smoke_' . getmypid() . '.txt';
@unlink($jar);
register_shutdown_function(static fn () => @unlink($jar));

function req(string $url, ?string $post = null, bool $follow = false): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $out  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $hlen), $m) ? trim($m[1]) : '', 'body' => substr($out, $hlen)];
}

echo "# Smoke test authV2 — $base$guarded\n";

// 0. Dự án đã nằm trong allowlist redirect chưa. Sai ở đây thì mọi bước sau vẫn "chạy" nhưng user
//    bị đá về portal thay vì quay lại dự án — lỗi rất dễ bỏ sót nếu chỉ nhìn mã 302.
check("prefix $prefix có trong EnvConfig::projects()", array_key_exists($prefix, EnvConfig::projects()),
    'nếu FAIL: thêm vào EnvConfig::projects() hoặc SHARED_AUTH_PROJECTS rồi chạy lại');

// 1. Chưa đăng nhập thì bị chặn, và mang theo đúng chỗ định vào.
$r = req($base . $guarded);
$redirectedToLogin = $r['code'] === 302 && str_contains($r['loc'], EnvConfig::loginUrl());
check('chưa login → 302 sang trang login chung', $redirectedToLogin, $r['code'] . ' ' . $r['loc']);
check('redirect mang theo path đang muốn vào', str_contains(rawurldecode($r['loc']), $guarded), rawurldecode($r['loc']));

if ($public !== null) {
    $rp = req($base . $public);
    check('path public vẫn mở, không bị Guard chặn', $rp['code'] === 200, (string) $rp['code']);
}

// 2. Đăng nhập bằng một user thật trong DB dev.
$db = @new mysqli('127.0.0.1', 'root', '', 'mysqli');
if ($db->connect_errno) {
    echo "  BỎ QUA phần đăng nhập — không kết nối được DB mysqli: {$db->connect_error}\n";
    echo "\n$pass pass, $fail fail\n";
    exit($fail === 0 ? 0 : 1);
}
$wanted = getenv('SMOKE_USER') ?: null;
$sql = "SELECT id, name, password FROM `user` " . ($wanted ? "WHERE name = ? " : "") . "ORDER BY id LIMIT 1";
$stmt = $db->prepare($sql);
if ($wanted) {
    $stmt->bind_param('s', $wanted);
}
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if ($user === null) {
    echo "  BỎ QUA phần đăng nhập — không tìm thấy user" . ($wanted ? " '$wanted'" : '') . "\n";
    echo "\n$pass pass, $fail fail\n";
    exit($fail === 0 ? 0 : 1);
}
echo "  (đăng nhập bằng user '{$user['name']}', id={$user['id']})\n";

$loginUrl = $base . EnvConfig::loginUrl();
$r = req($loginUrl . '?redirect=' . rawurlencode($guarded));
preg_match('/name="_csrf"\s+value="([^"]+)"/', $r['body'], $m);
check('trang login trả form + CSRF', $r['code'] === 200 && !empty($m[1]));

$r = req($loginUrl, http_build_query([
    'username' => $user['name'], 'password' => $user['password'],
    '_csrf' => $m[1] ?? '', 'redirect' => $guarded,
]));
check('login đúng → 302 quay lại đúng path ban đầu', $r['code'] === 302 && rawurldecode($r['loc']) === $guarded,
    $r['code'] . ' ' . rawurldecode($r['loc']));

$r = req($base . $guarded, null, true);
check('vào được path cần login', $r['code'] === 200, (string) $r['code']);

// 3. Bối cảnh session: sau khi port, authV2 chỉ set user_id/name/logged_in_at. Nếu dự án còn dựa vào
//    khoá khác (ho_ten, vai_tro, phong_ban...) mà không dựng lại mỗi request thì trang vẫn 200 nhưng
//    hiện rỗng — nên phải nhìn nội dung, không chỉ nhìn mã trạng thái.
$hasLogout = (bool) preg_match('/Đăng xuất|logout/i', $r['body']);
$stillLogin = (bool) preg_match('/Đăng nhập/u', $r['body']);
check('trang hiển thị trạng thái ĐÃ đăng nhập', $hasLogout && !$stillLogin,
    'có link Đăng xuất: ' . var_export($hasLogout, true) . ', còn hiện "Đăng nhập": ' . var_export($stillLogin, true));
echo "  → Tự kiểm tra bằng mắt: tên/phòng ban/vai trò trên navbar có đúng không (khoá session dựng lại đủ chưa)\n";

// 4. Một phiên dùng chung cho mọi dự án.
foreach (EnvConfig::projects() as $p => $label) {
    if ($p === $prefix) {
        continue;
    }
    $r = req($base . $p);
    check("SSO chéo sang $label ($p)", $r['code'] !== 302 || !str_contains($r['loc'], EnvConfig::loginUrl()),
        $r['code'] . ' ' . $r['loc']);
}
$r = req($base . EnvConfig::portalUrl());
check('portal liệt kê dự án này', str_contains($r['body'], $prefix));

// 5. Đăng xuất phải huỷ PHIÊN CHUNG, không chỉ đá user ra khỏi dự án đang đứng. Không kiểm tra bằng
//    cách gọi trang chủ dự án khác — nhiều dự án cố ý để trang chủ public (lichkehoach), 200 ở đó
//    không nói lên điều gì. Portal của authV2 thì luôn đòi đăng nhập nên là phép thử sạch.
req($base . EnvConfig::logoutUrl(), null, true);
$r = req($base . $guarded);
check('sau logout, dự án này chặn lại', $r['code'] === 302 && str_contains($r['loc'], EnvConfig::loginUrl()),
    $r['code'] . ' ' . $r['loc']);
$r = req($base . EnvConfig::portalUrl());
check('sau logout, phiên chung đã bị huỷ (portal đòi login lại)',
    $r['code'] === 302 && str_contains($r['loc'], EnvConfig::loginUrl()), $r['code'] . ' ' . $r['loc']);

// 6. Bất biến rollout: chừng nào còn dự án chưa port, không được có hash nào bị ghi vào DB.
$total = (int) $db->query("SELECT COUNT(*) c FROM `user`")->fetch_assoc()['c'];
$hashed = (int) $db->query("SELECT COUNT(*) c FROM `user` WHERE password LIKE '\$2%'")->fetch_assoc()['c'];
$expectHash = EnvConfig::upgradeOnLogin();
check('mật khẩu trong DB không bị đổi (upgrade-on-login đang TẮT)', $expectHash || $hashed === 0,
    "$total user, $hashed bcrypt" . ($expectHash ? ' (upgrade đang BẬT — kỳ vọng tăng dần)' : ''));

echo "\n$pass pass, $fail fail\n";
exit($fail === 0 ? 0 : 1);
