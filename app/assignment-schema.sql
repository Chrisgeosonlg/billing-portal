-- Staff assignment for quotations and invoices. Select your existing ERP database before importing.
-- Adds one empty column; existing records are not changed. Import it once (a second import reports "Duplicate column", which is harmless).
ALTER TABLE documents ADD COLUMN assigned_to VARCHAR(190) NULL AFTER customer_id, ADD INDEX documents_assigned (assigned_to);
