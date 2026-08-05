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
        if ($path === '' || $path[0] !== '/' || !self::isAllowedPath($path)) {
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

    private static function isAllowedPath(string $path): bool
    {
        $normalized = self::normalizePath(rawurldecode($path));
        if (self::hasUnsafeChars($normalized)) {
            return false;
        }

        foreach (self::selfPaths() as $selfPath) {
            if ($normalized === $selfPath) {
                return false;
            }
        }

        foreach (self::allowedPrefixes() as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function allowedPrefixes(): array
    {
        $prefixes = array_keys(EnvConfig::projects());
        $prefixes[] = rtrim(EnvConfig::baseUrl(), '/') . '/';
        return $prefixes;
    }

    /**
     * Redirect về chính trang login/logout/thư mục gốc của authV2 sẽ tạo vòng lặp.
     *
     * @return list<string>
     */
    private static function selfPaths(): array
    {
        $base = rtrim(EnvConfig::baseUrl(), '/');
        return [$base, $base . '/', $base . '/index.php', $base . '/logout.php'];
    }

    /**
     * Gỡ `.` và `..` trước khi so prefix, nếu không `/nhansuhtdb/../authV2/public/logout.php`
     * sẽ lọt allowlist rồi mới được trình duyệt rút gọn thành path khác hẳn.
     */
    private static function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if ($normalized !== '/' && str_ends_with($path, '/')) {
            $normalized .= '/';
        }
        return $normalized;
    }
}
