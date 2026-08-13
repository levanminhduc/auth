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

test('isSecure: BASELINE quyết theo request chứ không theo môi trường (task 2.2 sẽ đổi)', function (): void {
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

    // Điểm mấu chốt của task 2.2: production + request HTTP vẫn ra false.
    assert_false(
        with_env(
            ['SHARED_AUTH_ENV' => 'production'],
            static fn () => with_server(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => null], $secure)
        ),
        'hiện tại production + HTTP ra false → cookie mất cờ Secure; sau task 2.2 phải là true'
    );
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
            ['/lichkehoach/public/' => 'Lịch kế hoạch', '/nhansuhtdb/' => 'Nhân sự HTĐB'],
            EnvConfig::projects(),
            'mặc định gồm đúng 2 dự án đã tích hợp'
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

test('sessionSavePath: BASELINE production trả null → rơi về save_path chung (task 2.1 sẽ vá)', function (): void {
    $local = with_env(['SHARED_AUTH_ENV' => 'local'], static fn () => EnvConfig::sessionSavePath());
    assert_true(is_string($local), 'local có thư mục riêng');
    assert_contains('storage', (string) $local, 'local dùng storage/sessions');

    assert_null(
        with_env(['SHARED_AUTH_ENV' => 'production'], static fn () => EnvConfig::sessionSavePath()),
        'hiện tại production trả null = dùng save_path mặc định của php.ini; sau task 2.1 phải là thư mục riêng'
    );
});
