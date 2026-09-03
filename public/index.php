<?php

declare(strict_types=1);

use Hoatho\SharedAuth\CredentialVerifier;
use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\LoginThrottle;
use Hoatho\SharedAuth\RedirectSanitizer;
use Hoatho\SharedAuth\SessionBootstrap;
use Hoatho\SharedAuth\SessionContract;

require __DIR__ . '/../bootstrap.php';

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$redirectTarget = RedirectSanitizer::sanitize($_GET['redirect'] ?? ($_POST['redirect'] ?? null));

if (SessionContract::isLoggedIn()) {
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

if (empty($_SESSION[SessionContract::CSRF])) {
    $_SESSION[SessionContract::CSRF] = bin2hex(random_bytes(32));
}

$config = require __DIR__ . '/../config/login_config.php';
$error = null;
$oldUsername = '';
$credentialError = false;
$lockedMinutes = null;
$reasonRaw = (string) ($_GET['reason'] ?? '');
$reason = in_array($reasonRaw, ['expired', 'denied'], true) ? $reasonRaw : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldUsername = trim((string) ($_POST['username'] ?? ''));
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) $_SESSION[SessionContract::CSRF], $token)) {
        $error = 'Phiên nhập liệu đã hết hạn, vui lòng thử lại.';
    } else {
        $username = $oldUsername;
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
            $credentialError = true;
        } else {
            $conn = require __DIR__ . '/../config/db.php';
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $throttle = null;
            if (!empty($config['throttle']['enabled'])) {
                $throttle = new LoginThrottle($conn, [
                    'table'          => (string) $config['throttle']['table'],
                    'maxAttempts'    => (int) $config['throttle']['max_attempts'],
                    'windowMinutes'  => (int) $config['throttle']['window_minutes'],
                    'lockoutMinutes' => (int) $config['throttle']['lockout_minutes'],
                    'byIp'           => (bool) $config['throttle']['by_ip'],
                ]);
                $lockedMinutes = $throttle->lockedMinutes($username, $ip);
                if ($lockedMinutes !== null) {
                    http_response_code(429);
                    $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$lockedMinutes} phút.";
                }
            }

            if ($error === null) {
                $verifier = new CredentialVerifier($conn, ['upgradeOnLogin' => EnvConfig::upgradeOnLogin()]);
                $user = $verifier->verify($username, $password);

                if ($user === null) {
                    $error = 'Sai tên đăng nhập hoặc mật khẩu.';
                    $credentialError = true;
                    if ($throttle !== null) {
                        $throttle->recordFailure($username, $ip);
                    }
                } else {
                    if ($throttle !== null) {
                        $throttle->clear($username);
                    }
                    session_regenerate_id(true);
                    SessionBootstrap::renewCookie();
                    unset($_SESSION[SessionContract::CSRF]);
                    SessionContract::set($user);

                    header('Location: ' . $redirectTarget, true, 302);
                    exit;
                }
            }
        }
    }
}

$viewData = [
    'config'          => $config,
    'error'           => $error,
    'csrf'            => (string) $_SESSION[SessionContract::CSRF],
    'redirectTarget'  => $redirectTarget,
    'originLabel'     => EnvConfig::projectLabel($redirectTarget),
    'reason'          => $reason,
    'oldUsername'     => $oldUsername,
    'lockedMinutes'   => $lockedMinutes,
    'credentialError' => $credentialError,
];
require __DIR__ . '/../views/login.php';
