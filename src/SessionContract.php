<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class SessionContract
{
    public const USER_ID      = 'user_id';
    public const NAME         = 'name';
    public const LOGGED_IN_AT = 'logged_in_at';

    public static function set(array $user): void
    {
        $_SESSION[self::USER_ID]      = (int) ($user['id'] ?? 0);
        $_SESSION[self::NAME]         = (string) ($user['name'] ?? '');
        $_SESSION[self::LOGGED_IN_AT] = time();
    }

    public static function userId(): ?int
    {
        $id = $_SESSION[self::USER_ID] ?? null;
        return ($id !== null && (int) $id > 0) ? (int) $id : null;
    }

    public static function name(): ?string
    {
        return isset($_SESSION[self::NAME]) ? (string) $_SESSION[self::NAME] : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null && isset($_SESSION[self::LOGGED_IN_AT]);
    }

    public static function clear(): void
    {
        unset($_SESSION[self::USER_ID], $_SESSION[self::NAME], $_SESSION[self::LOGGED_IN_AT]);
    }
}
