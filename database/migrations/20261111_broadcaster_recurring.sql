-- Sandbox-only broadcaster subscription contracts; independent of Listener Premium.
-- Apply to staging database ONLY, before enabling Broadcaster checkout.
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

-- Immutable one-time Boost scope. The signed Gateway paid event must identify
-- the exact approved station originally linked to this one payment.
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
