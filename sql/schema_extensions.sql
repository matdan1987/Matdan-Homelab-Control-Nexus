-- Matdan Control Nexus - Schema Extensions
-- Version 2.0 - Complete Feature Set
-- This file extends the base schema with all new features

-- ============================================================================
-- NOTIFICATIONS SYSTEM
-- ============================================================================

CREATE TABLE `notification_channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('email', 'slack', 'discord', 'telegram', 'gotify', 'webhook') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `config_json` JSON NOT NULL COMMENT 'Channel-specific configuration',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `severity` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
  `context_json` JSON DEFAULT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notification_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `event_type` VARCHAR(50) NOT NULL COMMENT 'vm_down, backup_failed, etc.',
  `conditions_json` JSON DEFAULT NULL,
  `min_severity` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'warning',
  `cooldown_minutes` INT UNSIGNED DEFAULT 60,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- API KEYS SYSTEM
-- ============================================================================

CREATE TABLE `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `key_hash` VARCHAR(255) NOT NULL,
  `key_prefix` VARCHAR(20) NOT NULL COMMENT 'First chars for identification',
  `permissions_json` JSON DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_key_hash` (`key_hash`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_key_prefix` (`key_prefix`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `api_requests_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `method` VARCHAR(10) NOT NULL,
  `status_code` INT NOT NULL,
  `response_time_ms` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_api_key_id` (`api_key_id`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- MULTI-TENANCY & TEAM MANAGEMENT
-- ============================================================================

CREATE TABLE `teams` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quota_cpu` INT UNSIGNED DEFAULT NULL COMMENT 'Total CPU cores quota',
  `quota_memory_gb` INT UNSIGNED DEFAULT NULL,
  `quota_storage_gb` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `team_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('member', 'admin') NOT NULL DEFAULT 'member',
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_member` (`team_id`, `user_id`),
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `team_resources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `vmid` INT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_resource` (`team_id`, `pve_node_id`, `vmid`),
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- COST TRACKING & REPORTING
-- ============================================================================

CREATE TABLE `cost_models` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `cpu_core_hourly` DECIMAL(10,4) DEFAULT 0.0000,
  `memory_gb_hourly` DECIMAL(10,4) DEFAULT 0.0000,
  `storage_gb_monthly` DECIMAL(10,4) DEFAULT 0.0000,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `effective_from` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `resource_usage_snapshots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `vmid` INT UNSIGNED NOT NULL,
  `cpu_cores` INT UNSIGNED NOT NULL,
  `memory_gb` DECIMAL(10,2) NOT NULL,
  `storage_gb` DECIMAL(10,2) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `snapshot_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_snapshot_at` (`snapshot_at`),
  INDEX `idx_vm` (`pve_node_id`, `vmid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cost_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `team_id` INT UNSIGNED DEFAULT NULL,
  `service_id` INT UNSIGNED DEFAULT NULL,
  `total_cost` DECIMAL(10,2) NOT NULL,
  `breakdown_json` JSON DEFAULT NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_period` (`period_start`, `period_end`),
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VM/LXC PROVISIONING
-- ============================================================================

CREATE TABLE `vm_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `template_vmid` INT UNSIGNED NOT NULL,
  `type` ENUM('qemu', 'lxc') NOT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `config_json` JSON DEFAULT NULL COMMENT 'Default configuration',
  `post_deploy_script` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `provisioning_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `target_node_id` INT UNSIGNED NOT NULL,
  `vm_name` VARCHAR(100) NOT NULL,
  `config_override_json` JSON DEFAULT NULL,
  `status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `new_vmid` INT UNSIGNED DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`template_id`) REFERENCES `vm_templates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`target_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- MONITORING INTEGRATION
-- ============================================================================

CREATE TABLE `monitoring_sources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('prometheus', 'grafana', 'zabbix', 'custom') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) DEFAULT NULL,
  `config_json` JSON DEFAULT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `alert_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `monitoring_source_id` INT UNSIGNED DEFAULT NULL,
  `metric` VARCHAR(100) NOT NULL,
  `condition` VARCHAR(50) NOT NULL COMMENT 'gt, lt, eq, etc.',
  `threshold` DECIMAL(10,2) NOT NULL,
  `duration_minutes` INT UNSIGNED DEFAULT 5,
  `severity` ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`monitoring_source_id`) REFERENCES `monitoring_sources`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `alerts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_rule_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED DEFAULT NULL,
  `vmid` INT UNSIGNED DEFAULT NULL,
  `message` TEXT NOT NULL,
  `value` DECIMAL(10,2) DEFAULT NULL,
  `status` ENUM('firing', 'resolved') NOT NULL DEFAULT 'firing',
  `fired_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_fired_at` (`fired_at`),
  FOREIGN KEY (`alert_rule_id`) REFERENCES `alert_rules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- NETWORK MANAGEMENT
-- ============================================================================

CREATE TABLE `networks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('bridge', 'bond', 'vlan', 'ovs') NOT NULL,
  `subnet` VARCHAR(50) DEFAULT NULL COMMENT 'CIDR notation',
  `gateway` VARCHAR(50) DEFAULT NULL,
  `vlan_id` INT UNSIGNED DEFAULT NULL,
  `config_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `ip_allocations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `network_id` INT UNSIGNED NOT NULL,
  `ip_address` VARCHAR(50) NOT NULL,
  `pve_node_id` INT UNSIGNED DEFAULT NULL,
  `vmid` INT UNSIGNED DEFAULT NULL,
  `hostname` VARCHAR(100) DEFAULT NULL,
  `mac_address` VARCHAR(17) DEFAULT NULL,
  `status` ENUM('available', 'allocated', 'reserved') NOT NULL DEFAULT 'available',
  `notes` TEXT DEFAULT NULL,
  `allocated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ip` (`network_id`, `ip_address`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`network_id`) REFERENCES `networks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- STORAGE MANAGEMENT
-- ============================================================================

CREATE TABLE `storage_pools` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pve_node_id` INT UNSIGNED NOT NULL,
  `storage_id` VARCHAR(100) NOT NULL COMMENT 'Proxmox storage ID',
  `type` VARCHAR(50) NOT NULL COMMENT 'dir, lvm, zfs, nfs, ceph, etc.',
  `total_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `used_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `available_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `last_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_storage` (`pve_node_id`, `storage_id`),
  FOREIGN KEY (`pve_node_id`) REFERENCES `pve_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- DOCUMENTATION & WIKI
-- ============================================================================

CREATE TABLE `documentation_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT DEFAULT NULL COMMENT 'Markdown content',
  `category` VARCHAR(50) DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slug` (`slug`),
  INDEX `idx_parent_id` (`parent_id`),
  FOREIGN KEY (`parent_id`) REFERENCES `documentation_pages`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `resource_documentation` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_type` ENUM('vm', 'service', 'node') NOT NULL,
  `resource_id` INT UNSIGNED NOT NULL,
  `documentation_page_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_resource_doc` (`resource_type`, `resource_id`),
  FOREIGN KEY (`documentation_page_id`) REFERENCES `documentation_pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- WORKFLOW & AUTOMATION ENGINE
-- ============================================================================

CREATE TABLE `workflows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `trigger_type` ENUM('manual', 'schedule', 'event', 'webhook') NOT NULL,
  `trigger_config_json` JSON DEFAULT NULL,
  `steps_json` JSON NOT NULL COMMENT 'Array of workflow steps',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `workflow_executions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` INT UNSIGNED NOT NULL,
  `triggered_by` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'running',
  `current_step` INT UNSIGNED DEFAULT 0,
  `result_json` JSON DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_workflow_id` (`workflow_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`workflow_id`) REFERENCES `workflows`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`triggered_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- COMPLIANCE & GOVERNANCE
-- ============================================================================

CREATE TABLE `compliance_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `rule_type` VARCHAR(50) NOT NULL COMMENT 'tag_required, backup_required, etc.',
  `scope` ENUM('all', 'service', 'tag', 'team') NOT NULL DEFAULT 'all',
  `scope_value` VARCHAR(100) DEFAULT NULL,
  `config_json` JSON DEFAULT NULL,
  `severity` ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `compliance_violations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id` INT UNSIGNED NOT NULL,
  `pve_node_id` INT UNSIGNED DEFAULT NULL,
  `vmid` INT UNSIGNED DEFAULT NULL,
  `service_id` INT UNSIGNED DEFAULT NULL,
  `violation_message` TEXT NOT NULL,
  `status` ENUM('open', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open',
  `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_rule_id` (`rule_id`),
  FOREIGN KEY (`rule_id`) REFERENCES `compliance_rules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- CHANGE MANAGEMENT
-- ============================================================================

CREATE TABLE `change_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `change_type` ENUM('deployment', 'configuration', 'maintenance', 'emergency') NOT NULL,
  `risk_level` ENUM('low', 'medium', 'high', 'critical') NOT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `scheduled_at` DATETIME DEFAULT NULL,
  `status` ENUM('draft', 'pending_approval', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  `implementation_plan` TEXT DEFAULT NULL,
  `rollback_plan` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- REPORTING TEMPLATES
-- ============================================================================

CREATE TABLE `report_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` ENUM('summary', 'detailed', 'compliance', 'cost', 'custom') NOT NULL,
  `query_json` JSON NOT NULL COMMENT 'Report configuration and queries',
  `schedule_cron` VARCHAR(100) DEFAULT NULL,
  `recipients` JSON DEFAULT NULL COMMENT 'Email addresses',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `generated_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `period_start` DATETIME NOT NULL,
  `period_end` DATETIME NOT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `file_size_bytes` INT UNSIGNED DEFAULT NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_template_id` (`template_id`),
  FOREIGN KEY (`template_id`) REFERENCES `report_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

-- ============================================================================
-- END OF SCHEMA EXTENSIONS
-- ============================================================================
