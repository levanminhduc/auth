<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use mysqli;

final class CredentialVerifier
{
    private const DUMMY_HASH = '$2y$10$9n8vagSlqismArdfujAdmud.jmAs./XO0LiZy2BkHCie9t8a02/nq';

    private mysqli $conn;
    private string $table;
    private string $userColumn;
    private string $passColumn;
    private string $idColumn;
    private bool $upgradeOnLogin;

    public function __construct(mysqli $conn, array $options = [])
    {
        $this->conn           = $conn;
        $this->table          = $options['table']          ?? 'user';
        $this->userColumn     = $options['userColumn']     ?? 'name';
        $this->passColumn     = $options['passColumn']     ?? 'password';
        $this->idColumn       = $options['idColumn']       ?? 'id';
        $this->upgradeOnLogin = $options['upgradeOnLogin'] ?? true;
    }

    public function verify(string $username, string $password): ?array
    {
        $t = $this->quoteIdent($this->table);
        $userCol = $this->quoteIdent($this->userColumn);

        $stmt = $this->conn->prepare("SELECT * FROM {$t} WHERE {$userCol} = ? LIMIT 1");
        if ($stmt === false) {
            error_log('CredentialVerifier: prepare thất bại: ' . $this->conn->error);
            return null;
        }
        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            error_log('CredentialVerifier: execute thất bại: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row === null || !array_key_exists($this->passColumn, $row)) {
            // WHY: mọi nhánh return null chưa chạy password_verify thật phải tốn bcrypt, nếu không thì "user không tồn tại" nhanh hơn "sai plaintext" và thành oracle.
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        $stored = (string) $row[$this->passColumn];

        if ($this->looksHashed($stored)) {
            if (!password_verify($password, $stored)) {
                return null;
            }
        } else {
            if (!hash_equals($stored, $password)) {
                password_verify($password, self::DUMMY_HASH);
                return null;
            }
            if ($this->upgradeOnLogin) {
                $this->upgradeHash($row, $password);
            }
        }

        unset($row[$this->passColumn]);
        return $row;
    }

    private function looksHashed(string $stored): bool
    {
        return str_starts_with($stored, '$2');
    }

    private function upgradeHash(array $row, string $plaintext): void
    {
        $id = $row[$this->idColumn] ?? null;
        if ($id === null) {
            return;
        }
        $hash = password_hash($plaintext, PASSWORD_DEFAULT);
        if ($hash === false) {
            return;
        }
        $t = $this->quoteIdent($this->table);
        $passCol = $this->quoteIdent($this->passColumn);
        $idCol = $this->quoteIdent($this->idColumn);

        $stmt = $this->conn->prepare("UPDATE {$t} SET {$passCol} = ? WHERE {$idCol} = ? LIMIT 1");
        if ($stmt === false) {
            error_log('CredentialVerifier: prepare upgrade thất bại: ' . $this->conn->error);
            return;
        }
        $idInt = (int) $id;
        $stmt->bind_param('si', $hash, $idInt);
        if (!$stmt->execute()) {
            error_log('CredentialVerifier: upgrade hash thất bại: ' . $stmt->error);
        }
        $stmt->close();
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '', $ident) . '`';
    }
}
