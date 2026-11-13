-- Staging-only immutable origin scope for variable-price Advertising checkouts.
-- Never run against production, and never use this table as a credit ledger.
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
