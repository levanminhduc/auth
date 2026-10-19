<?php

declare(strict_types=1);

use Hoatho\SharedAuth\RedirectSanitizer;

/**
 * Chốt hành vi HIỆN TẠI của RedirectSanitizer (golden baseline).
 * Đây là lớp allowlist duy nhất cho ?redirect=; mọi thay đổi làm đổi kết quả ở đây
 * phải là thay đổi có chủ ý.
 */

function sanitize_with(string $host, ?string $target): string
{
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = $host;
    try {
        return with_default_env(static fn (): string => RedirectSanitizer::sanitize($target));
    } finally {
        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }
    }
}

function sanitize_or_null_with(string $host, ?string $target, array $envOverrides = []): ?string
{
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = $host;
    try {
        return with_default_env(static fn (): ?string => RedirectSanitizer::sanitizeOrNull($target), $envOverrides);
    } finally {
        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }
    }
}

const PORTAL = '/authV2/public/portal.php';

test('sanitize: null và chuỗi rỗng về portal', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', null), 'null');
    assert_same(PORTAL, sanitize_with('localhost', ''), 'rỗng');
    assert_same(PORTAL, sanitize_with('localhost', '   '), 'toàn khoảng trắng');
});

test('sanitize: path nội bộ thuộc dự án đã khai báo thì giữ nguyên', function (): void {
    assert_same(
        '/lichkehoach/public/index.php',
        sanitize_with('localhost', '/lichkehoach/public/index.php')
    );
    assert_same('/nhansuhtdb/', sanitize_with('localhost', '/nhansuhtdb/'));
});

test('sanitize: path ngoài allowlist dự án bị loại về portal', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', '/duan-chua-khai-bao/index.php'));
    assert_same(PORTAL, sanitize_with('localhost', '/'));
});

test('sanitize: chặn open redirect ra host khác', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', 'http://evil.com/nhansuhtdb/'), 'http tuyệt đối');
    assert_same(PORTAL, sanitize_with('localhost', '//evil.com/nhansuhtdb/'), 'scheme-relative');
    assert_same(PORTAL, sanitize_with('localhost', 'https://evil.com/nhansuhtdb/'), 'https tuyệt đối');
});

test('sanitize: chặn nguỵ trang host bằng userinfo', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', 'http://localhost@evil.com/nhansuhtdb/'));
});

test('sanitize: chặn scheme không phải http/https', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', 'javascript:alert(1)'));
    assert_same(PORTAL, sanitize_with('localhost', 'data:text/html,x'));
});

test('sanitize: URL tuyệt đối cùng host thì được giữ cả origin', function (): void {
    assert_same(
        'http://localhost/nhansuhtdb/',
        sanitize_with('localhost', 'http://localhost/nhansuhtdb/')
    );
});

test('sanitize: chặn backslash và ký tự điều khiển', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', '/nhansuhtdb\\..\\x'), 'backslash');
    assert_same(PORTAL, sanitize_with('localhost', "/nhansuhtdb/\n/x"), 'xuống dòng');
});

test('sanitize: chặn redirect về chính trang login/logout (vòng lặp)', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', '/authV2/public/index.php'), 'login');
    assert_same(PORTAL, sanitize_with('localhost', '/authV2/public/logout.php'), 'logout');
    assert_same(PORTAL, sanitize_with('localhost', '/authV2/public'), 'thư mục gốc');
    assert_same(PORTAL, sanitize_with('localhost', '/authV2/public/'), 'thư mục gốc có /');
});

test('sanitize: trang khác trong authV2 vẫn được phép', function (): void {
    assert_same(PORTAL, sanitize_with('localhost', '/authV2/public/portal.php'));
});

test('sanitizeOrNull: mọi thứ dưới base authV2 bị loại, kể cả biến thể hoa/thường và PATH_INFO', function (): void {
    $rejected = [
        '/authV2/public/logout.php/x',
        '/authV2/public/Logout.php',
        '/authV2/public/LOGOUT.PHP',
        '/authV2/public/%6cogout.php',
        '/authV2/public/%4Cogout.php',
        '/authV2/public/./logout.php',
        '/authV2/public/x/../logout.php',
        '/authV2/public/index.php/x',
        '/authV2/public/Index.php',
        '/authV2/public/INDEX.PHP',
        '/authV2/public/portal.php/x',
        '/authV2/public/portal.php/',
        '/authV2/public/portal.php.',
        '/authV2/public/assets/css/app.css',
        '/authV2/public/foo.php',
        '/AUTHV2/PUBLIC/logout.php',
        '/authv2/public',
        '/authv2/public/',
        '/authV2/public//logout.php',
        '/nhansuhtdb/../authV2/public/Logout.php',
        '/nhansuhtdb/%2e%2e/authV2/public/LOGOUT.php',
    ];
    foreach ($rejected as $path) {
        assert_null(sanitize_or_null_with('localhost', $path), $path);
        assert_null(sanitize_or_null_with('localhost', 'http://localhost' . $path), 'http://localhost' . $path);
    }
});

test('sanitizeOrNull: portal.php là file duy nhất dưới base được phép, không phân biệt hoa/thường', function (): void {
    foreach (['/authV2/public/portal.php', '/authV2/public/Portal.php', '/authV2/public/PORTAL.PHP'] as $path) {
        assert_same($path, sanitize_or_null_with('localhost', $path), $path);
    }
    assert_same('/authV2/public/portal.php', sanitize_or_null_with('localhost', '/authV2/public/%70ortal.php'), 'trả path đã giải mã');
    assert_same(
        'http://localhost/authV2/public/portal.php?x=1',
        sanitize_or_null_with('localhost', 'http://localhost/authV2/public/portal.php?x=1')
    );
});

test('sanitizeOrNull: path mơ hồ (%2f, %5c, %25) bị loại, không giải mã lần hai', function (): void {
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/..%2fauthV2/public/logout.php'), '%2f');
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/..%5cauthV2/public/logout.php'), '%5c');
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/%252e%252e/authV2/public/logout.php'), '%25');
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/a%2fb'), '%2f trong path dự án');
});

test('sanitizeOrNull: ranh giới segment của base, prefix chung không bị loại nhầm', function (): void {
    $prod = ['SHARED_AUTH_BASE_URL' => '/auth', 'SHARED_AUTH_PROJECTS' => '/authV2extra/|Khác,/nhansuhtdb/|Nhân sự HTĐB'];
    assert_null(sanitize_or_null_with('localhost', '/auth/logout.php', $prod), 'dưới /auth');
    assert_null(sanitize_or_null_with('localhost', '/auth', $prod), 'base');
    assert_null(sanitize_or_null_with('localhost', '/auth/', $prod), 'base có /');
    assert_same('/auth/portal.php', sanitize_or_null_with('localhost', '/auth/portal.php', $prod), 'portal');
    assert_same('/authV2extra/x', sanitize_or_null_with('localhost', '/authV2extra/x', $prod), '/authV2extra không nằm dưới /auth');
    assert_same('/nhansuhtdb/', sanitize_or_null_with('localhost', '/nhansuhtdb/', $prod), 'dự án khác');

    $withSlash = ['SHARED_AUTH_BASE_URL' => '/authV2/public/'];
    assert_null(sanitize_or_null_with('localhost', '/authV2/public/Logout.php', $withSlash), 'base khai báo có / cuối');
    assert_same('/authV2/public/portal.php', sanitize_or_null_with('localhost', '/authV2/public/portal.php', $withSlash));
});

test('sanitizeOrNull: trả về path đã chuẩn hoá, không phải bản thô đã qua allowlist', function (): void {
    assert_same(
        '/lichkehoach/public/x',
        sanitize_or_null_with('localhost', '/nhansuhtdb/../lichkehoach/public/x'),
        'traversal sang dự án khác'
    );
    assert_same(
        '/lichkehoach/public/x',
        sanitize_or_null_with('localhost', '/nhansuhtdb/%2e%2e/lichkehoach/public/x'),
        '%2e%2e'
    );
    assert_same('/nhansuhtdb/a/b', sanitize_or_null_with('localhost', '/nhansuhtdb/./a//b'), '. và //');
    assert_same(
        'http://localhost/lichkehoach/public/x?a=../b#f/../g',
        sanitize_or_null_with('localhost', 'http://localhost/nhansuhtdb/../lichkehoach/public/x?a=../b#f/../g'),
        'origin, query, fragment giữ nguyên'
    );
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/../x'), 'rút gọn ra ngoài allowlist');
    assert_null(sanitize_or_null_with('localhost', '/nhansuhtdb/../../etc/passwd'), 'vượt gốc');
    assert_same(
        'Lịch kế hoạch',
        Hoatho\SharedAuth\EnvConfig::projectLabel(
            with_default_env(static fn (): string => RedirectSanitizer::sanitize('/nhansuhtdb/../lichkehoach/public/x'))
        ),
        'nhãn theo dự án thật'
    );
});

test('sanitizeOrNull: ký tự giải mã ra `?`, `#`, khoảng trắng được mã hoá lại, không đổi nghĩa URL', function (): void {
    assert_same('/nhansuhtdb/a%3Fb', sanitize_or_null_with('localhost', '/nhansuhtdb/a%3fb'), '%3f');
    assert_same('/nhansuhtdb/a%23b', sanitize_or_null_with('localhost', '/nhansuhtdb/a%23b'), '%23');
    assert_same('/nhansuhtdb/a%20b?q=1', sanitize_or_null_with('localhost', '/nhansuhtdb/a%20b?q=1'), '%20');
    assert_same('/nhansuhtdb/%C3%A9', sanitize_or_null_with('localhost', "/nhansuhtdb/\xC3\xA9"), 'byte không ASCII');
    assert_same('/nhansuhtdb/a-b_c.php', sanitize_or_null_with('localhost', '/nhansuhtdb/a-b_c.php'), 'ký tự an toàn giữ nguyên');
});

test('sanitizeOrNull: base là gốc site (`/`) chỉ cho portal.php, vẫn nhận path dự án', function (): void {
    foreach (['/', '//'] as $base) {
        $root = ['SHARED_AUTH_BASE_URL' => $base];
        $portal = with_default_env(static fn (): string => Hoatho\SharedAuth\EnvConfig::portalUrl(), $root);
        assert_same('/portal.php', $portal, "portalUrl với base '$base'");

        assert_same('/portal.php', sanitize_or_null_with('localhost', '/portal.php', $root), "portal, base '$base'");
        assert_same('/Portal.php', sanitize_or_null_with('localhost', '/Portal.php', $root), 'portal hoa/thường');
        assert_same('/portal.php', sanitize_or_null_with('localhost', '/%70ortal.php', $root), 'portal mã hoá');
        assert_same('/nhansuhtdb/x', sanitize_or_null_with('localhost', '/nhansuhtdb/x', $root), 'dự án');
        assert_same(
            '/lichkehoach/public/x',
            sanitize_or_null_with('localhost', '/nhansuhtdb/../lichkehoach/public/x', $root),
            'dự án, chuẩn hoá'
        );

        $rejected = [
            '/index.php', '/Index.php', '/INDEX.PHP', '/index.php/x',
            '/logout.php', '/Logout.php', '/LOGOUT.PHP', '/logout.php/x', '/%6cogout.php', '/./logout.php',
            '/nhansuhtdb/../logout.php', '/nhansuhtdb/../Index.php',
            '/portal.php/x', '/portal.php/', '/portal.php.',
            '/', '/assets/css/app.css', '/foo.php', '/duan-chua-khai-bao/index.php',
        ];
        foreach ($rejected as $path) {
            assert_null(sanitize_or_null_with('localhost', $path, $root), "base '$base': $path");
            assert_null(sanitize_or_null_with('localhost', 'http://localhost' . $path, $root), "base '$base': http://localhost$path");
        }
    }
});

test('sanitizeOrNull: portalUrl mặc định qua được chính sanitizer', function (): void {
    foreach ([['SHARED_AUTH_BASE_URL' => '/authV2/public'], ['SHARED_AUTH_BASE_URL' => '/auth'], ['SHARED_AUTH_BASE_URL' => '/auth/'], ['SHARED_AUTH_BASE_URL' => '/']] as $env) {
        $portal = with_default_env(static fn (): string => Hoatho\SharedAuth\EnvConfig::portalUrl(), $env);
        assert_same($portal, sanitize_or_null_with('localhost', $portal, $env), $portal);
    }
});

test('sanitize: `..` được rút gọn trước khi so allowlist', function (): void {
    // Rút gọn ra /authV2/public/logout.php = self path → bị loại.
    assert_same(
        PORTAL,
        sanitize_with('localhost', '/nhansuhtdb/../authV2/public/logout.php'),
        'traversal về self path'
    );
    // Rút gọn ra /nhansuhtdb/x.php = hợp lệ; giá trị trả về là path ĐÃ rút gọn.
    assert_same(
        '/nhansuhtdb/x.php',
        sanitize_with('localhost', '/lichkehoach/public/../../nhansuhtdb/x.php'),
        'traversal về path hợp lệ'
    );
});

test('sanitize: percent-encoding không lách được allowlist', function (): void {
    assert_same(
        PORTAL,
        sanitize_with('localhost', '/nhansuhtdb/%2e%2e/authV2/public/logout.php')
    );
});

test('sanitize: query và fragment được giữ lại', function (): void {
    assert_same(
        '/nhansuhtdb/?page=list#top',
        sanitize_with('localhost', '/nhansuhtdb/?page=list#top')
    );
});

test('sanitizeOrNull: phân biệt "không có redirect" với "về mặc định"', function (): void {
    assert_null(sanitize_or_null_with('localhost', null), 'null vào thì null ra');
    assert_null(sanitize_or_null_with('localhost', '/duan-chua-khai-bao/'), 'path bị loại thì null');
    assert_same('/nhansuhtdb/', sanitize_or_null_with('localhost', '/nhansuhtdb/'), 'path hợp lệ');
});
