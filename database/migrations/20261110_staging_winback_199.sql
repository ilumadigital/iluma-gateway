-- Execute ONLY in the iRadios STAGING tenant.
-- One durable promotional checkout per verified customer; pending attempts
-- may be retried only after the provider conclusively marks them failed.
-- A successful Nexi capture permanently consumes the one-time discount.
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
