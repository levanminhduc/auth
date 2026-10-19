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

function throttle_drop(mysqli $conn): void
{
    $conn->query('DROP TABLE IF EXISTS `' . THROTTLE_TABLE . '`');
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

function throttle_total(mysqli $conn): int
{
    $result = $conn->query('SELECT COUNT(*) AS n FROM `' . THROTTLE_TABLE . '`');
    return (int) ($result->fetch_assoc()['n'] ?? 0);
}

function throttle_make(mysqli $conn, array $options = []): LoginThrottle
{
    return new LoginThrottle($conn, $options + ['table' => THROTTLE_TABLE]);
}

function throttle_with_log(callable $fn): string
{
    $prevLog = ini_get('error_log');
    $logFile = sys_get_temp_dir() . '/authv2-throttle-test.log';
    @unlink($logFile);
    ini_set('error_log', $logFile);
    try {
        $fn();
        return is_file($logFile) ? (string) file_get_contents($logFile) : '';
    } finally {
        if ($prevLog === false || $prevLog === '') {
            ini_restore('error_log');
        } else {
            ini_set('error_log', $prevLog);
        }
        @unlink($logFile);
    }
}

test('LoginThrottle: 5 lần sai đầu vẫn được thử mật khẩu', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            assert_null($t->begin('alice', '10.0.0.1'), 'lần ' . ($i + 1));
        }
        assert_same(5, throttle_count($conn, 'p:alice|10.0.0.1'), 'đã ghi đủ 5 lần sai');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: lần thứ 6 bị khoá đúng lockout phút và không được lưu', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            $t->begin('alice', '10.0.0.1');
        }
        assert_same(15, $t->begin('alice', '10.0.0.1'), 'khoá 15 phút');
        assert_same(15, $t->begin('alice', '10.0.0.1'), 'đập tiếp không kéo dài khoá');
        assert_same(5, throttle_count($conn, 'p:alice|10.0.0.1'), 'lần bị chặn bị xoá');
        assert_same(5, throttle_count($conn, 'u:alice'));
        assert_same(5, throttle_count($conn, 'ip:10.0.0.1'));
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: 4 lần sai 14..11 phút trước + 1 lần bây giờ vẫn khoá ~15 phút (không phải 1)', function (): void {
    $conn = throttle_setup();
    try {
        foreach ([14, 13, 12, 11] as $m) {
            throttle_seed($conn, 'p:alice|10.0.0.1', $m);
        }
        $t = throttle_make($conn);
        assert_null($t->begin('alice', '10.0.0.1'), 'lần thứ 5 vẫn được thử');
        assert_same(15, $t->begin('alice', '10.0.0.1'), 'khoá tính từ lần sai vượt ngưỡng');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: lockout > window: 5 lần sai 16 phút trước vẫn khoá (window 15, lockout 60)', function (): void {
    $conn = throttle_setup();
    try {
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'p:alice|10.0.0.1', 16);
        }
        $t = throttle_make($conn, ['windowMinutes' => 15, 'lockoutMinutes' => 60]);
        assert_same(44, $t->begin('alice', '10.0.0.1'), 'còn 60 - 16 phút');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: khoá hết hạn sau đúng lockout phút', function (): void {
    $conn = throttle_setup();
    try {
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'p:old|10.0.0.1', 16);
        }
        $t = throttle_make($conn);
        assert_null($t->begin('old', '10.0.0.1'), '16 phút trước đã hết khoá 15 phút');

        $conn->query('DELETE FROM `' . THROTTLE_TABLE . '`');
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'p:recent|10.0.0.1', 14);
        }
        assert_same(1, $t->begin('recent', '10.0.0.1'), '14 phút trước còn 1 phút');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: username không phải UTF-8 ném InvalidArgumentException và không ghi gì', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        $thrown = false;
        try {
            $t->begin("bad\xC3\x28name", '10.0.0.1');
        } catch (\InvalidArgumentException $e) {
            $thrown = true;
        }
        assert_true($thrown, 'ném InvalidArgumentException');
        assert_same(0, throttle_total($conn), 'không bản ghi nào, kể cả khoá ip');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: lỗi DB ở một khoá không làm bỏ qua các khoá còn lại', function (): void {
    $conn = throttle_setup();
    try {
        $added = $conn->query(
            'ALTER TABLE `' . THROTTLE_TABLE . "` ADD CONSTRAINT chk_no_user CHECK (identifier NOT LIKE 'u:%')"
        );
        if ($added === false) {
            fail_test('không thêm được CHECK để mô phỏng lỗi: ' . $conn->error);
        }
        $t = throttle_make($conn);
        $log = throttle_with_log(function () use ($t): void {
            for ($i = 0; $i < 5; $i++) {
                assert_null($t->begin('alice', '10.0.0.1'), 'lần ' . ($i + 1));
            }
            assert_same(15, $t->begin('alice', '10.0.0.1'), 'khoá p: vẫn hoạt động');
        });
        assert_same(0, throttle_count($conn, 'u:alice'), 'khoá u: bị lỗi');
        assert_same(5, throttle_count($conn, 'p:alice|10.0.0.1'), 'khoá p: vẫn được ghi');
        assert_same(5, throttle_count($conn, 'ip:10.0.0.1'), 'khoá ip: vẫn được ghi');
        assert_contains('LoginThrottle:', $log, 'lỗi khoá u: được ghi log');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: succeed() xoá khoá p:+u:, chỉ xoá đúng dòng ip: của chính request', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        $t->begin('alice', '10.0.0.1');
        $t->begin('alice', '10.0.0.1');
        $t->begin('alice', '10.0.0.2');
        $t->begin('bob', '10.0.0.1');

        assert_null($t->begin('alice', '10.0.0.1'), 'đăng nhập đúng');
        assert_same(3, throttle_count($conn, 'p:alice|10.0.0.1'));
        assert_same(4, throttle_count($conn, 'ip:10.0.0.1'));
        $t->succeed('alice', '10.0.0.1');

        assert_same(0, throttle_count($conn, 'p:alice|10.0.0.1'), 'khoá p: mất');
        assert_same(0, throttle_count($conn, 'u:alice'), 'khoá u: mất, kể cả từ IP khác');
        assert_same(1, throttle_count($conn, 'p:alice|10.0.0.2'), 'khoá p: của IP khác còn');
        assert_same(3, throttle_count($conn, 'ip:10.0.0.1'), 'chỉ dòng ip: của request này bị xoá');
        assert_same(1, throttle_count($conn, 'u:bob'), 'user khác không bị ảnh hưởng');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: nhiều begin() trước khi verify thì tối đa max lần qua', function (): void {
    $conn = throttle_setup();
    try {
        $passed = 0;
        for ($i = 0; $i < 12; $i++) {
            $t = throttle_make($conn);
            if ($t->begin('alice', '10.0.0.1') === null) {
                $passed++;
            }
        }
        assert_same(5, $passed, 'đúng 5 lần qua');
        assert_same(5, throttle_count($conn, 'p:alice|10.0.0.1'), 'các lần bị chặn không để lại bản ghi');

        $conn->query('DELETE FROM `' . THROTTLE_TABLE . '`');
        for ($i = 0; $i < 5; $i++) {
            throttle_seed($conn, 'p:bob|10.0.0.1', 0);
        }
        $t = throttle_make($conn);
        assert_same(15, $t->begin('bob', '10.0.0.1'), '5 bản ghi chờ có sẵn (mô phỏng 5 request đã chèn xong), request thứ 6 bị chặn');
        assert_same(5, throttle_count($conn, 'p:bob|10.0.0.1'), 'bản ghi chờ của chính nó bị xoá');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: abort() xoá đúng các bản ghi chờ của begin() hiện tại, giữ lịch sử cũ', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        $t->begin('alice', '10.0.0.1');
        $t->begin('alice', '10.0.0.1');
        assert_same(2, throttle_count($conn, 'p:alice|10.0.0.1'));

        assert_null($t->begin('alice', '10.0.0.1'), 'lần thứ 3 qua');
        assert_same(3, throttle_count($conn, 'p:alice|10.0.0.1'));
        $t->abort();

        assert_same(2, throttle_count($conn, 'p:alice|10.0.0.1'), 'p: về 2, không tính lỗi hạ tầng');
        assert_same(2, throttle_count($conn, 'u:alice'), 'u: về 2');
        assert_same(2, throttle_count($conn, 'ip:10.0.0.1'), 'ip: về 2');
        assert_same(6, throttle_total($conn), 'tổng còn 3 khoá x 2 bản ghi');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: abort() không có begin() chờ thì không làm gì; gọi hai lần cũng vậy', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        $t->abort();
        $t->begin('alice', '10.0.0.1');
        $t->abort();
        $t->abort();
        assert_same(0, throttle_total($conn));

        throttle_seed($conn, 'p:bob|10.0.0.1', 1);
        $t->abort();
        assert_same(1, throttle_total($conn), 'không đụng dữ liệu không thuộc begin() này');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: abort() sau begin() bị khoá là no-op, và sau succeed() cũng vậy', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            $t->begin('alice', '10.0.0.1');
        }
        assert_same(15, $t->begin('alice', '10.0.0.1'));
        $t->abort();
        assert_same(5, throttle_count($conn, 'p:alice|10.0.0.1'), 'lịch sử sai không bị xoá nhầm');

        $other = throttle_make($conn);
        $other->begin('bob', '10.0.0.2');
        $other->succeed('bob', '10.0.0.2');
        $other->abort();
        assert_same(0, throttle_count($conn, 'u:bob'));
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: abort() khi DB lỗi chỉ ghi log, không ném', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        $t->begin('alice', '10.0.0.1');
        $conn->query('DROP TABLE `' . THROTTLE_TABLE . '`');
        $log = throttle_with_log(function () use ($t): void {
            $t->abort();
        });
        assert_contains('LoginThrottle:', $log);
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: cảnh báo trần chỉ gắn với lần thứ max theo thứ tự id, kể cả khi lần đó bị khoá', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn, ['userMaxAttempts' => 6]);
        $log = throttle_with_log(function () use ($t): void {
            for ($i = 0; $i < 5; $i++) {
                assert_null($t->begin('alice', '10.0.0.1'));
            }
            assert_same(15, $t->begin('alice', '10.0.0.1'), 'lần thứ 6 bị khoá p: nhưng vẫn là lần thứ max của u:');
        });
        assert_same(1, substr_count($log, 'chạm trần 6 lần sai'), 'cảnh báo vẫn phát khi request chạm trần bị khoá bởi p:');

        $conn->query('DELETE FROM `' . THROTTLE_TABLE . '`');
        for ($i = 0; $i < 6; $i++) {
            throttle_seed($conn, 'u:carol', 0);
        }
        $log = throttle_with_log(function () use ($t): void {
            assert_same(30, $t->begin('carol', '10.0.0.9'), 'lần thứ 7 bị khoá u:');
        });
        assert_false(str_contains($log, 'chạm trần'), 'đã vượt trần từ trước thì không báo lại');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: sai từ IP X không khoá user từ IP Y; trần u: thì khoá mọi IP', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn, ['userMaxAttempts' => 6]);
        $log = throttle_with_log(function () use ($t): void {
            for ($i = 0; $i < 5; $i++) {
                $t->begin('alice', '10.0.0.1');
            }
            assert_same(15, $t->begin('alice', '10.0.0.1'), 'IP X bị khoá');
            assert_null($t->begin('alice', '10.0.0.2'), 'IP Y vẫn thử được (lần sai thứ 6 của u:)');
            assert_same(30, $t->begin('alice', '10.0.0.3'), 'trần u: chặn mọi IP, khoá 30 phút');
        });
        assert_contains('chạm trần 6 lần sai', $log, 'có cảnh báo trần');
        assert_contains('"alice"', $log, 'username được quote');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: 5 username khác cùng IP thì khoá theo ip: (ngưỡng ip cấu hình được); byIp=false thì không', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn, ['ipMaxAttempts' => 5]);
        for ($i = 1; $i <= 5; $i++) {
            assert_null($t->begin('user' . $i, '10.0.0.8'));
        }
        assert_same(15, $t->begin('user6', '10.0.0.8'), 'khoá theo IP');

        $noIp = throttle_make($conn, ['byIp' => false, 'ipMaxAttempts' => 5]);
        assert_null($noIp->begin('user6', '10.0.0.8'), 'byIp=false không khoá theo IP');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: ngưỡng ip mặc định là 30 (NAT)', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        for ($i = 1; $i <= 30; $i++) {
            assert_null($t->begin('user' . $i, '10.0.0.8'), 'lần ' . $i);
        }
        assert_same(15, $t->begin('user31', '10.0.0.8'), 'lần 31 bị khoá');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: Admin/admin/ADMIN/Ádmin dùng chung khoá (collation như user.name)', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn);
        foreach (['Admin', 'admin', 'ADMIN', 'Ádmin', 'ÁDMIN'] as $name) {
            assert_null($t->begin($name, '10.0.0.1'), $name);
        }
        assert_same(15, $t->begin('aDmIn', '10.0.0.1'), 'biến thể thứ 6 bị khoá');
        assert_same(5, throttle_count($conn, 'p:admin|10.0.0.1'));
        assert_same(5, throttle_count($conn, 'u:ADMIN'));
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: username dài 255 ký tự vẫn khoá đúng, không ném', function (): void {
    $conn = throttle_setup();
    try {
        $name = str_repeat('a', 254) . 'Z';
        $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $t = throttle_make($conn);
        for ($i = 0; $i < 5; $i++) {
            assert_null($t->begin($name, $ip));
        }
        assert_same(15, $t->begin(strtolower($name), $ip));
        $max = (int) $conn->query('SELECT MAX(CHAR_LENGTH(identifier)) AS n FROM `' . THROTTLE_TABLE . '`')
            ->fetch_assoc()['n'];
        assert_true($max > 190 && $max <= 330, 'khoá dài vừa cột, nhận ' . $max);
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: username có xuống dòng không chèn được dòng log giả', function (): void {
    $conn = throttle_setup();
    try {
        $t = throttle_make($conn, ['userMaxAttempts' => 1]);
        $log = throttle_with_log(function () use ($t): void {
            $t->begin("evil\nLoginThrottle: forged", "10.0.0.1\n");
        });
        assert_contains('chạm trần', $log);
        assert_false(str_contains($log, "\nLoginThrottle: forged"), 'không có dòng giả');
        assert_contains('evil\nLoginThrottle: forged', $log, 'xuống dòng được thoát');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: cleanupProbability=1.0 xoá bản ghi 25h, giữ bản ghi 1h', function (): void {
    $conn = throttle_setup();
    try {
        throttle_seed($conn, 'u:ancient', 25 * 60);
        throttle_seed($conn, 'u:hour', 60);
        $t = throttle_make($conn, ['cleanupProbability' => 1.0]);
        $t->begin('someone', '10.0.0.2');
        assert_same(0, throttle_count($conn, 'u:ancient'), '25h bị xoá');
        assert_same(1, throttle_count($conn, 'u:hour'), '1h còn');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: cleanup giữ bản ghi lâu hơn retentionHours khi window + lockout dài hơn', function (): void {
    $conn = throttle_setup();
    try {
        throttle_seed($conn, 'u:long', 30 * 60);
        $t = throttle_make($conn, [
            'cleanupProbability' => 1.0,
            'retentionHours'     => 1,
            'userLockoutMinutes' => 3 * 24 * 60,
        ]);
        $t->begin('someone', '10.0.0.2');
        assert_same(1, throttle_count($conn, 'u:long'), '30h còn vì window + lockout > 72h');
    } finally {
        throttle_drop($conn);
    }
});

test('LoginThrottle: bảng không tồn tại thì fail-open, không ném', function (): void {
    $conn = throttle_conn();
    $t = new LoginThrottle($conn, ['table' => '_authv2_khong_ton_tai']);
    $log = throttle_with_log(function () use ($t): void {
        assert_null($t->begin('alice', '10.0.0.1'));
        $t->succeed('alice', '10.0.0.1');
    });
    assert_contains('LoginThrottle:', $log);
});
