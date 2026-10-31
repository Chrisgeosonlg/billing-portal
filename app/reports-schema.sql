-- Monthly email report. Select your existing ERP database before importing.
-- Existing business records are not changed. Safe to re-import.
CREATE TABLE IF NOT EXISTS report_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 recipients VARCHAR(1000) NOT NULL DEFAULT '',
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_by INT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_runs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 report_date DATE NOT NULL UNIQUE,
 recipients VARCHAR(1000) NOT NULL,
 mail_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
