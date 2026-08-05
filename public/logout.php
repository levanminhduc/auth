<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\RedirectSanitizer;
use Hoatho\SharedAuth\SessionBootstrap;

require __DIR__ . '/../bootstrap.php';

// Xác định dự án gọi logout TRƯỚC khi huỷ phiên, để đăng nhập lại quay đúng về đó.
$redirect = RedirectSanitizer::sanitizeOrNull($_GET['redirect'] ?? null);

SessionBootstrap::destroy();

$location = EnvConfig::loginUrl();
if ($redirect !== null) {
    $location .= '?redirect=' . rawurlencode($redirect);
}

header('Location: ' . $location, true, 302);
exit;
