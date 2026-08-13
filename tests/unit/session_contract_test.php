<?php

declare(strict_types=1);

use Hoatho\SharedAuth\SessionContract;

/**
 * Chốt hành vi HIỆN TẠI của SessionContract (golden baseline).
 * Thao tác thẳng trên $_SESSION, không cần mở phiên thật.
 */

function with_session(array $session, callable $fn)
{
    $previous = $_SESSION ?? null;
    $_SESSION = $session;
    try {
        return $fn();
    } finally {
        if ($previous === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $previous;
        }
    }
}

test('set: ghi đúng 3 key chuẩn', function (): void {
    with_session([], static function (): void {
        SessionContract::set(['id' => 42, 'name' => 'nguyenvana', 'role' => 'admin']);
        assert_same(42, $_SESSION[SessionContract::USER_ID]);
        assert_same('nguyenvana', $_SESSION[SessionContract::NAME]);
        assert_true(is_int($_SESSION[SessionContract::LOGGED_IN_AT]), 'logged_in_at là unix time');
        assert_false(array_key_exists('role', $_SESSION), 'role KHÔNG vào phiên (authz ngoài ranh giới)');
    });
});

test('set: thiếu id/name thì về giá trị rỗng chứ không lỗi', function (): void {
    with_session([], static function (): void {
        SessionContract::set([]);
        assert_same(0, $_SESSION[SessionContract::USER_ID]);
        assert_same('', $_SESSION[SessionContract::NAME]);
    });
});

test('userId: chỉ nhận id dương', function (): void {
    assert_same(7, with_session(['user_id' => 7], static fn () => SessionContract::userId()));
    assert_same(7, with_session(['user_id' => '7'], static fn () => SessionContract::userId()), 'chuỗi số');
    assert_null(with_session([], static fn () => SessionContract::userId()), 'không có key');
    assert_null(with_session(['user_id' => 0], static fn () => SessionContract::userId()), 'id = 0');
    assert_null(with_session(['user_id' => -1], static fn () => SessionContract::userId()), 'id âm');
});

test('isLoggedIn: phải có CẢ user_id lẫn logged_in_at', function (): void {
    // Chốt an toàn của shared-auth task 2.0b: phiên kế thừa chỉ có user_id KHÔNG được coi là đã login.
    assert_true(
        with_session(['user_id' => 3, 'logged_in_at' => 1], static fn () => SessionContract::isLoggedIn()),
        'đủ cả hai'
    );
    assert_false(
        with_session(['user_id' => 3], static fn () => SessionContract::isLoggedIn()),
        'phiên kế thừa chỉ có user_id'
    );
    assert_false(
        with_session(['logged_in_at' => 1], static fn () => SessionContract::isLoggedIn()),
        'chỉ có dấu thời gian'
    );
    assert_false(with_session([], static fn () => SessionContract::isLoggedIn()), 'phiên rỗng');
});

test('isLoggedIn: BASELINE không xét logged_in_at cũ tới đâu (task 3.8 thêm trần tuyệt đối)', function (): void {
    $tenYearsAgo = time() - (10 * 365 * 24 * 60 * 60);
    assert_true(
        with_session(['user_id' => 3, 'logged_in_at' => $tenYearsAgo], static fn () => SessionContract::isLoggedIn()),
        'hiện tại phiên 10 năm tuổi vẫn hợp lệ; sau task 3.8 phải là false'
    );
});

test('clear: xoá đúng 3 key, giữ nguyên key của dự án', function (): void {
    with_session(
        ['user_id' => 3, 'name' => 'a', 'logged_in_at' => 1, 'lkh_bo_loc' => 'x'],
        static function (): void {
            SessionContract::clear();
            assert_false(isset($_SESSION['user_id']));
            assert_false(isset($_SESSION['name']));
            assert_false(isset($_SESSION['logged_in_at']));
            assert_same('x', $_SESSION['lkh_bo_loc'], 'key riêng của dự án không bị đụng');
        }
    );
});
