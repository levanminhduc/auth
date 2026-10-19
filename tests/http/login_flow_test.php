<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;

/**
 * Smoke HTTP cho app login, chạy trên `php -S` (golden baseline).
 *
 * Cần MySQL: test tự thêm một user tạm tên `__authv2_http_test__` vào bảng `user` thật rồi xoá ở
 * cuối, vì `public/index.php` cố định bảng `user`. `SHARED_AUTH_UPGRADE_ON_LOGIN` để TẮT nên
 * không có mật khẩu nào trong bảng bị ghi lại.
 */

const HTTP_HOST_PORT = '127.0.0.1:8799';
const HTTP_USER = '__authv2_http_test__';
const HTTP_PASS = 'matkhau-test-123';

final class HttpResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function sessionCookie(): ?string
    {
        foreach ($this->headers['set-cookie'] ?? [] as $line) {
            if (preg_match('/^HTSSOSESSID=([^;]*)/', $line, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }
}

function http_build_request(string $path, array $options = []): string
{
    $method = $options['method'] ?? 'GET';
    $body = '';
    $headers = ['Host: ' . ($options['hostPort'] ?? HTTP_HOST_PORT), 'Connection: close'];

    if (isset($options['post'])) {
        $method = 'POST';
        $body = http_build_query($options['post']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    if (!empty($options['cookie'])) {
        $headers[] = 'Cookie: HTSSOSESSID=' . $options['cookie'];
    }
    foreach ($options['headers'] ?? [] as $header) {
        $headers[] = $header;
    }

    return "{$method} {$path} HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function http_parse_response(string $raw): HttpResponse
{
    [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $status = (int) (explode(' ', array_shift($lines))[1] ?? 0);

    $parsed = [];
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $parsed[strtolower(trim($parts[0]))][] = trim($parts[1]);
        }
    }

    return new HttpResponse($status, $parsed, $responseBody);
}

function http_request(string $path, array $options = []): HttpResponse
{
    [$host, $port] = explode(':', $options['hostPort'] ?? HTTP_HOST_PORT);
    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 5);
    if ($socket === false) {
        fail_test("không kết nối được server test: {$errstr}");
    }
    stream_set_timeout($socket, 5);

    fwrite($socket, http_build_request($path, $options));

    $raw = '';
    while (!feof($socket)) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    fclose($socket);

    return http_parse_response($raw);
}

/**
 * Gửi mọi request cùng lúc (ghi hết trước, đọc sau) tới các server khác nhau; mỗi `php -S` chỉ xử lý
 * tuần tự nên song song thật chỉ có giữa các server.
 *
 * @param list<array{0: string, 1: array}> $jobs [path, options]
 * @return list<HttpResponse>
 */
function http_request_many(array $jobs): array
{
    $sockets = [];
    $raw = [];
    foreach ($jobs as $i => [$path, $options]) {
        $socket = @stream_socket_client('tcp://' . ($options['hostPort'] ?? HTTP_HOST_PORT), $errno, $errstr, 5);
        if ($socket === false) {
            fail_test("không kết nối được server test: {$errstr}");
        }
        fwrite($socket, http_build_request($path, $options));
        $sockets[$i] = $socket;
        $raw[$i] = '';
    }

    $deadline = microtime(true) + 30;
    while ($sockets !== [] && microtime(true) < $deadline) {
        $read = array_values($sockets);
        $write = $except = null;
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }
        foreach ($read as $socket) {
            $i = array_search($socket, $sockets, true);
            $chunk = fread($socket, 8192);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                fclose($socket);
                unset($sockets[$i]);
                continue;
            }
            $raw[$i] .= $chunk;
        }
    }
    foreach ($sockets as $socket) {
        fclose($socket);
    }

    return array_map('http_parse_response', $raw);
}

function http_csrf(string $body): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) !== 1) {
        fail_test('không tìm thấy _csrf trong form login');
    }
    return $m[1];
}

function http_hidden_redirect(string $body): string
{
    if (preg_match('/name="redirect" value="([^"]*)"/', $body, $m) !== 1) {
        fail_test('không tìm thấy field redirect trong form login');
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/**
 * Chạy `php -S` trên $hostPort với env test; trả null nếu không lên được.
 *
 * @param array<string, string> $extraEnv
 * @return resource|null
 */
function http_spawn_server(string $hostPort, array $extraEnv = [])
{
    $root = dirname(__DIR__, 2);

    // Ghi log server ra file, KHÔNG ra pipe: `php -S` log mọi request vào stderr, pipe không ai
    // đọc sẽ đầy và làm server đứng giữa chừng.
    $logFile = sys_get_temp_dir() . '/authv2-test-server-' . str_replace(':', '-', $hostPort) . '.log';
    $descriptors = [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']];
    // `bypass_shell` + dạng mảng: trên Windows, chạy qua cmd.exe thì proc_terminate() chỉ giết cái
    // vỏ cmd, php.exe vẫn sống và proc_close() treo vô hạn khi dọn dẹp.
    $process = @proc_open(
        [PHP_BINARY, '-S', $hostPort, '-t', $root],
        $descriptors,
        $pipes,
        $root,
        $extraEnv + [
            'SHARED_AUTH_ENV'              => 'local',
            'SHARED_AUTH_BASE_URL'         => '/public',
            'SHARED_AUTH_PROJECTS'         => '/duan-test/|Dự án test',
            'SHARED_AUTH_UPGRADE_ON_LOGIN' => '0',
            'AUTHV2_THROTTLE_TABLE'        => '_authv2_test_attempts',
            'PATH'                         => getenv('PATH') ?: '',
            'SystemRoot'                   => getenv('SystemRoot') ?: '',
        ],
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return null;
    }

    [$host, $port] = explode(':', $hostPort);
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1);
        if ($probe !== false) {
            fclose($probe);
            return $process;
        }
        usleep(100000);
    }
    proc_terminate($process);
    proc_close($process);
    return null;
}

/**
 * Dựng server + user tạm một lần, dùng chung cho mọi test trong file này.
 * Trả về null nếu không chạy được → mọi test SKIP.
 */
function http_env(): ?array
{
    static $env = null;
    static $tried = false;

    if ($tried) {
        return $env;
    }
    $tried = true;

    $conn = test_db();
    if ($conn === null) {
        return null;
    }

    $root = dirname(__DIR__, 2);

    $process = http_spawn_server(HTTP_HOST_PORT);
    if ($process === null) {
        return null;
    }

    $conn->query("DELETE FROM `user` WHERE name = '" . HTTP_USER . "'");
    $stmt = $conn->prepare('INSERT INTO `user` (name, password, signature_image, full_name, role) VALUES (?, ?, "", "HTTP Test", "user")');
    $name = HTTP_USER;
    $pass = HTTP_PASS;
    $stmt->bind_param('ss', $name, $pass);
    $stmt->execute();
    $stmt->close();

    $conn->query('DROP TABLE IF EXISTS `_authv2_test_attempts`');
    $ddl = str_replace(
        'auth_login_attempts',
        '_authv2_test_attempts',
        (string) file_get_contents($root . '/database/auth_login_attempts.sql')
    );
    if ($conn->query($ddl) === false) {
        proc_terminate($process);
        return null;
    }

    register_shutdown_function(static function () use ($process, $conn): void {
        proc_terminate($process);
        proc_close($process);
        $conn->query("DELETE FROM `user` WHERE name = '" . HTTP_USER . "'");
        $conn->query('DROP TABLE IF EXISTS `_authv2_test_attempts`');
    });

    $env = ['conn' => $conn];
    return $env;
}

function http_require_env(): void
{
    if (http_env() === null) {
        skip_test('không dựng được server `php -S` hoặc không có MySQL');
    }
}

test('HTTP: GET trang login trả form kèm CSRF và cookie phiên', function (): void {
    http_require_env();

    $response = http_request('/public/index.php');
    assert_same(200, $response->status, 'trạng thái');
    assert_contains('name="username"', $response->body, 'có ô username');
    assert_contains('name="password"', $response->body, 'có ô password');
    assert_true($response->sessionCookie() !== null, 'có cookie HTSSOSESSID');
    assert_true(http_csrf($response->body) !== '', 'có token CSRF');
});

test('HTTP: cookie phiên đặt HttpOnly, path=/, SameSite=Lax, không Secure ở local', function (): void {
    http_require_env();

    $response = http_request('/public/index.php');
    $line = null;
    foreach ($response->headers['set-cookie'] ?? [] as $candidate) {
        if (str_starts_with($candidate, 'HTSSOSESSID=')) {
            $line = $candidate;
        }
    }
    assert_true($line !== null, 'có Set-Cookie cho HTSSOSESSID');
    assert_contains('HttpOnly', $line, 'HttpOnly');
    assert_contains('path=/', strtolower((string) $line), 'path=/');
    assert_contains('samesite=lax', strtolower((string) $line), 'SameSite=Lax');
    assert_false(str_contains(strtolower((string) $line), 'secure'), 'local thì không đặt Secure');
});

test('HTTP: POST thiếu CSRF bị từ chối, không tạo phiên đăng nhập', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $cookie = (string) $first->sessionCookie();

    $response = http_request('/public/index.php', [
        'cookie' => $cookie,
        'post'   => ['username' => HTTP_USER, 'password' => HTTP_PASS],
    ]);
    assert_same(200, $response->status, 'ở lại form');
    assert_contains('Phiên nhập liệu đã hết hạn', $response->body, 'báo lỗi CSRF');

    $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
    assert_same(302, $portal->status, 'vẫn chưa đăng nhập');
});

test('HTTP: sai mật khẩu ở lại form với thông báo lỗi', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $cookie = (string) $first->sessionCookie();

    $response = http_request('/public/index.php', [
        'cookie' => $cookie,
        'post'   => ['username' => HTTP_USER, 'password' => 'sai-mat-khau', '_csrf' => http_csrf($first->body)],
    ]);
    assert_same(200, $response->status);
    assert_contains('Sai tên đăng nhập hoặc mật khẩu', $response->body);
});

test('HTTP: login đúng → 302 về portal, đổi session id', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $before = (string) $first->sessionCookie();

    $response = http_request('/public/index.php', [
        'cookie' => $before,
        'post'   => ['username' => HTTP_USER, 'password' => HTTP_PASS, '_csrf' => http_csrf($first->body)],
    ]);
    assert_same(302, $response->status, 'chuyển hướng sau khi login');
    assert_same('/public/portal.php', $response->header('location'), 'về portal');

    $after = (string) $response->sessionCookie();
    assert_true($after !== '', 'có cookie mới');
    assert_false($after === $before, 'session id được sinh lại sau khi login');
});

test('HTTP: đã đăng nhập thì portal mở được, trang login bỏ qua form (SSO)', function (): void {
    http_require_env();

    $cookie = http_login();

    $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
    assert_same(200, $portal->status, 'portal mở được');
    assert_contains(HTTP_USER, $portal->body, 'hiện tên người dùng');
    assert_contains('Dự án test', $portal->body, 'liệt kê dự án từ allowlist');

    $login = http_request('/public/index.php', ['cookie' => $cookie]);
    assert_same(302, $login->status, 'không hiện lại form');
    assert_same('/public/portal.php', $login->header('location'));
});

test('HTTP: logout huỷ phiên, sau đó portal lại bị chặn', function (): void {
    http_require_env();

    $cookie = http_login();

    $logout = http_request('/public/logout.php', ['cookie' => $cookie]);
    assert_same(302, $logout->status);
    assert_same('/public/index.php', $logout->header('location'), 'về trang login');

    $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
    assert_same(302, $portal->status, 'phiên đã mất');
    assert_contains('/public/index.php', (string) $portal->header('location'));
});

test('HTTP: ?redirect= ngoài allowlist bị thay bằng portal ngay trong form', function (): void {
    http_require_env();

    $evil = http_request('/public/index.php?redirect=' . rawurlencode('http://evil.com/'));
    assert_same('/public/portal.php', http_hidden_redirect($evil->body), 'host ngoài bị loại');

    $ok = http_request('/public/index.php?redirect=' . rawurlencode('/duan-test/trang.php'));
    assert_same('/duan-test/trang.php', http_hidden_redirect($ok->body), 'path trong allowlist được giữ');
});

test('HTTP: login xong quay đúng về nơi đã yêu cầu', function (): void {
    http_require_env();

    $first = http_request('/public/index.php?redirect=' . rawurlencode('/duan-test/trang.php'));
    $response = http_request('/public/index.php', [
        'cookie' => (string) $first->sessionCookie(),
        'post'   => [
            'username' => HTTP_USER,
            'password' => HTTP_PASS,
            '_csrf'    => http_csrf($first->body),
            'redirect' => '/duan-test/trang.php',
        ],
    ]);
    assert_same(302, $response->status);
    assert_same('/duan-test/trang.php', $response->header('location'));
});

test('HTTP: login đúng KHÔNG ghi lại mật khẩu khi công tắc nâng cấp đang tắt', function (): void {
    http_require_env();

    http_login();

    $conn = test_db();
    assert_same(
        HTTP_PASS,
        fetch_password($conn, 'user', HTTP_USER),
        'mật khẩu trong DB vẫn nguyên plaintext'
    );
});

function http_clear_attempts(): void
{
    $conn = test_db();
    if ($conn !== null) {
        $conn->query('DELETE FROM `_authv2_test_attempts`');
    }
}

function http_login(): string
{
    $first = http_request('/public/index.php');
    $response = http_request('/public/index.php', [
        'cookie' => (string) $first->sessionCookie(),
        'post'   => ['username' => HTTP_USER, 'password' => HTTP_PASS, '_csrf' => http_csrf($first->body)],
    ]);
    if ($response->status !== 302) {
        fail_test('login thất bại khi dựng bối cảnh test');
    }
    return (string) $response->sessionCookie();
}

test('HTTP: security header đầy đủ, không còn meta CSP/inline style/CDN', function (): void {
    http_require_env();

    $response = http_request('/public/index.php');
    $csp = (string) $response->header('content-security-policy');
    assert_contains("frame-ancestors 'none'", $csp);
    assert_contains("form-action 'self'", $csp);
    assert_contains("style-src 'self'", $csp);
    assert_false(str_contains($csp, 'unsafe-inline'), 'CSP không chứa unsafe-inline');
    assert_same('DENY', $response->header('x-frame-options'));
    assert_same('nosniff', $response->header('x-content-type-options'));
    assert_same('same-origin', $response->header('referrer-policy'));
    assert_contains('no-store', (string) $response->header('cache-control'));

    assert_false(str_contains($response->body, 'http-equiv'), 'không meta CSP');
    assert_false(str_contains($response->body, '<style'), 'không thẻ style');
    assert_false(str_contains($response->body, ' style="'), 'không inline style');
    assert_false(str_contains($response->body, 'cdn.' . 'jsdelivr'), 'không CDN jsDelivr');
    assert_false(str_contains($response->body, 'cdn' . 'js.cloudflare'), 'không CDN Cloudflare');
    assert_false(str_contains($response->body, 'boot' . 'strap'), 'không framework CSS cũ');
    assert_false(str_contains($response->body, 'font-' . 'awesome'), 'không webfont icon cũ');
});

test('HTTP: sai mật khẩu giữ username và focus vào ô mật khẩu', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $response = http_request('/public/index.php', [
        'cookie' => (string) $first->sessionCookie(),
        'post'   => ['username' => HTTP_USER, 'password' => 'sai-mat-khau', '_csrf' => http_csrf($first->body)],
    ]);
    assert_same(200, $response->status);
    assert_true(
        preg_match('/id="username"[^>]*value="' . preg_quote(HTTP_USER, '/') . '"/', $response->body) === 1,
        'giữ username'
    );
    assert_true(preg_match('/id="password"[^>]*autofocus/', $response->body) === 1, 'focus password');
    assert_true(preg_match('/id="username"[^>]*autofocus/', $response->body) === 0, 'username không autofocus');
    assert_false(str_contains($response->body, 'sai-mat-khau'), 'không in lại mật khẩu');
});

test('HTTP: ?reason=expired hiện banner, reason lạ bị bỏ qua', function (): void {
    http_require_env();

    $expired = http_request('/public/index.php?reason=expired');
    assert_contains('Phiên làm việc đã hết hạn', $expired->body);

    $junk = http_request('/public/index.php?reason=khong-hop-le');
    assert_false(str_contains($junk->body, 'Phiên làm việc đã hết hạn'), 'reason lạ bị bỏ');
    assert_false(str_contains($junk->body, 'alert-warn'), 'không banner vàng');
});

test('HTTP: sai 5 lần → 429, đúng mật khẩu vẫn bị chặn', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $first = http_request('/public/index.php');
        $cookie = (string) $first->sessionCookie();
        for ($i = 0; $i < 5; $i++) {
            $page = http_request('/public/index.php', ['cookie' => $cookie]);
            $response = http_request('/public/index.php', [
                'cookie' => $cookie,
                'post'   => [
                    'username' => HTTP_USER,
                    'password' => 'sai-' . $i,
                    '_csrf'    => http_csrf($page->body),
                ],
            ]);
            assert_same(200, $response->status, 'lần sai ' . ($i + 1));
            $next = $response->sessionCookie();
            if ($next !== null && $next !== '') {
                $cookie = $next;
            }
        }

        $page = http_request('/public/index.php', ['cookie' => $cookie]);
        $locked = http_request('/public/index.php', [
            'cookie' => $cookie,
            'post'   => [
                'username' => HTTP_USER,
                'password' => HTTP_PASS,
                '_csrf'    => http_csrf($page->body),
            ],
        ]);
        assert_same(429, $locked->status, 'đúng mật khẩu vẫn 429');
        assert_contains('thử lại sau', $locked->body);
        assert_true(
            preg_match('/thử lại sau (\d+) phút/', $locked->body, $m) === 1,
            'có số phút'
        );
        $mins = (int) $m[1];
        assert_true($mins >= 1 && $mins <= 15, 'phút trong [1,15], nhận ' . $mins);

        $after = $locked->sessionCookie();
        assert_true($after === null || $after === $cookie, 'không đổi session id');

        $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
        assert_same(302, $portal->status, 'vẫn chưa đăng nhập');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: login đúng xoá bộ đếm của username', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $first = http_request('/public/index.php');
        $cookie = (string) $first->sessionCookie();
        for ($i = 0; $i < 2; $i++) {
            $page = http_request('/public/index.php', ['cookie' => $cookie]);
            $response = http_request('/public/index.php', [
                'cookie' => $cookie,
                'post'   => [
                    'username' => HTTP_USER,
                    'password' => 'sai-' . $i,
                    '_csrf'    => http_csrf($page->body),
                ],
            ]);
            assert_same(200, $response->status);
            $next = $response->sessionCookie();
            if ($next !== null && $next !== '') {
                $cookie = $next;
            }
        }

        $page = http_request('/public/index.php', ['cookie' => $cookie]);
        $ok = http_request('/public/index.php', [
            'cookie' => $cookie,
            'post'   => [
                'username' => HTTP_USER,
                'password' => HTTP_PASS,
                '_csrf'    => http_csrf($page->body),
            ],
        ]);
        assert_same(302, $ok->status, 'login đúng');

        $conn = test_db();
        $u = $conn->query("SELECT COUNT(*) AS n FROM `_authv2_test_attempts` WHERE identifier='u:" . HTTP_USER . "'");
        assert_same(0, (int) $u->fetch_assoc()['n'], 'khoá username bị xoá');
        $ip = $conn->query("SELECT COUNT(*) AS n FROM `_authv2_test_attempts` WHERE identifier='ip:127.0.0.1'");
        assert_same(2, (int) $ip->fetch_assoc()['n'], 'khoá IP còn');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: CSRF session key là shared_auth_csrf', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $id = $first->sessionCookie();
    assert_true($id !== null && $id !== '', 'có session id');

    $path = EnvConfig::sessionSavePath() . '/sess_' . $id;
    $data = false;
    for ($i = 0; $i < 10; $i++) {
        if (is_file($path)) {
            $data = file_get_contents($path);
            if ($data !== false && $data !== '') {
                break;
            }
        }
        usleep(50000);
    }
    if ($data === false || $data === '') {
        fail_test('không đọc được file session tại ' . $path);
    }
    assert_true(str_contains($data, 'shared_auth_csrf|'), 'có key shared_auth_csrf');
    assert_same(0, preg_match('/(?<![A-Za-z0-9_])_csrf\|/', $data), 'không còn key _csrf');
});

function http_attempt_count(?string $identifier = null): int
{
    $conn = test_db();
    if ($identifier === null) {
        $result = $conn->query('SELECT COUNT(*) AS n FROM `_authv2_test_attempts`');
    } else {
        $result = $conn->query(
            "SELECT COUNT(*) AS n FROM `_authv2_test_attempts` WHERE identifier = '" . $conn->real_escape_string($identifier) . "'"
        );
    }
    return (int) $result->fetch_assoc()['n'];
}

function http_post_login(array $fields, array $options = [], string $path = '/public/index.php'): HttpResponse
{
    $first = http_request('/public/index.php', $options);
    return http_request($path, $options + [
        'cookie' => (string) $first->sessionCookie(),
        'post'   => $fields + ['_csrf' => http_csrf($first->body)],
    ]);
}

const HTTP_GENERIC_ERROR = 'Sai tên đăng nhập hoặc mật khẩu';

test('HTTP: username không phải UTF-8 bị từ chối bằng lỗi chung, không ghi throttle', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $response = http_post_login(['username' => "bad\xC3\x28name", 'password' => 'x']);
        assert_same(200, $response->status);
        assert_contains(HTTP_GENERIC_ERROR, $response->body, 'cùng thông báo với sai mật khẩu');
        assert_false(str_contains($response->body, "\xC3\x28"), 'không in lại byte lỗi');
        assert_same(0, http_attempt_count(), 'không chạm bảng throttle');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: username dài quá 255 ký tự và mật khẩu dài quá 1024 byte bị từ chối, không chạm throttle', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $long = http_post_login(['username' => str_repeat('a', 256), 'password' => 'x']);
        assert_same(200, $long->status);
        assert_contains(HTTP_GENERIC_ERROR, $long->body);
        assert_same(0, http_attempt_count(), 'username 256 ký tự không ghi throttle');

        $multibyte = http_post_login(['username' => str_repeat('đ', 256), 'password' => 'x']);
        assert_contains(HTTP_GENERIC_ERROR, $multibyte->body);
        assert_same(0, http_attempt_count(), 'tính theo ký tự, không theo byte');

        $bigPassword = http_post_login(['username' => HTTP_USER, 'password' => str_repeat('p', 1025)]);
        assert_same(200, $bigPassword->status);
        assert_contains(HTTP_GENERIC_ERROR, $bigPassword->body);
        assert_same(0, http_attempt_count(), 'mật khẩu 1025 byte không ghi throttle');

        $boundary = http_post_login(['username' => str_repeat('a', 255), 'password' => str_repeat('p', 1024)]);
        assert_contains(HTTP_GENERIC_ERROR, $boundary->body);
        assert_true(http_attempt_count() > 0, 'đúng giới hạn thì vẫn đi qua throttle + verifier');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: tham số dạng mảng trên trang login không gây lỗi fatal', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $get = http_request('/public/index.php?redirect[]=x&reason[]=expired');
        assert_same(200, $get->status);
        assert_same('/public/portal.php', http_hidden_redirect($get->body), 'redirect mảng = không có');
        assert_false(str_contains($get->body, 'Phiên làm việc đã hết hạn'), 'reason mảng bị bỏ');
        assert_false(str_contains($get->body, 'TypeError'), 'không lộ lỗi');

        $first = http_request('/public/index.php');
        $cookie = (string) $first->sessionCookie();
        $csrf = http_csrf($first->body);

        $arrays = http_request('/public/index.php?redirect[]=x', [
            'cookie' => $cookie,
            'post'   => ['username' => ['a'], 'password' => ['b'], '_csrf' => $csrf, 'redirect' => ['y']],
        ]);
        assert_same(200, $arrays->status);
        assert_contains('Vui lòng nhập tên đăng nhập và mật khẩu', $arrays->body, 'mảng = trống');

        $csrfArray = http_request('/public/index.php', [
            'cookie' => $cookie,
            'post'   => ['username' => HTTP_USER, 'password' => HTTP_PASS, '_csrf' => [$csrf]],
        ]);
        assert_same(200, $csrfArray->status);
        assert_contains('Phiên nhập liệu đã hết hạn', $csrfArray->body, '_csrf mảng bị từ chối');
        assert_same(0, http_attempt_count(), 'không chạm throttle');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: reason=expired không hiện lại sau khi đăng nhập sai (POST)', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $get = http_request('/public/index.php?reason=expired');
        assert_contains('Phiên làm việc đã hết hạn', $get->body, 'GET vẫn hiện banner');

        $post = http_request('/public/index.php?reason=expired', [
            'cookie' => (string) $get->sessionCookie(),
            'post'   => ['username' => HTTP_USER, 'password' => 'sai-mat-khau', '_csrf' => http_csrf($get->body)],
        ]);
        assert_same(200, $post->status);
        assert_contains(HTTP_GENERIC_ERROR, $post->body);
        assert_false(str_contains($post->body, 'Phiên làm việc đã hết hạn'), 'POST không hiện banner hết hạn');
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: logout với redirect dạng mảng vẫn huỷ phiên', function (): void {
    http_require_env();

    $cookie = http_login();
    $logout = http_request('/public/logout.php?redirect[]=x', ['cookie' => $cookie]);
    assert_same(302, $logout->status);
    assert_same('/public/index.php', $logout->header('location'), 'redirect mảng = không có');

    $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
    assert_same(302, $portal->status, 'phiên đã mất');
});

test('HTTP: logout bị kích hoạt từ nơi khác thì KHÔNG huỷ phiên', function (): void {
    http_require_env();

    $cases = [
        'Sec-Fetch-Site cross-site'      => ['Sec-Fetch-Site: cross-site', 'Sec-Fetch-Dest: document'],
        'Sec-Fetch-Dest image'           => ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Dest: image'],
        'Sec-Fetch-Site same-site'       => ['Sec-Fetch-Site: same-site', 'Sec-Fetch-Dest: document'],
        'Sec-Fetch-Site lạ'              => ['Sec-Fetch-Site: khong-biet', 'Sec-Fetch-Dest: document'],
        'Sec-Fetch-Site same-site, thiếu dest' => ['Sec-Fetch-Site: same-site'],
        'Sec-Fetch-Dest iframe'          => ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Dest: iframe'],
        'Origin lạ'                      => ['Origin: http://evil.com'],
        'Origin null'                    => ['Origin: null'],
        'Origin cổng khác'               => ['Origin: http://127.0.0.1:8798'],
        'Origin không cổng'              => ['Origin: http://127.0.0.1'],
        'Origin host khác cùng máy'      => ['Origin: http://localhost:8799'],
        'Origin https'                   => ['Origin: https://' . HTTP_HOST_PORT],
        'Referer lạ'                     => ['Referer: http://evil.com/trang.html'],
        'Referer cổng khác'              => ['Referer: http://127.0.0.1:8798/duan-test/trang.php'],
        'Referer đánh lừa bằng userinfo' => ['Referer: http://' . HTTP_HOST_PORT . '@evil.com/'],
    ];
    foreach ($cases as $label => $headers) {
        $cookie = http_login();
        $logout = http_request('/public/logout.php?redirect=' . rawurlencode('/duan-test/trang.php'), [
            'cookie'  => $cookie,
            'headers' => $headers,
        ]);
        assert_same(303, $logout->status, $label);
        assert_same(
            '/public/index.php?redirect=' . rawurlencode('/duan-test/trang.php'),
            $logout->header('location'),
            $label . ': vẫn mang redirect đã lọc'
        );
        $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
        assert_same(200, $portal->status, $label . ': phiên còn nguyên');
    }
});

test('HTTP: logout hợp lệ (same-origin, none, Origin/Referer đúng origin của chính mình) huỷ phiên', function (): void {
    http_require_env();

    $cases = [
        'same-origin'  => ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Dest: document'],
        'none'         => ['Sec-Fetch-Site: none', 'Sec-Fetch-Dest: document'],
        'Origin nhà'   => ['Origin: http://' . HTTP_HOST_PORT],
        'Referer nhà'  => ['Referer: http://' . HTTP_HOST_PORT . '/duan-test/trang.php'],
        'không header' => [],
    ];
    foreach ($cases as $label => $headers) {
        $cookie = http_login();
        $logout = http_request('/public/logout.php', ['cookie' => $cookie, 'headers' => $headers]);
        assert_same(302, $logout->status, $label);
        assert_same('/public/index.php', $logout->header('location'), $label);
        $portal = http_request('/public/portal.php', ['cookie' => $cookie]);
        assert_same(302, $portal->status, $label . ': phiên đã mất');
    }
});

test('HTTP: backend DB lỗi → 503, form hiện lại, không lộ đường dẫn/stack trace', function (): void {
    http_require_env();

    $source = getenv('SHARED_AUTH_DB_CONFIG') ?: 'C:/xampp/config/db.php';
    $cfg = is_file($source) ? require $source : null;
    if (!is_array($cfg)) {
        skip_test('không đọc được cấu hình DB để dựng DB giả');
    }
    // information_schema không có bảng `user` nên truy vấn xác thực lỗi thật, còn kết nối vẫn thành công.
    $badConfig = tempnam(sys_get_temp_dir(), 'authv2-db-');
    file_put_contents($badConfig, '<?php return ' . var_export(['database' => 'information_schema'] + $cfg, true) . ';');

    $hostPort = '127.0.0.1:8798';
    $process = http_spawn_server($hostPort, ['SHARED_AUTH_DB_CONFIG' => $badConfig]);
    try {
        if ($process === null) {
            skip_test('không dựng được server phụ');
        }
        $first = http_request('/public/index.php', ['hostPort' => $hostPort]);
        $response = http_request('/public/index.php', [
            'hostPort' => $hostPort,
            'cookie'   => (string) $first->sessionCookie(),
            'post'     => ['username' => HTTP_USER, 'password' => HTTP_PASS, '_csrf' => http_csrf($first->body)],
        ]);
        assert_same(503, $response->status);
        assert_contains('Hệ thống đang bận, vui lòng thử lại.', $response->body);
        assert_contains('name="username"', $response->body, 'form hiện lại');
        assert_same(null, $response->header('location'), 'không đăng nhập');
        foreach (['Stack trace', 'mysqli', 'Fatal error', 'Exception', '.php on line', 'information_schema', 'C:\\'] as $leak) {
            assert_false(str_contains($response->body, $leak), 'không lộ ' . $leak);
        }
        assert_false(str_contains($response->body, HTTP_PASS), 'không in lại mật khẩu');
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        @unlink($badConfig);
    }
});

test('HTTP: lỗi DB khi xác thực → 503 và không để lại bản ghi throttle (không tính là lần sai)', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        // Cột user.name là utf8mb3 nên ký tự 4 byte làm truy vấn xác thực lỗi thật, còn bảng throttle (utf8mb4) vẫn ghi được.
        $emoji = "\u{1F600}";
        for ($i = 0; $i < 7; $i++) {
            $response = http_post_login(['username' => $emoji, 'password' => 'x']);
            assert_same(503, $response->status, 'lần ' . ($i + 1) . ' vẫn 503, không bị chuyển sang 429');
            assert_contains('Hệ thống đang bận, vui lòng thử lại.', $response->body);
            assert_same(0, http_attempt_count(), 'lần ' . ($i + 1) . ': không còn bản ghi throttle nào');
        }
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: mật khẩu chứa byte NUL → lỗi chung 200, không 500/stack trace, không đăng nhập', function (): void {
    http_require_env();
    http_clear_attempts();
    try {
        $cases = [
            'user không tồn tại'      => ['__authv2_khong_co__', "\0"],
            'user plaintext, sai'     => [HTTP_USER, "\0"],
            'user plaintext, tiền tố' => [HTTP_USER, HTTP_PASS . "\0x"],
        ];
        foreach ($cases as $label => [$username, $password]) {
            $response = http_post_login(['username' => $username, 'password' => $password]);
            assert_same(200, $response->status, $label);
            assert_contains(HTTP_GENERIC_ERROR, $response->body, $label);
            assert_same(null, $response->header('location'), $label . ': không đăng nhập');
            foreach (['ValueError', 'Stack trace', 'Fatal error', '.php on line'] as $leak) {
                assert_false(str_contains($response->body, $leak), $label . ': không lộ ' . $leak);
            }
        }
    } finally {
        http_clear_attempts();
    }
});

test('HTTP: đăng nhập thành công không thừa kế khoá còn sót trong phiên cũ', function (): void {
    http_require_env();

    $first = http_request('/public/index.php');
    $oldId = (string) $first->sessionCookie();
    $oldPath = EnvConfig::sessionSavePath() . '/sess_' . $oldId;
    for ($i = 0; $i < 10 && !(is_file($oldPath) && (string) file_get_contents($oldPath) !== ''); $i++) {
        usleep(50000);
    }
    if (!is_file($oldPath)) {
        fail_test('không đọc được file session tại ' . $oldPath);
    }
    file_put_contents($oldPath, 'nhan_vien_ma|s:3:"EVL";phong_ban_ma|s:2:"PB";', FILE_APPEND);

    $response = http_request('/public/index.php', [
        'cookie' => $oldId,
        'post'   => ['username' => HTTP_USER, 'password' => HTTP_PASS, '_csrf' => http_csrf($first->body)],
    ]);
    assert_same(302, $response->status, 'đăng nhập được');
    $newId = (string) $response->sessionCookie();
    assert_true($newId !== '' && $newId !== $oldId, 'đổi session id');

    $newPath = EnvConfig::sessionSavePath() . '/sess_' . $newId;
    $data = '';
    for ($i = 0; $i < 10 && !str_contains($data, 'user_id|'); $i++) {
        usleep(50000);
        $data = is_file($newPath) ? (string) file_get_contents($newPath) : '';
    }
    assert_contains('user_id|', $data, 'phiên mới có user_id');
    assert_false(str_contains($data, 'nhan_vien_ma'), 'khoá dự án cũ không được thừa kế');
    assert_false(str_contains($data, 'phong_ban_ma'), 'khoá dự án cũ không được thừa kế');
    assert_false(str_contains($data, 'shared_auth_csrf'), 'CSRF đã bị bỏ');
});

test('HTTP: 20 POST sai mật khẩu song song cho một username → đúng 5 request được verify, 15 bị 429', function (): void {
    http_require_env();

    // `php -S` đơn luồng, PHP_CLI_SERVER_WORKERS cần fork() nên không có trên Windows; thay vào đó
    // chạy nhiều server trên các cổng khác nhau dùng chung DB để các request thật sự chồng lên nhau.
    $extraPorts = [8801, 8802, 8803, 8804, 8805];
    $processes = [];
    $hostPorts = [HTTP_HOST_PORT];
    http_clear_attempts();
    try {
        foreach ($extraPorts as $port) {
            $hostPort = '127.0.0.1:' . $port;
            $process = http_spawn_server($hostPort);
            if ($process === null) {
                skip_test('không dựng được server phụ trên cổng ' . $port);
            }
            $processes[] = $process;
            $hostPorts[] = $hostPort;
        }

        $jobs = [];
        for ($i = 0; $i < 20; $i++) {
            $hostPort = $hostPorts[$i % count($hostPorts)];
            $first = http_request('/public/index.php', ['hostPort' => $hostPort]);
            $jobs[] = ['/public/index.php', [
                'hostPort' => $hostPort,
                'cookie'   => (string) $first->sessionCookie(),
                'post'     => ['username' => HTTP_USER, 'password' => 'sai-' . $i, '_csrf' => http_csrf($first->body)],
            ]];
        }

        $verified = 0;
        $locked = 0;
        foreach (http_request_many($jobs) as $i => $response) {
            if ($response->status === 429) {
                $locked++;
            } elseif ($response->status === 200 && str_contains($response->body, HTTP_GENERIC_ERROR)) {
                $verified++;
            } else {
                fail_test("request #{$i}: trạng thái bất ngờ {$response->status}");
            }
        }

        assert_same(20, $verified + $locked, 'mọi request đều có phản hồi hợp lệ');
        assert_same(5, $verified, "đúng 5 request được verify, nhận {$verified}");
        assert_same(15, $locked, "đúng 15 request bị 429, nhận {$locked}");
        $rows = http_attempt_count('p:' . HTTP_USER . '|127.0.0.1');
        assert_true($rows <= 5, "tối đa 5 bản ghi p:, nhận {$rows}");
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        http_clear_attempts();
    }
});
