<?php

declare(strict_types=1);

use Hoatho\SharedAuth\Guard;

/**
 * Chốt hành vi HIỆN TẠI của Guard::isPublic (golden baseline).
 * `requireLogin()` gọi header()+exit nên được phủ ở tests/http/, không phủ ở đây.
 */

test('isPublic: danh sách rỗng thì không có gì là public', function (): void {
    $guard = new Guard();
    assert_false($guard->isPublic('/nhansuhtdb/index.php'));
    assert_false($guard->isPublic('/'));
});

test('isPublic: entry không kết thúc bằng / là khớp tuyệt đối', function (): void {
    $guard = new Guard(['/lichkehoach/public/index.php']);
    assert_true($guard->isPublic('/lichkehoach/public/index.php'), 'khớp đúng');
    assert_false($guard->isPublic('/lichkehoach/public/index.php/x'), 'dài hơn thì không khớp');
    assert_false($guard->isPublic('/lichkehoach/public/'), 'ngắn hơn thì không khớp');
});

test('isPublic: entry kết thúc bằng / là khớp tiền tố', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_true($guard->isPublic('/lichkehoach/public/api/load_tasks.php'));
    assert_true($guard->isPublic('/lichkehoach/public/api/'));
    assert_false($guard->isPublic('/lichkehoach/public/index.php'));
});

test('isPublic: entry rỗng bị bỏ qua, không biến mọi path thành public', function (): void {
    $guard = new Guard(['', '/nhansuhtdb/webhook.php']);
    assert_false($guard->isPublic('/nhansuhtdb/index.php'));
    assert_true($guard->isPublic('/nhansuhtdb/webhook.php'));
});

/**
 * BASELINE SAI — task 3.2 của auth-hardening sẽ lật hai khẳng định dưới đây thành false.
 * Ghi lại ở đây để lúc lật là lật có chủ ý, không phải vô tình.
 * Guard so path thô, trong khi web server phục vụ path đã rút gọn → lọt allowlist.
 */
test('isPublic: BASELINE `..` hiện LỌT qua allowlist tiền tố (lỗ bypass, task 3.2 sẽ vá)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_true(
        $guard->isPublic('/lichkehoach/public/api/../edit.php'),
        'hiện tại trả true; sau task 3.2 phải là false'
    );
});

test('isPublic: BASELINE percent-encoding hiện KHÔNG được giải mã (task 3.2 sẽ vá)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_false(
        $guard->isPublic('/lichkehoach/public/%61pi/load_tasks.php'),
        'path mã hoá không khớp tiền tố — fail-closed, an toàn nhưng không nhất quán'
    );
    assert_true(
        $guard->isPublic('/lichkehoach/public/api/%2e%2e/edit.php'),
        'hiện tại trả true; sau task 3.2 phải là false'
    );
});

function with_login_location_context(array $cookie, array $session, string $uri, callable $fn, bool $startSession = false): void
{
    with_default_env(static function () use ($cookie, $session, $uri, $fn, $startSession): void {
        $prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $prevCookie = $_COOKIE;
        $prevSession = $_SESSION ?? null;
        $started = false;
        $prevSavePath = session_save_path();
        $prevUseCookies = ini_get('session.use_cookies');
        $prevLimiter = ini_get('session.cache_limiter');
        $_SERVER['REQUEST_URI'] = $uri;
        $_COOKIE = $cookie;
        $_SESSION = $session;
        try {
            if ($startSession) {
                session_save_path(sys_get_temp_dir());
                ini_set('session.use_cookies', '0');
                ini_set('session.cache_limiter', '');
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                    $started = true;
                }
                $_SESSION = $session;
            }
            $fn();
        } finally {
            if ($started) {
                $_SESSION = [];
                session_write_close();
            }
            session_save_path($prevSavePath);
            if ($prevUseCookies !== false) {
                ini_set('session.use_cookies', (string) $prevUseCookies);
            }
            if ($prevLimiter !== false) {
                ini_set('session.cache_limiter', (string) $prevLimiter);
            }
            if ($prevUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $prevUri;
            }
            $_COOKIE = $prevCookie;
            if ($prevSession === null) {
                $_SESSION = [];
            } else {
                $_SESSION = $prevSession;
            }
        }
    });
}

test('loginLocation: không cookie thì chỉ redirect', function (): void {
    $uri = '/lichkehoach/public/index.php';
    with_login_location_context([], [], $uri, static function () use ($uri): void {
        $loc = (new Guard())->loginLocation();
        assert_same('/authV2/public/index.php?redirect=' . rawurlencode($uri), $loc);
        assert_false(str_contains($loc, 'reason='), 'không có reason');
    });
});

test('loginLocation: cookie + session active + phiên rỗng → reason=expired', function (): void {
    $uri = '/lichkehoach/public/index.php';
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        [],
        $uri,
        static function () use ($uri): void {
            $loc = (new Guard())->loginLocation();
            assert_same(
                '/authV2/public/index.php?redirect=' . rawurlencode($uri) . '&reason=expired',
                $loc
            );
        },
        true
    );
});

test('loginLocation: cookie + session có CSRF → không có reason', function (): void {
    $uri = '/lichkehoach/public/index.php';
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        ['shared_auth_csrf' => 'x'],
        $uri,
        static function () use ($uri): void {
            $loc = (new Guard())->loginLocation();
            assert_same('/authV2/public/index.php?redirect=' . rawurlencode($uri), $loc);
            assert_false(str_contains($loc, 'reason='));
        },
        true
    );
});

test('loginLocation: cookie + session chưa active → không có reason', function (): void {
    $uri = '/lichkehoach/public/index.php';
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        [],
        $uri,
        static function () use ($uri): void {
            $loc = (new Guard())->loginLocation();
            assert_same('/authV2/public/index.php?redirect=' . rawurlencode($uri), $loc);
            assert_false(str_contains($loc, 'reason='));
        },
        false
    );
});
