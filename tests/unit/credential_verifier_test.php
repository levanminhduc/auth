<?php

declare(strict_types=1);

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

test('verify: BASELINE trả về mọi cột khác của bảng (task 3.6 sẽ thu hẹp)', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => 'abc123', 'role' => 'admin']]);
    try {
        $user = $verifier->verify('user1', 'abc123');
        assert_same('admin', $user['role'] ?? null, 'hiện SELECT * nên role lọt ra; sau task 3.6 không còn');
        assert_true(array_key_exists('full_name', $user), 'full_name cũng lọt ra');
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

test('verify: BASELINE plaintext bắt đầu bằng "$2" bị hiểu nhầm là hash', function (): void {
    [$conn, $verifier] = cv_setup([['name' => 'user1', 'password' => '$2abc']]);
    try {
        assert_null(
            $verifier->verify('user1', '$2abc'),
            'nhập đúng vẫn không vào được, vì looksHashed() chỉ nhìn tiền tố; task 3.5 dùng password_get_info'
        );
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
