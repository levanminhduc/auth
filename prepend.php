<?php

declare(strict_types=1);

try {
    require_once __DIR__ . '/autoload.php';
    \Hoatho\SharedAuth\SessionBootstrap::configure();
} catch (\Throwable $e) {
    error_log('[authV2 prepend] ' . $e->getMessage());
    // WHY: nuốt lỗi ở đây thì session_start() của dự án chạy tiếp với save_path chung của php.ini (phiên SSO bị GC của app khác xoá, lẫn phiên giữa các app). Request web phải dừng; CLI (cron/artisan) không dùng phiên nên chạy tiếp.
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        http_response_code(500);
        exit;
    }
}
