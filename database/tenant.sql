-- Execute independently on BOTH iluma_staging and iluma_production
-- No cross-tenant joins; monetary records in INTEGER CENTS and UTC timestamps
CREATE TABLE IF NOT EXISTS customers (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,application_id BIGINT UNSIGNED NOT NULL,external_user_id VARCHAR(190) NOT NULL,
 email VARCHAR(250) NOT NULL,name VARCHAR(250) NOT NULL DEFAULT '',type VARCHAR(32) NOT NULL DEFAULT 'individual',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(application_id,external_user_id), INDEX(application_id,email)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customer_application_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,application_id BIGINT UNSIGNED NOT NULL,
 verified_at DATETIME NULL,link_proof_hash CHAR(64) NOT NULL,UNIQUE(customer_id,application_id),FOREIGN KEY(customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,application_id BIGINT UNSIGNED NOT NULL,sku VARCHAR(80) NOT NULL,title VARCHAR(180) NOT NULL,
 amount_cents BIGINT NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'EUR',period_days SMALLINT UNSIGNED NULL,
 enabled BOOLEAN NOT NULL DEFAULT TRUE,UNIQUE(application_id,sku), CHECK(amount_cents>0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS subscriptions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,application_id BIGINT UNSIGNED NOT NULL,
 customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 external_subscription_id VARCHAR(190) NOT NULL, state VARCHAR(32) NOT NULL DEFAULT 'pending', auto_renew BOOLEAN NOT NULL DEFAULT FALSE,
 contract_id VARCHAR(100) NULL,period_end_at DATETIME NULL,next_billing_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(application_id,external_subscription_id),
 FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS billing_cycles (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cycle_key VARCHAR(80) NOT NULL,attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,state VARCHAR(30) NOT NULL DEFAULT 'pending',
 next_attempt_at DATETIME NULL,UNIQUE(subscription_id,cycle_key),FOREIGN KEY(subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payments (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,application_id BIGINT UNSIGNED NOT NULL, customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,billing_cycle_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 order_id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,operation_id VARCHAR(100) NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,request_hash CHAR(64) NOT NULL,
 amount_cents BIGINT NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'EUR',status VARCHAR(28) NOT NULL DEFAULT 'pending',
 hosted_page TEXT NULL,security_token_box TEXT NULL,confirmed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(application_id,request_key),UNIQUE(billing_cycle_id),FOREIGN KEY(customer_id) REFERENCES customers(id),
 FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(subscription_id) REFERENCES subscriptions(id),
 FOREIGN KEY(billing_cycle_id) REFERENCES billing_cycles(id),INDEX(status,created_at), CHECK(amount_cents>0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payment_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_status VARCHAR(40) NOT NULL,operation_id VARCHAR(100) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(payment_id) REFERENCES payments(id),INDEX(payment_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payment_methods (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_method VARCHAR(40) NOT NULL,masked VARCHAR(40) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS nexi_contracts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 provider_contract_ref VARCHAR(120) NOT NULL UNIQUE,contract_type VARCHAR(30) NOT NULL,consent_at DATETIME NULL,active BOOLEAN NOT NULL DEFAULT FALSE,
 FOREIGN KEY(subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS refunds (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(100) NULL,amount_cents BIGINT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 confirmed_at DATETIME NULL,FOREIGN KEY(payment_id) REFERENCES payments(id),CHECK(amount_cents>0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS webhook_events (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,order_id VARCHAR(27) NOT NULL,verified BOOLEAN NOT NULL DEFAULT FALSE,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(order_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS outgoing_events (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 application_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(90) NOT NULL,body_json JSON NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',attempts SMALLINT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(payment_id,event_type),FOREIGN KEY(payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS event_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 http_status SMALLINT NULL,result VARCHAR(64) NOT NULL,occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(event_id) REFERENCES outgoing_events(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payment_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(70) NOT NULL,message_gr VARCHAR(250) NOT NULL,occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(payment_id) REFERENCES payments(id),INDEX(payment_id,occurred_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT NOT NULL
) ENGINE=InnoDB;
