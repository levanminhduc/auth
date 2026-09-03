CREATE TABLE IF NOT EXISTS auth_login_attempts (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(190) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
