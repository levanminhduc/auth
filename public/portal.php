<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\Guard;
use Hoatho\SharedAuth\SessionContract;

require __DIR__ . '/../bootstrap.php';

(new Guard())->requireLogin();

$name = SessionContract::name() ?? '';

$projects = [
    ['label' => 'Lịch kế hoạch',   'url' => '/lichkehoach/public/'],
    ['label' => 'Nhân sự HTĐB',    'url' => '/nhansuhtdb/'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cổng đăng nhập chung</title>
    <style>
        body { font-family: system-ui, Segoe UI, Arial, sans-serif; background: #f5f6f8; margin: 0; color: #1f2933; }
        .wrap { max-width: 560px; margin: 8vh auto; padding: 0 20px; }
        .card { background: #fff; border-radius: 12px; padding: 28px 32px; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #7b8794; margin: 0 0 20px; font-size: 14px; }
        ul { list-style: none; padding: 0; margin: 0; }
        li a { display: block; padding: 14px 16px; border: 1px solid #e4e7eb; border-radius: 8px; margin-bottom: 10px;
               text-decoration: none; color: #2563eb; font-weight: 600; }
        li a:hover { background: #f0f5ff; border-color: #b6d0ff; }
        .logout { display: inline-block; margin-top: 8px; color: #9aa5b1; font-size: 13px; text-decoration: none; }
        .logout:hover { color: #e12d39; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Xin chào<?= $name !== '' ? ', ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '' ?></h1>
            <p class="sub">Chọn hệ thống để tiếp tục — bạn đã đăng nhập một lần cho tất cả.</p>
            <ul>
                <?php foreach ($projects as $p): ?>
                    <li><a href="<?= htmlspecialchars($p['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?></a></li>
                <?php endforeach; ?>
            </ul>
            <a class="logout" href="<?= htmlspecialchars(EnvConfig::logoutUrl(), ENT_QUOTES, 'UTF-8') ?>">Đăng xuất</a>
        </div>
    </div>
</body>
</html>
