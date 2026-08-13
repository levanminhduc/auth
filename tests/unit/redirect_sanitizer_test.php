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

function sanitize_or_null_with(string $host, ?string $target): ?string
{
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = $host;
    try {
        return with_default_env(static fn (): ?string => RedirectSanitizer::sanitizeOrNull($target));
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

test('sanitize: `..` được rút gọn trước khi so allowlist', function (): void {
    // Rút gọn ra /authV2/public/logout.php = self path → bị loại.
    assert_same(
        PORTAL,
        sanitize_with('localhost', '/nhansuhtdb/../authV2/public/logout.php'),
        'traversal về self path'
    );
    // Rút gọn ra /nhansuhtdb/x.php = hợp lệ; giá trị trả về là chuỗi GỐC chưa rút gọn.
    assert_same(
        '/lichkehoach/public/../../nhansuhtdb/x.php',
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
