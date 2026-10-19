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
        if ($this->isPublic($this->currentPath())) {
            return;
        }

        // WHY: ở chế độ prepend (configure() không mở phiên, cũng không gia hạn cookie) phải tự mở/gia hạn ở đây, nếu không cookie không bao giờ trượt và requireLogin đọc phiên chưa start.
        if (session_status() === PHP_SESSION_NONE) {
            // WHY: header đã gửi thì session_start() không mở được (chỉ in warning, trang trắng); không có phiên để xác thực nên chặn request thay vì chạy tiếp.
            if (headers_sent($file, $line)) {
                error_log(sprintf('[shared-auth] Guard: header đã gửi tại %s:%d trước khi mở phiên, chặn request.', $file, $line));
                exit;
            }
            SessionBootstrap::start();
        } else {
            SessionBootstrap::renewCookie();
        }

        if (SessionContract::isLoggedIn()) {
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
        if ($this->sessionExpired()) {
            $location .= '&reason=expired';
        }
        return $location;
    }

    public function isPublic(string $path): bool
    {
        // WHY: so khớp trên path đã giải mã + rút gọn, đúng như web server sẽ phục vụ; so path thô thì `/api/../admin.php` lọt allowlist. Path mơ hồ (`\`, %2f, %5c, %25) thì không public, vì độ sâu thật của nó phụ thuộc server. Hạ chữ thường vì filesystem Windows không phân biệt hoa/thường.
        if (PathNormalizer::isAmbiguous($path)) {
            return false;
        }
        $path = strtolower(PathNormalizer::normalize($path));
        foreach ($this->publicPaths as $entry) {
            if ($entry === '') {
                continue;
            }
            $entry = strtolower($entry);
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

    private function sessionExpired(): bool
    {
        if (SessionContract::wasCapExpired()) {
            return true;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        // WHY: use_strict_mode=1 nên cookie mang id PHP không còn biết (file bị GC/xoá) bị bỏ và cấp id mới; cookie khác session_id() là dấu hiệu hết hạn đáng tin. Cookie trùng id mà phiên rỗng chỉ là khách chưa có gì.
        $cookie = $_COOKIE[EnvConfig::sessionName()] ?? null;
        return is_string($cookie) && $cookie !== '' && $cookie !== session_id();
    }

    private function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        // WHY: không dùng parse_url — `//host/x` bị hiểu là authority và làm rơi segment đầu của path.
        $end = strcspn($uri, '?#');
        return substr($uri, 0, $end);
    }

    private function currentUri(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? EnvConfig::portalUrl());
    }
}
