-- Staging-only rollout: run via bin/manage.php migrate-staging-saved-cards
-- No PAN, CVV, expiry or full card details. Nexi owns card credentials.
CREATE TABLE IF NOT EXISTS saved_card_agreements (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_customer_id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 contract_id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 contract_type VARCHAR(22) NOT NULL DEFAULT 'CIT',
 consent_version VARCHAR(80) NOT NULL,
 consent_at DATETIME NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'pending',
 verified_at DATETIME NULL,
 deactivated_at DATETIME NULL,
 last_checked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (payment_id) REFERENCES payments(id),
 FOREIGN KEY (customer_id) REFERENCES customers(id),
 INDEX (application_id,customer_id,status),
 INDEX (status,last_checked_at)
) ENGINE=InnoDB;
