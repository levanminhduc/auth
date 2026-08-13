<?php

declare(strict_types=1);

use Hoatho\SharedAuth\IdentitySync;

/**
 * Chốt hành vi HIỆN TẠI của IdentitySync (golden baseline).
 *
 * Lưu ý về thứ tự: `sync()` dùng `static $done` bên trong một method KHÔNG static, nên cờ đó
 * dùng chung cho cả tiến trình. Một khi có một lần sync() thành công thì MỌI lần gọi sau —
 * kể cả của instance khác — trả về ngay. Vì vậy chỉ có ĐÚNG MỘT test dưới đây gọi sync()
 * thành công, và nó phải là test cuối. Task 3.3 sẽ gỡ ràng buộc này.
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

test('sync: ghi vào phiên + 2 BASELINE sai (task 3.3 và 3.4)', function (): void {
    // Tên có `&` để lộ việc giá trị bị mã hoá HTML ngay lúc GHI vào phiên.
    $conn = is_setup('user1', 'Nguyễn A & B');
    try {
        $id = (int) $conn->insert_id;

        with_session(['user_id' => $id, 'logged_in_at' => time()], static function () use ($conn): void {
            $first = new IdentitySync($conn, [
                'table'   => IS_TABLE,
                'columns' => ['full_name' => ['ho_ten', 'ten_day_du'], 'role' => 'vai_tro'],
            ]);
            assert_true($first->sync(), 'lần sync đầu chạy');

            assert_same('Nguyễn A &amp; B', $_SESSION['ho_ten'] ?? null,
                'BASELINE: giá trị bị htmlspecialchars ngay lúc ghi vào phiên; sau task 3.4 phải là "Nguyễn A & B"');
            assert_same('Nguyễn A &amp; B', $_SESSION['ten_day_du'] ?? null, 'một cột ghi được nhiều key');
            assert_same('user', $_SESSION['vai_tro'] ?? null);

            // Instance thứ hai, bản đồ cột khác hẳn.
            $second = new IdentitySync($conn, [
                'table'   => IS_TABLE,
                'columns' => ['name' => 'ten_dang_nhap'],
            ]);
            assert_true($second->sync(), 'trả true...');
            assert_false(
                isset($_SESSION['ten_dang_nhap']),
                'BASELINE: ...nhưng không ghi gì, vì static $done dùng chung mọi instance; task 3.3 sẽ sửa'
            );
        });
    } finally {
        drop_user_fixture($conn, IS_TABLE);
    }
});
