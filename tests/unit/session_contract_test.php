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

function with_lifetime(?string $cap, callable $fn)
{
    return with_env(['SHARED_AUTH_ABSOLUTE_LIFETIME' => $cap], $fn);
}

test('isLoggedIn: phải có CẢ user_id lẫn logged_in_at', function (): void {
    // Chốt an toàn của shared-auth task 2.0b: phiên kế thừa chỉ có user_id KHÔNG được coi là đã login.
    assert_true(
        with_lifetime(null, static fn () => with_session(['user_id' => 3, 'logged_in_at' => time()], static fn () => SessionContract::isLoggedIn())),
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

test('isLoggedIn: trần tuyệt đối theo logged_in_at (task 3.8)', function (): void {
    $login = static fn (array $session, ?string $cap) => with_lifetime(
        $cap,
        static fn () => with_session($session, static fn () => SessionContract::isLoggedIn())
    );
    $tenYearsAgo = time() - (10 * 365 * 24 * 60 * 60);
    $day = 24 * 60 * 60;

    assert_false($login(['user_id' => 3, 'logged_in_at' => $tenYearsAgo], null), 'phiên 10 năm tuổi bị từ chối');
    assert_false($login(['user_id' => 3, 'logged_in_at' => time() - 31 * $day], null), 'quá 30 ngày mặc định');
    assert_true($login(['user_id' => 3, 'logged_in_at' => time() - 29 * $day], null), 'chưa quá 30 ngày');
    assert_true($login(['user_id' => 3, 'logged_in_at' => time()], null), 'vừa login');

    assert_false($login(['user_id' => 3, 'logged_in_at' => time() - 120], '60'), 'trần tuỳ chỉnh 60s');
    assert_true($login(['user_id' => 3, 'logged_in_at' => time() - 30], '60'));

    assert_true($login(['user_id' => 3, 'logged_in_at' => $tenYearsAgo], '0'), '0 = tắt trần');
    assert_true($login(['user_id' => 3, 'logged_in_at' => 1], '0'), '0 = tắt trần, kể cả mốc rất cũ');
});

test('isLoggedIn: logged_in_at hỏng bị từ chối khi trần đang bật, thiếu thì luôn bị từ chối (task 3.8)', function (): void {
    $login = static fn (array $session, ?string $cap) => with_lifetime(
        $cap,
        static fn () => with_session($session, static fn () => SessionContract::isLoggedIn())
    );
    assert_false($login(['user_id' => 3, 'logged_in_at' => 'hôm qua'], null), 'chuỗi không phải số');
    assert_false($login(['user_id' => 3, 'logged_in_at' => 0], null), 'số 0');
    assert_false($login(['user_id' => 3, 'logged_in_at' => -5], null), 'số âm');
    assert_false($login(['user_id' => 3, 'logged_in_at' => ''], null), 'rỗng');
    assert_false($login(['user_id' => 3], '0'), 'thiếu dấu thời gian, kể cả khi tắt trần');
});

test('clear: xoá đúng 4 key, giữ nguyên key của dự án', function (): void {
    with_session(
        ['user_id' => 3, 'name' => 'a', 'logged_in_at' => 1, 'shared_auth_csrf' => 'tok', 'lkh_bo_loc' => 'x'],
        static function (): void {
            SessionContract::clear();
            assert_false(isset($_SESSION['user_id']));
            assert_false(isset($_SESSION['name']));
            assert_false(isset($_SESSION['logged_in_at']));
            assert_false(isset($_SESSION['shared_auth_csrf']));
            assert_same('x', $_SESSION['lkh_bo_loc'], 'key riêng của dự án không bị đụng');
        }
    );
});

test('clear: xoá shared_auth_csrf', function (): void {
    with_session(
        ['shared_auth_csrf' => 'tok', 'lkh_bo_loc' => 'x'],
        static function (): void {
            SessionContract::clear();
            assert_false(isset($_SESSION[SessionContract::CSRF]));
            assert_same('x', $_SESSION['lkh_bo_loc']);
        }
    );
});

function session_contract_reset_cap_flag(): void
{
    (new ReflectionProperty(SessionContract::class, 'capExpired'))->setValue(null, false);
}

function with_cap_session(array $session, ?string $cap, callable $fn)
{
    session_contract_reset_cap_flag();
    try {
        return with_lifetime($cap, static fn () => with_session($session, $fn));
    } finally {
        session_contract_reset_cap_flag();
    }
}

test('trần tuyệt đối: quá trần thì xoá cả $_SESSION, kể cả key riêng của dự án', function (): void {
    $stale = time() - (31 * 24 * 60 * 60);
    foreach (['isLoggedIn', 'userId', 'name', 'enforceAbsoluteLifetime'] as $entry) {
        with_cap_session(
            ['user_id' => 3, 'name' => 'a', 'logged_in_at' => $stale, 'nhan_vien_ma' => 'NV1', 'shared_auth_csrf' => 'x'],
            null,
            static function () use ($entry): void {
                SessionContract::$entry();
                assert_same([], $_SESSION, $entry . ': không còn key nào');
                assert_true(SessionContract::wasCapExpired(), $entry . ': ghi nhận để Guard báo expired');
                assert_false(isset($_SESSION['user_id']), $entry . ': kiểm tra thẳng isset($_SESSION) cũng thấy chưa login');
            }
        );
    }
});

test('trần tuyệt đối: enforceAbsoluteLifetime trả true đúng khi vừa xoá', function (): void {
    $stale = ['user_id' => 3, 'logged_in_at' => time() - (31 * 24 * 60 * 60)];
    with_cap_session($stale, null, static function (): void {
        assert_true(SessionContract::enforceAbsoluteLifetime(), 'lần đầu: vừa xoá');
        assert_false(SessionContract::enforceAbsoluteLifetime(), 'lần hai: không còn gì để xoá');
        assert_true(SessionContract::wasCapExpired(), 'cờ giữ nguyên trong request');
    });
});

test('trần tuyệt đối: còn trong trần thì không đụng vào $_SESSION', function (): void {
    $fresh = ['user_id' => 3, 'name' => 'a', 'logged_in_at' => time() - 60, 'nhan_vien_ma' => 'NV1'];
    with_cap_session($fresh, null, static function () use ($fresh): void {
        assert_true(SessionContract::isLoggedIn());
        assert_same($fresh, $_SESSION);
        assert_false(SessionContract::wasCapExpired());
    });
});

test('trần tuyệt đối: cap = 0 (tắt) thì không bao giờ xoá, kể cả mốc rất cũ', function (): void {
    $old = ['user_id' => 3, 'name' => 'a', 'logged_in_at' => 1, 'nhan_vien_ma' => 'NV1'];
    with_cap_session($old, '0', static function () use ($old): void {
        assert_true(SessionContract::isLoggedIn());
        assert_same($old, $_SESSION);
        assert_false(SessionContract::wasCapExpired());
    });
});

test('trần tuyệt đối: dấu thời gian hỏng khi đã có user_id thì xoá (fail-closed)', function (): void {
    foreach (['abc', '1700000000', 0, -5, 12.5, [1]] as $bad) {
        with_cap_session(
            ['user_id' => 3, 'logged_in_at' => $bad, 'nhan_vien_ma' => 'NV1'],
            null,
            static function () use ($bad): void {
                assert_false(SessionContract::isLoggedIn(), var_export($bad, true));
                assert_same([], $_SESSION, var_export($bad, true));
            }
        );
    }
});

test('trần tuyệt đối: phiên khách (chỉ CSRF) hoặc user_id kế thừa không có logged_in_at thì không bị xoá', function (): void {
    foreach ([['shared_auth_csrf' => 'x'], ['user_id' => 3, 'nhan_vien_ma' => 'NV1'], ['logged_in_at' => 1, 'shared_auth_csrf' => 'x']] as $session) {
        with_cap_session($session, null, static function () use ($session): void {
            assert_false(SessionContract::isLoggedIn());
            assert_same($session, $_SESSION);
            assert_false(SessionContract::wasCapExpired());
        });
    }
});

test('trần tuyệt đối: set() sau khi bị xoá thì đăng nhập lại được và cờ expired được xoá', function (): void {
    with_cap_session(['user_id' => 3, 'logged_in_at' => time() - (31 * 24 * 60 * 60)], null, static function (): void {
        assert_false(SessionContract::isLoggedIn());
        assert_true(SessionContract::wasCapExpired());
        SessionContract::set(['id' => 9, 'name' => 'b']);
        assert_true(SessionContract::isLoggedIn());
        assert_false(SessionContract::wasCapExpired());
        assert_same(9, SessionContract::userId());
    });
});
