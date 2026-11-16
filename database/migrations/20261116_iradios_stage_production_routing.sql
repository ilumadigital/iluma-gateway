-- Manual SQL for iluma_production ONLY. Backup and VERIFY restore first.
-- Existing payments default to unchanged production return/webhook behavior.
ALTER TABLE payments
 ADD COLUMN iradios_stage_routed TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN origin_credential_id BIGINT UNSIGNED NULL DEFAULT NULL,
 ADD KEY ix_iradios_stage_routed (iradios_stage_routed,origin_credential_id);
