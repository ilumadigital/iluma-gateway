-- STAGING DATABASE ONLY. Execute manually after backup and verification.
-- Never run against the production tenant. No new subscription or recurring tables.
CREATE TABLE IF NOT EXISTS advertising_budget_payments (
 payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 campaign_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents BIGINT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY advertising_quote_unique (application_id,quote_id),
 KEY advertising_campaign (application_id,campaign_id),
 CONSTRAINT advertising_payment_fk FOREIGN KEY(payment_id) REFERENCES payments(id),
 CONSTRAINT advertising_amount_positive CHECK(amount_cents>0)
) ENGINE=InnoDB;
-- Fixed catalog anchor: its amount is NEVER used for advertising checkout.
-- The HMAC-authenticated advertising request provides the validated variable amount.
INSERT INTO products(application_id,sku,title,amount_cents,currency,period_days,enabled)
 SELECT application_id,'ADV-BUDGET','iRadios GR Advertising Budget',100,'EUR',NULL,1
 FROM products WHERE sku='L-PREM' AND enabled=1
 ON DUPLICATE KEY UPDATE title=VALUES(title);
