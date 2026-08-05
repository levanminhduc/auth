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

    public static function logoutUrl(): string
    {
        return self::baseUrl() . '/logout.php';
    }

    public static function portalUrl(): string
    {
        return self::baseUrl() . '/portal.php';
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
