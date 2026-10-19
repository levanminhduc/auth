<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use mysqli;

final class CredentialVerifier
{
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
        $this->upgradeOnLogin = $options['upgradeOnLogin'] ?? false;
    }

    public function verify(string $username, #[\SensitiveParameter] string $password): ?array
    {
        try {
            return $this->doVerify($username, $password);
        } catch (\mysqli_sql_exception $e) {
            error_log('CredentialVerifier: lỗi DB khi xác thực user ' . $this->escapeForLog($username) . ': ' . $this->escapeForLog($e->getMessage(), 300));
            throw new AuthBackendException('Không truy vấn được dữ liệu xác thực.', 0, $e);
        }
    }

    private function doVerify(string $username, #[\SensitiveParameter] string $password): ?array
    {
        $rows = $this->lookup($username);

        if (count($rows) > 1) {
            error_log('CredentialVerifier: ambiguous username ' . $this->escapeForLog($username) . ' khớp nhiều hơn một dòng, từ chối đăng nhập');
            $this->burnVerifyCost($password);
            return null;
        }

        $row = $rows[0] ?? null;
        if ($row === null || !array_key_exists($this->passColumn, $row)) {
            $this->burnVerifyCost($password);
            return null;
        }

        // WHY: crypt() của bcrypt cắt chuỗi ở byte NUL nên "abc\0xyz" khớp hash của "abc", còn password_hash() ném ValueError trên PHP 8.3; từ chối đồng nhất, cùng chi phí bcrypt, để không thành oracle theo loại hash.
        if (str_contains($password, "\0")) {
            $this->burnVerifyCost($password);
            return null;
        }

        $stored = (string) $row[$this->passColumn];

        if ($this->looksHashed($stored)) {
            if (!password_verify($password, $stored)) {
                return null;
            }
        } else {
            if (!hash_equals($stored, $password)) {
                $this->burnVerifyCost($password);
                return null;
            }
            if ($this->upgradeOnLogin) {
                try {
                    $this->upgradeHash($row, $password);
                } catch (\Throwable $e) {
                    error_log('CredentialVerifier: không nâng cấp được hash cho user ' . $this->escapeForLog((string) ($row[$this->userColumn] ?? '')) . ': ' . $this->escapeForLog($e->getMessage(), 300));
                }
            }
        }

        unset($row[$this->passColumn]);
        return $row;
    }

    private function lookup(string $username): array
    {
        $t = $this->quoteIdent($this->table);
        $userCol = $this->quoteIdent($this->userColumn);
        $idCol = $this->quoteIdent($this->idColumn);

        $stmt = $this->conn->prepare("SELECT * FROM {$t} WHERE {$userCol} = ? ORDER BY {$idCol} LIMIT 2") ?: throw $this->dbError($this->conn);
        $stmt->bind_param('s', $username);
        $stmt->execute() ?: throw $this->dbError($stmt);
        $result = $stmt->get_result() ?: throw $this->dbError($stmt);
        $rows = [];
        while (count($rows) < 2 && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    // WHY: nhánh return null mà chưa chạy bcrypt thật sẽ nhanh hơn nhánh sai mật khẩu và thành oracle liệt kê user; password_hash ở cost mặc định ~ một lần verify, không phụ thuộc cost của hash đang lưu.
    private function burnVerifyCost(#[\SensitiveParameter] string $password): void
    {
        password_hash(str_replace("\0", '', $password), PASSWORD_DEFAULT);
    }

    private function escapeForLog(string $value, int $limit = 64): string
    {
        $encoded = json_encode(substr($value, 0, $limit), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '"?"' : $encoded;
    }

    private function looksHashed(string $stored): bool
    {
        return str_starts_with($stored, '$2');
    }

    private function upgradeHash(array $row, #[\SensitiveParameter] string $plaintext): void
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

        $stmt = $this->conn->prepare("UPDATE {$t} SET {$passCol} = ? WHERE {$idCol} = ? LIMIT 1") ?: throw $this->dbError($this->conn);
        $idInt = (int) $id;
        $stmt->bind_param('si', $hash, $idInt);
        $stmt->execute() ?: throw $this->dbError($stmt);
        $stmt->close();
    }

    // WHY: nếu caller đã tắt mysqli_report thì lỗi DB chỉ trả false; ép về cùng đường exception để không lọt thành "sai mật khẩu" hay Error thô.
    private function dbError(mysqli|\mysqli_stmt $source): \mysqli_sql_exception
    {
        return new \mysqli_sql_exception($source->error, $source->errno);
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '', $ident) . '`';
    }
}
