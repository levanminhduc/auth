<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class Guard
{
    private array $publicPaths;

    public function __construct(array $publicPaths = [])
    {
        $this->publicPaths = $publicPaths;
    }

    public function requireLogin(): void
    {
        if ($this->isPublic($this->currentPath()) || SessionContract::isLoggedIn()) {
            return;
        }

        $location = $this->loginLocation();
        if (!headers_sent()) {
            header('Location: ' . $location, true, 302);
        }
        exit;
    }

    public function loginLocation(): string
    {
        $location = EnvConfig::loginUrl() . '?redirect=' . rawurlencode($this->currentUri());
        // WHY: cookie phiên còn + session đã start nhưng rỗng = PHP vừa cấp phiên mới sau khi file cũ bị GC (hết hạn), không phải khách lần đầu (không cookie) hay khách chỉ có CSRF (session không rỗng).
        if (
            isset($_COOKIE[EnvConfig::sessionName()])
            && session_status() === PHP_SESSION_ACTIVE
            && $_SESSION === []
        ) {
            $location .= '&reason=expired';
        }
        return $location;
    }

    public function isPublic(string $path): bool
    {
        foreach ($this->publicPaths as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_ends_with($entry, '/')) {
                if (str_starts_with($path, $entry)) {
                    return true;
                }
            } elseif ($path === $entry) {
                return true;
            }
        }
        return false;
    }

    private function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    private function currentUri(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? EnvConfig::portalUrl());
    }
}
