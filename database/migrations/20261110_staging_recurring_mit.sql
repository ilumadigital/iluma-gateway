-- Staging-only: recurring mandate with explicit MIT consent.
-- Zero card numbers/CVV; provider owns the payment instrument.
-- Never enable automatic charges until Nexi HPP MIT contract UAT completes.
CREATE TABLE IF NOT EXISTS recurring_mandates (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 initial_payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 provider_customer_id VARCHAR(27) NOT NULL,
 contract_id VARCHAR(27) NOT NULL UNIQUE,
 contract_type VARCHAR(24) NOT NULL DEFAULT 'MIT_SCHEDULED',
 sku VARCHAR(80) NOT NULL DEFAULT 'L-PREM',
 amount_cents INT UNSIGNED NOT NULL DEFAULT 399,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 consent_version VARCHAR(80) NOT NULL,
 consent_at DATETIME NOT NULL,
 status ENUM('pending','active','cancelled','past_due','verification_pending') NOT NULL DEFAULT 'pending',
 paid_through_at DATETIME NULL,
 next_due_at DATETIME NULL,
 anchor_day TINYINT UNSIGNED NULL,
 verified_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 deactivated_at DATETIME NULL,
 last_error_code VARCHAR(32) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (customer_id) REFERENCES customers(id),
 FOREIGN KEY (initial_payment_id) REFERENCES payments(id),
 INDEX(application_id,customer_id,status),
 INDEX(status,next_due_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS recurring_cycles (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 mandate_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cycle_due_at DATETIME NOT NULL,
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 status ENUM('pending','unknown','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY one_cycle(mandate_id,cycle_due_at),
 FOREIGN KEY (mandate_id) REFERENCES recurring_mandates(id),
 FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;
