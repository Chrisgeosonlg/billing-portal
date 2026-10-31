-- Document numbering (PF-1128…, INV-1701…). The portal adds this by itself on first use; import it only if the
-- portal's error log says it could not. Select your ERP database first. Existing records are not changed.
ALTER TABLE documents ADD COLUMN doc_no INT UNSIGNED NULL AFTER kind, ADD UNIQUE KEY documents_kind_no (kind,doc_no);
CREATE TABLE IF NOT EXISTS doc_counters (
 kind ENUM('quotation','invoice') PRIMARY KEY,
 next_no INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO doc_counters (kind,next_no) VALUES ('quotation',1128),('invoice',1701);
