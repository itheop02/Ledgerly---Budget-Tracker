CREATE TABLE IF NOT EXISTS gdpr_consents (
  user_id INT NOT NULL PRIMARY KEY,
  value ENUM('yes', 'no') NOT NULL,
  withdrawn TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT gdpr_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT gdpr_withdrawn_boolean CHECK (withdrawn IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
