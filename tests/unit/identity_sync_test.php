<?php

declare(strict_types=1);

use Hoatho\SharedAuth\IdentitySync;

/**
 * Chốt hành vi của IdentitySync.
 */

const IS_TABLE = '_authv2_test_user';

function is_setup(string $name, string $fullName): mysqli
{
    $conn = test_db();
    if ($conn === null) {
        skip_test('không kết nối được MySQL (đặt AUTHV2_TEST_DB_* nếu cần)');
    }

    make_user_fixture($conn, IS_TABLE);
    $stmt = $conn->prepare('INSERT INTO `' . IS_TABLE . '` (name, password, full_name, role) VALUES (?, ?, ?, ?)');
    $password = 'x';
    $role = 'user';
    $stmt->bind_param('ssss', $name, $password, $fullName, $role);
    $stmt->execute();
    $stmt->close();

    return $conn;
}

test('sync: chưa đăng nhập thì không làm gì', function (): void {
    $conn = is_setup('user1', 'Nguyễn Văn A');
    try {
        with_session([], static function () use ($conn): void {
            $sync = new IdentitySync($conn, ['table' => IS_TABLE, 'columns' => ['full_name' => 'ho_ten']]);
            assert_false($sync->sync(), 'không có user_id trong phiên');
            assert_false(isset($_SESSION['ho_ten']), 'không ghi gì vào phiên');
        });
    } finally {
        drop_user_fixture($conn, IS_TABLE);
    }
});

test('sync: user_id không có trong bảng thì trả false', function (): void {
    $conn = is_setup('user1', 'Nguyễn Văn A');
    try {
        with_session(['user_id' => 999999, 'logged_in_at' => time()], static function () use ($conn): void {
            $sync = new IdentitySync($conn, ['table' => IS_TABLE, 'columns' => ['full_name' => 'ho_ten']]);
            assert_false($sync->sync());
            assert_false(isset($_SESSION['ho_ten']));
        });
    } finally {
        drop_user_fixture($conn, IS_TABLE);
    }
});

test('sync: ghi giá trị thô vào phiên, cờ chạy-một-lần theo từng instance (task 3.3 và 3.4)', function (): void {
    // Tên có `&` và `'` để lộ nếu giá trị bị mã hoá HTML lúc GHI vào phiên.
    $conn = is_setup('user1', "  Nguyễn A & B'O  ");
    try {
        $id = (int) $conn->insert_id;

        with_session(['user_id' => $id, 'logged_in_at' => time()], static function () use ($conn): void {
            $first = new IdentitySync($conn, [
                'table'   => IS_TABLE,
                'columns' => ['full_name' => ['ho_ten', 'ten_day_du'], 'role' => 'vai_tro'],
            ]);
            assert_true($first->sync(), 'lần sync đầu chạy');

            assert_same("Nguyễn A & B'O", $_SESSION['ho_ten'] ?? null, 'thô, chỉ trim, không htmlspecialchars');
            assert_same("Nguyễn A & B'O", $_SESSION['ten_day_du'] ?? null, 'một cột ghi được nhiều key');
            assert_same('user', $_SESSION['vai_tro'] ?? null);

            // Cùng instance gọi lại thì không truy vấn nữa: giá trị bị sửa tay vẫn giữ nguyên.
            $_SESSION['ho_ten'] = 'đã sửa';
            assert_true($first->sync(), 'lần hai trả true');
            assert_same('đã sửa', $_SESSION['ho_ten'], 'lần hai không ghi lại');

            // Instance thứ hai, bản đồ cột khác hẳn, phải tự chạy.
            $second = new IdentitySync($conn, [
                'table'   => IS_TABLE,
                'columns' => ['name' => 'ten_dang_nhap'],
            ]);
            assert_true($second->sync());
            assert_same('user1', $_SESSION['ten_dang_nhap'] ?? null, 'instance khác không bị cờ của instance đầu chặn');
        });
    } finally {
        drop_user_fixture($conn, IS_TABLE);
    }
});
