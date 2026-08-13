<?php

declare(strict_types=1);

use Hoatho\SharedAuth\Guard;

/**
 * Chốt hành vi HIỆN TẠI của Guard::isPublic (golden baseline).
 * `requireLogin()` gọi header()+exit nên được phủ ở tests/http/, không phủ ở đây.
 */

test('isPublic: danh sách rỗng thì không có gì là public', function (): void {
    $guard = new Guard();
    assert_false($guard->isPublic('/nhansuhtdb/index.php'));
    assert_false($guard->isPublic('/'));
});

test('isPublic: entry không kết thúc bằng / là khớp tuyệt đối', function (): void {
    $guard = new Guard(['/lichkehoach/public/index.php']);
    assert_true($guard->isPublic('/lichkehoach/public/index.php'), 'khớp đúng');
    assert_false($guard->isPublic('/lichkehoach/public/index.php/x'), 'dài hơn thì không khớp');
    assert_false($guard->isPublic('/lichkehoach/public/'), 'ngắn hơn thì không khớp');
});

test('isPublic: entry kết thúc bằng / là khớp tiền tố', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_true($guard->isPublic('/lichkehoach/public/api/load_tasks.php'));
    assert_true($guard->isPublic('/lichkehoach/public/api/'));
    assert_false($guard->isPublic('/lichkehoach/public/index.php'));
});

test('isPublic: entry rỗng bị bỏ qua, không biến mọi path thành public', function (): void {
    $guard = new Guard(['', '/nhansuhtdb/webhook.php']);
    assert_false($guard->isPublic('/nhansuhtdb/index.php'));
    assert_true($guard->isPublic('/nhansuhtdb/webhook.php'));
});

/**
 * BASELINE SAI — task 3.2 của auth-hardening sẽ lật hai khẳng định dưới đây thành false.
 * Ghi lại ở đây để lúc lật là lật có chủ ý, không phải vô tình.
 * Guard so path thô, trong khi web server phục vụ path đã rút gọn → lọt allowlist.
 */
test('isPublic: BASELINE `..` hiện LỌT qua allowlist tiền tố (lỗ bypass, task 3.2 sẽ vá)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_true(
        $guard->isPublic('/lichkehoach/public/api/../edit.php'),
        'hiện tại trả true; sau task 3.2 phải là false'
    );
});

test('isPublic: BASELINE percent-encoding hiện KHÔNG được giải mã (task 3.2 sẽ vá)', function (): void {
    $guard = new Guard(['/lichkehoach/public/api/']);
    assert_false(
        $guard->isPublic('/lichkehoach/public/%61pi/load_tasks.php'),
        'path mã hoá không khớp tiền tố — fail-closed, an toàn nhưng không nhất quán'
    );
    assert_true(
        $guard->isPublic('/lichkehoach/public/api/%2e%2e/edit.php'),
        'hiện tại trả true; sau task 3.2 phải là false'
    );
});
