<?php
/**
 * Quét một dự án PHP và in ra bản đồ auth hiện tại của nó, đủ để quyết định cách port sang authV2.
 *
 *   php audit.php C:\laragon\www\<du-an>
 *
 * Chỉ ĐỌC, không sửa gì. Kết quả là điểm khởi đầu để đọc code, không phải kết luận cuối cùng —
 * regex bắt được chỗ điển hình, còn biến thể lạ thì vẫn phải mở file ra xem.
 */

declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Dùng: php audit.php <đường-dẫn-dự-án>\n");
    exit(1);
}
$root = rtrim(str_replace('\\', '/', realpath($root)), '/');

const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'storage', 'uploads', 'backups', 'logs', 'assets/uploads'];

/** @return list<string> */
function phpFiles(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                if (!$f->isDir()) {
                    return true;
                }
                return !in_array(strtolower($f->getFilename()), SKIP_DIRS, true);
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($files);
    return $files;
}

/** @param list<string> $files @return array<string, list<array{int, string}>> */
function scan(array $files, string $root, string $regex): array
{
    $hits = [];
    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match($regex, $line)) {
                $hits[substr($file, strlen($root) + 1)][] = [$i + 1, trim($line)];
            }
        }
    }
    return $hits;
}

/** @param array<string, list<array{int, string}>> $hits */
function report(string $title, array $hits, string $note = '', int $maxPerFile = 3): void
{
    $total = array_sum(array_map('count', $hits));
    echo "\n## $title — $total chỗ / " . count($hits) . " file\n";
    if ($note !== '') {
        echo "   $note\n";
    }
    foreach ($hits as $file => $rows) {
        echo "   $file (" . count($rows) . ")\n";
        foreach (array_slice($rows, 0, $maxPerFile) as [$ln, $line]) {
            echo "      $ln: " . mb_substr($line, 0, 110) . "\n";
        }
        if (count($rows) > $maxPerFile) {
            echo "      … còn " . (count($rows) - $maxPerFile) . " chỗ\n";
        }
    }
}

$files = phpFiles($root);
echo "# Audit authV2 — $root\n";
echo "Tổng: " . count($files) . " file PHP\n";

$isGit = is_dir($root . '/.git');
echo $isGit
    ? "Git: CÓ → sửa/xoá an toàn, có thể revert\n"
    : "Git: KHÔNG → mọi thao tác xoá là KHÔNG hoàn tác được, phải sao lưu trước khi xoá\n";

$sessionStart = scan($files, $root, '/\bsession_start\s*\(/');
$nStart = array_sum(array_map('count', $sessionStart));
report('Điểm mở phiên (session_start)', $sessionStart);

echo "\n=> NHÓM: ";
if ($nStart === 0) {
    echo "chưa rõ (0 session_start — có thể dùng framework/session wrapper riêng, phải đọc code)\n";
} elseif ($nStart <= 10) {
    echo "1 — sửa trực tiếp từng chỗ (mẫu lichkehoach/sangkienct)\n";
} else {
    echo "2 — auto_prepend_file (mẫu nhansuhtdb): $nStart chỗ, sửa tay quá rủi ro\n";
}

report(
    'Có file session tập trung không',
    scan($files, $root, '/function\s+\w*[Ss]ession\w*\s*\(|session_set_cookie_params|session_name\s*\(/'),
    'Có → biến chính file đó thành shim ủy quyền cho package, ~mọi include cũ khỏi phải sửa (mẫu lichkehoach/src/session.php).'
);

// Đòi hỏi trên cùng dòng phải có từ "pass" thì mới tính, nếu không md5() dùng cho rate-limit/cache
// cũng bị gom vào và làm loãng danh sách phải sửa.
report(
    'SO mật khẩu lúc login — PHẢI gỡ hết',
    scan($files, $root, '/(?=.*pass)(?:===?\s*\$|\$\w*pass\w*\s*[!=]==?|password_verify\s*\(|\bmd5\s*\(|\bsha1\s*\()/i'),
    'Còn sót một chỗ = còn đường vòng qua authV2, và sẽ vỡ khi bật hash ở Giai đoạn 2.'
);

report(
    'GHI mật khẩu (đổi mật khẩu / tạo user / import)',
    scan($files, $root, '/(UPDATE[^;]*\bpassword\b\s*=)|(INSERT\s+INTO\s+`?user`?[\s(`])/i'),
    'Ghi vào bảng `user` của DB `mysqli` → phải chuyển sang password_hash ở task 3.2. Ghi vào bảng user RIÊNG của dự án thì không liên quan — kiểm tra tên DB trước khi kết luận.'
);

report(
    'Remember-me tự chế — gỡ, thay bằng phiên 7 ngày trượt của authV2',
    scan($files, $root, '/user_login|remember_token|remember_me/i')
);

report(
    'Form login (input password) — thành code chết sau khi port',
    scan($files, $root, '/type\s*=\s*["\']password["\']/i')
);

report(
    'Endpoint JSON/XHR — dùng 403 JSON, KHÔNG dùng Guard (302 sẽ vỡ phía client)',
    scan($files, $root, '/(header\s*\(\s*["\']Content-Type:\s*application\/json)|(echo\s+json_encode)|HTTP_X_REQUESTED_WITH/i'),
    'Ở các file này chỉ đổi check sang SessionContract::isLoggedIn(), giữ nguyên nhánh trả 403.'
);

report(
    'Chỗ chặn truy cập hiện tại (check $_SESSION rồi redirect)',
    scan($files, $root, '/if\s*\(\s*!?\s*isset\s*\(\s*\$_SESSION\[|!\s*\$logged_in|Location:\s*.{0,20}login/i'),
    'Đây là các chỗ sẽ thay bằng Guard::requireLogin().',
    5
);

report('Kết nối DB', scan($files, $root, '/mysqli_connect|new\s+mysqli|new\s+PDO/i'));

// Khoá session được ĐỌC ở nơi khác chính là hợp đồng ngầm mà login cũ phải dựng — sau khi port,
// authV2 chỉ set user_id/name/logged_in_at nên mọi khoá còn lại phải được dựng lại mỗi request.
$keys = [];
foreach ($files as $file) {
    $src = @file_get_contents($file) ?: '';
    if (preg_match_all('/\$_SESSION\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $idx => $hit) {
            $key = $hit[0];
            $after = substr($src, $m[0][$idx][1] + strlen($m[0][$idx][0]), 20);
            $isWrite = (bool) preg_match('/^\s*(=[^=]|\.=)/', $after);
            $keys[$key][$isWrite ? 'ghi' : 'doc'][] = substr($file, strlen($root) + 1);
        }
    }
}
ksort($keys);
$standard = ['user_id', 'name', 'logged_in_at'];
// App-state (flash message, CSRF, giỏ dữ liệu tạm) do chính dự án ghi trong lúc chạy, không phải danh
// tính do login dựng → port xong vẫn hoạt động y nguyên, không cần đụng.
$appState = '/^(csrf|_csrf|csrf_token|error|success|message|flash|warning|info|notice|login_error|.*_result|.*_message)$/i';
echo "\n## Khoá \$_SESSION — cột 'đọc' là hợp đồng phải giữ sau khi port\n";
echo "   authV2 chỉ set: " . implode(', ', $standard) . ". Khoá DANH TÍNH nào đang được ĐỌC mà login cũ dựng\n";
echo "   → phải dựng lại MỖI REQUEST từ DB, nếu không navbar/quyền sẽ trống sau khi đăng nhập.\n";
printf("   %-26s %6s %6s  %s\n", 'khoá', 'ghi', 'đọc', 'ghi chú');
foreach ($keys as $key => $use) {
    $w = count($use['ghi'] ?? []);
    $r = count($use['doc'] ?? []);
    if (in_array($key, $standard, true)) {
        $note = 'authV2 lo';
    } elseif (preg_match($appState, $key)) {
        $note = 'app-state, giữ nguyên';
    } elseif ($r > 0) {
        $note = 'CẦN dựng lại';
    } else {
        $note = 'chỉ ghi, không ai đọc → bỏ được';
    }
    printf("   %-26s %6d %6d  %s\n", $key, $w, $r, $note);
}

echo "\n## Việc tiếp theo\n";
echo "   1. Đọc kỹ các file xuất hiện ở mục 'SO mật khẩu' và 'chặn truy cập' — đó là phần lõi phải sửa.\n";
echo "   2. Với mỗi khoá 'CẦN dựng lại', tìm trong login cũ xem nó lấy từ bảng/cột nào.\n";
echo "   3. Thêm prefix URL của dự án vào EnvConfig::projects() TRƯỚC khi test, nếu không redirect sẽ bị loại.\n";
