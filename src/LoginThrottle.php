<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use mysqli;

final class LoginThrottle
{
    private mysqli $conn;
    private string $table;
    private int $maxAttempts;
    private int $windowMinutes;
    private int $lockoutMinutes;
    private bool $byIp;
    private float $cleanupProbability;
    private int $retentionHours;

    public function __construct(mysqli $conn, array $options = [])
    {
        $this->conn               = $conn;
        $this->table              = (string) ($options['table'] ?? 'auth_login_attempts');
        $this->maxAttempts        = (int) ($options['maxAttempts'] ?? 5);
        $this->windowMinutes      = (int) ($options['windowMinutes'] ?? 15);
        $this->lockoutMinutes     = (int) ($options['lockoutMinutes'] ?? 15);
        $this->byIp               = (bool) ($options['byIp'] ?? true);
        $this->cleanupProbability = (float) ($options['cleanupProbability'] ?? 0.05);
        $this->retentionHours     = (int) ($options['retentionHours'] ?? 24);
    }

    public function lockedMinutes(string $username, string $ip): ?int
    {
        try {
            $max = null;
            foreach ($this->keys($username, $ip) as $identifier) {
                $remaining = $this->remainingSeconds($identifier);
                if ($remaining === null || $remaining <= 0) {
                    continue;
                }
                $minutes = (int) ceil($remaining / 60);
                if ($minutes > $this->lockoutMinutes) {
                    $minutes = $this->lockoutMinutes;
                }
                if ($minutes > 0 && ($max === null || $minutes > $max)) {
                    $max = $minutes;
                }
            }
            return $max;
        } catch (\Throwable $e) {
            error_log('LoginThrottle: ' . $e->getMessage());
            return null;
        }
    }

    public function recordFailure(string $username, string $ip): void
    {
        try {
            foreach ($this->keys($username, $ip) as $identifier) {
                $this->insertAttempt($identifier);
                $count = $this->countInWindow($identifier);
                if ($count === $this->maxAttempts) {
                    error_log(
                        "LoginThrottle: khoá {$identifier} sau {$count} lần sai, ip={$ip}, user={$username}"
                    );
                }
            }
            $this->maybeCleanup();
        } catch (\Throwable $e) {
            error_log('LoginThrottle: ' . $e->getMessage());
        }
    }

    public function clear(string $username): void
    {
        try {
            $identifier = $this->clip('u:' . $username);
            $t = $this->quoteIdent($this->table);
            $stmt = $this->conn->prepare("DELETE FROM {$t} WHERE identifier = ?");
            if ($stmt === false) {
                throw new \RuntimeException($this->conn->error);
            }
            $stmt->bind_param('s', $identifier);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($err);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('LoginThrottle: ' . $e->getMessage());
        }
    }

    private function maybeCleanup(): void
    {
        $p = $this->cleanupProbability;
        if (!($p >= 1.0 || mt_rand() / mt_getrandmax() < $p)) {
            return;
        }
        $t = $this->quoteIdent($this->table);
        $stmt = $this->conn->prepare("DELETE FROM {$t} WHERE attempted_at < NOW() - INTERVAL ? HOUR");
        if ($stmt === false) {
            throw new \RuntimeException($this->conn->error);
        }
        $hours = $this->retentionHours;
        $stmt->bind_param('i', $hours);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($err);
        }
        $stmt->close();
    }

    private function keys(string $username, string $ip): array
    {
        $keys = [$this->clip('u:' . $username)];
        if ($this->byIp && $ip !== '') {
            $keys[] = $this->clip('ip:' . $ip);
        }
        return $keys;
    }

    private function clip(string $identifier): string
    {
        return mb_substr($identifier, 0, 190);
    }

    private function remainingSeconds(string $identifier): ?int
    {
        $t = $this->quoteIdent($this->table);
        $sql = "SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? MINUTE) AS remaining
            FROM {$t} WHERE identifier = ? AND attempted_at >= NOW() - INTERVAL ? MINUTE
            ORDER BY attempted_at DESC LIMIT 1 OFFSET ?";
        $stmt = $this->conn->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException($this->conn->error);
        }
        $lockout = $this->lockoutMinutes;
        $window = $this->windowMinutes;
        $offset = $this->maxAttempts - 1;
        $stmt->bind_param('isii', $lockout, $identifier, $window, $offset);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($err);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null || !isset($row['remaining'])) {
            return null;
        }
        return (int) $row['remaining'];
    }

    private function insertAttempt(string $identifier): void
    {
        $t = $this->quoteIdent($this->table);
        $stmt = $this->conn->prepare("INSERT INTO {$t} (identifier, attempted_at) VALUES (?, NOW())");
        if ($stmt === false) {
            throw new \RuntimeException($this->conn->error);
        }
        $stmt->bind_param('s', $identifier);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($err);
        }
        $stmt->close();
    }

    private function countInWindow(string $identifier): int
    {
        $t = $this->quoteIdent($this->table);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS n FROM {$t} WHERE identifier = ? AND attempted_at >= NOW() - INTERVAL ? MINUTE"
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->conn->error);
        }
        $window = $this->windowMinutes;
        $stmt->bind_param('si', $identifier, $window);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($err);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '', $ident) . '`';
    }
}
