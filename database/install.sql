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
  `host` INT NOT NULL,
  PRIMARY KEY (`company_id`, `type`, `endpoint`),
  KEY `idx_company_type_host` (`company_id`, `type`, `host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

