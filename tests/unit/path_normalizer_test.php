<?php

declare(strict_types=1);

use Hoatho\SharedAuth\PathNormalizer;

test('normalize: path sạch giữ nguyên, kể cả dấu / cuối', function (): void {
    assert_same('/a/b/c.php', PathNormalizer::normalize('/a/b/c.php'));
    assert_same('/a/b/', PathNormalizer::normalize('/a/b/'));
    assert_same('/', PathNormalizer::normalize('/'));
    assert_same('/', PathNormalizer::normalize(''));
});

test('normalize: `.` và `..` được rút gọn, không bao giờ vượt gốc', function (): void {
    assert_same('/a/c', PathNormalizer::normalize('/a/b/../c'));
    assert_same('/a/b/c', PathNormalizer::normalize('/a/./b/./c'));
    assert_same('/c', PathNormalizer::normalize('/a/../../../c'));
    assert_same('/', PathNormalizer::normalize('/..'));
    assert_same('/', PathNormalizer::normalize('/../..'));
    assert_same('/a/', PathNormalizer::normalize('/a/b/../'));
});

test('normalize: // gộp thành /, path không có / đầu vẫn ra path tuyệt đối', function (): void {
    assert_same('/a/b', PathNormalizer::normalize('//a///b'));
    assert_same('/a/b', PathNormalizer::normalize('a/b'));
});

test('normalize: backslash là dấu phân cách', function (): void {
    assert_same('/a/c', PathNormalizer::normalize('/a/b\\..\\c'));
    assert_same('/a/c', PathNormalizer::normalize('\\a\\b\\..\\c'));
    assert_same('/c', PathNormalizer::normalize('/a\\..\\c'));
});

test('normalize: percent-encoding giải mã trước khi rút gọn, hoa/thường đều được', function (): void {
    assert_same('/a/c', PathNormalizer::normalize('/a/b/%2e%2e/c'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b/%2E%2E/c'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b/.%2e/c'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b/%2e./c'));
    assert_same('/a/b', PathNormalizer::normalize('/a/%62'));
});

test('normalize: %2f và %5c được coi là dấu phân cách, không né được `..`', function (): void {
    assert_same('/a/c', PathNormalizer::normalize('/a/b/..%2fc'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b/..%5cc'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b%2f..%2fc'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b%5c..%5cc'));
    assert_same('/a/c', PathNormalizer::normalize('/a/b%2F..%2Fc'));
});

test('normalize: chỉ giải mã MỘT lượt, như web server', function (): void {
    assert_same('/a/b/%2e%2e/c', PathNormalizer::normalize('/a/b/%252e%252e/c'), '`%252e` chỉ là chữ %2e, không phải `.`');
    assert_same('/a/b/c', PathNormalizer::normalize('/a/b/api%252fx/../c'), '`api%252fx` là một segment, `..` rút đúng nó');
});

test('isAmbiguous: path có `\\`, %2f, %5c, %25 (mọi kiểu hoa/thường) là mơ hồ', function (): void {
    foreach (['/a\\b', '/a/%2f/b', '/a/%2F/b', '/a/%5c/b', '/a/%5C/b', '/a/%25/b', '/a/%252e%252e/b', '/a/b%2fc'] as $p) {
        assert_true(PathNormalizer::isAmbiguous($p), $p);
    }
    foreach (['/a/b', '/a/%2e%2e/b', '/a/%61/b', '/a//b/', '/a/b.php', '/', ''] as $p) {
        assert_false(PathNormalizer::isAmbiguous($p), $p);
    }
});

test('normalize: `+` không bị coi là khoảng trắng, `%` hỏng giữ nguyên', function (): void {
    assert_same('/a+b', PathNormalizer::normalize('/a+b'));
    assert_same('/a/%zz', PathNormalizer::normalize('/a/%zz'));
    assert_same('/a/100%', PathNormalizer::normalize('/a/100%'));
});

test('normalize: giữ nguyên chữ hoa/thường (so khớp không phân biệt là việc của người gọi)', function (): void {
    assert_same('/Nhan/Su', PathNormalizer::normalize('/Nhan/Su'));
});
