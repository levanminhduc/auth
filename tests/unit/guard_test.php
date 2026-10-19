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

test('isPublic: `..` không còn lọt qua allowlist tiền tố (task 3.2)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_false($guard->isPublic('/lichkehoach/public/api/../edit.php'));
    assert_false($guard->isPublic('/lichkehoach/public/api/../../index.php'));
    assert_false($guard->isPublic('/lichkehoach/public/api/x/../../edit.php'));
    assert_true($guard->isPublic('/lichkehoach/public/api/x/../load_tasks.php'), '`..` ở trong allowlist vẫn public');
    assert_false($guard->isPublic('/../../lichkehoach/public/api/../edit.php'), 'không vượt quá gốc');
});

test('isPublic: percent-encoding được giải mã trước khi so (task 3.2)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_true($guard->isPublic('/lichkehoach/public/%61pi/load_tasks.php'), 'ký tự mã hoá khớp tiền tố');
    assert_false($guard->isPublic('/lichkehoach/public/api/%2e%2e/edit.php'), '%2e%2e');
    assert_false($guard->isPublic('/lichkehoach/public/api/%2E%2E/edit.php'), '%2E%2E');
    assert_false($guard->isPublic('/lichkehoach/public/api/.%2e/edit.php'), 'trộn . và %2e');
    assert_false($guard->isPublic('/lichkehoach/public/api/%252e%252e/edit.php'), 'mã hoá hai lần');
    assert_false($guard->isPublic('/lichkehoach/public/api/..%2fedit.php'), '%2f sau ..');
    assert_false($guard->isPublic('/lichkehoach/public/api/..%5cedit.php'), '%5c sau ..');
});

test('isPublic: path mơ hồ (`\\`, %2f, %5c, %25) không bao giờ public — chống `..` bị rút nhầm segment', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_false($guard->isPublic('/lichkehoach/public/api%252fx/../edit.php'), '%252f: server phục vụ public/edit.php');
    assert_false($guard->isPublic('/lichkehoach/public/api\\x/../edit.php'), '\\ thêm độ sâu');
    assert_false($guard->isPublic('/lichkehoach/public/api%2fx/../edit.php'), '%2f khi AllowEncodedSlashes On/NoDecode');
    assert_false($guard->isPublic('/lichkehoach/public/api%5cx/../edit.php'), '%5c');
    assert_false($guard->isPublic('/lichkehoach/public/api/legit%2fname.php'), 'cả path hợp lệ có %2f cũng bị từ chối (fail-closed)');
});

test('isPublic: dấu \\ và // không né được allowlist', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_false($guard->isPublic('/lichkehoach/public/api\\..\\edit.php'), '\\..\\');
    assert_false($guard->isPublic('/lichkehoach/public/api/..\\edit.php'));
    assert_true($guard->isPublic('/lichkehoach/public//api//load_tasks.php'), '// gộp lại');
    assert_true($guard->isPublic('//lichkehoach/public/api/load_tasks.php'), '// ở đầu');
    assert_false($guard->isPublic('/lichkehoach/public//api/..//edit.php'));
});

test('isPublic: không phân biệt hoa/thường (filesystem Windows)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/', '/NhanSu/Webhook.php']);
    assert_true($guard->isPublic('/LichKeHoach/Public/API/load_tasks.php'));
    assert_true($guard->isPublic('/nhansu/webhook.PHP'));
    assert_false($guard->isPublic('/LichKeHoach/Public/API/../Edit.php'));
});

test('isPublic: entry không có / cuối thì khớp tuyệt đối, không khớp tiền tố chuỗi', function (): void {
    $guard = new Guard(['/lichkehoach/public/api']);
    assert_true($guard->isPublic('/lichkehoach/public/api'));
    assert_false($guard->isPublic('/lichkehoach/public/apix'), 'ranh giới segment');
    assert_false($guard->isPublic('/lichkehoach/public/api/x.php'));
    assert_false($guard->isPublic('/lichkehoach/public/api/../api2'));
});

function guard_run_script(string $body, array $env = []): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'authv2_guard_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $script = $dir . DIRECTORY_SEPARATOR . 'run.php';
    $autoload = var_export(dirname(__DIR__, 2) . '/autoload.php', true);
    file_put_contents($script, "<?php\nrequire {$autoload};\n" . $body);

    $childEnv = getenv();
    foreach (array_merge([
        'SHARED_AUTH_ENV'            => 'local',
        'SHARED_AUTH_SESSION_PATH'   => $dir . DIRECTORY_SEPARATOR . 'sessions',
        'SHARED_AUTH_SESSION_NAME'   => null,
        'SHARED_AUTH_BASE_URL'       => '/authV2/public',
    ], $env) as $key => $value) {
        if ($value === null) {
            unset($childEnv[$key]);
        } else {
            $childEnv[$key] = $value;
        }
    }

    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'error_log=', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $childEnv
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    foreach (glob($dir . '/sessions/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir . '/sessions');
    @unlink($script);
    @rmdir($dir);

    return ['out' => trim((string) $out), 'err' => trim((string) $err), 'code' => $code];
}

const GUARD_SNAPSHOT = <<<'PHP'
register_shutdown_function(static function (): void {
    $r = new ReflectionProperty(Hoatho\SharedAuth\SessionBootstrap::class, 'renewedFor');
    echo json_encode([
        'active'  => session_status() === PHP_SESSION_ACTIVE,
        'renewed' => $r->getValue() !== null && $r->getValue() === session_id(),
    ]);
});
PHP;

test('requireLogin: prepend mode (chỉ configure) tự start phiên và gia hạn cookie (task 2.3)', function (): void {
    $run = guard_run_script(
        GUARD_SNAPSHOT . "\n"
        . "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/index.php';\n"
        . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
        . "(new Hoatho\\SharedAuth\\Guard(['/nhansuhtdb/public/']))->requireLogin();\n"
    );
    assert_same('{"active":true,"renewed":true}', $run['out'], $run['err']);
});

test('requireLogin: phiên đã start bởi session_start() của dự án thì chỉ gia hạn cookie (task 2.3)', function (): void {
    $run = guard_run_script(
        GUARD_SNAPSHOT . "\n"
        . "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/index.php';\n"
        . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
        . "session_start();\n"
        . "(new Hoatho\\SharedAuth\\Guard())->requireLogin();\n"
    );
    assert_same('{"active":true,"renewed":true}', $run['out'], $run['err']);
});

test('requireLogin: đã đăng nhập thì quay lại bình thường, cookie vẫn được gia hạn', function (): void {
    $run = guard_run_script(
        "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/index.php';\n"
        . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
        . "session_start();\n"
        . "Hoatho\\SharedAuth\\SessionContract::set(['id' => 5, 'name' => 'a']);\n"
        . "(new Hoatho\\SharedAuth\\Guard())->requireLogin();\n"
        . "\$r = new ReflectionProperty(Hoatho\\SharedAuth\\SessionBootstrap::class, 'renewedFor');\n"
        . "echo 'returned:' . (\$r->getValue() === session_id() ? 'renewed' : 'not-renewed');\n"
    );
    assert_same('returned:renewed', $run['out'], $run['err']);
});

test('requireLogin: path public thì không mở phiên', function (): void {
    $run = guard_run_script(
        GUARD_SNAPSHOT . "\n"
        . "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/public/ping.php?x=1';\n"
        . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
        . "(new Hoatho\\SharedAuth\\Guard(['/nhansuhtdb/public/']))->requireLogin();\n"
        . "echo '|returned';\n"
    );
    assert_same('|returned{"active":false,"renewed":false}', $run['out'], $run['err']);
});

test('requireLogin: REQUEST_URI dạng `..` / `//` / mã hoá không được coi là public', function (): void {
    foreach (['/nhansuhtdb/public/../index.php', '/nhansuhtdb/public/%2e%2e/index.php', '//nhansuhtdb/public/..//index.php'] as $uri) {
        $run = guard_run_script(
            "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
            . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
            . "(new Hoatho\\SharedAuth\\Guard(['/nhansuhtdb/public/']))->requireLogin();\n"
            . "echo 'returned';\n"
        );
        assert_same('', $run['out'], $uri . ' ' . $run['err']);
        assert_same(0, $run['code'], $uri . ' thoát bất thường: ' . $run['err']);
        assert_same('', $run['err'], $uri);
    }
});

test('requireLogin: header đã gửi mà chưa có phiên thì chặn request, ghi log, không warning (fail-closed)', function (): void {
    $run = guard_run_script(
        "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/index.php';\n"
        . "Hoatho\\SharedAuth\\SessionBootstrap::configure();\n"
        . "echo 'x';\n"
        . "(new Hoatho\\SharedAuth\\Guard())->requireLogin();\n"
        . "echo 'returned';\n"
    );
    assert_same('x', $run['out'], 'không chạy tiếp sau requireLogin');
    assert_same(0, $run['code'], $run['err']);
    assert_contains('[shared-auth] Guard', $run['err'], 'phải log lý do chặn');
    assert_false(str_contains($run['err'], 'Warning'), 'không để session_start() phát warning: ' . $run['err']);
});

test('requireLogin: production thiếu SHARED_AUTH_SESSION_PATH thì ném lỗi, không chạy tiếp với save_path chung (task 2.1)', function (): void {
    $run = guard_run_script(
        "\$_SERVER['REQUEST_URI'] = '/nhansuhtdb/index.php';\n"
        . "try {\n"
        . "    (new Hoatho\\SharedAuth\\Guard())->requireLogin();\n"
        . "    echo 'returned';\n"
        . "} catch (RuntimeException \$e) {\n"
        . "    echo 'threw:' . (str_contains(\$e->getMessage(), 'SHARED_AUTH_SESSION_PATH') ? 'named' : 'unnamed');\n"
        . "}\n",
        ['SHARED_AUTH_ENV' => 'production', 'SHARED_AUTH_SESSION_PATH' => null]
    );
    assert_same('threw:named', $run['out'], $run['err']);
});

test('prepend.php: cấu hình lỗi vẫn được log, CLI chạy tiếp (nhánh web trả 500 chỉ kiểm được qua HTTP)', function (): void {
    $prepend = var_export(dirname(__DIR__, 2) . '/prepend.php', true);
    $run = guard_run_script(
        "require {$prepend};\n"
        . "echo 'continued';\n",
        ['SHARED_AUTH_ENV' => 'production', 'SHARED_AUTH_SESSION_PATH' => null]
    );
    assert_same('continued', $run['out'], 'CLI không dùng phiên nên không bị chặn');
    assert_contains('SHARED_AUTH_SESSION_PATH', $run['err'], 'lỗi vẫn được log ra stderr');
});

function guard_reset_cap_flag(): void
{
    (new ReflectionProperty(Hoatho\SharedAuth\SessionContract::class, 'capExpired'))->setValue(null, false);
}

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
        guard_reset_cap_flag();
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
            guard_reset_cap_flag();
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

const LOGIN_LOCATION_URI = '/lichkehoach/public/index.php';

function expected_login_location(bool $expired): string
{
    return '/authV2/public/index.php?redirect=' . rawurlencode(LOGIN_LOCATION_URI) . ($expired ? '&reason=expired' : '');
}

test('loginLocation: không cookie thì chỉ redirect', function (): void {
    with_login_location_context([], [], LOGIN_LOCATION_URI, static function (): void {
        assert_same(expected_login_location(false), (new Guard())->loginLocation());
    }, true);
});

test('loginLocation: cookie mang id PHP không biết (bị bỏ, cấp id mới) → reason=expired', function (): void {
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        [],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_true(session_id() !== 'abc', 'điều kiện đầu vào: id hiện tại khác cookie');
            assert_same(expected_login_location(true), (new Guard())->loginLocation());
        },
        true
    );
});

test('loginLocation: cookie lạ vẫn là expired dù phiên mới đã có dữ liệu (không dựa vào phiên rỗng)', function (): void {
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        ['shared_auth_csrf' => 'x'],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_same(expected_login_location(true), (new Guard())->loginLocation());
        },
        true
    );
});

test('loginLocation: cookie trùng session_id() và phiên rỗng → không reason (khách mới / dự án chỉ session_destroy)', function (): void {
    with_login_location_context(
        [],
        [],
        LOGIN_LOCATION_URI,
        static function (): void {
            $_COOKIE['HTSSOSESSID'] = session_id();
            assert_same([], $_SESSION);
            assert_same(expected_login_location(false), (new Guard())->loginLocation());
        },
        true
    );
});

test('loginLocation: cookie trùng session_id() và phiên có CSRF → không reason', function (): void {
    with_login_location_context(
        [],
        ['shared_auth_csrf' => 'x'],
        LOGIN_LOCATION_URI,
        static function (): void {
            $_COOKIE['HTSSOSESSID'] = session_id();
            assert_same(expected_login_location(false), (new Guard())->loginLocation());
        },
        true
    );
});

test('loginLocation: cookie không phải chuỗi (HTSSOSESSID[]=x) → không reason', function (): void {
    with_login_location_context(
        ['HTSSOSESSID' => ['x']],
        [],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_same(expected_login_location(false), (new Guard())->loginLocation());
        },
        true
    );
});

test('loginLocation: cookie + session chưa active → không reason', function (): void {
    with_login_location_context(
        ['HTSSOSESSID' => 'abc'],
        [],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_same(expected_login_location(false), (new Guard())->loginLocation());
        },
        false
    );
});

test('loginLocation: phiên bị xoá vì quá trần tuyệt đối → reason=expired dù không cookie, kể cả khi lần kiểm tra sau thấy phiên rỗng', function (): void {
    with_login_location_context(
        [],
        ['user_id' => 3, 'logged_in_at' => time() - (60 * 24 * 60 * 60), 'nhan_vien_ma' => 'NV1'],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_false(Hoatho\SharedAuth\SessionContract::isLoggedIn(), 'quá trần');
            assert_false(Hoatho\SharedAuth\SessionContract::isLoggedIn(), 'lần gọi thứ hai trên phiên đã rỗng');
            assert_same([], $_SESSION);
            assert_same(expected_login_location(true), (new Guard())->loginLocation());
        }
    );
});

test('loginLocation: phiên còn trong trần thì không bị đánh dấu expired', function (): void {
    with_login_location_context(
        [],
        ['user_id' => 3, 'logged_in_at' => time() - 60],
        LOGIN_LOCATION_URI,
        static function (): void {
            assert_true(Hoatho\SharedAuth\SessionContract::isLoggedIn());
            assert_same(expected_login_location(false), (new Guard())->loginLocation());
        }
    );
});
