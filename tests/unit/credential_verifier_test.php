<?php

declare(strict_types=1);

use Hoatho\SharedAuth\AuthBackendException;
use Hoatho\SharedAuth\CredentialVerifier;

/**
 * Chốt hành vi HIỆN TẠI của CredentialVerifier (golden baseline).
 * Chạy trên bảng tạm `_authv2_test_user`, KHÔNG bao giờ đụng bảng `user` thật.
 * Không có DB thì SKIP để bộ test vẫn chạy được.
 */

const CV_TABLE = '_authv2_test_user';

function cv_setup(array $rows): array
{
    $conn = test_db();
    if ($conn === null) {
        skip_test('không kết nối được MySQL (đặt AUTHV2_TEST_DB_* nếu cần)');
    }

    make_user_fixture($conn, CV_TABLE);
    $stmt = $conn->prepare('INSERT INTO `' . CV_TABLE . '` (name, password, full_name, role) VALUES (?, ?, ?, ?)');
    foreach ($rows as $row) {
        $name = $row['name'];
        $password = $row['password'];
        $fullName = $row['full_name'] ?? 'Nguyễn Văn A';
        $role = $row['role'] ?? 'user';
        $stmt->bind_param('ssss', $name, $password, $fullName, $role);
        $stmt->execute();
    }
    $stmt->close();

    return [$conn, new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => false])];
}

test('verify: plaintext đúng thì trả về user', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $user = $verifier->verify('user1', 'abc123');
        assert_true(is_array($user), 'trả về mảng');
        assert_same('user1', $user['name']);
        assert_true((int) $user['id'] > 0, 'có id');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: không trả cột password ra ngoài', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $user = $verifier->verify('user1', 'abc123');
        assert_false(array_key_exists('password', $user), 'password bị gỡ khỏi kết quả');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: chỉ trả đúng id + name, không lọt cột khác (task 3.6)', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123', 'role' => 'admin']]);
    try {
        $user = $verifier->verify('user1', 'abc123');
        assert_same(['id', 'name'], array_keys($user));
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: tên cột tuỳ biến vẫn trả về khoá id + name (task 3.6)', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123', 'full_name' => 'login_khac']]);
    try {
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE, 'userColumn' => 'full_name']);
        $user = $verifier->verify('login_khac', 'abc123');
        assert_true(is_array($user), 'đăng nhập bằng cột tuỳ biến');
        assert_same(['id', 'name'], array_keys($user));
        assert_same('login_khac', $user['name']);
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: thiếu cột password là lỗi backend, không âm thầm thành "sai mật khẩu" (task 3.6)', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE, 'passColumn' => 'khong_co']);
        $caught = null;
        cv_with_log(function () use ($verifier, &$caught): void {
            cv_strict_reporting(function () use ($verifier, &$caught): void {
                try {
                    $verifier->verify('user1', 'abc123');
                } catch (Throwable $e) {
                    $caught = $e;
                }
            });
        });
        assert_true($caught instanceof AuthBackendException, 'phải là AuthBackendException');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: sai mật khẩu và không có tài khoản đều trả null', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        assert_null($verifier->verify('user1', 'sai'), 'sai mật khẩu');
        assert_null($verifier->verify('khongcó', 'abc123'), 'không có tài khoản');
        assert_null($verifier->verify('user1', ''), 'mật khẩu rỗng');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: bcrypt đúng/sai', function (): void {
    $hash = password_hash('abc123', PASSWORD_DEFAULT);
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => $hash]]);
    try {
        assert_true(is_array($verifier->verify('user1', 'abc123')), 'hash đúng');
        assert_null($verifier->verify('user1', 'sai'), 'hash sai');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: công tắc nâng cấp TẮT thì không ghi gì lên DB', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $verifier->verify('user1', 'abc123');
        assert_same('abc123', fetch_password($conn, CV_TABLE, 'user1'), 'password vẫn nguyên plaintext');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: công tắc nâng cấp BẬT thì plaintext được thay bằng hash', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => true]);
        $verifier->verify('user1', 'abc123');

        $stored = (string) fetch_password($conn, CV_TABLE, 'user1');
        assert_true(str_starts_with($stored, '$2'), 'đã thành bcrypt');
        assert_true(password_verify('abc123', $stored), 'hash mới khớp mật khẩu cũ');
        assert_true(is_array($verifier->verify('user1', 'abc123')), 'lần login sau vẫn vào được');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: plaintext bắt đầu bằng "$2" vẫn là plaintext (task 3.5)', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => '$2abc']]);
    try {
        assert_true(is_array($verifier->verify('user1', '$2abc')), 'nhập đúng thì vào được');
        assert_null($verifier->verify('user1', '$2abd'), 'nhập sai thì không');
        assert_same('$2abc', fetch_password($conn, CV_TABLE, 'user1'), 'công tắc TẮT: không ghi');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: bcrypt dạng $2a$/$2b$ vẫn được nhận là hash (task 3.5)', function (): void {
    $hash = password_hash('abc123', PASSWORD_BCRYPT);
    [$conn, $verifier] = cv_setup([
        ['name' => 'a', 'password' => '$2a$' . substr($hash, 4)],
        ['name' => 'b', 'password' => '$2b$' . substr($hash, 4)],
    ]);
    try {
        assert_true(is_array($verifier->verify('a', 'abc123')), '$2a$');
        assert_true(is_array($verifier->verify('b', 'abc123')), '$2b$');
        assert_null($verifier->verify('a', '$2a$' . substr($hash, 4)), 'gõ nguyên chuỗi hash không vào được');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: hash cần rehash chỉ được ghi lại khi công tắc BẬT (task 3.5)', function (): void {
    $weak = password_hash('abc123', PASSWORD_BCRYPT, ['cost' => 4]);
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => $weak]]);
    try {
        assert_true(is_array($verifier->verify('user1', 'abc123')), 'TẮT: đăng nhập được');
        assert_same($weak, fetch_password($conn, CV_TABLE, 'user1'), 'TẮT: hash giữ nguyên từng byte');

        $upgrading = new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => true]);
        assert_null($upgrading->verify('user1', 'sai'), 'BẬT: sai mật khẩu');
        assert_same($weak, fetch_password($conn, CV_TABLE, 'user1'), 'BẬT + sai mật khẩu: không ghi');

        assert_true(is_array($upgrading->verify('user1', 'abc123')), 'BẬT: đăng nhập được');
        $stored = (string) fetch_password($conn, CV_TABLE, 'user1');
        assert_true($stored !== $weak, 'BẬT: hash đã được ghi lại');
        assert_false(password_needs_rehash($stored, PASSWORD_DEFAULT), 'hash mới đúng cost mặc định');
        assert_true(password_verify('abc123', $stored), 'hash mới khớp mật khẩu');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: username không bị SQL injection', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        assert_null($verifier->verify("user1' OR '1'='1", 'abc123'));
        assert_null($verifier->verify("' OR 1=1 -- ", 'abc123'));
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

function cv_use_legacy_name_collation(mysqli $conn): void
{
    $conn->query('ALTER TABLE `' . CV_TABLE . '` MODIFY name VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL');
}

function cv_strict_reporting(callable $fn)
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        return $fn();
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

function cv_with_log(callable $fn): string
{
    $prevLog = ini_get('error_log');
    $logFile = sys_get_temp_dir() . '/authv2-verifier-test.log';
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

function cv_chain_text(Throwable $e): string
{
    $text = '';
    for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
        $text .= $cur->getMessage() . "\n" . $cur->getTraceAsString() . "\n";
    }
    return $text;
}

test('verify: không truyền upgradeOnLogin thì mặc định TẮT, không ghi DB', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE]);
        assert_true(is_array($verifier->verify('user1', 'abc123')), 'đăng nhập được');
        assert_same('abc123', fetch_password($conn, CV_TABLE, 'user1'), 'password vẫn nguyên plaintext');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: username lệch collation ném AuthBackendException (chế độ strict)', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    cv_use_legacy_name_collation($conn);
    try {
        $caught = null;
        cv_strict_reporting(function () use ($verifier, &$caught): void {
            try {
                $verifier->verify("user1\x80", 'abc123');
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        assert_true($caught instanceof AuthBackendException, 'phải là AuthBackendException, không phải ' . ($caught ? get_class($caught) : 'null'));
        assert_true($caught->getPrevious() instanceof mysqli_sql_exception, 'giữ exception gốc làm previous');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: lỗi DB khi mysqli_report TẮT cũng ném AuthBackendException', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    cv_use_legacy_name_collation($conn);
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $caught = null;
        try {
            $verifier->verify("user1\x80", 'abc123');
        } catch (Throwable $e) {
            $caught = $e;
        }
        assert_true($caught instanceof AuthBackendException, 'phải là AuthBackendException');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: bảng không tồn tại ném AuthBackendException', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE . '_missing']);
        $caught = null;
        cv_strict_reporting(function () use ($verifier, &$caught): void {
            try {
                $verifier->verify('user1', 'abc123');
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        assert_true($caught instanceof AuthBackendException, 'phải là AuthBackendException');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: UPDATE nâng cấp hash lỗi thì ghi log và vẫn đăng nhập được, plaintext giữ nguyên', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $conn->query('ALTER TABLE `' . CV_TABLE . '` MODIFY password VARCHAR(10) NOT NULL');
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => true]);
        $user = null;
        $thrown = null;
        $log = cv_with_log(function () use ($verifier, &$user, &$thrown): void {
            cv_strict_reporting(function () use ($verifier, &$user, &$thrown): void {
                try {
                    $user = $verifier->verify('user1', 'abc123');
                } catch (Throwable $e) {
                    $thrown = $e;
                }
            });
        });
        assert_same(null, $thrown, 'không ném: ' . ($thrown ? get_class($thrown) : ''));
        assert_true(is_array($user), 'đăng nhập vẫn thành công');
        assert_contains('không nâng cấp được hash', $log, 'có log lỗi nâng cấp');
        assert_same('abc123', fetch_password($conn, CV_TABLE, 'user1'), 'plaintext nguyên vẹn');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: lỗi nâng cấp hash khi mysqli_report TẮT cũng không chặn đăng nhập', function (): void {
    [$conn] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    try {
        $conn->query('ALTER TABLE `' . CV_TABLE . '` MODIFY password VARCHAR(10) NOT NULL');
        mysqli_report(MYSQLI_REPORT_OFF);
        $verifier = new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => true]);
        $user = null;
        cv_with_log(function () use ($verifier, &$user): void {
            $user = $verifier->verify('user1', 'abc123');
        });
        assert_true(is_array($user), 'đăng nhập vẫn thành công');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: mật khẩu chứa byte NUL bị từ chối đồng nhất, không ném (PHP 8.3 password_hash ném ValueError)', function (): void {
    $hash = password_hash('abc123', PASSWORD_DEFAULT);
    [$conn, $verifier] = cv_setup([
        ['name' => 'plain', 'password' => 'abc123'],
        ['name' => 'hashed', 'password' => $hash],
        ['name' => 'dup', 'password' => 'abc123'],
        ['name' => 'DUP', 'password' => 'abc123'],
    ]);
    $upgrading = new CredentialVerifier($conn, ['table' => CV_TABLE, 'upgradeOnLogin' => true]);
    try {
        cv_with_log(function () use ($verifier, $upgrading): void {
            foreach (["\0", "abc\0xyz", "abc123\0", "\0abc123", "wrong\0"] as $password) {
                $label = bin2hex($password);
                assert_null($verifier->verify('khongco', $password), 'user không tồn tại ' . $label);
                assert_null($verifier->verify('plain', $password), 'user plaintext ' . $label);
                assert_null($verifier->verify('hashed', $password), 'user bcrypt ' . $label);
                assert_null($verifier->verify('dup', $password), 'username mơ hồ ' . $label);
                assert_null($upgrading->verify('plain', $password), 'plaintext + nâng cấp BẬT ' . $label);
            }
        });
        assert_same('abc123', fetch_password($conn, CV_TABLE, 'plain'), 'không bị nâng cấp hash');
        assert_true(is_array($verifier->verify('hashed', 'abc123')), 'mật khẩu đúng vẫn vào được');
        assert_true(is_array($verifier->verify('plain', 'abc123')), 'plaintext đúng vẫn vào được');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: mật khẩu không xuất hiện trong chuỗi exception và trace', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123']]);
    cv_use_legacy_name_collation($conn);
    $secret = 'S3cretPw!xyz';
    $oldIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    try {
        $caught = null;
        cv_strict_reporting(function () use ($verifier, $secret, &$caught): void {
            try {
                $verifier->verify("user1\x80", $secret);
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        assert_true($caught instanceof AuthBackendException, 'phải ném AuthBackendException');
        assert_false(str_contains(cv_chain_text($caught), $secret), 'mật khẩu lọt vào message/trace');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $oldIgnoreArgs);
        drop_user_fixture($conn, CV_TABLE);
    }
});

test('verify: username khớp hơn một dòng (khác hoa/thường, dấu) thì trả null', function (): void {
    [$conn, $verifier] = cv_setup([
        ['name' => 'hoa', 'password' => 'abc123'],
        ['name' => 'HOA', 'password' => 'abc123'],
        ['name' => 'hoà', 'password' => 'abc123'],
    ]);
    try {
        assert_null($verifier->verify('hoa', 'abc123'), 'khớp nhiều dòng thì từ chối dù mật khẩu đúng');
        assert_null($verifier->verify('hoà', 'abc123'), 'khớp nhiều dòng (theo dấu) thì từ chối');
    } finally {
        drop_user_fixture($conn, CV_TABLE);
    }
});
