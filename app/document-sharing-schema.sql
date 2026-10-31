CREATE TABLE IF NOT EXISTS document_shares (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 recipient VARCHAR(190) NOT NULL DEFAULT '',
 mail_status ENUM('none','pending','accepted','failed') NOT NULL DEFAULT 'none',
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX document_shares_document (document_id),
 FOREIGN KEY (document_id) REFERENCES documents(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
