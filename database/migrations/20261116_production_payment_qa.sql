-- ONE manual, reviewed migration on iluma_production ONLY.
-- Leaves all existing production payments and routing unchanged (NULL = normal).
-- Do not run this against staging or before verified DB backup/restore.
ALTER TABLE payments
 ADD COLUMN qa_credential_id BIGINT UNSIGNED NULL DEFAULT NULL,
 ADD COLUMN qa_run_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
 ADD UNIQUE KEY uq_production_qa_run_key (qa_run_key),
 ADD KEY ix_production_qa_credential (qa_credential_id);
