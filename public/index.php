<?php

declare(strict_types=1);

use Hoatho\SharedAuth\AuthBackendException;
use Hoatho\SharedAuth\CredentialVerifier;
use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\LoginThrottle;
use Hoatho\SharedAuth\RedirectSanitizer;
use Hoatho\SharedAuth\SessionBootstrap;
use Hoatho\SharedAuth\SessionContract;

require __DIR__ . '/../bootstrap.php';

const USERNAME_MAX_CHARS = 255;
const PASSWORD_MAX_BYTES = 1024;
const INVALID_CREDENTIALS_MESSAGE = 'Sai tên đăng nhập hoặc mật khẩu.';
const BACKEND_BUSY_MESSAGE = 'Hệ thống đang bận, vui lòng thử lại.';

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$stringParam = static fn (array $source, string $key): ?string => is_string($source[$key] ?? null) ? $source[$key] : null;

$redirectTarget = RedirectSanitizer::sanitize($stringParam($_GET, 'redirect') ?? $stringParam($_POST, 'redirect'));

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
$reason = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reasonRaw = (string) $stringParam($_GET, 'reason');
    $reason = in_array($reasonRaw, ['expired', 'denied'], true) ? $reasonRaw : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) $stringParam($_POST, 'username'));
    $password = (string) $stringParam($_POST, 'password');
    $token = (string) $stringParam($_POST, '_csrf');
    $usernameValid = mb_check_encoding($username, 'UTF-8') && mb_strlen($username, 'UTF-8') <= USERNAME_MAX_CHARS;
    $oldUsername = $usernameValid ? $username : '';

    if (!hash_equals((string) $_SESSION[SessionContract::CSRF], $token)) {
        $error = 'Phiên nhập liệu đã hết hạn, vui lòng thử lại.';
    } elseif ($username === '' || $password === '') {
        $error = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
        $credentialError = true;
    } elseif (!$usernameValid || strlen($password) > PASSWORD_MAX_BYTES) {
        // Cùng thông báo với sai mật khẩu để không lộ ràng buộc nào bị vi phạm; không chạm throttle/DB.
        $error = INVALID_CREDENTIALS_MESSAGE;
        $credentialError = true;
    } else {
        $conn = require __DIR__ . '/../config/db.php';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $throttle = null;
            $lockedMinutes = null;
            if (!empty($config['throttle']['enabled'])) {
                $throttle = new LoginThrottle($conn, [
                    'table'              => (string) $config['throttle']['table'],
                    'maxAttempts'        => (int) $config['throttle']['max_attempts'],
                    'windowMinutes'      => (int) $config['throttle']['window_minutes'],
                    'lockoutMinutes'     => (int) $config['throttle']['lockout_minutes'],
                    'byIp'               => (bool) $config['throttle']['by_ip'],
                    'ipMaxAttempts'      => (int) $config['throttle']['ip_max_attempts'],
                    'ipWindowMinutes'    => (int) $config['throttle']['ip_window_minutes'],
                    'ipLockoutMinutes'   => (int) $config['throttle']['ip_lockout_minutes'],
                    'userMaxAttempts'    => (int) $config['throttle']['user_max_attempts'],
                    'userWindowMinutes'  => (int) $config['throttle']['user_window_minutes'],
                    'userLockoutMinutes' => (int) $config['throttle']['user_lockout_minutes'],
                ]);
                $lockedMinutes = $throttle->begin($username, $ip);
            }

            if ($lockedMinutes !== null) {
                http_response_code(429);
                $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$lockedMinutes} phút.";
            } else {
                $verifier = new CredentialVerifier($conn, ['upgradeOnLogin' => EnvConfig::upgradeOnLogin()]);
                $user = $verifier->verify($username, $password);

                if ($user === null) {
                    $error = INVALID_CREDENTIALS_MESSAGE;
                    $credentialError = true;
                } else {
                    if ($throttle !== null) {
                        $throttle->succeed($username, $ip);
                    }
                    session_regenerate_id(true);
                    SessionBootstrap::renewCookie();
                    // WHY: phiên cũ "có dữ liệu nhưng chưa đăng nhập" (vd. quá trần tuyệt đối) có thể còn khoá riêng của dự án; không để user mới thừa kế.
                    $_SESSION = [];
                    SessionContract::set($user);

                    header('Location: ' . $redirectTarget, true, 302);
                    exit;
                }
            }
        } catch (AuthBackendException | \InvalidArgumentException $e) {
            $throttle?->abort();
            http_response_code(503);
            $error = BACKEND_BUSY_MESSAGE;
        } catch (\Throwable $e) {
            error_log('login: ' . get_class($e) . ' ' . json_encode(substr($e->getMessage(), 0, 300), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
            $throttle?->abort();
            http_response_code(503);
            $error = BACKEND_BUSY_MESSAGE;
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
    'credentialError' => $credentialError,
];
require __DIR__ . '/../views/login.php';
