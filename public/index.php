<?php

declare(strict_types=1);

use Hoatho\SharedAuth\CredentialVerifier;
use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\RedirectSanitizer;
use Hoatho\SharedAuth\SessionBootstrap;
use Hoatho\SharedAuth\SessionContract;

require __DIR__ . '/../bootstrap.php';

$redirectTarget = RedirectSanitizer::sanitize($_GET['redirect'] ?? ($_POST['redirect'] ?? null));

if (SessionContract::isLoggedIn()) {
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['_csrf'], $token)) {
        $error = 'Phiên nhập liệu đã hết hạn, vui lòng thử lại.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
        } else {
            $conn = require __DIR__ . '/../config/db.php';
            $verifier = new CredentialVerifier($conn, ['upgradeOnLogin' => EnvConfig::upgradeOnLogin()]);
            $user = $verifier->verify($username, $password);

            if ($user === null) {
                $error = 'Sai tên đăng nhập hoặc mật khẩu.';
            } else {
                session_regenerate_id(true);
                SessionBootstrap::renewCookie();
                unset($_SESSION['_csrf']);
                SessionContract::set($user);

                header('Location: ' . $redirectTarget, true, 302);
                exit;
            }
        }
    }
}

$csrf = (string) $_SESSION['_csrf'];
$portalUrl = EnvConfig::portalUrl();
require __DIR__ . '/../views/login.php';
