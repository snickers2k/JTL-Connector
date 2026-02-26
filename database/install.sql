-- Multi-vendor aware settings per company (vendor)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_vendor` (
  `company_id` INT UNSIGNED NOT NULL,
  `enabled` CHAR(1) NOT NULL DEFAULT 'N',
  `token` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Mapping table for PrimaryKeyMapperInterface
CREATE TABLE IF NOT EXISTS `?:jtl_connector_mapping` (
  `company_id` INT UNSIGNED NOT NULL,
  `type` INT NOT NULL,
  `endpoint` VARBINARY(64) NOT NULL,
  `host` BIGINT NOT NULL,
  PRIMARY KEY (`company_id`, `type`, `endpoint`),
  KEY `idx_company_type_host` (`company_id`, `type`, `host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Request audit log (endpoint-level)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_request_log` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `ts` INT UNSIGNED NOT NULL,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `content_length` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `ok` CHAR(1) NOT NULL DEFAULT 'Y',
  `error` TEXT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_company_ts` (`company_id`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Basic per-vendor rate limiting window
CREATE TABLE IF NOT EXISTS `?:jtl_connector_rate_limit` (
  `company_id` INT UNSIGNED NOT NULL,
  `window_start` INT UNSIGNED NOT NULL,
  `cnt` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Debug events (optional)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_debug_event` (
  `event_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `ts` INT UNSIGNED NOT NULL,
  `level` VARCHAR(8) NOT NULL DEFAULT 'INFO',
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `context_json` MEDIUMTEXT NULL,
  `payload_snippet` MEDIUMTEXT NULL,
  `log_id` INT UNSIGNED NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_company_ts` (`company_id`, `ts`),
  KEY `idx_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Watchdog state per vendor
CREATE TABLE IF NOT EXISTS `?:jtl_connector_watchdog_state` (
  `company_id` INT UNSIGNED NOT NULL,
  `last_ok_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  `consecutive_errors` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `last_notified_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Variation grouping state (stores chosen parent product per variation group code)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_variation_parent` (
  `company_id` INT UNSIGNED NOT NULL,
  `group_code` VARCHAR(64) NOT NULL,
  `parent_product_id` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`, `group_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Admin alerts (shown in backend, optionally also via pop-up notifications)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_admin_alert` (
  `alert_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `ts` INT UNSIGNED NOT NULL,
  `level` VARCHAR(8) NOT NULL DEFAULT 'ERROR',
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `is_read` CHAR(1) NOT NULL DEFAULT 'N',
  `hash` CHAR(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`alert_id`),
  KEY `idx_unread_ts` (`is_read`, `ts`),
  KEY `idx_company_ts` (`company_id`, `ts`),
  KEY `idx_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Payload samples (last N samples per vendor+entity, for troubleshooting without DB access)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_payload_sample` (
  `sample_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `ts` INT UNSIGNED NOT NULL,
  `entity` VARCHAR(64) NOT NULL,
  `direction` VARCHAR(8) NOT NULL DEFAULT 'push',
  `snippet` MEDIUMTEXT NULL,
  `log_id` INT UNSIGNED NULL,
  PRIMARY KEY (`sample_id`),
  KEY `idx_company_entity_ts` (`company_id`, `entity`, `ts`),
  KEY `idx_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Internal scheduler state + cron token (single row, id=1)
CREATE TABLE IF NOT EXISTS `?:jtl_connector_scheduler_state` (
  `id` TINYINT UNSIGNED NOT NULL,
  `cron_token` VARCHAR(64) NOT NULL DEFAULT '',
  `last_watchdog_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_pruner_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO `?:jtl_connector_scheduler_state` (`id`, `cron_token`, `last_watchdog_ts`, `last_pruner_ts`)
VALUES (1, '', 0, 0);

