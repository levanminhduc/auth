<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class RedirectSanitizer
{
    public static function sanitize(?string $target, ?string $fallback = null): string
    {
        $fallback = $fallback ?? EnvConfig::portalUrl();

        if ($target === null || $target === '') {
            return $fallback;
        }

        $target = trim($target);

        if (preg_match('#[\\\\\x00-\x1f\x7f]#', $target)) {
            return $fallback;
        }

        if ($target[0] !== '/' || (isset($target[1]) && $target[1] === '/')) {
            return $fallback;
        }

        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        return $target;
    }
}
