<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

final class SkippedTest extends RuntimeException
{
}

final class FailedAssertion extends RuntimeException
{
}

final class TestRegistry
{
    /** @var list<array{name: string, fn: callable}> */
    public static array $cases = [];
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var list<string> */
    public static array $failures = [];
    /** @var list<string> */
    public static array $skips = [];
}

function test(string $name, callable $fn): void
{
    TestRegistry::$cases[] = ['name' => $name, 'fn' => $fn];
}

function skip_test(string $reason): void
{
    throw new SkippedTest($reason);
}

function fail_test(string $message): void
{
    throw new FailedAssertion($message);
}

function assert_same($expected, $actual, string $context = ''): void
{
    if ($expected !== $actual) {
        fail_test(trim($context . ' — mong ' . export_value($expected) . ', nhận ' . export_value($actual)));
    }
}

function assert_true($actual, string $context = ''): void
{
    assert_same(true, $actual, $context);
}

function assert_false($actual, string $context = ''): void
{
    assert_same(false, $actual, $context);
}

function assert_null($actual, string $context = ''): void
{
    assert_same(null, $actual, $context);
}

function assert_contains(string $needle, string $haystack, string $context = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail_test(trim($context . ' — không thấy ' . export_value($needle) . ' trong ' . export_value($haystack)));
    }
}

function export_value($value): string
{
    if (is_string($value)) {
        return "'" . $value . "'";
    }
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return (string) $value;
}

/**
 * Chạy $fn với biến môi trường đã đặt, rồi trả môi trường về nguyên trạng.
 * EnvConfig đọc getenv() ở mỗi lần gọi nên không cần xoá cache gì.
 *
 * @param array<string, string|null> $vars
 */
function with_env(array $vars, callable $fn)
{
    $previous = [];
    foreach ($vars as $key => $value) {
        $current = getenv($key);
        $previous[$key] = ($current === false) ? null : $current;
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }

    try {
        return $fn();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }
}

/**
 * Môi trường mặc định của bộ test: local, allowlist đúng 2 dự án đã tích hợp.
 * Chốt rõ ở đây để test không phụ thuộc env của máy đang chạy.
 *
 * @param array<string, string|null> $overrides
 */
function with_default_env(callable $fn, array $overrides = [])
{
    return with_env($overrides + [
        'SHARED_AUTH_ENV'              => 'local',
        'SHARED_AUTH_BASE_URL'         => '/authV2/public',
        'SHARED_AUTH_PROJECTS'         => '/lichkehoach/public/|Lịch kế hoạch,/nhansuhtdb/|Nhân sự HTĐB',
        'SHARED_AUTH_ALLOWED_HOSTS'    => null,
        'SHARED_AUTH_SESSION_NAME'     => null,
        'SHARED_AUTH_UPGRADE_ON_LOGIN' => null,
    ], $fn);
}

/**
 * Kết nối DB cho các test cần bảng thật. Trả null nếu không có DB — test gọi nó sẽ SKIP,
 * để bộ test vẫn chạy được trên máy không dựng sẵn MySQL.
 */
function test_db(): ?mysqli
{
    static $conn = null;
    static $tried = false;

    if ($tried) {
        return $conn;
    }
    $tried = true;

    $host = getenv('AUTHV2_TEST_DB_HOST') ?: 'localhost';
    $user = getenv('AUTHV2_TEST_DB_USER') ?: 'root';
    $pass = getenv('AUTHV2_TEST_DB_PASS');
    $name = getenv('AUTHV2_TEST_DB_NAME') ?: 'mysqli';

    mysqli_report(MYSQLI_REPORT_OFF);
    $candidate = @new mysqli($host, $user, $pass === false ? '' : $pass, $name);
    if ($candidate->connect_errno !== 0) {
        return null;
    }
    $candidate->set_charset('utf8mb4');
    $conn = $candidate;
    return $conn;
}

/**
 * Bảng user tạm cho test xác thực. Không bao giờ đụng bảng `user` thật.
 */
function make_user_fixture(mysqli $conn, string $table): void
{
    $conn->query("DROP TABLE IF EXISTS `{$table}`");
    $conn->query(
        "CREATE TABLE `{$table}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function drop_user_fixture(mysqli $conn, string $table): void
{
    $conn->query("DROP TABLE IF EXISTS `{$table}`");
}

function fetch_password(mysqli $conn, string $table, string $username): ?string
{
    $stmt = $conn->prepare("SELECT password FROM `{$table}` WHERE name = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? null : (string) $row['password'];
}
