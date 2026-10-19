<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class SessionContract
{
    public const USER_ID      = 'user_id';
    public const NAME         = 'name';
    public const LOGGED_IN_AT = 'logged_in_at';
    public const CSRF         = 'shared_auth_csrf';

    private static bool $capExpired = false;

    public static function set(array $user): void
    {
        self::$capExpired = false;
        $_SESSION[self::USER_ID]      = (int) ($user['id'] ?? 0);
        $_SESSION[self::NAME]         = (string) ($user['name'] ?? '');
        $_SESSION[self::LOGGED_IN_AT] = time();
    }

    public static function userId(): ?int
    {
        self::enforceAbsoluteLifetime();
        $id = $_SESSION[self::USER_ID] ?? null;
        return ($id !== null && (int) $id > 0) ? (int) $id : null;
    }

    public static function name(): ?string
    {
        self::enforceAbsoluteLifetime();
        return isset($_SESSION[self::NAME]) ? (string) $_SESSION[self::NAME] : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null && isset($_SESSION[self::LOGGED_IN_AT]);
    }

    public static function enforceAbsoluteLifetime(): bool
    {
        $id = $_SESSION[self::USER_ID] ?? null;
        if ($id === null || (int) $id <= 0 || !isset($_SESSION[self::LOGGED_IN_AT])) {
            return false;
        }

        $cap = EnvConfig::absoluteLifetime();
        if ($cap === 0) {
            return false;
        }

        $at = $_SESSION[self::LOGGED_IN_AT];
        // WHY: dấu thời gian hỏng/không đọc được thì không có cơ sở để tin phiên còn trong trần, nên coi như quá trần.
        if (is_int($at) && $at > 0 && (time() - $at) <= $cap) {
            return false;
        }

        // WHY: xoá cả $_SESSION, không chỉ clear(): các dự án tự kiểm tra thẳng `isset($_SESSION['user_id'])` và còn giữ key riêng (vd nhan_vien_ma) — để sót thì phiên quá trần vẫn dùng được và key đó lọt sang lần đăng nhập sau.
        $_SESSION = [];
        self::$capExpired = true;
        return true;
    }

    public static function wasCapExpired(): bool
    {
        return self::$capExpired;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::USER_ID], $_SESSION[self::NAME], $_SESSION[self::LOGGED_IN_AT], $_SESSION[self::CSRF]);
    }
}
