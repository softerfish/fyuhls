-- Consolidated Database Schema for High-Performance File Hosting Script
-- Version: Pre-Beta (Core Infrastructure)
-- Engine: InnoDB
-- Charset: utf8mb4

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- 1. Packages Table (Crucial for user limits)
-- --------------------------------------------------------
  CREATE TABLE `packages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `level_type` ENUM('guest', 'free', 'paid', 'admin') NOT NULL,
  `max_storage_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `max_upload_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `max_daily_downloads` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_speed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `wait_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `wait_time_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `concurrent_uploads` INT UNSIGNED NOT NULL DEFAULT 1,
  `concurrent_downloads` INT UNSIGNED NOT NULL DEFAULT 1,
  `accepted_file_types` TEXT NULL,
  `show_ads` TINYINT(1) NOT NULL DEFAULT 1,
  `file_expiry_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `allow_direct_links` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_remote_upload` TINYINT(1) NOT NULL DEFAULT 0,
  `ppd_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `ppd_rate_per_1000` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pps_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `pps_commission_percent` INT UNSIGNED NOT NULL DEFAULT 0,
  `block_adblock` TINYINT(1) NOT NULL DEFAULT 0,
  `block_vpn` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  INSERT INTO `packages` (`name`, `price`, `level_type`, `max_storage_bytes`, `max_upload_size`, `max_daily_downloads`, `download_speed`, `wait_time`, `concurrent_uploads`, `show_ads`, `file_expiry_days`, `allow_direct_links`, `allow_remote_upload`, `block_adblock`, `block_vpn`) VALUES
  ('Guest', 0.00, 'guest', 0, 104857600, 5, 512000, 30, 1, 1, 30, 0, 0, 1, 1),
  ('Free User', 0.00, 'free', 5368709120, 524288000, 20, 1048576, 15, 2, 1, 365, 0, 0, 1, 1),
  ('Premium', 9.99, 'paid', 0, 0, 0, 0, 0, 10, 0, 0, 1, 1, 0, 0),
  ('Admin', 0.00, 'admin', 0, 0, 0, 0, 0, 10, 0, 0, 1, 1, 0, 0);

-- --------------------------------------------------------
-- 2. Users Table
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(16) NOT NULL,
  `username` VARCHAR(255) NOT NULL /* Encrypted */,
  `email` VARCHAR(255) NOT NULL /* Encrypted */,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('guest', 'user', 'admin') NOT NULL DEFAULT 'user',
  `package_id` INT UNSIGNED NOT NULL DEFAULT 2,
  `referrer_id` BIGINT UNSIGNED NULL,
  `status` ENUM('active', 'banned', 'pending') NOT NULL DEFAULT 'active',
  `email_verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(255) NULL,
  `reset_token` VARCHAR(255) NULL,
  `reset_expires` DATETIME NULL,
  `storage_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `storage_warning_threshold` INT UNSIGNED NOT NULL DEFAULT 75,
  `storage_warning_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `premium_expiry` DATETIME NULL DEFAULT NULL,
  `api_key` VARCHAR(255) NULL /* Encrypted */,
  `default_privacy` ENUM('public', 'private') NOT NULL DEFAULT 'public',
  `timezone` VARCHAR(100) NOT NULL DEFAULT 'UTC',
  `language` VARCHAR(10) NOT NULL DEFAULT 'en',
  `payment_method` ENUM('paypal', 'stripe', 'bitcoin', 'wire') NULL,
  `payment_details` TEXT NULL /* Encrypted */,
  `monetization_model` ENUM('ppd', 'pps', 'mixed') NOT NULL DEFAULT 'ppd',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  INDEX `status` (`status`),
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`),
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. File Servers
-- --------------------------------------------------------
CREATE TABLE `file_servers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `server_type` ENUM('local', 's3', 'wasabi', 'backblaze', 'b2', 'r2') NOT NULL DEFAULT 'local',
  `status` ENUM('active', 'disabled', 'read-only') NOT NULL DEFAULT 'active',
  `storage_path` VARCHAR(255) NULL /* Encrypted */,
  `public_url` VARCHAR(255) NULL,
  `config` TEXT NULL /* Encrypted JSON */,
  `current_usage_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `max_capacity_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `delivery_method` ENUM('php', 'nginx', 'apache', 'litespeed') NOT NULL DEFAULT 'php',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `file_servers` (`name`, `server_type`, `status`, `storage_path`, `is_default`) VALUES ('Main Local Storage', 'local', 'active', NULL, 1);

-- --------------------------------------------------------
-- 4. Stored Files (Deduplication Layer)
-- --------------------------------------------------------
CREATE TABLE `stored_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_server_id` INT UNSIGNED NULL,
  `file_hash` CHAR(64) NOT NULL,
  `storage_provider` VARCHAR(50) NOT NULL DEFAULT 'local',
  `storage_path` VARCHAR(255) NOT NULL /* Encrypted */,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(255) NOT NULL /* Encrypted */,
  `provider_etag` VARCHAR(255) NULL,
  `checksum_verified_at` DATETIME NULL,
  `ref_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_hash` (`file_hash`),
  KEY `file_hash_size_idx` (`file_hash`, `file_size`),
  FOREIGN KEY (`file_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. Folders
-- --------------------------------------------------------
CREATE TABLE `folders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `short_id` VARCHAR(12) NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(191) NOT NULL /* Encrypted */,
  `status` ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_id` (`short_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE,
  INDEX `folders_hierarchy_idx` (`user_id`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Files (User Metadata)
-- --------------------------------------------------------
CREATE TABLE `files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `short_id` VARCHAR(12) NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `stored_file_id` BIGINT UNSIGNED NOT NULL,
  `folder_id` BIGINT UNSIGNED NULL,
  `filename` VARCHAR(255) NOT NULL /* Encrypted */,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `password` VARCHAR(255) NULL,
  `downloads` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_download_at` DATETIME NULL,
  `delete_at` DATETIME NULL,
  `status` ENUM('uploading', 'processing', 'ready', 'active', 'deleted', 'hidden', 'pending_purge', 'failed', 'abandoned', 'quarantined') NOT NULL DEFAULT 'active',
  `deleted_restore_status` VARCHAR(32) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_id` (`short_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`stored_file_id`) REFERENCES `stored_files`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL,
  INDEX `status_idx` (`status`),
  INDEX `files_dashboard_idx` (`user_id`, `folder_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. Upload Sessions and Quota Reservations
-- --------------------------------------------------------
CREATE TABLE `upload_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(32) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `guest_session_id` VARCHAR(128) NULL,
  `folder_id` BIGINT UNSIGNED NULL,
  `storage_server_id` INT UNSIGNED NULL,
  `storage_provider` VARCHAR(50) NOT NULL DEFAULT 'local',
  `original_filename` VARCHAR(255) NOT NULL /* Encrypted */,
  `object_key` VARCHAR(255) NOT NULL /* Encrypted */,
  `expected_size` BIGINT UNSIGNED NOT NULL,
  `mime_hint` VARCHAR(255) NULL /* Encrypted */,
  `checksum_sha256` CHAR(64) NULL,
  `multipart_upload_id` VARCHAR(255) NULL,
  `status` ENUM('pending', 'uploading', 'completing', 'processing', 'completed', 'failed', 'aborted', 'expired') NOT NULL DEFAULT 'pending',
  `reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `completed_parts` INT UNSIGNED NOT NULL DEFAULT 0,
  `part_size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `metadata_json` LONGTEXT NULL,
  `error_message` TEXT NULL,
  `expires_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `upload_sessions_public_id` (`public_id`),
  KEY `upload_sessions_user_status` (`user_id`, `status`),
  KEY `upload_sessions_guest_status` (`guest_session_id`, `status`),
  KEY `upload_sessions_expiry` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`storage_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `upload_session_parts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_session_id` BIGINT UNSIGNED NOT NULL,
  `part_number` INT UNSIGNED NOT NULL,
  `etag` VARCHAR(255) NULL,
  `part_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` CHAR(64) NULL,
  `status` ENUM('signed', 'uploaded', 'verified', 'failed') NOT NULL DEFAULT 'signed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `upload_session_part_unique` (`upload_session_id`, `part_number`),
  KEY `upload_session_part_status` (`upload_session_id`, `status`),
  FOREIGN KEY (`upload_session_id`) REFERENCES `upload_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quota_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(32) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `upload_session_id` BIGINT UNSIGNED NULL,
  `storage_server_id` INT UNSIGNED NULL,
  `reserved_bytes` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('active', 'committed', 'released', 'expired') NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NULL,
  `released_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quota_reservations_public_id` (`public_id`),
  KEY `quota_reservations_user_status` (`user_id`, `status`),
  KEY `quota_reservations_expiry` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`upload_session_id`) REFERENCES `upload_sessions`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`storage_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. API Tokens
-- --------------------------------------------------------
CREATE TABLE `api_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(24) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `token_prefix` VARCHAR(16) NOT NULL,
  `token_last_four` VARCHAR(4) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `scopes_json` LONGTEXT NOT NULL,
  `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NULL,
  `last_used_at` DATETIME NULL,
  `last_used_ip` VARCHAR(64) NULL,
  `revoked_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_tokens_public_id` (`public_id`),
  UNIQUE KEY `api_tokens_hash` (`token_hash`),
  KEY `api_tokens_user_status` (`user_id`, `status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 12. API Idempotency Keys
-- --------------------------------------------------------
CREATE TABLE `api_idempotency_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idem_key` VARCHAR(128) NOT NULL,
  `endpoint` VARCHAR(80) NOT NULL,
  `actor_key` VARCHAR(96) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `api_token_id` BIGINT UNSIGNED NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
  `response_code` SMALLINT UNSIGNED NULL,
  `response_json` LONGTEXT NULL,
  `completed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_idem_lookup` (`idem_key`, `endpoint`, `actor_key`),
  KEY `api_idem_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. Subscriptions & Billing
-- --------------------------------------------------------
CREATE TABLE `subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending',
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `billing_period` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
  `gateway` VARCHAR(50) NOT NULL,
  `gateway_reference` VARCHAR(191) NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `gateway` VARCHAR(50) NOT NULL,
  `gateway_reference` VARCHAR(191) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `ip_address` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. Remote Upload Queue
-- --------------------------------------------------------
CREATE TABLE `remote_upload_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `folder_id` BIGINT UNSIGNED NULL,
  `url` TEXT NOT NULL,
  `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 9. System Tracking & Security
-- --------------------------------------------------------
CREATE TABLE `active_downloads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `ip_address` VARCHAR(255) NOT NULL /* Encrypted */,
  `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_ping_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
  INDEX `ip_address_idx` (`ip_address`),
  INDEX `active_dl_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL /* Encrypted */,
  `email` VARCHAR(255) NOT NULL /* Encrypted */,
  `subject` TEXT NOT NULL /* Encrypted */,
  `message` TEXT NOT NULL /* Encrypted */,
  `status` ENUM('new', 'read', 'replied', 'archived') NOT NULL DEFAULT 'new',
  `ip_address` VARCHAR(255) NULL /* Encrypted */,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dmca_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_name` VARCHAR(255) NOT NULL /* Encrypted */,
  `reporter_email` VARCHAR(255) NOT NULL /* Encrypted */,
  `infringing_url` TEXT NOT NULL /* Encrypted */,
  `description` TEXT NOT NULL /* Encrypted */,
  `signature` VARCHAR(255) NOT NULL /* Encrypted */,
  `status` ENUM('pending', 'investigating', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
  `ip_address` VARCHAR(255) NULL /* Encrypted */,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `abuse_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `reporter_ip` VARCHAR(255) NOT NULL /* Encrypted */,
  `reason` ENUM('copyright', 'illegal', 'spam', 'other') NOT NULL,
  `details` TEXT NULL /* Encrypted */,
  `status` ENUM('pending', 'reviewed', 'action_taken', 'ignored') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_activity_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `activity_type` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `ip_address` VARCHAR(255) NULL /* Encrypted */,
  `user_agent` TEXT NULL /* Encrypted */,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `type` ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mail_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `priority` ENUM('high', 'low') NOT NULL DEFAULT 'low',
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `process_idx` (`status`, `priority`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(50) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_key` (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`template_key`, `subject`, `body`, `description`) VALUES 
('confirm_email', 'Confirm your email address for {site_name}', 'Hi {username},\n\nWelcome to {site_name}. Please confirm your email address by opening the link below:\n\n{confirm_link}\n\nIf you did not create this account, you can safely ignore this email.\n\nRegards,\n{site_name}\n{site_url}', 'Sent after registration when email verification is required.'),
('welcome_email', 'Welcome to {site_name}', 'Hi {username},\n\nYour account is ready and you can now start using {site_name}.\n\nYou can log in here:\n{site_url}/login\n\nThanks for joining us.\n\nRegards,\n{site_name}', 'Sent after successful registration or email verification.'),
('forgot_password', 'Reset your {site_name} password', 'Hi {username},\n\nWe received a request to reset your password.\n\nUse the link below to choose a new password:\n\n{reset_link}\n\nIf you did not request this, you can ignore this email.\n\nRegards,\n{site_name}', 'Sent when a user requests a password reset.'),
('admin_notification', '[{site_name}] {event_type}', 'Hello,\n\nA new event requires your attention on {site_name}.\n\nEvent: {event_type}\n\nDetails:\n{details}\n\nAdmin area:\n{site_url}/admin\n', 'Sent to the admin notification address for operational alerts.'),
  ('abuse_report_confirmation', 'We received your abuse report on {site_name}', 'Hi {username},\n\nThanks for submitting an abuse report for:\n{file_name}\n\nOur team will review it as soon as possible.\n\nRegards,\n{site_name}', 'Sent to a logged-in user after they submit an abuse report.'),
  ('contact_form_responder', 'We received your message: {subject}', 'Hi {username},\n\nThanks for contacting {site_name}. We received your message about:\n{subject}\n\nOur team will get back to you as soon as possible.\n\nRegards,\n{site_name}\n{support_email}', 'Sent after a public contact form submission.'),
  ('dmca_form_responder', 'We received your DMCA notice', 'Hi {username},\n\nWe received your DMCA notice on {site_name}.\n\nOur team will review the submission and follow up if additional information is needed.\n\nRegards,\n{site_name}\n{support_email}', 'Sent after a public DMCA form submission.'),
  ('account_downgrade', 'Your account has been moved to the Free tier', 'Hi {username},\n\nYour premium subscription has expired. Your account has been automatically moved to our Free tier.\n\nYour files are still safe, but your account may now be subject to free-tier limits.\n\nYou can upgrade again at any time from your dashboard.\n\nRegards,\n{site_name}', 'Sent when a premium subscription expires.'),
  ('package_changed', 'Your account package has been updated', 'Hi {username},\n\nYour account package on {site_name} has been updated.\n\nPrevious package: {old_package}\nNew package: {new_package}\n\nIf you were not expecting this change, please contact support.\n\nRegards,\n{site_name}\n{support_email}', 'Sent when an admin manually changes a user package.'),
  ('premium_expiry_reminder_7d', 'Your premium plan expires in 7 days', 'Hi {username},\n\nThis is a reminder that your premium plan on {site_name} will expire on {expiry_date}.\n\nIf you want to keep your premium features active, please renew before that date.\n\nRegards,\n{site_name}', 'Sent 7 days before premium expiry.'),
('premium_expiry_reminder_1d', 'Your premium plan expires tomorrow', 'Hi {username},\n\nYour premium plan on {site_name} expires on {expiry_date}.\n\nRenew now if you want to avoid interruption to your premium features.\n\nRegards,\n{site_name}', 'Sent 1 day before premium expiry.'),
('storage_limit_warning', 'Storage warning: you are using {usage_percent}% of your quota', 'Hi {username},\n\nYou are currently using {usage_percent}% of your available storage on {site_name}.\n\nWarning threshold: {threshold}%\nTotal package storage: {max_storage}\n\nPlease clean up unused files or upgrade your package if you need more room.\n\nRegards,\n{site_name}', 'Sent when a user reaches their storage warning threshold.'),
('withdrawal_request_submitted', 'We received your withdrawal request', 'Hi {username},\n\nWe received your withdrawal request on {site_name}.\n\nAmount: {amount}\nMethod: {method}\n\nYour request is now pending review. We will update you again once it is processed.\n\nRegards,\n{site_name}', 'Sent when a user submits a withdrawal request.'),
('withdrawal_status_approved', 'Your withdrawal request has been approved', 'Hi {username},\n\nYour withdrawal request has been approved.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nRegards,\n{site_name}', 'Sent when an admin approves a withdrawal request.'),
('withdrawal_status_paid', 'Your withdrawal has been marked as paid', 'Hi {username},\n\nYour withdrawal request has been marked as paid.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nRegards,\n{site_name}', 'Sent when an admin marks a withdrawal as paid.'),
  ('withdrawal_status_rejected', 'Your withdrawal request was rejected', 'Hi {username},\n\nYour withdrawal request was rejected.\n\nAmount: {amount}\nMethod: {method}\n\nAdmin note:\n{admin_note}\n\nPlease review the note above and update your payout details if needed.\n\nRegards,\n{site_name}', 'Sent when an admin rejects a withdrawal request.'),
  ('two_factor_enabled', 'Two-factor authentication is now enabled', 'Hi {username},\n\nTwo-factor authentication has been enabled on your {site_name} account.\n\nIf this was you, no action is needed.\nIf you did not enable 2FA, contact support immediately.\n\nRegards,\n{site_name}\n{support_email}', 'Sent after a user successfully enables 2FA.'),
  ('two_factor_disabled', 'Two-factor authentication has been disabled', 'Hi {username},\n\nTwo-factor authentication has been disabled on your {site_name} account.\n\nIf this was expected, no action is needed.\nIf you did not request this change, secure your account immediately and contact support.\n\nRegards,\n{site_name}\n{support_email}', 'Sent after 2FA is disabled for a user account.'),
  ('payment_completed', 'Your payment was completed successfully', 'Hi {username},\n\nWe received your payment successfully.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nYour account will be updated according to your purchase.\n\nRegards,\n{site_name}', 'Reserved for successful package payment confirmations once live gateway callbacks are enabled.'),
  ('payment_failed', 'Your payment could not be completed', 'Hi {username},\n\nWe were unable to complete your payment.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nPlease try again or use a different payment method.\n\nRegards,\n{site_name}', 'Reserved for failed package payments once live gateway callbacks are enabled.'),
  ('payment_pending', 'Your payment is pending review', 'Hi {username},\n\nWe received your payment attempt and it is currently pending review.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nWe will send another email as soon as the payment is confirmed or declined.\n\nRegards,\n{site_name}', 'Reserved for pending payment states once live gateway callbacks are enabled.'),
  ('payment_on_hold', 'Your payment is currently on hold', 'Hi {username},\n\nYour recent payment has been placed on hold.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nWe will update you again when the payment is released or declined.\n\nRegards,\n{site_name}', 'Reserved for held payment states once live gateway callbacks are enabled.'),
  ('payment_denied', 'Your payment was denied', 'Hi {username},\n\nYour payment could not be approved.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nPlease try again or use a different payment method.\n\nRegards,\n{site_name}', 'Reserved for denied payment states once live gateway callbacks are enabled.'),
  ('payment_refunded', 'Your payment has been refunded', 'Hi {username},\n\nA refund has been recorded for your payment.\n\nPackage: {package_name}\nAmount: {amount}\nGateway: {gateway}\n\nIf you have questions about this refund, please contact support.\n\nRegards,\n{site_name}', 'Reserved for refunded payment states once live gateway callbacks are enabled.'),
  ('new_device_login', 'New device sign-in detected on your account', 'Hi {username},\n\nWe detected a sign-in to your {site_name} account from a new device or browser.\n\nIP: {login_ip}\nTime: {login_time}\n\nIf this was you, no action is needed.\nIf this was not you, change your password immediately and review your security settings.\n\nRegards,\n{site_name}\n{support_email}', 'Sent when a user signs in from a browser or device token that has not been seen before.');

  CREATE TABLE `user_login_devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `device_token_hash` CHAR(64) NOT NULL,
    `user_agent_hash` CHAR(64) NOT NULL,
    `first_seen_ip` VARCHAR(45) NOT NULL,
    `last_seen_ip` VARCHAR(45) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_device_unique` (`user_id`, `device_token_hash`),
    KEY `user_last_seen_idx` (`user_id`, `last_seen_at`),
    CONSTRAINT `user_login_devices_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `server_monitoring_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `server_id` INT UNSIGNED NOT NULL,
  `status` ENUM('online', 'offline') NOT NULL,
  `response_time_ms` INT UNSIGNED DEFAULT 0,
  `error_message` TEXT NULL,
  `checked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `lookup_idx` (`server_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 10. Settings & Plugins
-- --------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(32) NOT NULL DEFAULT 'general',
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('enable_abuse_reports', '1', 'general'),
('require_account_to_download', '0', 'general'),
('blocked_download_countries', '', 'general'),
('track_current_downloads', '0', 'general'),
('remote_url_background', '0', 'general'),
('streaming_support_enabled', '0', 'downloads'),
('user_can_empty_trash', '1', 'general'),
('upload_concurrent', '0', 'uploads'),
('upload_concurrent_limit', '2', 'uploads'),
('upload_hide_popup', '0', 'uploads'),
('upload_append_filename', '1', 'uploads'),
('upload_chunking_enabled', '1', 'uploads'),
('upload_chunk_size_mb', '100', 'uploads'),
('upload_login_required', '1', 'uploads'),
('upload_detect_duplicates', '1', 'uploads'),
('app.name', 'fyuhls', 'general'),
('allow_registrations', '1', 'general'),
('require_email_verification', '1', 'general'),
('demo_mode', '0', 'general'),
('maintenance_mode', '0', 'general'),
  ('rewards_enabled', '0', 'rewards'),
  ('affiliate_enabled', '0', 'rewards'),
  ('payment_stripe_enabled', '0', 'payments'),
  ('payment_stripe_publishable_key', '', 'payments'),
  ('payment_paypal_enabled', '0', 'payments'),
  ('payment_paypal_client_id', '', 'payments'),
  ('payment_paypal_webhook_id', '', 'payments'),
  ('payment_paypal_sandbox', '1', 'payments'),
  ('enabled_models', 'ppd,pps,mixed', 'rewards'),
('global_model_status', 'enabled', 'rewards'),
('ppd_rate_per_1000', '1.00', 'rewards'),
('pps_commission_percent', '50', 'rewards'),
('mixed_ppd_percent', '30', 'rewards'),
('mixed_pps_percent', '30', 'rewards'),
('ppd_min_file_size', '1048576', 'rewards'),
('ppd_max_file_size', '0', 'rewards'),
('ppd_ip_reward_limit', '1', 'rewards'),
('ppd_min_download_percent', '0', 'rewards'),
('ppd_max_earn_ip', '0', 'rewards'),
('ppd_max_earn_file', '0', 'rewards'),
('ppd_max_earn_user', '0', 'rewards'),
('ppd_only_guests_count', '0', 'rewards'),
('ppd_reward_vpn', '0', 'rewards'),
('rewards_retention_days', '7', 'rewards'),
('rewards_min_video_watch_percent', '80', 'rewards'),
('rewards_min_video_watch_seconds', '30', 'rewards'),
('rewards_fraud_enabled', '1', 'rewards_fraud'),
('rewards_verified_completion_required', '1', 'rewards_fraud'),
('rewards_auto_clear_low_risk', '0', 'rewards_fraud'),
('rewards_hold_days', '7', 'rewards_fraud'),
('rewards_review_threshold', '25', 'rewards_fraud'),
('rewards_flag_threshold', '50', 'rewards_fraud'),
('rewards_fraud_event_retention_days', '30', 'rewards_fraud'),
('rewards_fraud_trim_mb', '1024', 'rewards_fraud'),
('rewards_use_cloudflare_intel', '1', 'rewards_fraud'),
('rewards_use_proxy_intel', '0', 'rewards_fraud'),
('rewards_use_ip_hash', '1', 'rewards_fraud'),
('rewards_use_ua_hash', '1', 'rewards_fraud'),
('rewards_use_cookie_hash', '1', 'rewards_fraud'),
('rewards_use_accept_language_hash', '1', 'rewards_fraud'),
('rewards_use_timezone_offset', '1', 'rewards_fraud'),
('rewards_use_platform_screen', '1', 'rewards_fraud'),
('rewards_use_asn_network', '1', 'rewards_fraud'),
('rewards_ppd_guests_only', '0', 'rewards_fraud'),
('rewards_require_downloader_verification', '0', 'rewards_fraud'),
('rewards_min_downloader_account_age_days', '0', 'rewards_fraud'),
('rewards_block_linked_downloader_accounts', '0', 'rewards_fraud'),
('rewards_hold_new_account_downloads', '0', 'rewards_fraud'),
('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'),
('vpn_proxy_mode', 'enforcement', 'security'),
('two_factor_enabled', '0', 'security'),
('2fa_enabled', '0', 'security'),
('2fa_enforce_date', '', 'security');

CREATE TABLE `plugins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `directory` VARCHAR(100) NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `installed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `directory` (`directory`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. Security & Anti-Spoofing
-- --------------------------------------------------------
CREATE TABLE `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(50) NOT NULL,
  `identifier` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `action_identifier_created` (`action`, `identifier`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `download_limits` (
  `ip_address` VARCHAR(45) NOT NULL,
  `window_start` BIGINT UNSIGNED NOT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`ip_address`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trusted_proxies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_range` VARCHAR(64) NOT NULL,
  `proxy_type` ENUM('cloudflare', 'custom') NOT NULL DEFAULT 'cloudflare',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_range` (`ip_range`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `trusted_proxies` (`ip_range`) VALUES 
('103.21.244.0/22'), ('103.22.200.0/22'), ('103.31.4.0/22'), ('104.16.0.0/13'), ('104.24.0.0/14'), ('108.162.192.0/18'), ('131.0.72.0/22'), ('141.101.64.0/18'), ('162.158.0.0/15'), ('172.64.0.0/13'), ('173.245.48.0/20'), ('188.114.96.0/20'), ('190.93.240.0/20'), ('197.234.240.0/22'), ('198.41.128.0/17'), 
('2400:cb00::/32'), ('2606:4700::/32'), ('2803:f800::/32'), ('2405:b500::/32'), ('2405:8100::/32'), ('2a06:98c0::/29'), ('2c0f:f248::/32');

-- --------------------------------------------------------
-- 12. Automation & Task Scheduling
-- --------------------------------------------------------
CREATE TABLE `cron_tasks` (
  `task_key` VARCHAR(50) NOT NULL,
  `task_name` VARCHAR(100) NOT NULL,
  `plugin_dir` VARCHAR(100) NULL,
  `interval_mins` INT UNSIGNED NOT NULL DEFAULT 15,
  `last_run_at` TIMESTAMP NULL,
  `last_status` ENUM('success', 'failed', 'skipped') NOT NULL DEFAULT 'skipped',
  `last_error` TEXT NULL,
  `execution_time` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`task_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cron_tasks` (`task_key`, `task_name`, `interval_mins`) VALUES 
  ('cleanup', 'File & Cache Cleanup', 15),
  ('cf_sync', 'Cloudflare IP Sync', 1440),
  ('rl_purge', 'Rate Limit Log Purge', 1440),
  ('account_downgrade', 'Premium Expiry & Downgrade', 60),
  ('server_monitoring', 'Storage Node Health Check', 60),
  ('mail_queue', 'Background Email Worker', 1),
  ('reward_flush', 'Async Rewards', 1),
  ('reward_rollup', 'Rewards Data Rollup', 1440),
  ('fraud_scores', 'Rewards Fraud Score Refresh', 15),
  ('fraud_clearance', 'Rewards Hold Clearance', 15),
  ('fraud_cleanup', 'Rewards Fraud Log Cleanup', 1440);

-- Final Security Config
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('cloudflare_last_sync', '0', 'security'),
('trust_cloudflare', '1', 'security'),
('rate_limit_login', '5', 'security'),
('rate_limit_registration', '5', 'security'),
('last_cron_run_timestamp', '0', 'system');

-- --------------------------------------------------------
-- 13. Security Cache (Managed by SecurityService)
-- --------------------------------------------------------
CREATE TABLE `security_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(255) NOT NULL /* Encrypted */,
  `is_vpn` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `ip_lookup` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 14. Rewards Tables
-- --------------------------------------------------------
CREATE TABLE `reward_receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `downloader_user_id` BIGINT UNSIGNED NULL,
  `ip_address` TEXT NOT NULL /* Encrypted */,
  `ip_hash` VARCHAR(64) NOT NULL,
  `ua_hash` VARCHAR(64) NULL,
  `visitor_cookie_hash` VARCHAR(64) NULL,
  `accept_language_hash` VARCHAR(64) NULL,
  `timezone_offset` SMALLINT NULL,
  `platform_bucket` VARCHAR(64) NULL,
  `screen_bucket` VARCHAR(32) NULL,
  `asn` VARCHAR(64) NULL,
  `network_type` VARCHAR(32) NULL,
  `country_code` CHAR(2) NULL,
  `risk_score` INT NOT NULL DEFAULT 0,
  `risk_level` VARCHAR(16) NULL,
  `risk_reasons_json` JSON NULL,
  `proof_status` VARCHAR(32) NULL,
  `status` ENUM('pending', 'flagged', 'processed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `status_idx` (`status`),
  INDEX `receipt_guard_idx` (`user_id`, `file_id`, `ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `earnings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,4) NOT NULL,
  `type` ENUM('download_reward', 'referral', 'bonus', 'withdrawal', 'aggregate_summary') NOT NULL,
  `status` ENUM('held', 'flagged_review', 'cleared', 'reversed', 'paid', 'cancelled') NOT NULL DEFAULT 'held',
  `file_id` BIGINT UNSIGNED NULL,
  `session_id` BIGINT UNSIGNED NULL,
  `ip_hash` VARCHAR(64) NULL,
  `risk_score` INT NOT NULL DEFAULT 0,
  `risk_reasons_json` JSON NULL,
  `hold_until` TIMESTAMP NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `reviewed_at` TIMESTAMP NULL,
  `review_note` TEXT NULL,
  `country_code` CHAR(2) NULL,
  `network_type` VARCHAR(32) NULL,
  `asn` VARCHAR(64) NULL,
  `description` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `earnings_user_date` (`user_id`, `created_at`),
  INDEX `earnings_guard_idx` (`user_id`, `file_id`, `ip_hash`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `method` ENUM('paypal', 'stripe', 'bitcoin', 'wire') NOT NULL,
  `details` TEXT NOT NULL /* Encrypted */,
  `status` ENUM('pending', 'approved', 'paid', 'rejected') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT NULL /* Encrypted */,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ppd_tiers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `rate_per_1000` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ppd_tier_countries` (
  `tier_id` INT UNSIGNED NOT NULL,
  `country_code` CHAR(2) NOT NULL,
  PRIMARY KEY (`tier_id`, `country_code`),
  FOREIGN KEY (`tier_id`) REFERENCES `ppd_tiers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stats_daily` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
  `earnings` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_day` (`user_id`, `day`),
  INDEX `day` (`day`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 15. Two-Factor Authentication
-- --------------------------------------------------------
CREATE TABLE `user_two_factor` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `secret_key` TEXT NOT NULL /* Encrypted */,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_codes` TEXT NULL /* Encrypted */,
  PRIMARY KEY (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_two_factor_devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `trust_token` VARCHAR(64) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `trust_lookup` (`user_id`, `trust_token`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
