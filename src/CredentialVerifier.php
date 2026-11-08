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
        if ($row === null) {
            $this->burnVerifyCost($password);
            return null;
        }

        // WHY: crypt() của bcrypt cắt chuỗi ở byte NUL nên "abc\0xyz" khớp hash của "abc", còn password_hash() ném ValueError trên PHP 8.3; từ chối đồng nhất, cùng chi phí bcrypt, để không thành oracle theo loại hash.
        if (str_contains($password, "\0")) {
            $this->burnVerifyCost($password);
            return null;
        }

        $stored = (string) $row['password'];

        if ($this->looksHashed($stored)) {
            if (!password_verify($password, $stored)) {
                return null;
            }
            if ($this->upgradeOnLogin && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                $this->tryUpgradeHash($row, $password);
            }
        } else {
            if (!hash_equals($stored, $password)) {
                $this->burnVerifyCost($password);
                return null;
            }
            if ($this->upgradeOnLogin) {
                $this->tryUpgradeHash($row, $password);
            }
        }

        return ['id' => $row['id'], 'name' => $row['name']];
    }

    private function tryUpgradeHash(array $row, #[\SensitiveParameter] string $password): void
    {
        try {
            $this->upgradeHash($row, $password);
        } catch (\Throwable $e) {
            error_log('CredentialVerifier: không nâng cấp được hash cho user ' . $this->escapeForLog((string) $row['name']) . ': ' . $this->escapeForLog($e->getMessage(), 300));
        }
    }

    private function lookup(string $username): array
    {
        $t = $this->quoteIdent($this->table);
        $userCol = $this->quoteIdent($this->userColumn);
        $idCol = $this->quoteIdent($this->idColumn);
        $passCol = $this->quoteIdent($this->passColumn);

        // WHY: chỉ lấy đúng 3 cột và đặt alias cố định; SELECT * từng trả cả role/full_name/... ra ngoài package.
        $stmt = $this->conn->prepare("SELECT {$idCol} AS `id`, {$userCol} AS `name`, {$passCol} AS `password` FROM {$t} WHERE {$userCol} = ? ORDER BY {$idCol} LIMIT 2") ?: throw $this->dbError($this->conn);
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

    // WHY: password_get_info chỉ nhận bcrypt dạng `$2y$`; hash `$2a$`/`$2b$` hợp lệ (vd. từ hệ thống khác) mà bị coi là plaintext thì chủ tài khoản không đăng nhập được.
    private function looksHashed(string $stored): bool
    {
        return password_get_info($stored)['algo'] !== null
            || preg_match('#^\$2[abxy]\$\d{2}\$[./A-Za-z0-9]{53}$#', $stored) === 1;
    }

    private function upgradeHash(array $row, #[\SensitiveParameter] string $plaintext): void
    {
        $id = $row['id'] ?? null;
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
