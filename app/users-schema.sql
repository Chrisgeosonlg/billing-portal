-- Staff users and activity history. Select your existing ERP database before importing.
-- Your existing account becomes the administrator; business records are not changed. Import it once (a second import reports "Duplicate column", which is harmless).
ALTER TABLE users
 ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '' AFTER email,
 ADD COLUMN role ENUM('admin','staff') NOT NULL DEFAULT 'staff' AFTER password_hash,
 ADD COLUMN status ENUM('active','paused','removed') NOT NULL DEFAULT 'active' AFTER role,
 ADD COLUMN last_login_at DATETIME NULL,
 ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
UPDATE users SET role='admin' WHERE id=1;
CREATE TABLE IF NOT EXISTS activity_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 summary VARCHAR(500) NOT NULL,
 document_id INT UNSIGNED NULL,
 customer_id INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX activity_user (user_id,id),
 INDEX activity_document (document_id,id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
