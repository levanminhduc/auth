-- Chạy lại nhiều lần an toàn. Cần InnoDB ROW_FORMAT=DYNAMIC (xem auth_login_attempts.sql).
ALTER TABLE auth_login_attempts
  MODIFY identifier VARCHAR(330) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE auth_login_attempts COLLATE = utf8mb4_unicode_ci;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'auth_login_attempts'
    AND INDEX_NAME = 'idx_attempted_at'
);
SET @ddl := IF(
  @has_idx = 0,
  'ALTER TABLE auth_login_attempts ADD KEY idx_attempted_at (attempted_at)',
  'DO 0'
);
PREPARE add_idx FROM @ddl;
EXECUTE add_idx;
DEALLOCATE PREPARE add_idx;
