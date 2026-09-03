<?php

declare(strict_types=1);

use Hoatho\SharedAuth\LoginThrottle;

const THROTTLE_TABLE = '_authv2_test_attempts';

function throttle_conn(): mysqli
{
    $conn = test_db();
    if ($conn === null) {
        skip_test('không kết nối được MySQL (đặt AUTHV2_TEST_DB_* nếu cần)');
    }
    return $conn;
}

function throttle_ddl(): string
{
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/auth_login_attempts.sql');
    return str_replace('auth_login_attempts', THROTTLE_TABLE, $sql);
}

function throttle_setup(): mysqli
{
    $conn = throttle_conn();
    $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    if ($conn->query(throttle_ddl()) === false) {
        fail_test('không tạo được bảng tạm: ' . $conn->error);
    }
    return $conn;
}

function throttle_seed(mysqli $conn, string $identifier, int $minutesAgo): void
{
    $stmt = $conn->prepare(
        'INSERT INTO `' . THROTTLE_TABLE . '` (identifier, attempted_at) VALUES (?, NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->bind_param('si', $identifier, $minutesAgo);
    $stmt->execute();
    $stmt->close();
}

function throttle_count(mysqli $conn, string $identifier): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM `' . THROTTLE_TABLE . '` WHERE identifier = ?');
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
}

function throttle_make(mysqli $conn, array $options = []): LoginThrottle
{
    return new LoginThrottle($conn, $options + ['table' => THROTTLE_TABLE]);
}

test('LoginThrottle: dưới ngưỡng (4 lần) thì không khoá', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 4; $i++) {
            $t->recordFailure('alice', '10.0.0.1');
        }
        assert_null($t->lockedMinutes('alice', '10.0.0.1'));
    } finally {
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: 5 lần sai thì khoá trong [1, lockout] và ghi error_log', function (): void {
    $conn = throttle_setup();
    $prevLog = ini_get('error_log');
    $logFile = sys_get_temp_dir() . '/authv2-throttle-test.log';
    @unlink($logFile);
    ini_set('error_log', $logFile);
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('alice', '10.0.0.1');
        }
        $minutes = $t->lockedMinutes('alice', '10.0.0.1');
        assert_true(is_int($minutes), 'trả int');
        assert_true($minutes >= 1 && $minutes <= 15, 'trong [1, 15], nhận ' . (string) $minutes);
        $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        assert_true(str_contains($log, 'LoginThrottle: khoá u:alice sau 5 lần sai'), 'có log khoá username');
    } finally {
        if ($prevLog === false || $prevLog === '') {
            ini_restore('error_log');
        } else {
            ini_set('error_log', $prevLog);
        }
        @unlink($logFile);
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: 5 username khác cùng IP thì khoá theo IP; byIp=false thì không', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 1; $i <= 5; $i++) {
            $t->recordFailure('user' . $i, '10.0.0.8');
        }
        $minutes = $t->lockedMinutes('user6', '10.0.0.8');
        assert_true(is_int($minutes), 'khoá theo IP');
        assert_true($minutes >= 1 && $minutes <= 15);

        $noIp = throttle_make($conn, ['byIp' => false]);
        assert_null($noIp->lockedMinutes('user6', '10.0.0.8'), 'byIp=false không khoá IP');
    } finally {
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: clear(username) xoá khoá u: giữ khoá ip:', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('alice', '10.0.0.1');
        }
        $t->clear('alice');
        assert_same(0, throttle_count($conn, 'u:alice'), 'khoá u: mất');
        assert_true(throttle_count($conn, 'ip:10.0.0.1') > 0, 'khoá ip: còn');
    } finally {
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: 5 bản ghi 16 phút không khoá; 14 phút thì khoá còn 1 phút', function (): void {
    $conn = throttle_setup();
    try {
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'u:old', 16);
        }
        $t = throttle_make($conn);
        assert_null($t->lockedMinutes('old', ''), 'ngoài cửa sổ 15 phút');

        $conn->query('DELETE FROM `' . THROTTLE_TABLE . '`');
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'u:recent', 14);
        }
        assert_same(1, $t->lockedMinutes('recent', ''), 'còn 1 phút');
    } finally {
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: cleanupProbability=1.0 xoá bản ghi 25h, giữ bản ghi 1h', function (): void {
    $conn = throttle_setup();
    try {
        throttle_seed($conn, 'u:ancient', 25 * 60);
        throttle_seed($conn, 'u:hour', 60);
        $t = throttle_make($conn, ['cleanupProbability' => 1.0]);
        $t->recordFailure('someone', '10.0.0.2');
        assert_same(0, throttle_count($conn, 'u:ancient'), '25h bị xoá');
        assert_same(1, throttle_count($conn, 'u:hour'), '1h còn');
    } finally {
        $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
    }
});

test('LoginThrottle: bảng không tồn tại thì fail-open, không ném', function (): void {
    $conn = throttle_conn();
    $t = new LoginThrottle($conn, ['table' => '_authv2_khong_ton_tai']);
    assert_null($t->lockedMinutes('alice', '10.0.0.1'));
    $t->recordFailure('alice', '10.0.0.1');
    $t->clear('alice');
});
