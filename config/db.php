<?php

declare(strict_types=1);

$dbFail = static function (string $logMessage): never {
    http_response_code(500);
    error_log('authV2/config/db.php: ' . $logMessage);
    exit('Lỗi kết nối cơ sở dữ liệu.');
};

$configFile = getenv('SHARED_AUTH_DB_CONFIG') ?: 'C:/xampp/config/db.php';
if (!is_file($configFile) || !is_readable($configFile)) {
    $dbFail('không đọc được file cấu hình DB (' . $configFile . '); đặt env SHARED_AUTH_DB_CONFIG');
}

try {
    $cfg = require $configFile;
} catch (\Throwable $e) {
    $dbFail('file cấu hình DB lỗi: ' . $e->getMessage());
}

if (!is_array($cfg)
    || !is_string($cfg['host'] ?? null) || $cfg['host'] === ''
    || !is_string($cfg['username'] ?? null) || $cfg['username'] === ''
    || !is_string($cfg['password'] ?? null)
    || !is_string($cfg['database'] ?? null) || $cfg['database'] === ''
) {
    $dbFail('file cấu hình DB thiếu khóa host/username/password/database');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database']);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    $dbFail('không kết nối được DB: ' . $e->getMessage());
}

unset($cfg, $configFile, $dbFail, $e);

return $conn;
