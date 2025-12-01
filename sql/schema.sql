-- Matdan Control Nexus - Database Schema
-- Version 1.0
-- Requires: MySQL 8.0+ or MariaDB 10.5+

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- AUTHENTICATION AND USER MANAGEMENT
-- ============================================================================

-- Users table with role-based access control
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'viewer',
  `email` VARCHAR(100) DEFAULT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_username` (`username`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123 - PLEASE CHANGE!)
INSERT INTO `users` (`username`, `password_hash`, `role`, `email`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'admin@homelab.local');

-- Sessions table for secure session management
CREATE TABLE `sessions` (
  `id` VARCHAR(128) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `last_activity` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_last_activity` (`last_activity`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROXMOX VE NODE MANAGEMENT
-- ============================================================================

-- Proxmox VE nodes/clusters configuration
CREATE TABLE `pve_nodes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL COMMENT 'e.g., https://pve.homelab.local:8006/api2/json',
  `token_id` VARCHAR(100) NOT NULL COMMENT 'e.g., root@pam!api-token',
  `token_secret` VARCHAR(255) NOT NULL COMMENT 'API token secret',
  `verify_ssl` TINYINT(1) NOT NULL DEFAULT 1,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `remarks` TEXT DEFAULT NULL,
  `last_check` DATETIME DEFAULT NULL,
  `last_status` ENUM('online', 'offline', 'error') DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_enabled` (`enabled`),
  INDEX `idx_last_status` (`last_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache for Proxmox resources (VMs, LXCs, etc.)
CREATE TABLE `pve_resources_cache` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `node_name` VARCHAR(100) NOT NULL COMMENT 'PVE node name from API',
  `vmid` INT UNSIGNED NOT NULL,
  `type` ENUM('qemu', 'lxc') NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT NULL,
  `cpu` DECIMAL(5,2) DEFAULT NULL,
  `maxcpu` INT DEFAULT NULL,
  `mem` BIGINT DEFAULT NULL,
  `maxmem` BIGINT DEFAULT NULL,
  `disk` BIGINT DEFAULT NULL,
  `maxdisk` BIGINT DEFAULT NULL,
  `uptime` BIGINT DEFAULT NULL,
  `cached_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_resource` (`pve_node_id`, `vmid`),
  INDEX `idx_type` (`type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_cached_at` (`cached_at`),
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CMDB LIGHT: SERVICES AND DEPENDENCIES
-- ============================================================================

-- Services catalog
CREATE TABLE `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `environment` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., production, staging, development',
  `owner` VARCHAR(100) DEFAULT NULL,
  `criticality` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  `tags` JSON DEFAULT NULL COMMENT 'Array of tags',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_environment` (`environment`),
  INDEX `idx_criticality` (`criticality`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service instances (mapping to VMs/LXCs)
CREATE TABLE `service_instances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `vmid` INT UNSIGNED NOT NULL,
  `type` ENUM('qemu', 'lxc') NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_instance` (`pve_node_id`, `vmid`),
  INDEX `idx_service_id` (`service_id`),
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service dependencies
CREATE TABLE `service_dependencies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id` INT UNSIGNED NOT NULL,
  `depends_on_service_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dependency` (`service_id`, `depends_on_service_id`),
  INDEX `idx_service_id` (`service_id`),
  INDEX `idx_depends_on` (`depends_on_service_id`),
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`depends_on_service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_no_self_dependency` CHECK (`service_id` != `depends_on_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- POLICY ENGINE
-- ============================================================================

-- Policies for automated actions
CREATE TABLE `policies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `target_type` ENUM('vm', 'lxc', 'service', 'tag') NOT NULL,
  `target_value` VARCHAR(100) NOT NULL COMMENT 'VMID, service ID, or tag name',
  `rule_type` ENUM('schedule', 'load_based', 'dependency') NOT NULL,
  `config_json` JSON NOT NULL COMMENT 'Rule configuration',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_run` DATETIME DEFAULT NULL,
  `last_result` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_enabled` (`enabled`),
  INDEX `idx_rule_type` (`rule_type`),
  INDEX `idx_target` (`target_type`, `target_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Policy execution history
CREATE TABLE `policy_executions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `policy_id` INT UNSIGNED NOT NULL,
  `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `details_json` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_policy_id` (`policy_id`),
  INDEX `idx_executed_at` (`executed_at`),
  FOREIGN KEY (`policy_id`) REFERENCES `policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- BACKUP AND MAINTENANCE
-- ============================================================================

-- Backup policies
CREATE TABLE `backup_policies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `scope_type` ENUM('vm', 'lxc', 'service', 'tag', 'all') NOT NULL,
  `scope_value` VARCHAR(100) DEFAULT NULL COMMENT 'VMID, service ID, tag name, or NULL for all',
  `schedule_cron` VARCHAR(100) NOT NULL COMMENT 'Cron expression',
  `retention_count` INT UNSIGNED NOT NULL DEFAULT 7,
  `backup_mode` ENUM('snapshot', 'suspend', 'stop') NOT NULL DEFAULT 'snapshot',
  `compression` ENUM('none', 'lzo', 'gzip', 'zstd') NOT NULL DEFAULT 'zstd',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_run` DATETIME DEFAULT NULL,
  `next_run` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_enabled` (`enabled`),
  INDEX `idx_next_run` (`next_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup execution log
CREATE TABLE `backup_executions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backup_policy_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `vmid` INT UNSIGNED NOT NULL,
  `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL,
  `backup_file` VARCHAR(255) DEFAULT NULL,
  `size_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `duration_seconds` INT UNSIGNED DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_backup_policy_id` (`backup_policy_id`),
  INDEX `idx_executed_at` (`executed_at`),
  INDEX `idx_success` (`success`),
  FOREIGN KEY (`backup_policy_id`) REFERENCES `backup_policies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance windows
CREATE TABLE `maintenance_windows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `cron_expression` VARCHAR(100) NOT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
  `description` TEXT DEFAULT NULL,
  `pre_actions` JSON DEFAULT NULL COMMENT 'Actions to execute before maintenance',
  `post_actions` JSON DEFAULT NULL COMMENT 'Actions to execute after maintenance',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `next_occurrence` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_enabled` (`enabled`),
  INDEX `idx_next_occurrence` (`next_occurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INTEGRATIONS CONFIGURATION
-- ============================================================================

-- Integration endpoints configuration
CREATE TABLE `integrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('nginx_proxy_manager', 'pangolin', 'adguard_home', 'meshcentral', 'rustdesk', 'wireguard', 'netbird') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) DEFAULT NULL,
  `api_secret` VARCHAR(255) DEFAULT NULL,
  `config_json` JSON DEFAULT NULL COMMENT 'Additional configuration',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sync` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integration mappings (linking VMs/LXCs to integration resources)
CREATE TABLE `integration_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `integration_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `vmid` INT UNSIGNED NOT NULL,
  `resource_type` VARCHAR(50) NOT NULL COMMENT 'e.g., proxy_host, dns_record, device',
  `resource_id` VARCHAR(255) NOT NULL COMMENT 'ID in the external system',
  `resource_data` JSON DEFAULT NULL,
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_integration_id` (`integration_id`),
  INDEX `idx_vm` (`pve_node_id`, `vmid`),
  FOREIGN KEY (`integration_id`) REFERENCES `integrations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EVENT LOGGING AND AUDIT
-- ============================================================================

-- Events and audit log
CREATE TABLE `events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(50) NOT NULL COMMENT 'e.g., policy_action, backup_result, pve_error, user_action',
  `severity` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
  `message` TEXT NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `context_json` JSON DEFAULT NULL COMMENT 'Additional context data',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DASHBOARD AND USER PREFERENCES
-- ============================================================================

-- User dashboard layouts
CREATE TABLE `dashboard_layouts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `layout_json` JSON NOT NULL COMMENT 'Widget positions and configurations',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_layout` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User preferences
CREATE TABLE `user_preferences` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `preference_key` VARCHAR(100) NOT NULL,
  `preference_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_preference` (`user_id`, `preference_key`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SYSTEM CONFIGURATION
-- ============================================================================

-- Global system settings
CREATE TABLE `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('site_name', 'Matdan Control Nexus', 'Application name'),
('session_timeout', '3600', 'Session timeout in seconds'),
('cache_ttl', '300', 'Resource cache TTL in seconds'),
('enable_maintenance_mode', '0', 'Enable maintenance mode (0 or 1)'),
('timezone', 'Europe/Berlin', 'System timezone');

COMMIT;

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
