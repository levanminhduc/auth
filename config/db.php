<?php

declare(strict_types=1);

$configFile = 'C:/xampp/config/db.php';
$cfg = is_file($configFile) ? require $configFile : [];

$conn = mysqli_connect(
    $cfg['host']     ?? 'localhost',
    $cfg['username'] ?? 'root',
    $cfg['password'] ?? '',
    $cfg['database'] ?? 'mysqli'
);

if (!$conn) {
    http_response_code(500);
    error_log('authV2/config/db.php: không kết nối được DB: ' . mysqli_connect_error());
    exit('Lỗi kết nối cơ sở dữ liệu.');
}

mysqli_set_charset($conn, 'utf8mb4');

return $conn;
