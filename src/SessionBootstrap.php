<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use SessionHandlerInterface;

final class SessionBootstrap
{
    private static ?string $renewedFor = null;

    public static function start(?SessionHandlerInterface $handler = null): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        self::applySettings($handler);
        session_start();
        SessionContract::enforceAbsoluteLifetime();
        self::renewCookie();
    }

    public static function configure(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        self::applySettings(null);
    }

    private static function applySettings(?SessionHandlerInterface $handler): void
    {
        ini_set('session.gc_maxlifetime', (string) EnvConfig::SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');

        if ($handler instanceof SessionHandlerInterface) {
            session_set_save_handler($handler, true);
        } else {
            session_save_path(EnvConfig::sessionSavePath());
        }

        session_name(EnvConfig::sessionName());
        session_set_cookie_params([
            'lifetime' => EnvConfig::SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => EnvConfig::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function renewCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        // WHY: start() đã gia hạn rồi Guard gọi lại trong cùng request sẽ gửi Set-Cookie trùng; đổi session id (login) thì phải gia hạn lại.
        $id = session_id();
        if ($id === self::$renewedFor) {
            return;
        }
        self::$renewedFor = $id;
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + EnvConfig::SESSION_LIFETIME,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }
}
