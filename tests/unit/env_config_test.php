<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;

/**
 * Chốt hành vi HIỆN TẠI của EnvConfig (golden baseline).
 */

function with_server(array $vars, callable $fn)
{
    $previous = [];
    foreach ($vars as $key => $value) {
        $previous[$key] = $_SERVER[$key] ?? null;
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
    try {
        return $fn();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
}

test('env: chỉ đúng chuỗi "production" mới là production', function (): void {
    assert_same('local', with_env(['SHARED_AUTH_ENV' => null], static fn () => EnvConfig::env()), 'không đặt');
    assert_same('production', with_env(['SHARED_AUTH_ENV' => 'production'], static fn () => EnvConfig::env()));
    assert_same('local', with_env(['SHARED_AUTH_ENV' => 'Production'], static fn () => EnvConfig::env()), 'hoa thường');
    assert_same('local', with_env(['SHARED_AUTH_ENV' => 'prod'], static fn () => EnvConfig::env()), 'viết tắt');
});

test('isSecure: chỉ phản ánh request hiện tại (logout dùng để suy scheme), không theo môi trường', function (): void {
    $secure = static fn () => EnvConfig::isSecure();

    assert_false(
        with_server(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => null], $secure),
        'request HTTP'
    );
    assert_true(with_server(['HTTPS' => 'on'], $secure), 'HTTPS=on');
    assert_false(with_server(['HTTPS' => 'off'], $secure), 'HTTPS=off');
    assert_true(
        with_server(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => 'https'], $secure),
        'header do client gửi cũng được tin'
    );

    assert_false(
        with_env(
            ['SHARED_AUTH_ENV' => 'production'],
            static fn () => with_server(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => null], $secure)
        ),
        'production + HTTP: isSecure vẫn false; cờ cookie do cookieSecure() quyết'
    );
});

test('cookieSecure: production luôn true, header client không hạ được; local theo request (task 2.2)', function (): void {
    $cookie = static fn () => EnvConfig::cookieSecure();
    $prod = static fn (array $server) => with_env(
        ['SHARED_AUTH_ENV' => 'production'],
        static fn () => with_server($server, $cookie)
    );
    $local = static fn (array $server) => with_env(
        ['SHARED_AUTH_ENV' => null],
        static fn () => with_server($server, $cookie)
    );

    assert_true($prod(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => null]), 'production + HTTP');
    assert_true($prod(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => 'http']), 'production + X-Forwarded-Proto: http');
    assert_true($prod(['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => null]), 'production + HTTPS=off');
    assert_true($prod(['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => null]), 'production + HTTPS');

    assert_false($local(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => null]), 'local + HTTP');
    assert_true($local(['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => null]), 'local + HTTPS');
});

test('upgradeOnLogin: mặc định TẮT, chỉ bật bằng giá trị khẳng định', function (): void {
    $upgrade = static fn () => EnvConfig::upgradeOnLogin();

    assert_false(with_env(['SHARED_AUTH_UPGRADE_ON_LOGIN' => null], $upgrade), 'không đặt = tắt');
    foreach (['1', 'true', 'on', 'yes', 'TRUE', ' On '] as $value) {
        assert_true(with_env(['SHARED_AUTH_UPGRADE_ON_LOGIN' => $value], $upgrade), "bật bằng '{$value}'");
    }
    foreach (['0', 'false', 'off', 'no', ''] as $value) {
        assert_false(with_env(['SHARED_AUTH_UPGRADE_ON_LOGIN' => $value], $upgrade), "tắt bằng '{$value}'");
    }
});

test('baseUrl và các URL dẫn xuất', function (): void {
    with_env(['SHARED_AUTH_BASE_URL' => null, 'SHARED_AUTH_ENV' => null], static function (): void {
        assert_same('/authV2/public', EnvConfig::baseUrl(), 'mặc định local');
        assert_same('/authV2/public/index.php', EnvConfig::loginUrl());
        assert_same('/authV2/public/portal.php', EnvConfig::portalUrl());
        assert_same('/authV2/public/logout.php', EnvConfig::logoutUrl());
        assert_same(
            '/authV2/public/logout.php?redirect=%2Fnhansuhtdb%2F',
            EnvConfig::logoutUrl('/nhansuhtdb/')
        );
    });

    with_env(['SHARED_AUTH_BASE_URL' => null, 'SHARED_AUTH_ENV' => 'production'], static function (): void {
        assert_same('/auth', EnvConfig::baseUrl(), 'mặc định production');
    });

    with_env(['SHARED_AUTH_BASE_URL' => '/auth/'], static function (): void {
        assert_same('/auth', EnvConfig::baseUrl(), 'dấu / cuối bị cắt');
    });
});

test('projects: mặc định và khi khai báo qua env', function (): void {
    with_env(['SHARED_AUTH_PROJECTS' => null], static function (): void {
        assert_same(
            [
                '/lichkehoach/public/' => 'Lịch kế hoạch',
                '/nhansuhtdb/'         => 'Nhân sự HTĐB',
                '/sangkienct/'         => 'Sáng kiến cải tiến',
                '/khsanxuat/'          => 'Kế hoạch sản xuất',
            ],
            EnvConfig::projects(),
            'mặc định gồm đúng các dự án đã tích hợp'
        );
    });

    with_env(['SHARED_AUTH_PROJECTS' => '/a/|Dự án A,/b|Dự án B,khong-co-slash|X,/c/'], static function (): void {
        assert_same(
            ['/a/' => 'Dự án A', '/b/' => 'Dự án B', '/c/' => '/c/'],
            EnvConfig::projects(),
            'tự thêm / cuối, bỏ entry không bắt đầu bằng /, thiếu nhãn thì lấy prefix làm nhãn'
        );
    });
});

test('allowedHosts: mặc định rỗng (chỉ cùng host)', function (): void {
    assert_same([], with_env(['SHARED_AUTH_ALLOWED_HOSTS' => null], static fn () => EnvConfig::allowedHosts()));
    assert_same(
        ['a.local', 'b.local:8080'],
        with_env(
            ['SHARED_AUTH_ALLOWED_HOSTS' => ' A.local , b.local:8080 '],
            static fn () => EnvConfig::allowedHosts()
        ),
        'chuẩn hoá về chữ thường, bỏ khoảng trắng'
    );
});

test('projectLabel: prefix dài nhất thắng', function (): void {
    with_env(
        ['SHARED_AUTH_PROJECTS' => '/duan/|Dự án,/duan/con/|Dự án con'],
        static function (): void {
            assert_same('Dự án con', EnvConfig::projectLabel('/duan/con/trang.php'));
            assert_same('Dự án', EnvConfig::projectLabel('/duan/trang.php'));
            assert_null(EnvConfig::projectLabel('/khac/trang.php'), 'ngoài allowlist');
            assert_null(EnvConfig::projectLabel(null), 'null');
            assert_null(EnvConfig::projectLabel(''), 'rỗng');
        }
    );
});

test('sessionName: mặc định HTSSOSESSID', function (): void {
    assert_same(
        'HTSSOSESSID',
        with_env(['SHARED_AUTH_SESSION_NAME' => null], static fn () => EnvConfig::sessionName())
    );
    assert_same(
        'KHAC',
        with_env(['SHARED_AUTH_SESSION_NAME' => 'KHAC'], static fn () => EnvConfig::sessionName())
    );
});

function with_scratch_dir(callable $fn): void
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'authv2_envcfg_' . bin2hex(random_bytes(4));
    mkdir($base, 0700, true);
    try {
        $fn($base);
    } finally {
        foreach (glob($base . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($base . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($base);
    }
}

test('sessionSavePath: SHARED_AUTH_SESSION_PATH được dùng và tạo nếu thiếu (task 2.1)', function (): void {
    with_scratch_dir(static function (string $base): void {
        $dir = $base . '/sess';
        foreach (['local', 'production'] as $env) {
            $path = with_env(
                ['SHARED_AUTH_ENV' => $env, 'SHARED_AUTH_SESSION_PATH' => $dir],
                static fn () => EnvConfig::sessionSavePath()
            );
            assert_same($dir, $path, $env);
            assert_true(is_dir($dir) && is_writable($dir), $env . ' thư mục đã tạo');
        }
    });
});

test('sessionSavePath: local không đặt env thì dùng thư mục tạm NGOÀI repo, không phải storage/ (task 2.1)', function (): void {
    $path = with_env(
        ['SHARED_AUTH_ENV' => 'local', 'SHARED_AUTH_SESSION_PATH' => null],
        static fn () => EnvConfig::sessionSavePath()
    );
    $iniBase = trim((string) get_cfg_var('session.save_path'));
    $iniBase = ($p = strrpos($iniBase, ';')) !== false ? substr($iniBase, $p + 1) : $iniBase;
    $expectedBase = $iniBase !== '' ? $iniBase : sys_get_temp_dir();
    assert_same(rtrim($expectedBase, '/\\') . '/htsso-sessions', $path, 'thư mục con của save_path php.ini, không phụ thuộc TEMP');
    assert_true(is_dir($path) && is_writable($path));

    $repo = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2)));
    $real = str_replace('\\', '/', (string) realpath($path));
    assert_false(str_starts_with(strtolower($real) . '/', strtolower($repo) . '/'), 'nằm ngoài repo/webroot');
});

test('sessionSavePath: env rỗng coi như chưa đặt', function (): void {
    $local = with_env(
        ['SHARED_AUTH_ENV' => 'local', 'SHARED_AUTH_SESSION_PATH' => ''],
        static fn () => EnvConfig::sessionSavePath()
    );
    assert_contains('htsso-sessions', $local);

    $threw = false;
    try {
        with_env(['SHARED_AUTH_ENV' => 'production', 'SHARED_AUTH_SESSION_PATH' => '  '], static fn () => EnvConfig::sessionSavePath());
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'production + env rỗng phải ném lỗi');
});

test('sessionSavePath: production không đặt env thì ném lỗi nêu tên biến, KHÔNG trả null (task 2.1)', function (): void {
    try {
        with_env(
            ['SHARED_AUTH_ENV' => 'production', 'SHARED_AUTH_SESSION_PATH' => null],
            static fn () => EnvConfig::sessionSavePath()
        );
    } catch (RuntimeException $e) {
        assert_contains('SHARED_AUTH_SESSION_PATH', $e->getMessage());
        return;
    }
    fail_test('production thiếu SHARED_AUTH_SESSION_PATH phải ném RuntimeException');
});

test('sessionSavePath: thư mục không tạo được thì ném lỗi, không rơi về save_path chung (task 2.1)', function (): void {
    with_scratch_dir(static function (string $base): void {
        $file = $base . '/is_a_file';
        file_put_contents($file, 'x');
        foreach (['local', 'production'] as $env) {
            $threw = false;
            try {
                with_env(
                    ['SHARED_AUTH_ENV' => $env, 'SHARED_AUTH_SESSION_PATH' => $file . '/sub'],
                    static fn () => EnvConfig::sessionSavePath()
                );
            } catch (RuntimeException $e) {
                $threw = true;
                assert_contains('SHARED_AUTH_SESSION_PATH', $e->getMessage(), $env);
            }
            assert_true($threw, $env . ' phải ném lỗi');
        }
    });
});

test('absoluteLifetime: mặc định 30 ngày, 0 là tắt, giá trị hỏng về mặc định (task 3.8)', function (): void {
    $get = static fn (?string $v) => with_env(['SHARED_AUTH_ABSOLUTE_LIFETIME' => $v], static fn () => EnvConfig::absoluteLifetime());
    assert_same(2592000, $get(null), 'không đặt');
    assert_same(2592000, $get(''), 'rỗng');
    assert_same(0, $get('0'), '0 = tắt');
    assert_same(3600, $get('3600'));
    assert_same(3600, $get(' 3600 '), 'khoảng trắng');
    assert_same(2592000, $get('-5'), 'âm không được hiểu là tắt');
    assert_same(2592000, $get('abc'), 'không phải số');
    assert_same(2592000, $get('1.5'), 'số thực');
});
