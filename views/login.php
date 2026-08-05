<?php

declare(strict_types=1);

$loginConfig = require __DIR__ . '/../config/login_config.php';

$e = static fn (?string $value): string => htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');

$cspHeader = '';
if ($loginConfig['csp']['enabled']) {
    $cspParts = [];
    foreach ($loginConfig['csp']['directives'] as $directive => $value) {
        $cspParts[] = $directive . ' ' . $value;
    }
    $cspHeader = implode('; ', $cspParts);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($loginConfig['app']['name']) ?> - Đăng nhập</title>

    <?php if ($cspHeader !== ''): ?>
    <meta http-equiv="Content-Security-Policy" content="<?= $e($cspHeader) ?>">
    <?php endif; ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-box">

        <div class="login-header text-center">
            <div class="hoatho-logo">
                <img src="<?= $e($loginConfig['app']['logo_path']) ?>"
                     alt="<?= $e($loginConfig['app']['logo_alt']) ?>"
                     width="<?= (int) $loginConfig['app']['logo_width'] ?>"
                     height="<?= (int) $loginConfig['app']['logo_height'] ?>">
            </div>
            <h1 class="login-title"><?= $e($loginConfig['app']['name']) ?></h1>
        </div>

        <div class="login-body">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert"
                     data-dismiss-seconds="<?= (int) $loginConfig['ui']['alert_auto_dismiss_seconds'] ?>">
                    <?= $e($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($originLabel)): ?>
                <div class="login-origin" role="status">
                    <i class="fas fa-arrow-right-to-bracket me-2"></i>Đăng nhập để tiếp tục vào <strong><?= $e($originLabel) ?></strong>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="loginForm">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="redirect" value="<?= $e($redirectTarget) ?>">

                <div class="form-group mb-3">
                    <label for="username"><i class="fas fa-user me-2"></i>Tên đăng nhập</label>
                    <input type="text"
                           class="form-control"
                           id="username"
                           name="username"
                           autocomplete="username"
                           autofocus
                           required>
                </div>

                <div class="form-group mb-3">
                    <label for="password"><i class="fas fa-lock me-2"></i>Mật khẩu</label>
                    <div class="input-group">
                        <input type="password"
                               class="form-control"
                               id="password"
                               name="password"
                               autocomplete="current-password"
                               required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Hiện/ẩn mật khẩu">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="login-options">
                    <?php if ($loginConfig['ui']['show_remember_me']): ?>
                    <div class="remember-me">
                        <input type="checkbox" id="remember">
                        <label for="remember">Ghi nhớ đăng nhập</label>
                    </div>
                    <?php endif; ?>

                    <?php if ($loginConfig['ui']['show_forgot_password']): ?>
                    <div class="forgot-password">
                        <a href="#" tabindex="-1">Quên mật khẩu?</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="login-button">
                    <button type="submit" id="loginBtn" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt me-2"></i>Đăng nhập
                    </button>
                </div>
            </form>
        </div>

        <?php if ($loginConfig['ui']['show_copyright']): ?>
        <footer class="login-footer text-center">
            <p>© <?= date('Y') ?> - <?= $e($loginConfig['app']['name']) ?> - <?= $e($loginConfig['app']['company']) ?></p>
        </footer>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/login.js"></script>

</body>
</html>
