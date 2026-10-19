<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class EnvConfig
{
    public const SESSION_LIFETIME = 30 * 24 * 60 * 60;

    public const ABSOLUTE_LIFETIME_DEFAULT = 30 * 24 * 60 * 60;

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
                '/sangkienct/'         => 'Sáng kiến cải tiến',
                '/khsanxuat/'          => 'Kế hoạch sản xuất',
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

    /**
     * Không bao giờ trả null để rơi về `session.save_path` của php.ini: thư mục đó dùng chung với mọi
     * vhost khác, GC của app khác sẽ xoá phiên SSO. Cấu hình sai thì dừng, không chạy tiếp.
     */
    public static function sessionSavePath(): string
    {
        $configured = getenv('SHARED_AUTH_SESSION_PATH');
        if ($configured !== false && trim($configured) !== '') {
            $dir = trim($configured);
        } elseif (self::isProduction()) {
            throw new \RuntimeException(
                'Thiếu SHARED_AUTH_SESSION_PATH: production bắt buộc có thư mục lưu phiên riêng, nằm ngoài webroot.'
            );
        } else {
            $dir = self::localDefaultSessionPath();
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                'Thư mục lưu phiên không tồn tại hoặc không ghi được (SHARED_AUTH_SESSION_PATH): ' . $dir
            );
        }
        return $dir;
    }

    // WHY: get_cfg_var đọc giá trị php.ini gốc, không bị session_save_path() lúc chạy ghi đè, và
    // không phụ thuộc biến môi trường TEMP (tiến trình thiếu TEMP thì sys_get_temp_dir() = C:\Windows).
    private static function localDefaultSessionPath(): string
    {
        $base = (string) get_cfg_var('session.save_path');
        $pos = strrpos($base, ';');
        if ($pos !== false) {
            $base = substr($base, $pos + 1);
        }
        $base = trim($base);
        if ($base === '') {
            $base = sys_get_temp_dir();
        }
        return rtrim($base, '/\\') . '/htsso-sessions';
    }

    public static function absoluteLifetime(): int
    {
        $v = getenv('SHARED_AUTH_ABSOLUTE_LIFETIME');
        if ($v === false) {
            return self::ABSOLUTE_LIFETIME_DEFAULT;
        }
        $v = trim($v);
        return ctype_digit($v) ? (int) $v : self::ABSOLUTE_LIFETIME_DEFAULT;
    }
}
