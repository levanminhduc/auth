<?php

declare(strict_types=1);

namespace Hoatho\SharedAuth;

use mysqli;

final class IdentitySync
{
    private mysqli $conn;
    private string $table;
    private string $idColumn;
    private array $columns;
    private bool $done = false;

    public function __construct(mysqli $conn, array $options = [])
    {
        $this->conn     = $conn;
        $this->table    = $options['table']    ?? 'user';
        $this->idColumn = $options['idColumn'] ?? 'id';
        $this->columns  = $options['columns']  ?? ['name' => 'name'];
    }

    public function sync(): bool
    {
        if ($this->done) {
            return true;
        }

        $userId = SessionContract::userId();
        if ($userId === null) {
            return false;
        }

        $dbCols = array_keys($this->columns);
        $selectList = implode(', ', array_map([$this, 'quoteIdent'], $dbCols));
        $t = $this->quoteIdent($this->table);
        $idCol = $this->quoteIdent($this->idColumn);

        $stmt = $this->conn->prepare("SELECT {$selectList} FROM {$t} WHERE {$idCol} = ? LIMIT 1");
        if ($stmt === false) {
            error_log('IdentitySync: prepare thất bại: ' . $this->conn->error);
            return false;
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            error_log('IdentitySync: execute thất bại: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return false;
        }

        foreach ($this->columns as $dbCol => $sessionKeys) {
            $value = $this->normalize($row[$dbCol] ?? '');
            foreach ((array) $sessionKeys as $key) {
                $_SESSION[$key] = $value;
            }
        }

        $this->done = true;
        return true;
    }

    // WHY: phiên giữ giá trị thô; escape là việc của chỗ in ra HTML (escape lúc ghi làm hỏng mọi nơi dùng giá trị không phải HTML và gây escape hai lần).
    private function normalize($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '', $ident) . '`';
    }
}
