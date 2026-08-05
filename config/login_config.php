<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'HỆ THỐNG QUẢN LÝ NHÂN SỰ',
        'company' => 'CÔNG TY MAY HOÀ THỌ ĐIỆN BÀN',
        'logo_path' => 'img/logoht.png',
        'logo_width' => 100,
        'logo_height' => 100,
        'logo_alt' => 'Hoa Tho Logo',
    ],

    'csp' => [
        'enabled' => true,
        'directives' => [
            'default-src' => "'self'",
            'script-src' => "'self' https://cdn.jsdelivr.net",
            'style-src' => "'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            'img-src' => "'self' data:",
            'font-src' => "'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
        ],
    ],

    'ui' => [
        'show_forgot_password' => true,
        'show_remember_me' => true,
        'alert_auto_dismiss_seconds' => 5,
        'show_copyright' => true,
    ],
];
