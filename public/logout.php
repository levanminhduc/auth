<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\RedirectSanitizer;
use Hoatho\SharedAuth\SessionBootstrap;

require __DIR__ . '/../bootstrap.php';

// WHY: logout là GET không có CSRF token nên phải chặn đăng xuất do trang/thẻ ngoài kích hoạt.
// Sec-Fetch-* do trình duyệt đặt, JS không giả được; Origin/Referer chỉ là phương án dự phòng.
// Chỉ chấp nhận same-origin và none (gõ URL/bookmark): same-site gồm cả trang con khác trong cùng domain.
$isForeignRequest = static function (): bool {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null;
    if ($site !== null || $dest !== null) {
        return ($site !== null && !in_array($site, ['same-origin', 'none'], true))
            || ($dest !== null && $dest !== 'document');
    }

    $ownScheme = EnvConfig::isSecure() ? 'https' : 'http';
    $ownAuthority = parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($ownAuthority === false || !isset($ownAuthority['host'])) {
        return true;
    }
    $defaultPort = static fn (string $scheme): int => $scheme === 'https' ? 443 : 80;

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = $_SERVER[$key] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return true;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme !== $ownScheme
            || strtolower($parts['host']) !== strtolower($ownAuthority['host'])
            || ($parts['port'] ?? $defaultPort($scheme)) !== ($ownAuthority['port'] ?? $defaultPort($ownScheme));
    }
    return false;
};

// Xác định dự án gọi logout TRƯỚC khi huỷ phiên, để đăng nhập lại quay đúng về đó.
$redirect = RedirectSanitizer::sanitizeOrNull(is_string($_GET['redirect'] ?? null) ? $_GET['redirect'] : null);

$foreign = $isForeignRequest();
if (!$foreign) {
    SessionBootstrap::destroy();
}

$location = EnvConfig::loginUrl();
if ($redirect !== null) {
    $location .= '?redirect=' . rawurlencode($redirect);
}

header('Location: ' . $location, true, $foreign ? 303 : 302);
exit;
