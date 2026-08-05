<?php

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập</title>
    <style>
        body { font-family: system-ui, Segoe UI, Arial, sans-serif; background: #f5f6f8; margin: 0; color: #1f2933; }
        .wrap { max-width: 380px; margin: 12vh auto; padding: 0 20px; }
        .card { background: #fff; border-radius: 12px; padding: 30px 30px 26px; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
        h1 { font-size: 20px; margin: 0 0 22px; text-align: center; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; color: #52606d; }
        input[type=text], input[type=password] {
            width: 100%; box-sizing: border-box; padding: 11px 12px; margin-bottom: 16px;
            border: 1px solid #cbd2d9; border-radius: 8px; font-size: 15px;
        }
        input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
        button { width: 100%; padding: 12px; border: 0; border-radius: 8px; background: #2563eb; color: #fff;
                 font-size: 15px; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .error { background: #ffe3e3; color: #c92a2a; padding: 10px 12px; border-radius: 8px;
                 font-size: 14px; margin-bottom: 18px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Đăng nhập hệ thống</h1>
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8') ?>">
                <label for="username">Tên đăng nhập</label>
                <input type="text" id="username" name="username" autofocus autocomplete="username" required>
                <label for="password">Mật khẩu</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="submit">Đăng nhập</button>
            </form>
        </div>
    </div>
</body>
</html>
