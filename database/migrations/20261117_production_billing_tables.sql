-- Production billing prerequisites for the new iRadios stage application.
-- Apply ONLY through deploy/vps/production-billing.py after its backup restore check.
-- No rows, prices, existing payments, routes or credentials are changed here.

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

CREATE TABLE IF NOT EXISTS winback_claims (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expired_at DATETIME NOT NULL,
 redeemed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY one_winback_per_account(application_id,customer_id),
 FOREIGN KEY (customer_id) REFERENCES customers(id),
 FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcaster_mandates (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 initial_payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 provider_customer_id VARCHAR(27) NOT NULL,
 contract_id VARCHAR(27) NOT NULL UNIQUE,
 sku VARCHAR(80) NOT NULL,
 resource_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
 amount_cents INT UNSIGNED NOT NULL,
 consent_version VARCHAR(80) NOT NULL,
 consent_at DATETIME NOT NULL,
 status ENUM('pending','active','verification_pending','cancelled','past_due') NOT NULL DEFAULT 'pending',
 paid_through_at DATETIME NULL, next_due_at DATETIME NULL,
 anchor_day TINYINT UNSIGNED NULL, verified_at DATETIME NULL,
 cancelled_at DATETIME NULL, deactivated_at DATETIME NULL,
 last_error_code VARCHAR(32) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(customer_id) REFERENCES customers(id),
 FOREIGN KEY(initial_payment_id) REFERENCES payments(id),
 INDEX application_customer_product(application_id,customer_id,sku,resource_id,status),
 INDEX(status,next_due_at),
 CHECK(amount_cents > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS broadcaster_cycles (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 mandate_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cycle_due_at DATETIME NOT NULL,
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 status ENUM('pending','unknown','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY one_broadcaster_cycle(mandate_id,cycle_due_at),
 FOREIGN KEY(mandate_id) REFERENCES broadcaster_mandates(id),
 FOREIGN KEY(payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS broadcaster_boost_scopes (
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sku VARCHAR(80) NOT NULL,
 resource_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(payment_id) REFERENCES payments(id),
 FOREIGN KEY(customer_id) REFERENCES customers(id),
 INDEX(application_id,customer_id,resource_id,sku)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS advertising_budget_scopes (
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 campaign_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents BIGINT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY one_advertising_payment_per_quote(application_id,quote_id),
 INDEX application_campaign(application_id,campaign_id),
 FOREIGN KEY(payment_id) REFERENCES payments(id),
 FOREIGN KEY(customer_id) REFERENCES customers(id),
 CHECK(amount_cents BETWEEN 100 AND 100000000)
) ENGINE=InnoDB;
