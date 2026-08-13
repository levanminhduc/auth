<?php

declare(strict_types=1);

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

function http_request(string $path, array $options = []): HttpResponse
{
    [$host, $port] = explode(':', HTTP_HOST_PORT);
    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 5);
    if ($socket === false) {
        fail_test("không kết nối được server test: {$errstr}");
    }
    stream_set_timeout($socket, 5);

    $method = $options['method'] ?? 'GET';
    $body = '';
    $headers = ["Host: " . HTTP_HOST_PORT, 'Connection: close'];

    if (isset($options['post'])) {
        $method = 'POST';
        $body = http_build_query($options['post']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    if (!empty($options['cookie'])) {
        $headers[] = 'Cookie: HTSSOSESSID=' . $options['cookie'];
    }

    fwrite($socket, "{$method} {$path} HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);

    $raw = '';
    while (!feof($socket)) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    fclose($socket);

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

    // Ghi log server ra file, KHÔNG ra pipe: `php -S` log mọi request vào stderr, pipe không ai
    // đọc sẽ đầy và làm server đứng giữa chừng.
    $logFile = sys_get_temp_dir() . '/authv2-test-server.log';
    $descriptors = [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']];
    // `bypass_shell` + dạng mảng: trên Windows, chạy qua cmd.exe thì proc_terminate() chỉ giết cái
    // vỏ cmd, php.exe vẫn sống và proc_close() treo vô hạn khi dọn dẹp.
    $process = @proc_open(
        [PHP_BINARY, '-S', HTTP_HOST_PORT, '-t', $root],
        $descriptors,
        $pipes,
        $root,
        [
            'SHARED_AUTH_ENV'              => 'local',
            'SHARED_AUTH_BASE_URL'         => '/public',
            'SHARED_AUTH_PROJECTS'         => '/duan-test/|Dự án test',
            'SHARED_AUTH_UPGRADE_ON_LOGIN' => '0',
            'PATH'                         => getenv('PATH') ?: '',
            'SystemRoot'                   => getenv('SystemRoot') ?: '',
        ],
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return null;
    }

    [$host, $port] = explode(':', HTTP_HOST_PORT);
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1);
        if ($probe !== false) {
            fclose($probe);
            $up = true;
            break;
        }
        usleep(100000);
    }
    if (!$up) {
        proc_terminate($process);
        return null;
    }

    $conn->query("DELETE FROM `user` WHERE name = '" . HTTP_USER . "'");
    $stmt = $conn->prepare('INSERT INTO `user` (name, password, signature_image, full_name, role) VALUES (?, ?, "", "HTTP Test", "user")');
    $name = HTTP_USER;
    $pass = HTTP_PASS;
    $stmt->bind_param('ss', $name, $pass);
    $stmt->execute();
    $stmt->close();

    register_shutdown_function(static function () use ($process, $conn): void {
        proc_terminate($process);
        proc_close($process);
        $conn->query("DELETE FROM `user` WHERE name = '" . HTTP_USER . "'");
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
