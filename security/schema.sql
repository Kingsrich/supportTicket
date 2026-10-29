CREATE DATABASE IF NOT EXISTS php_security_monitor
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE php_security_monitor;

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_hits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hit_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NOT NULL,
  INDEX idx_hit_ip_time (ip_address, hit_time),
  INDEX idx_hit_time (hit_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NOT NULL,
  method VARCHAR(12) NOT NULL,
  request_path VARCHAR(2048) NOT NULL,
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  event_type VARCHAR(80) NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  details VARCHAR(1000) NOT NULL DEFAULT '',
  status_code SMALLINT UNSIGNED NULL,
  INDEX idx_event_time (event_time),
  INDEX idx_ip_time (ip_address, event_time),
  INDEX idx_severity_time (severity, event_time),
  INDEX idx_type_time (event_type, event_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blocked_ips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL UNIQUE,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  INDEX idx_blocked_enabled_expiry (enabled, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alert_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NULL,
  recipient VARCHAR(254) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status ENUM('sent','failed','skipped') NOT NULL,
  response_text VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alert_created (created_at),
  CONSTRAINT fk_alert_event FOREIGN KEY (event_id) REFERENCES security_events(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('alert_email',''),
('mail_from',''),
('alert_min_severity','medium'),
('rate_limit_window_seconds','60'),
('rate_limit_requests','120'),
('alert_cooldown_minutes','15'),
('auto_block_rate_limit','0'),
('auto_block_duration_minutes','30');
