<?php

declare(strict_types=1);

return [
    'app' => [
        'name'        => 'HỆ THỐNG QUẢN LÝ NHÂN SỰ',
        'company'     => 'CÔNG TY MAY HOÀ THỌ ĐIỆN BÀN',
        'logo_path'   => 'img/logoht.png',
        'logo_width'  => 88,
        'logo_height' => 81,
        'logo_alt'    => 'Logo Hoà Thọ',
    ],
    'ui' => [
        'show_forgot_password' => false,
        'forgot_password_url'  => '',
        'show_remember_me'     => true,
        'show_copyright'       => true,
        'remember_days'        => 30,
    ],
    'throttle' => [
        'enabled'         => true,
        'max_attempts'    => 5,
        'window_minutes'  => 15,
        'lockout_minutes' => 15,
        'by_ip'           => true,
        'table'           => getenv('AUTHV2_THROTTLE_TABLE') ?: 'auth_login_attempts',
    ],
];
