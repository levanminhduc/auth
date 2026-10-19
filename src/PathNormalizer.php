<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

final class PathNormalizer
{
    /**
     * Giải mã đúng MỘT lượt, như web server. Không lặp: mỗi lượt giải mã thêm có thể biến một segment
     * thành nhiều (`api%252fx` → `api/x`) và làm `..` phía sau rút nhầm segment, khiến path khác hẳn path
     * server phục vụ. `%2f`/`%5c` sau giải mã là dấu phân cách; ai cần chắc chắn path không mơ hồ thì
     * kiểm `isAmbiguous()` trên path thô trước.
     */
    public static function normalize(string $path): string
    {
        $decoded = str_replace('\\', '/', rawurldecode($path));

        $segments = [];
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if ($normalized !== '/' && str_ends_with($decoded, '/')) {
            $normalized .= '/';
        }
        return $normalized;
    }

    /**
     * Path thô chứa `\`, `%2f`, `%5c` hoặc `%25` thì độ sâu thực tế phụ thuộc cấu hình server
     * (AllowEncodedSlashes, NoDecode, proxy giải mã thêm lần nữa) nên không thể rút gọn một cách chắc chắn.
     */
    public static function isAmbiguous(string $path): bool
    {
        return str_contains($path, '\\')
            || preg_match('/%(?:2f|5c|25)/i', $path) === 1;
    }
}
