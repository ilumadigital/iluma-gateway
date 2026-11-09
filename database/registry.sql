-- Execute on iluma_registry (separate DB user; no grants on tenant DBs)
CREATE TABLE IF NOT EXISTS applications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(70) NOT NULL UNIQUE,name VARCHAR(160) NOT NULL,
 staging_origin VARCHAR(255) NOT NULL,production_origin VARCHAR(255) NOT NULL,enabled BOOLEAN NOT NULL DEFAULT TRUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS application_credentials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,application_id BIGINT UNSIGNED NOT NULL,environment ENUM('staging','production') NOT NULL,
 key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, key_prefix VARCHAR(24) NOT NULL,
 secret_box TEXT NOT NULL,enabled BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_used_at DATETIME NULL,
 FOREIGN KEY(application_id) REFERENCES applications(id),INDEX(application_id,environment)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS api_nonces (
 credential_id BIGINT UNSIGNED NOT NULL,nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,expires_at DATETIME NOT NULL,
 PRIMARY KEY(credential_id,nonce),INDEX(expires_at),FOREIGN KEY(credential_id) REFERENCES application_credentials(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS nexi_credentials (
 environment ENUM('staging','production') NOT NULL PRIMARY KEY,secret_box TEXT NOT NULL,updated_at DATETIME NOT NULL,last_checked_at DATETIME NULL,
 check_status VARCHAR(40) NOT NULL DEFAULT 'untested'
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(250) NOT NULL UNIQUE,passhash VARCHAR(255) NOT NULL,
 totp_secret_box TEXT NOT NULL,enabled BOOLEAN NOT NULL DEFAULT TRUE,failed_attempts SMALLINT NOT NULL DEFAULT 0,locked_until DATETIME NULL,
 last_login_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_id BIGINT UNSIGNED NULL,action VARCHAR(90) NOT NULL,target VARCHAR(180) NOT NULL,
 ip VARCHAR(45) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(admin_id) REFERENCES admin_users(id),INDEX(created_at)
) ENGINE=InnoDB;
INSERT INTO applications(slug,name,staging_origin,production_origin) VALUES('iradiosgr','iRadiosGR','https://stage.iradios.gr','https://play.iradios.gr')
ON DUPLICATE KEY UPDATE slug=VALUES(slug);
