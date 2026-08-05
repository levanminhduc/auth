<?php

declare(strict_types=1);

try {
    require_once __DIR__ . '/autoload.php';
    \Hoatho\SharedAuth\SessionBootstrap::configure();
} catch (\Throwable $e) {
    error_log('[authV2 prepend] ' . $e->getMessage());
}
