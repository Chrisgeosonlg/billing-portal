-- Recurring quotations. Select your existing ERP database before importing.
-- Existing business records are not changed. Safe to re-import.
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
