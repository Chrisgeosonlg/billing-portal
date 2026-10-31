CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL DEFAULT '',
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
 status ENUM('active','paused','removed') NOT NULL DEFAULT 'active',
 failed_attempts INT NOT NULL DEFAULT 0,
 locked_until DATETIME NULL,
 last_login_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS customers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 email VARCHAR(190) NOT NULL DEFAULT '',
 phone VARCHAR(80) NOT NULL DEFAULT '',
 address TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS documents (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('quotation','invoice') NOT NULL,
 doc_no INT UNSIGNED NULL,
 customer_id INT UNSIGNED NOT NULL,
 assigned_to VARCHAR(190) NULL,
 customer_snapshot TEXT NOT NULL,
 company_snapshot TEXT NOT NULL,
 source_id INT UNSIGNED NULL UNIQUE,
 document_date DATE NOT NULL,
 due_date DATE NOT NULL,
 notes TEXT NOT NULL,
 items_json MEDIUMTEXT NOT NULL,
 subtotal BIGINT NOT NULL,
 discount BIGINT NOT NULL,
 tax_bps INT NOT NULL,
 tax BIGINT NOT NULL,
 total BIGINT NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX documents_assigned (assigned_to),
 UNIQUE KEY documents_kind_no (kind,doc_no),
 FOREIGN KEY (customer_id) REFERENCES customers(id),
 FOREIGN KEY (source_id) REFERENCES documents(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_id INT UNSIGNED NOT NULL,
 amount BIGINT NOT NULL,
 payment_date DATE NOT NULL,
 reference VARCHAR(190) NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (document_id) REFERENCES documents(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
 user_id INT UNSIGNED PRIMARY KEY,
 token_hash CHAR(64) NULL UNIQUE,
 password_digest CHAR(64) NOT NULL,
 requested_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS recurring_quotations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 template_id INT UNSIGNED NOT NULL UNIQUE,
 recipient VARCHAR(190) NOT NULL,
 anchor_date DATE NOT NULL,
 sequence INT UNSIGNED NOT NULL DEFAULT 0,
 next_date DATE NULL,
 interval_count SMALLINT UNSIGNED NULL,
 interval_unit ENUM('day','week','month','year') NULL,
 end_date DATE NULL,
 valid_days SMALLINT UNSIGNED NOT NULL,
 status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX recurring_due (status,next_date),
 FOREIGN KEY (template_id) REFERENCES documents(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recurring_runs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 schedule_id INT UNSIGNED NOT NULL,
 run_date DATE NOT NULL,
 document_id INT UNSIGNED NOT NULL UNIQUE,
 share_id BIGINT UNSIGNED NULL,
 mail_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE recurring_run_once (schedule_id,run_date),
 FOREIGN KEY (schedule_id) REFERENCES recurring_quotations(id),
 FOREIGN KEY (document_id) REFERENCES documents(id),
 FOREIGN KEY (share_id) REFERENCES document_shares(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
CREATE TABLE IF NOT EXISTS doc_counters (
 kind ENUM('quotation','invoice') PRIMARY KEY,
 next_no INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO doc_counters (kind,next_no) VALUES ('quotation',1128),('invoice',1701);
