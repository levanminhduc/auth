<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class EnvConfig
{
    public const SESSION_LIFETIME = 7 * 24 * 60 * 60;

    public const SESSION_NAME_DEFAULT = 'HTSSOSESSID';

    public static function env(): string
    {
        $env = getenv('SHARED_AUTH_ENV');
        return ($env === 'production') ? 'production' : 'local';
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    public static function sessionName(): string
    {
        $name = getenv('SHARED_AUTH_SESSION_NAME');
        return ($name !== false && $name !== '') ? $name : self::SESSION_NAME_DEFAULT;
    }

    public static function upgradeOnLogin(): bool
    {
        $v = getenv('SHARED_AUTH_UPGRADE_ON_LOGIN');
        if ($v === false) {
            return false;
        }
        return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function baseUrl(): string
    {
        $base = getenv('SHARED_AUTH_BASE_URL');
        if ($base !== false && $base !== '') {
            return rtrim($base, '/');
        }
        return self::isProduction() ? '/auth' : '/authV2/public';
    }

    public static function loginUrl(): string
    {
        return self::baseUrl() . '/index.php';
    }

    public static function logoutUrl(?string $redirect = null): string
    {
        $url = self::baseUrl() . '/logout.php';
        if ($redirect !== null && $redirect !== '') {
            $url .= '?redirect=' . rawurlencode($redirect);
        }
        return $url;
    }

    public static function portalUrl(): string
    {
        return self::baseUrl() . '/portal.php';
    }

    /**
     * Các dự án được phép nhận redirect, dạng `prefix path => nhãn hiển thị`.
     * Đây là allowlist DUY NHẤT cho `?redirect=`; path nào không khớp prefix nào ở đây đều bị loại.
     * Env `SHARED_AUTH_PROJECTS`: `/nhansuhtdb/|Nhân sự HTĐB,/lichkehoach/public/|Lịch kế hoạch`
     *
     * @return array<string, string>
     */
    public static function projects(): array
    {
        $raw = getenv('SHARED_AUTH_PROJECTS');
        if ($raw === false || trim($raw) === '') {
            return [
                '/lichkehoach/public/' => 'Lịch kế hoạch',
                '/nhansuhtdb/'         => 'Nhân sự HTĐB',
            ];
        }

        $projects = [];
        foreach (explode(',', $raw) as $entry) {
            $parts = explode('|', $entry, 2);
            $prefix = trim($parts[0]);
            if ($prefix === '' || $prefix[0] !== '/') {
                continue;
            }
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $label = trim($parts[1] ?? '');
            $projects[$prefix] = ($label !== '') ? $label : $prefix;
        }
        return $projects;
    }

    /**
     * Host tuyệt đối được phép trong `?redirect=`, ngoài chính host đang phục vụ request.
     * Mặc định rỗng: chỉ chấp nhận redirect cùng host (đúng mô hình shared session cùng host).
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $raw = getenv('SHARED_AUTH_ALLOWED_HOSTS');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $hosts = [];
        foreach (explode(',', $raw) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    public static function projectLabel(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $best = null;
        $bestLength = 0;
        foreach (self::projects() as $prefix => $label) {
            if (str_starts_with($path, $prefix) && strlen($prefix) > $bestLength) {
                $best = $label;
                $bestLength = strlen($prefix);
            }
        }
        return $best;
    }

    public static function sessionSavePath(): ?string
    {
        if (self::isProduction()) {
            return null;
        }
        $dir = dirname(__DIR__) . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return (is_dir($dir) && is_writable($dir)) ? $dir : null;
    }
}
