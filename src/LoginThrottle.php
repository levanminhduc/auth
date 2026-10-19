<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use mysqli;

final class LoginThrottle
{
    private const MAX_USERNAME_CHARS = 255;
    private const MAX_IP_CHARS = 64;
    private const HISTORY_LIMIT = 1000;

    private mysqli $conn;
    private string $table;
    private array $policies;
    private bool $byIp;
    private float $cleanupProbability;
    private int $retentionHours;
    private ?array $pending = null;

    public function __construct(mysqli $conn, array $options = [])
    {
        $this->conn = $conn;
        $this->table = (string) ($options['table'] ?? 'auth_login_attempts');
        $this->policies = [
            'p'  => $this->policy($options, 'maxAttempts', 'windowMinutes', 'lockoutMinutes', 5, 15, 15),
            'ip' => $this->policy($options, 'ipMaxAttempts', 'ipWindowMinutes', 'ipLockoutMinutes', 30, 15, 15),
            'u'  => $this->policy($options, 'userMaxAttempts', 'userWindowMinutes', 'userLockoutMinutes', 50, 60, 30),
        ];
        $this->byIp = (bool) ($options['byIp'] ?? true);
        $this->cleanupProbability = (float) ($options['cleanupProbability'] ?? 0.05);
        $this->retentionHours = (int) ($options['retentionHours'] ?? 24);
    }

    // Chèn bản ghi chờ cho MỌI khoá trước rồi mới đếm: N request song song đều thấy nhau
    // nên tối đa `max` request qua được, thay vì cả N cùng vượt bước kiểm tra.
    public function begin(string $username, string $ip): ?int
    {
        if (!mb_check_encoding($username, 'UTF-8')) {
            throw new \InvalidArgumentException('Username không phải UTF-8 hợp lệ.');
        }

        $this->pending = null;
        $keys = $this->keys($username, $ip);

        $ids = [];
        foreach ($keys as $kind => $identifier) {
            try {
                $ids[$kind] = $this->insertAttempt($identifier);
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }

        $lockedSeconds = 0;
        foreach ($ids as $kind => $id) {
            try {
                $policy = $this->policies[$kind];
                $ages = $this->recentAges($keys[$kind], $id, $policy);
                $remaining = $this->lockRemaining($ages, $policy);
                if ($remaining > 0) {
                    $lockedSeconds = max($lockedSeconds, $remaining);
                }
                if ($kind === 'u') {
                    $this->alertIfCeilingReached($keys[$kind], $id, $policy, $username, $ip);
                }
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }

        try {
            if ($lockedSeconds > 0) {
                $this->deleteIds(array_values($ids));
            } else {
                $this->pending = ['ip' => $ip, 'ids' => $ids];
            }
            $this->maybeCleanup();
        } catch (\Throwable $e) {
            $this->logError($e);
        }

        return $lockedSeconds > 0 ? (int) ceil($lockedSeconds / 60) : null;
    }

    public function abort(): void
    {
        $pending = $this->pending;
        $this->pending = null;
        if ($pending === null) {
            return;
        }

        try {
            $this->deleteIds(array_values($pending['ids']));
        } catch (\Throwable $e) {
            $this->logError($e);
        }
    }

    public function succeed(string $username, string $ip): void
    {
        $pending = $this->pending;
        $this->pending = null;

        $keys = $this->keys($username, $ip);
        foreach (['p', 'u'] as $kind) {
            try {
                $this->deleteIdentifier($keys[$kind]);
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }

        if ($pending !== null && $pending['ip'] === $ip && isset($pending['ids']['ip'])) {
            try {
                $this->deleteIds([$pending['ids']['ip']]);
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }
    }

    private function policy(
        array $options,
        string $maxKey,
        string $windowKey,
        string $lockoutKey,
        int $max,
        int $window,
        int $lockout
    ): array {
        return [
            'max'     => max(1, (int) ($options[$maxKey] ?? $max)),
            'window'  => max(1, (int) ($options[$windowKey] ?? $window)),
            'lockout' => max(1, (int) ($options[$lockoutKey] ?? $lockout)),
        ];
    }

    // Khoá `p:` gắn username với IP nên IP lạ không khoá được tài khoản; `u:` chỉ là trần chống dò từ nhiều IP.
    // Cắt username ở 255 ký tự (độ dài user.name) và IP ở 64 để khoá `p:` luôn vừa cột VARCHAR(330).
    private function keys(string $username, string $ip): array
    {
        $name = mb_substr($username, 0, self::MAX_USERNAME_CHARS, 'UTF-8');
        $ip = $this->byIp ? mb_substr($ip, 0, self::MAX_IP_CHARS, 'UTF-8') : '';

        $keys = ['p' => 'p:' . $name . '|' . $ip, 'u' => 'u:' . $name];
        if ($ip !== '') {
            $keys['ip'] = 'ip:' . $ip;
        }
        return $keys;
    }

    // Tuổi (giây) của các lần thử khác cùng khoá, mới nhất trước. Chỉ cần nhìn lại window + lockout:
    // khoá bắt đầu trong vòng `lockout` gần đây và nhóm kích hoạt nằm trong `window` trước đó.
    private function recentAges(string $identifier, int $selfId, array $policy): array
    {
        $t = $this->quoteIdent($this->table);
        $horizon = ($policy['window'] + $policy['lockout']) * 60;
        $limit = max(self::HISTORY_LIMIT, $policy['max']);
        $stmt = $this->execute(
            "SELECT TIMESTAMPDIFF(SECOND, attempted_at, NOW()) AS age FROM {$t}
            WHERE identifier = ? AND id <> ? AND attempted_at >= NOW() - INTERVAL ? SECOND
            ORDER BY attempted_at DESC, id DESC LIMIT ?",
            'siii',
            [$identifier, $selfId, $horizon, $limit]
        );
        $ages = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ages[] = max(0, (int) $row['age']);
        }
        $stmt->close();
        return $ages;
    }

    // Khoá bắt đầu từ lần sai thứ `max` trong một cửa sổ `window` (nhóm gần nhất) và kéo dài đúng
    // `lockout`, không phụ thuộc `window`.
    private function lockRemaining(array $ages, array $policy): int
    {
        $max = $policy['max'];
        $windowSeconds = $policy['window'] * 60;
        $count = count($ages);
        for ($i = 0; $i + $max - 1 < $count; $i++) {
            if ($ages[$i + $max - 1] - $ages[$i] <= $windowSeconds) {
                return $policy['lockout'] * 60 - $ages[$i];
            }
        }
        return 0;
    }

    // Đếm theo thứ tự id (kể cả bản ghi chờ sẽ bị xoá vì khoá) nên đúng một request là lần thứ `max`
    // trong cửa sổ, kể cả khi các request đồng thời chèn xong hết rồi mới đếm và đều thấy count > max.
    private function alertIfCeilingReached(string $identifier, int $selfId, array $policy, string $username, string $ip): void
    {
        $t = $this->quoteIdent($this->table);
        $stmt = $this->execute(
            "SELECT COUNT(*) AS n FROM {$t} WHERE identifier = ? AND id <= ? AND attempted_at >= NOW() - INTERVAL ? SECOND",
            'sii',
            [$identifier, $selfId, $policy['window'] * 60]
        );
        $rank = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        if ($rank === $policy['max']) {
            error_log(
                'LoginThrottle: username ' . $this->quote($username) . ' chạm trần ' . $rank
                . ' lần sai trong ' . $policy['window'] . ' phút, ip=' . $this->quote($ip)
            );
        }
    }

    private function insertAttempt(string $identifier): int
    {
        $t = $this->quoteIdent($this->table);
        $stmt = $this->execute("INSERT INTO {$t} (identifier, attempted_at) VALUES (?, NOW())", 's', [$identifier]);
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function deleteIdentifier(string $identifier): void
    {
        $t = $this->quoteIdent($this->table);
        $this->execute("DELETE FROM {$t} WHERE identifier = ?", 's', [$identifier])->close();
    }

    private function deleteIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $t = $this->quoteIdent($this->table);
        $list = implode(',', array_map('intval', $ids));
        $this->execute("DELETE FROM {$t} WHERE id IN ({$list})")->close();
    }

    // Giữ bản ghi ít nhất window + lockout của mọi chính sách: ngắn hơn là làm khoá hết hạn sớm.
    private function maybeCleanup(): void
    {
        $p = $this->cleanupProbability;
        if (!($p >= 1.0 || mt_rand() / mt_getrandmax() < $p)) {
            return;
        }
        $retention = $this->retentionHours * 60;
        foreach ($this->policies as $policy) {
            $retention = max($retention, $policy['window'] + $policy['lockout']);
        }
        $t = $this->quoteIdent($this->table);
        $this->execute("DELETE FROM {$t} WHERE attempted_at < NOW() - INTERVAL ? MINUTE", 'i', [$retention])->close();
    }

    private function execute(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        $stmt = $this->conn->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException($this->conn->error);
        }
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($err);
        }
        return $stmt;
    }

    // json_encode thoát xuống dòng/ký tự điều khiển, chặn chèn dòng log giả bằng username.
    private function quote(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function logError(\Throwable $e): void
    {
        error_log('LoginThrottle: ' . $this->quote($e->getMessage()));
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '', $ident) . '`';
    }
}
