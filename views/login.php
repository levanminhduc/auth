<?php

declare(strict_types=1);

/**
 * $viewData keys:
 * - config (array)
 * - error (?string)
 * - csrf (string)
 * - redirectTarget (string)
 * - originLabel (?string)
 * - reason (?string)
 * - oldUsername (string)
 * - lockedMinutes (?int)
 * - credentialError (bool)
 */

require __DIR__ . '/partials/icon.php';

$e = static fn (?string $v): string => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

$config          = $viewData['config'];
$error           = $viewData['error'];
$csrf            = $viewData['csrf'];
$redirectTarget  = $viewData['redirectTarget'];
$originLabel     = $viewData['originLabel'];
$reason          = $viewData['reason'];
$oldUsername     = $viewData['oldUsername'];
$credentialError = (bool) $viewData['credentialError'];

$usernameAutofocus = $oldUsername === '' && $error === null;
$passwordAutofocus = $oldUsername !== '' || $error !== null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($config['app']['name']) ?> - Đăng nhập</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-white sm:bg-linear-to-br sm:from-brand-500 sm:to-accent text-ink-700 font-sans">

<main class="login-shell">
    <section class="w-full max-w-[400px] bg-white flex flex-col min-h-dvh sm:min-h-0 sm:rounded-card sm:shadow-card">

        <header class="px-6 pt-6 pb-3 flex flex-col items-center text-center">
            <img src="<?= $e($config['app']['logo_path']) ?>"
                 alt="<?= $e($config['app']['logo_alt']) ?>"
                 width="<?= (int) $config['app']['logo_width'] ?>"
                 height="<?= (int) $config['app']['logo_height'] ?>">
            <h1 class="text-[20px] sm:text-[22px] font-semibold text-ink-900"><?= $e($config['app']['name']) ?></h1>
        </header>

        <div class="p-6 flex flex-col gap-5">
            <?php if ($error !== null && $error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?= icon('alert-triangle', 'w-4 h-4') ?>
                <span><?= $e($error) ?></span>
            </div>
            <?php endif; ?>

            <?php
            $statusHtml = null;
            $statusClass = 'alert alert-info';
            if ($reason === 'expired' && $originLabel !== null) {
                $statusClass = 'alert alert-warn';
                $statusHtml = 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại để tiếp tục vào <strong>' . $e($originLabel) . '</strong>.';
            } elseif ($reason === 'expired') {
                $statusClass = 'alert alert-warn';
                $statusHtml = 'Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại.';
            } elseif ($reason === 'denied') {
                $statusClass = 'alert alert-warn';
                $statusHtml = 'Bạn cần đăng nhập để truy cập trang này.';
            } elseif ($reason === null && $originLabel !== null) {
                $statusHtml = 'Đăng nhập để tiếp tục vào <strong>' . $e($originLabel) . '</strong>';
            }
            if ($statusHtml !== null):
            ?>
            <div class="<?= $statusClass ?>" role="status">
                <?php if ($reason === 'expired'): ?>
                    <?= icon('alert-triangle', 'w-4 h-4') ?>
                <?php endif; ?>
                <span><?= $statusHtml ?></span>
            </div>
            <?php endif; ?>

            <form method="post" action="" id="loginForm" data-remember-days="<?= (int) $config['ui']['remember_days'] ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="redirect" value="<?= $e($redirectTarget) ?>">

                <div class="flex flex-col gap-5">
                    <div class="flex flex-col gap-1">
                        <label for="username" class="text-[14px] font-medium flex items-center gap-2">
                            <?= icon('user', 'w-4 h-4') ?>
                            Tên đăng nhập
                            <?php if ($credentialError): ?>
                                <?= icon('alert-triangle', 'w-4 h-4 text-danger-ink') ?>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="username" name="username" autocomplete="username" required class="field-input" value="<?= $e($oldUsername) ?>"<?php if ($usernameAutofocus): ?> autofocus<?php endif; ?><?php if ($credentialError): ?> aria-invalid="true"<?php endif; ?>>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="password" class="text-[14px] font-medium flex items-center gap-2">
                            <?= icon('lock', 'w-4 h-4') ?>
                            Mật khẩu
                            <?php if ($credentialError): ?>
                                <?= icon('alert-triangle', 'w-4 h-4 text-danger-ink') ?>
                            <?php endif; ?>
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password" autocomplete="current-password" required class="field-input pr-11"<?php if ($passwordAutofocus): ?> autofocus<?php endif; ?><?php if ($credentialError): ?> aria-invalid="true"<?php endif; ?>>
                            <template id="togglePasswordTemplate">
                                <button type="button" class="absolute right-0 top-1/2 -translate-y-1/2 w-11 h-11 flex items-center justify-center text-ink-500 hover:text-ink-700 rounded-field focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand-500/25" aria-label="Hiện mật khẩu" aria-pressed="false" data-label-show="Hiện mật khẩu" data-label-hide="Ẩn mật khẩu">
                                    <?= icon('eye', 'w-5 h-5') ?>
                                    <?= icon('eye-off', 'w-5 h-5 hidden') ?>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <?php if ($config['ui']['show_remember_me']): ?>
                        <div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="remember">
                                <label for="remember" class="text-[14px]">Ghi nhớ tên đăng nhập</label>
                            </div>
                            <p id="rememberHint" class="text-[13px] text-ink-500 hidden">Chỉ lưu tên đăng nhập trên máy này.</p>
                        </div>
                        <?php endif; ?>

                        <?php if ($config['ui']['show_forgot_password'] && $config['ui']['forgot_password_url'] !== ''): ?>
                        <a href="<?= $e($config['ui']['forgot_password_url']) ?>" class="text-brand-700 focus-visible:underline focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand-700/40 rounded-field">Quên mật khẩu?</a>
                        <?php endif; ?>
                    </div>

                    <div aria-live="polite">
                        <button type="submit" id="loginBtn" class="btn-primary w-full" aria-busy="false"><span data-label>Đăng nhập</span></button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($config['ui']['show_copyright']): ?>
        <footer class="bg-slate-50 text-ink-500 text-[13px] text-center mt-auto p-3.5">© <?= date('Y') ?> – <?= $e($config['app']['name']) ?> – <?= $e($config['app']['company']) ?></footer>
        <?php endif; ?>

    </section>
</main>

<script src="assets/js/login.js" defer></script>
</body>
</html>
