-- identifier: COLLATE utf8mb4_unicode_ci cho cùng độ nhạy hoa/thường và dấu như user.name (utf8mb3_unicode_ci).
-- VARCHAR(330) x 4 byte = 1320 byte chỉ vừa khoá chỉ mục khi InnoDB dùng ROW_FORMAT=DYNAMIC (mặc định MySQL 5.7+/MariaDB 10.2+).
CREATE TABLE IF NOT EXISTS auth_login_attempts (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(330) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_identifier_time (identifier, attempted_at),
  KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
