<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class RedirectSanitizer
{
    public static function sanitize(?string $target, ?string $fallback = null): string
    {
        return self::sanitizeOrNull($target) ?? ($fallback ?? EnvConfig::portalUrl());
    }

    /**
     * Trả về URL an toàn để redirect, hoặc null nếu không dùng được.
     * Dùng bản null khi cần phân biệt "không có redirect" với "redirect về trang mặc định"
     * (logout: không có origin thì về thẳng login, không gắn ?redirect=).
     */
    public static function sanitizeOrNull(?string $target): ?string
    {
        if ($target === null) {
            return null;
        }

        $target = trim($target);
        if ($target === '' || self::hasUnsafeChars($target)) {
            return null;
        }

        $parts = parse_url($target);
        if ($parts === false) {
            return null;
        }

        $origin = '';
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = self::resolveOrigin($parts);
            if ($origin === null) {
                return null;
            }
        } elseif ($target[0] !== '/' || (isset($target[1]) && $target[1] === '/')) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        $path = self::allowedPathOrNull($path);
        if ($path === null) {
            return null;
        }

        $url = $origin . $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }

    private static function hasUnsafeChars(string $value): bool
    {
        return (bool) preg_match('#[\\\\\x00-\x1f\x7f]#', $value);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function resolveOrigin(array $parts): ?string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // userinfo trong URL là cách kinh điển để nguỵ trang host (http://tin-cay@evil.com)
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $authority = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $current = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($authority !== $current && !in_array($authority, EnvConfig::allowedHosts(), true)) {
            return null;
        }

        return $scheme . '://' . $authority;
    }

    /**
     * Trả về path đã chuẩn hoá (và mã hoá lại) nếu được phép. Phải trả bản chuẩn hoá chứ không phải bản thô:
     * allowlist đã duyệt path SAU khi rút gọn `..`, nên bản thô có thể trỏ sang dự án khác với bản đã duyệt
     * (và bị gắn nhãn dự án sai).
     */
    private static function allowedPathOrNull(string $path): ?string
    {
        // WHY: path mơ hồ (`%2f`, `%5c`, `%25`) có độ sâu thật phụ thuộc server/proxy nên không thể chứng minh nó nằm ngoài vùng cấm; từ chối hẳn thay vì đoán. Chuẩn hoá giải mã đúng một lượt, không giải mã trước rồi mới gọi.
        if (PathNormalizer::isAmbiguous($path)) {
            return null;
        }

        $normalized = PathNormalizer::normalize($path);
        if (self::hasUnsafeChars($normalized)) {
            return null;
        }

        $base = self::normalizedBase();
        $lower = strtolower($normalized);
        // WHY: allowlist, không phải blocklist — mọi thứ dưới base đều bị loại (logout.php/x, Logout.php, assets, ...) trừ trang đích portal.php. Apache trên Windows không phân biệt hoa/thường và chấp nhận PATH_INFO, nên so khớp chính xác từng file là không đủ.
        if ($base === '') {
            // WHY: base là gốc site thì "dưới base" là mọi path; chỉ portal.php được miễn, còn lại phải khớp prefix dự án (index.php, logout.php và mọi biến thể không có prefix nào nên bị loại).
            if ($lower === '/portal.php') {
                return self::encodePath($normalized);
            }
        } elseif ($lower === $base || str_starts_with($lower, $base . '/')) {
            return $lower === $base . '/portal.php' ? self::encodePath($normalized) : null;
        }

        foreach (array_keys(EnvConfig::projects()) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return self::encodePath($normalized);
            }
        }
        return null;
    }

    // WHY: normalize() đã giải mã; ký tự như `?`, `#`, khoảng trắng sinh ra từ `%3f`, `%23`, `%20` phải mã hoá lại, nếu không chúng đổi nghĩa (cắt path, mở query/fragment) khi ghép vào URL.
    private static function encodePath(string $path): string
    {
        return preg_replace_callback(
            '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/]#',
            static fn (array $m): string => rawurlencode($m[0]),
            $path
        );
    }

    private static function normalizedBase(): string
    {
        return rtrim(strtolower(PathNormalizer::normalize(EnvConfig::baseUrl())), '/');
    }
}
