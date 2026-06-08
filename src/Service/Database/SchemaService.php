<?php

namespace App\Service\Database;

use App\Core\Database;
use Exception;
use PDO;

/**
 * SchemaService - Master Database Blueprint
 *
 * Futureproofed for 1M+ rows using deterministic indexes, partitioned audit trails,
 * and high-concurrency cron locking.
 */
class SchemaService
{
    const SCHEMA_VERSION = '2.9.1';
    private const SCHEMA_SYNC_LOCK_NAME = 'fyuhls_schema_sync';
    private const SCHEMA_REPAIR_CHECKPOINT_KEY = 'schema_repair_checkpoint';
    private static int $explicitRepairDepth = 0;

    private $db;
    private array $logs = [];
    private bool $driftDetected = false;
    private array $structuralErrors = [];

    public function __construct($db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    private function getPdo(): PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }

        if ($this->db instanceof Database) {
            $pdo = $this->db->getConnection();
            if ($pdo instanceof PDO) {
                return $pdo;
            }

            throw new Exception('Database connection is not initialized.');
        }

        if (is_object($this->db) && method_exists($this->db, 'getConnection')) {
            $pdo = $this->db->getConnection();
            if ($pdo instanceof PDO) {
                return $pdo;
            }

            throw new Exception('Database connection is not initialized.');
        }

        throw new Exception('Invalid database adapter supplied for schema sync.');
    }

    /**
     * Get the master schema definition.
     */
    public static function getMasterSchema(array $plugins = [], bool $includePluginTables = true): array
    {
        // 1. Core Schema
        $schema = [
            'packages' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'name' => "VARCHAR(50) NOT NULL",
                    'price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'subscription_term_days' => "INT UNSIGNED NOT NULL DEFAULT 30",
                    'renewal_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'level_type' => "ENUM('guest', 'free', 'paid', 'admin') NOT NULL",
                    'max_storage_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'max_upload_size' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'max_daily_downloads' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'download_speed' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'wait_time' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'wait_time_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'concurrent_uploads' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'concurrent_downloads' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'accepted_file_types' => "TEXT NULL",
                    'show_ads' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'file_expiry_days' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'allow_direct_links' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'allow_remote_upload' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'ppd_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'ppd_rate_per_1000' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'pps_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'pps_commission_percent' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'block_adblock' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'block_vpn' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                ],
                'primary' => 'id'
            ],
            'users' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(16) NOT NULL",
                    'username' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'username_lookup' => "CHAR(64) NULL",
                    'email' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'email_lookup' => "CHAR(64) NULL",
                    'pending_email' => "VARCHAR(255) NULL /* Encrypted */",
                    'pending_email_lookup' => "CHAR(64) NULL",
                    'password' => "VARCHAR(255) NOT NULL",
                    'session_version' => "BIGINT UNSIGNED NOT NULL DEFAULT 1",
                    'role' => "ENUM('guest', 'user', 'moderator', 'admin') NOT NULL DEFAULT 'user'",
                    'is_super_admin' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'package_id' => "INT UNSIGNED NOT NULL DEFAULT 2",
                    'referrer_id' => "BIGINT UNSIGNED NULL",
                    'referrer_source' => "VARCHAR(32) NULL",
                    'status' => "ENUM('active', 'banned', 'pending') NOT NULL DEFAULT 'active'",
                    'email_verified' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
                    'verification_token' => "VARCHAR(255) NULL",
                    'verification_expires' => "DATETIME NULL",
                    'pending_email' => "VARCHAR(255) NULL /* Encrypted */",
                    'pending_email_lookup' => "CHAR(64) NULL",
                    'email_change_token' => "VARCHAR(255) NULL",
                    'email_change_expires' => "DATETIME NULL",
                    'reset_token' => "VARCHAR(255) NULL",
                    'reset_expires' => "DATETIME NULL",
                    'storage_used' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'storage_warning_threshold' => "INT UNSIGNED NOT NULL DEFAULT 75",
                    'storage_warning_sent' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'premium_expiry' => "DATETIME NULL DEFAULT NULL",
                    'premium_started_at' => "DATETIME NULL DEFAULT NULL",
                    'api_key' => "VARCHAR(255) NULL /* Encrypted */",
                    'default_privacy' => "ENUM('public', 'private') NOT NULL DEFAULT 'public'",
                    'timezone' => "VARCHAR(100) NOT NULL DEFAULT 'UTC'",
                    'language' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
                    'payment_method' => "VARCHAR(100) NULL",
                    'payment_details' => "TEXT NULL /* Encrypted */",
                    'monetization_model' => "ENUM('ppd', 'pps', 'mixed') NOT NULL DEFAULT 'ppd'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'public_id' => "UNIQUE INDEX public_id (public_id)",
                    'username_lookup_idx' => "INDEX username_lookup_idx (username_lookup)",
                    'email_lookup_idx' => "INDEX email_lookup_idx (email_lookup)",
                    'pending_email_lookup_idx' => "INDEX pending_email_lookup_idx (pending_email_lookup)",
                    'username_idx' => "UNIQUE INDEX username_idx (username)",
                    'email_idx' => "UNIQUE INDEX email_idx (email)",
                    'status_idx' => "INDEX status_idx (status)",
                    'email_change_token_idx' => "INDEX email_change_token_idx (email_change_token)"
                ],
                'foreign_keys' => [
                    'users_package_fk' => "FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`)",
                    'users_referrer_fk' => "FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'file_servers' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'name' => "VARCHAR(100) NOT NULL",
                    'server_type' => "ENUM('local', 's3', 'wasabi', 'backblaze', 'b2', 'r2') NOT NULL DEFAULT 'local'",
                    'status' => "ENUM('active', 'disabled', 'read-only') NOT NULL DEFAULT 'active'",
                    'storage_path' => "VARCHAR(255) NULL /* Encrypted */",
                    'public_url' => "VARCHAR(255) NULL",
                    'config' => "TEXT NULL /* Encrypted JSON */",
                    'max_capacity_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'current_usage_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'delivery_method' => "ENUM('php', 'nginx', 'apache', 'litespeed') NOT NULL DEFAULT 'php'",
                    'is_default' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id'
            ],
            'stored_files' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'file_server_id' => "INT UNSIGNED NULL",
                    'file_hash' => "CHAR(64) NOT NULL",
                    'storage_provider' => "VARCHAR(50) NOT NULL DEFAULT 'local'",
                    'storage_path' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'file_size' => "BIGINT UNSIGNED NOT NULL",
                    'mime_type' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'provider_etag' => "VARCHAR(255) NULL",
                    'checksum_verified_at' => "DATETIME NULL",
                    'ref_count' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'file_hash_idx' => "INDEX file_hash_idx (file_hash)",
                    'file_hash_size_idx' => "INDEX file_hash_size_idx (file_hash, file_size)",
                    'file_hash_size_reuse_idx' => "INDEX file_hash_size_reuse_idx (file_hash, file_size, checksum_verified_at, ref_count, id)"
                ],
                'foreign_keys' => [
                    'sf_server_fk' => "FOREIGN KEY (`file_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL"
                ]
            ],
            'folders' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'short_id' => "VARCHAR(12) NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'parent_id' => "BIGINT UNSIGNED NULL",
                    'name' => "VARCHAR(191) NOT NULL /* Encrypted */",
                    'status' => "ENUM('active', 'deleted') NOT NULL DEFAULT 'active'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'short_id' => "UNIQUE INDEX short_id (short_id)",
                    'folders_hierarchy_idx' => "INDEX folders_hierarchy_idx (user_id, parent_id)"
                ],
                'foreign_keys' => [
                    'folders_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'folders_parent_fk' => "FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE"
                ]
            ],
            'files' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'short_id' => "VARCHAR(12) NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'stored_file_id' => "BIGINT UNSIGNED NOT NULL",
                    'folder_id' => "BIGINT UNSIGNED NULL",
                    'filename' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'is_public' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'allow_ppd' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'password' => "VARCHAR(255) NULL",
                    'downloads' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'last_download_at' => "DATETIME NULL",
                    'delete_at' => "DATETIME NULL",
                    'status' => "ENUM('uploading', 'processing', 'ready', 'active', 'deleted', 'hidden', 'pending_purge', 'failed', 'abandoned', 'quarantined') NOT NULL DEFAULT 'active'",
                    'creation_origin' => "VARCHAR(32) NOT NULL DEFAULT 'upload'",
                    'deleted_restore_status' => "VARCHAR(32) NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'short_id' => "UNIQUE INDEX short_id (short_id)",
                    'status_idx' => "INDEX status_idx (status)",
                    'filename_idx' => "INDEX filename_idx (filename)",
                    'files_dashboard_idx' => "INDEX files_dashboard_idx (user_id, folder_id, status)",
                    'files_user_origin_status_idx' => "INDEX files_user_origin_status_idx (user_id, creation_origin, status)",
                    'files_stored_status_idx' => "INDEX files_stored_status_idx (stored_file_id, status)"
                ],
                'foreign_keys' => [
                    'files_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'files_stored_fk' => "FOREIGN KEY (`stored_file_id`) REFERENCES `stored_files`(`id`) ON DELETE RESTRICT",
                    'files_folder_fk' => "FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL"
                ]
            ],
            'cron_tasks' => [
                'columns' => [
                    'task_key' => "VARCHAR(50) NOT NULL",
                    'task_name' => "VARCHAR(100) NOT NULL",
                    'plugin_dir' => "VARCHAR(100) NULL",
                    'interval_mins' => "INT UNSIGNED NOT NULL DEFAULT 60",
                    'last_run_at' => "TIMESTAMP NULL",
                    'locked_at' => "TIMESTAMP NULL",
                    'last_status' => "ENUM('success', 'failed', 'skipped') NOT NULL DEFAULT 'skipped'",
                    'last_error' => "TEXT NULL",
                    'execution_time' => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
                ],
                'primary' => 'task_key'
            ],
            'settings' => [
                'columns' => [
                    'setting_key' => "VARCHAR(64) NOT NULL",
                    'setting_value' => "TEXT NULL",
                    'setting_group' => "VARCHAR(32) NOT NULL DEFAULT 'general'",
                    'is_system' => "TINYINT(1) NOT NULL DEFAULT 0"
                ],
                'primary' => 'setting_key'
            ],
            'upload_sessions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(32) NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'guest_session_id' => "VARCHAR(128) NULL",
                    'folder_id' => "BIGINT UNSIGNED NULL",
                    'storage_server_id' => "INT UNSIGNED NULL",
                    'storage_provider' => "VARCHAR(50) NOT NULL DEFAULT 'local'",
                    'original_filename' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'object_key' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'expected_size' => "BIGINT UNSIGNED NOT NULL",
                    'mime_hint' => "VARCHAR(255) NULL /* Encrypted */",
                    'checksum_sha256' => "CHAR(64) NULL",
                    'multipart_upload_id' => "VARCHAR(512) NULL",
                    'status' => "ENUM('pending', 'uploading', 'completing', 'processing', 'completed', 'failed', 'aborted', 'expired') NOT NULL DEFAULT 'pending'",
                    'reserved_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'uploaded_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'completed_parts' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'part_size_bytes' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'metadata_json' => "LONGTEXT NULL",
                    'error_message' => "TEXT NULL",
                    'expires_at' => "DATETIME NULL",
                    'completed_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'upload_sessions_public_id' => "UNIQUE INDEX upload_sessions_public_id (public_id)",
                    'upload_sessions_user_status' => "INDEX upload_sessions_user_status (user_id, status)",
                    'upload_sessions_guest_status' => "INDEX upload_sessions_guest_status (guest_session_id, status)",
                    'upload_sessions_expiry' => "INDEX upload_sessions_expiry (expires_at)",
                    'upload_sessions_status_expiry' => "INDEX upload_sessions_status_expiry (status, expires_at)",
                    'upload_sessions_completed_checksum' => "INDEX upload_sessions_completed_checksum (status, checksum_sha256, completed_at, id)"
                ],
                'foreign_keys' => [
                    'upload_sessions_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'upload_sessions_folder_fk' => "FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL",
                    'upload_sessions_server_fk' => "FOREIGN KEY (`storage_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL"
                ]
            ],
            'upload_session_parts' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'upload_session_id' => "BIGINT UNSIGNED NOT NULL",
                    'part_number' => "INT UNSIGNED NOT NULL",
                    'etag' => "VARCHAR(255) NULL",
                    'part_size' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'checksum_sha256' => "CHAR(64) NULL",
                    'status' => "ENUM('signed', 'uploaded', 'verified', 'failed') NOT NULL DEFAULT 'signed'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'upload_session_part_unique' => "UNIQUE INDEX upload_session_part_unique (upload_session_id, part_number)",
                    'upload_session_part_status' => "INDEX upload_session_part_status (upload_session_id, status)",
                    'upload_session_part_completion' => "INDEX upload_session_part_completion (upload_session_id, status, part_number)"
                ],
                'foreign_keys' => [
                    'upload_session_parts_session_fk' => "FOREIGN KEY (`upload_session_id`) REFERENCES `upload_sessions`(`id`) ON DELETE CASCADE"
                ]
            ],
            'quota_reservations' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(32) NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'upload_session_id' => "BIGINT UNSIGNED NULL",
                    'storage_server_id' => "INT UNSIGNED NULL",
                    'reserved_bytes' => "BIGINT UNSIGNED NOT NULL",
                    'status' => "ENUM('active', 'committed', 'released', 'expired') NOT NULL DEFAULT 'active'",
                    'expires_at' => "DATETIME NULL",
                    'released_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'quota_reservations_public_id' => "UNIQUE INDEX quota_reservations_public_id (public_id)",
                    'quota_reservations_user_status' => "INDEX quota_reservations_user_status (user_id, status)",
                    'quota_reservations_expiry' => "INDEX quota_reservations_expiry (expires_at)"
                ],
                'foreign_keys' => [
                    'quota_reservations_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'quota_reservations_session_fk' => "FOREIGN KEY (`upload_session_id`) REFERENCES `upload_sessions`(`id`) ON DELETE SET NULL",
                    'quota_reservations_server_fk' => "FOREIGN KEY (`storage_server_id`) REFERENCES `file_servers`(`id`) ON DELETE SET NULL"
                ]
            ],
            'bonus_offers' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(16) NOT NULL",
                    'name' => "VARCHAR(191) NOT NULL",
                    'public_title' => "VARCHAR(191) NOT NULL",
                    'public_description' => "TEXT NULL",
                    'status' => "VARCHAR(32) NOT NULL DEFAULT 'draft'",
                    'offer_kind' => "VARCHAR(32) NOT NULL DEFAULT 'milestone'",
                    'metric_key' => "VARCHAR(64) NOT NULL",
                    'threshold_value' => "DECIMAL(14,4) NOT NULL DEFAULT 0.0000",
                    'threshold_unit' => "VARCHAR(32) NOT NULL DEFAULT 'count'",
                    'trigger_style' => "VARCHAR(32) NOT NULL DEFAULT 'once'",
                    'reward_type' => "VARCHAR(32) NOT NULL DEFAULT 'fixed'",
                    'reward_value' => "DECIMAL(14,4) NOT NULL DEFAULT 0.0000",
                    'schedule_mode' => "VARCHAR(32) NOT NULL DEFAULT 'always'",
                    'start_at' => "DATETIME NULL",
                    'end_at' => "DATETIME NULL",
                    'timezone' => "VARCHAR(64) NOT NULL DEFAULT 'UTC'",
                    'weekday_json' => "TEXT NULL",
                    'audience_type' => "VARCHAR(32) NOT NULL DEFAULT 'all_rewards'",
                    'audience_json' => "TEXT NULL",
                    'public_visibility' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'award_mode' => "VARCHAR(32) NOT NULL DEFAULT 'pending_review'",
                    'fraud_hold' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'count_cleared_only' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'notify_on_start' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'email_on_start' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'notify_on_earned' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'email_on_earned' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'announced_at' => "DATETIME NULL",
                    'created_by_user_id' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'bonus_offers_public_id' => "UNIQUE INDEX bonus_offers_public_id (public_id)",
                    'bonus_offers_status_idx' => "INDEX bonus_offers_status_idx (status, start_at, end_at)",
                    'bonus_offers_visibility_idx' => "INDEX bonus_offers_visibility_idx (public_visibility, status, start_at, end_at)"
                ]
            ],
            'bonus_offer_awards' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'offer_id' => "BIGINT UNSIGNED NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'award_key' => "VARCHAR(191) NOT NULL",
                    'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending_review'",
                    'amount' => "DECIMAL(14,4) NOT NULL DEFAULT 0.0000",
                    'progress_value' => "DECIMAL(14,4) NOT NULL DEFAULT 0.0000",
                    'threshold_value' => "DECIMAL(14,4) NOT NULL DEFAULT 0.0000",
                    'source_started_at' => "DATETIME NULL",
                    'source_ended_at' => "DATETIME NULL",
                    'earned_at' => "DATETIME NOT NULL",
                    'reviewed_at' => "DATETIME NULL",
                    'credited_at' => "DATETIME NULL",
                    'credited_earning_id' => "BIGINT UNSIGNED NULL",
                    'reviewed_by_user_id' => "BIGINT UNSIGNED NULL",
                    'note' => "TEXT NULL",
                    'metadata_json' => "LONGTEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'bonus_offer_awards_award_key' => "UNIQUE INDEX bonus_offer_awards_award_key (award_key)",
                    'bonus_offer_awards_user_status_idx' => "INDEX bonus_offer_awards_user_status_idx (user_id, status, earned_at)",
                    'bonus_offer_awards_offer_status_idx' => "INDEX bonus_offer_awards_offer_status_idx (offer_id, status, earned_at)"
                ]
            ],
            'bonus_offer_announcements' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'offer_id' => "BIGINT UNSIGNED NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'event_type' => "VARCHAR(32) NOT NULL",
                    'sent_at' => "DATETIME NOT NULL"
                ],
                'primary' => 'id',
                'indexes' => [
                    'bonus_offer_announcements_unique' => "UNIQUE INDEX bonus_offer_announcements_unique (offer_id, user_id, event_type)"
                ]
            ],
            'api_tokens' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(24) NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'name' => "VARCHAR(100) NOT NULL",
                    'token_prefix' => "VARCHAR(16) NOT NULL",
                    'token_last_four' => "VARCHAR(4) NOT NULL",
                    'token_hash' => "CHAR(64) NOT NULL",
                    'scopes_json' => "LONGTEXT NOT NULL",
                    'status' => "ENUM('active', 'revoked') NOT NULL DEFAULT 'active'",
                    'expires_at' => "DATETIME NULL",
                    'last_used_at' => "DATETIME NULL",
                    'last_used_ip' => "VARCHAR(255) NULL /* Encrypted */",
                    'revoked_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'api_tokens_public_id' => "UNIQUE INDEX api_tokens_public_id (public_id)",
                    'api_tokens_hash' => "UNIQUE INDEX api_tokens_hash (token_hash)",
                    'api_tokens_user_status' => "INDEX api_tokens_user_status (user_id, status)"
                ],
                'foreign_keys' => [
                    'api_tokens_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'remember_login_tokens' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'selector' => "VARCHAR(32) NOT NULL",
                    'validator_hash' => "CHAR(64) NOT NULL",
                    'user_agent_hash' => "CHAR(64) NOT NULL",
                    'last_used_ip' => "VARCHAR(255) NULL /* Encrypted */",
                    'user_agent' => "TEXT NULL /* Encrypted */",
                    'expires_at' => "DATETIME NOT NULL",
                    'last_used_at' => "DATETIME NULL",
                    'revoked_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'remember_login_selector' => "UNIQUE INDEX remember_login_selector (selector)",
                    'remember_login_user_status' => "INDEX remember_login_user_status (user_id, revoked_at, expires_at)"
                ],
                'foreign_keys' => [
                    'remember_login_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'api_idempotency_keys' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'idem_key' => "VARCHAR(128) NOT NULL",
                    'endpoint' => "VARCHAR(80) NOT NULL",
                    'actor_key' => "VARCHAR(96) NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'api_token_id' => "BIGINT UNSIGNED NULL",
                    'request_hash' => "CHAR(64) NOT NULL",
                    'status' => "ENUM('pending', 'completed') NOT NULL DEFAULT 'pending'",
                    'response_code' => "SMALLINT UNSIGNED NULL",
                    'response_json' => "LONGTEXT NULL",
                    'completed_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'api_idem_lookup' => "UNIQUE INDEX api_idem_lookup (idem_key, endpoint, actor_key)",
                    'api_idem_created' => "INDEX api_idem_created (created_at)"
                ]
            ],
            'user_login_devices' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'device_token_hash' => "CHAR(64) NOT NULL",
                    'user_agent_hash' => "CHAR(64) NOT NULL",
                    'first_seen_ip' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'last_seen_ip' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'last_seen_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'user_device_unique' => "UNIQUE INDEX user_device_unique (user_id, device_token_hash)",
                    'user_last_seen_idx' => "INDEX user_last_seen_idx (user_id, last_seen_at)"
                ],
                'foreign_keys' => [
                    'user_login_devices_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'coupons' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'code' => "VARCHAR(64) NOT NULL",
                    'internal_label' => "VARCHAR(150) NOT NULL",
                    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'starts_at' => "DATETIME NULL",
                    'expires_at' => "DATETIME NULL",
                    'discount_type' => "ENUM('amount', 'percent') NOT NULL DEFAULT 'amount'",
                    'discount_value' => "DECIMAL(10,2) NOT NULL",
                    'percent_cap_amount' => "DECIMAL(10,2) NULL",
                    'applies_to_all_paid' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'eligible_package_ids' => "LONGTEXT NULL",
                    'eligible_billing_option_ids' => "LONGTEXT NULL",
                    'purchase_scope' => "ENUM('new_only', 'renewal_only', 'both') NOT NULL DEFAULT 'both'",
                    'new_account_rule' => "ENUM('first_subscription', 'first_paid_subscription') NOT NULL DEFAULT 'first_paid_subscription'",
                    'renewal_rule' => "ENUM('active_only', 'active_or_returning') NOT NULL DEFAULT 'active_or_returning'",
                    'duration_type' => "ENUM('once', 'cycles', 'forever') NOT NULL DEFAULT 'once'",
                    'duration_cycles' => "INT UNSIGNED NULL",
                    'total_redemption_limit' => "INT UNSIGNED NULL",
                    'per_user_redemption_limit' => "INT UNSIGNED NULL",
                    'notes' => "TEXT NULL",
                    'created_by' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'coupons_code_unique' => "UNIQUE INDEX coupons_code_unique (code)",
                    'coupons_active_window_idx' => "INDEX coupons_active_window_idx (is_active, starts_at, expires_at)"
                ],
                'foreign_keys' => [
                    'coupons_created_by_fk' => "FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'transactions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'package_id' => "INT UNSIGNED NOT NULL",
                    'package_billing_option_id' => "BIGINT UNSIGNED NULL",
                    'coupon_id' => "BIGINT UNSIGNED NULL",
                    'coupon_code' => "VARCHAR(64) NULL",
                    'original_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'discount_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'amount' => "DECIMAL(10,2) NOT NULL",
                    'currency' => "VARCHAR(3) NOT NULL DEFAULT 'USD'",
                    'term_days' => "INT UNSIGNED NOT NULL DEFAULT 30",
                    'auto_renew' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'gateway' => "VARCHAR(50) NOT NULL",
                    'gateway_reference' => "VARCHAR(191) NULL",
                    'provider_subscription_id' => "VARCHAR(191) NULL",
                    'status' => "ENUM('pending', 'completed', 'failed', 'refunded', 'on_hold', 'denied') NOT NULL DEFAULT 'pending'",
                    'ip_address' => "VARCHAR(255) NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'transactions_gateway_reference_idx' => "INDEX transactions_gateway_reference_idx (gateway_reference)",
                    'transactions_provider_subscription_idx' => "INDEX transactions_provider_subscription_idx (provider_subscription_id)",
                    'transactions_billing_option_idx' => "INDEX transactions_billing_option_idx (package_billing_option_id)",
                    'transactions_status_created_idx' => "INDEX transactions_status_created_idx (status, created_at)",
                    'transactions_coupon_idx' => "INDEX transactions_coupon_idx (coupon_id)"
                ],
                'foreign_keys' => [
                    'transactions_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'transactions_package_fk' => "FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE",
                    'transactions_billing_option_fk' => "FOREIGN KEY (`package_billing_option_id`) REFERENCES `package_billing_options`(`id`) ON DELETE SET NULL",
                    'transactions_coupon_fk' => "FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL"
                ]
            ],
            'subscriptions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'package_id' => "INT UNSIGNED NOT NULL",
                    'package_billing_option_id' => "BIGINT UNSIGNED NULL",
                    'coupon_id' => "BIGINT UNSIGNED NULL",
                    'coupon_code' => "VARCHAR(64) NULL",
                    'original_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'discount_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'status' => "ENUM('active', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending'",
                    'amount' => "DECIMAL(10,2) NOT NULL",
                    'currency' => "VARCHAR(3) NOT NULL DEFAULT 'USD'",
                    'term_days' => "INT UNSIGNED NOT NULL DEFAULT 30",
                    'auto_renew' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'billing_period' => "ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly'",
                    'gateway' => "VARCHAR(50) NOT NULL",
                    'gateway_reference' => "VARCHAR(191) NULL",
                    'provider_subscription_id' => "VARCHAR(191) NULL",
                    'expires_at' => "DATETIME NOT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'subscriptions_user_status_idx' => "INDEX subscriptions_user_status_idx (user_id, status)",
                    'subscriptions_gateway_reference_idx' => "INDEX subscriptions_gateway_reference_idx (gateway_reference)",
                    'subscriptions_provider_subscription_idx' => "INDEX subscriptions_provider_subscription_idx (provider_subscription_id)",
                    'subscriptions_billing_option_idx' => "INDEX subscriptions_billing_option_idx (package_billing_option_id)",
                    'subscriptions_coupon_idx' => "INDEX subscriptions_coupon_idx (coupon_id)"
                ],
                'foreign_keys' => [
                    'subscriptions_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'subscriptions_package_fk' => "FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE",
                    'subscriptions_billing_option_fk' => "FOREIGN KEY (`package_billing_option_id`) REFERENCES `package_billing_options`(`id`) ON DELETE SET NULL",
                    'subscriptions_coupon_fk' => "FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL"
                ]
            ],
            'subscription_reminder_dispatches' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'reminder_type' => "VARCHAR(64) NOT NULL",
                    'target_expiry_date' => "DATE NOT NULL",
                    'sent_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'subscription_reminder_unique' => "UNIQUE INDEX subscription_reminder_unique (user_id, reminder_type, target_expiry_date)",
                    'subscription_reminder_lookup' => "INDEX subscription_reminder_lookup (reminder_type, target_expiry_date)"
                ],
                'foreign_keys' => [
                    'subscription_reminder_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'coupon_redemptions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'coupon_id' => "BIGINT UNSIGNED NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'package_id' => "INT UNSIGNED NOT NULL",
                    'transaction_id' => "BIGINT UNSIGNED NULL",
                    'subscription_id' => "BIGINT UNSIGNED NULL",
                    'coupon_code' => "VARCHAR(64) NOT NULL",
                    'purchase_kind' => "ENUM('new', 'renewal') NOT NULL",
                    'status' => "ENUM('reserved', 'redeemed', 'released', 'refunded') NOT NULL DEFAULT 'reserved'",
                    'discount_type' => "ENUM('amount', 'percent') NOT NULL",
                    'discount_value' => "DECIMAL(10,2) NOT NULL",
                    'discount_amount' => "DECIMAL(10,2) NOT NULL",
                    'currency' => "VARCHAR(3) NOT NULL DEFAULT 'USD'",
                    'redemption_sequence' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'redeemed_at' => "DATETIME NULL",
                    'released_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'coupon_redemptions_coupon_status_idx' => "INDEX coupon_redemptions_coupon_status_idx (coupon_id, status)",
                    'coupon_redemptions_user_coupon_status_idx' => "INDEX coupon_redemptions_user_coupon_status_idx (user_id, coupon_id, status)",
                    'coupon_redemptions_transaction_unique' => "UNIQUE INDEX coupon_redemptions_transaction_unique (transaction_id)",
                    'coupon_redemptions_subscription_idx' => "INDEX coupon_redemptions_subscription_idx (subscription_id)"
                ],
                'foreign_keys' => [
                    'coupon_redemptions_coupon_fk' => "FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE CASCADE",
                    'coupon_redemptions_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'coupon_redemptions_package_fk' => "FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE",
                    'coupon_redemptions_transaction_fk' => "FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL",
                    'coupon_redemptions_subscription_fk' => "FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE SET NULL"
                ]
            ],
            'payment_webhook_events' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'gateway' => "VARCHAR(50) NOT NULL",
                    'event_id' => "VARCHAR(191) NOT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'payment_webhook_gateway_event' => "UNIQUE INDEX payment_webhook_gateway_event (gateway, event_id)"
                ]
            ],
            'payment_gateway_sync_jobs' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'dedupe_key' => "VARCHAR(191) NOT NULL",
                    'gateway' => "VARCHAR(50) NOT NULL",
                    'action' => "VARCHAR(64) NOT NULL",
                    'provider_subscription_id' => "VARCHAR(191) NOT NULL",
                    'subscription_id' => "BIGINT UNSIGNED NULL",
                    'payload_json' => "LONGTEXT NULL",
                    'status' => "ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending'",
                    'attempt_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'last_error' => "TEXT NULL",
                    'available_at' => "DATETIME NULL",
                    'processed_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'payment_gateway_sync_jobs_dedupe_key' => "UNIQUE INDEX payment_gateway_sync_jobs_dedupe_key (dedupe_key)",
                    'payment_gateway_sync_jobs_status_idx' => "INDEX payment_gateway_sync_jobs_status_idx (status, available_at, id)",
                    'payment_gateway_sync_jobs_subscription_idx' => "INDEX payment_gateway_sync_jobs_subscription_idx (subscription_id)"
                ],
                'foreign_keys' => [
                    'payment_gateway_sync_jobs_subscription_fk' => "FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE SET NULL"
                ]
            ],
            'staff_permissions' => [
                'columns' => [
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'capability' => "VARCHAR(64) NOT NULL",
                    'is_allowed' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'updated_by' => "BIGINT UNSIGNED NULL",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => ['user_id', 'capability'],
                'indexes' => [
                    'staff_permissions_updated_by_idx' => "INDEX staff_permissions_updated_by_idx (updated_by)"
                ],
                'foreign_keys' => [
                    'staff_permissions_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'staff_permissions_updated_by_fk' => "FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'admin_activity_log' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'admin_id' => "BIGINT UNSIGNED NOT NULL",
                    'actor_role' => "VARCHAR(32) NULL",
                    'action' => "VARCHAR(100) NOT NULL",
                    'item_type' => "VARCHAR(50) NULL",
                    'item_id' => "BIGINT UNSIGNED NULL",
                    'target_user_id' => "BIGINT UNSIGNED NULL",
                    'details' => "TEXT NULL /* Encrypted */",
                    'metadata_json' => "TEXT NULL",
                    'ip_address' => "VARCHAR(255) NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'admin_id' => "INDEX (admin_id)",
                    'created_at' => "INDEX (created_at)"
                ]
            ],
            'active_downloads' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'file_id' => "BIGINT UNSIGNED NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'session_id' => "BIGINT UNSIGNED NULL",
                    'ip_address' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'ip_hash' => "VARCHAR(64) NULL",
                    'ua_hash' => "VARCHAR(64) NULL",
                    'visitor_cookie_hash' => "VARCHAR(64) NULL",
                    'accept_language_hash' => "VARCHAR(64) NULL",
                    'timezone_offset' => "SMALLINT NULL",
                    'platform_bucket' => "VARCHAR(64) NULL",
                    'screen_bucket' => "VARCHAR(32) NULL",
                    'asn' => "VARCHAR(64) NULL",
                    'network_type' => "VARCHAR(32) NULL",
                    'country_code' => "CHAR(2) NULL",
                    'bytes_sent' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'started_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'last_ping_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'ip_address_idx' => "INDEX ip_address_idx (ip_address)",
                    'active_dl_user' => "INDEX active_dl_user (user_id)",
                    'active_dl_session' => "INDEX active_dl_session (session_id)"
                ],
                'foreign_keys' => [
                    'active_downloads_file_fk' => "FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE"
                ]
            ],
            'abuse_reports' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'file_id' => "BIGINT UNSIGNED NOT NULL",
                    'reporter_ip' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'reason' => "ENUM('copyright', 'illegal', 'spam', 'other') NOT NULL",
                    'details' => "TEXT NULL /* Encrypted */",
                    'status' => "ENUM('pending', 'reviewed', 'action_taken', 'ignored') NOT NULL DEFAULT 'pending'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'abuse_reports_status_created_idx' => "INDEX abuse_reports_status_created_idx (status, created_at, id)"
                ],
                'foreign_keys' => [
                    'abuse_reports_file_fk' => "FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE"
                ]
            ],
            'user_activity_log' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'activity_type' => "VARCHAR(50) NOT NULL",
                    'description' => "TEXT NULL",
                    'ip_address' => "TEXT NULL /* Encrypted */",
                    'user_agent' => "TEXT NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'user_activity_user' => "INDEX user_activity_user (user_id)",
                    'user_activity_type' => "INDEX user_activity_type (activity_type)"
                ]
            ],
            'server_monitoring_log' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'server_id' => "INT UNSIGNED NOT NULL",
                    'status' => "ENUM('online', 'offline') NOT NULL",
                    'response_time_ms' => "INT UNSIGNED NULL",
                    'error_message' => "TEXT NULL",
                    'checked_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'server_id' => "INDEX (server_id)",
                    'checked_at' => "INDEX (checked_at)"
                ]
            ],
            'contact_messages' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'name' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'email' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'subject' => "TEXT NOT NULL /* Encrypted */",
                    'message' => "TEXT NOT NULL /* Encrypted */",
                    'status' => "ENUM('new', 'read', 'replied', 'archived') NOT NULL DEFAULT 'new'",
                    'ip_address' => "VARCHAR(255) NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'contact_messages_status_created_idx' => "INDEX contact_messages_status_created_idx (status, created_at, id)"
                ]
            ],
            'package_billing_options' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'package_id' => "INT UNSIGNED NOT NULL",
                    'option_label' => "VARCHAR(100) NULL",
                    'price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                    'term_days' => "INT UNSIGNED NOT NULL DEFAULT 30",
                    'renewal_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'display_order' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'package_billing_options_package_idx' => "INDEX package_billing_options_package_idx (package_id, is_active, display_order)"
                ],
                'foreign_keys' => [
                    'package_billing_options_package_fk' => "FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE"
                ]
            ],
            'notifications' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'category' => "VARCHAR(64) NOT NULL DEFAULT 'general'",
                    'event_key' => "VARCHAR(191) NULL",
                    'title' => "VARCHAR(191) NOT NULL",
                    'message' => "TEXT NOT NULL",
                    'action_url' => "VARCHAR(255) NULL",
                    'metadata_json' => "TEXT NULL",
                    'is_read' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'read_at' => "DATETIME NULL",
                    'type' => "ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'notifications_user_read_idx' => "INDEX notifications_user_read_idx (user_id, is_read, created_at)",
                    'notifications_event_key_idx' => "INDEX notifications_event_key_idx (event_key)"
                ],
                'foreign_keys' => [
                    'notifications_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'mail_queue' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'recipient' => "VARCHAR(255) NOT NULL",
                    'subject' => "VARCHAR(255) NOT NULL",
                    'body' => "TEXT NOT NULL",
                    'priority' => "ENUM('high', 'low') NOT NULL DEFAULT 'low'",
                    'status' => "ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending'",
                    'attempts' => "TINYINT UNSIGNED NOT NULL DEFAULT 0",
                    'last_error' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'sent_at' => "TIMESTAMP NULL"
                ],
                'primary' => 'id',
                'indexes' => [
                    'mail_queue_process_idx' => "INDEX mail_queue_process_idx (status, priority, created_at)"
                ]
            ],
            'support_tickets' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "VARCHAR(32) NOT NULL",
                    'ticket_type' => "VARCHAR(32) NOT NULL DEFAULT 'support'",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'status' => "ENUM('open','waiting_user','waiting_staff','closed') NOT NULL DEFAULT 'open'",
                    'priority' => "ENUM('normal','high') NOT NULL DEFAULT 'normal'",
                    'subject' => "TEXT NOT NULL /* Encrypted */",
                    'submitter_name' => "TEXT NULL /* Encrypted */",
                    'submitter_email' => "TEXT NULL /* Encrypted */",
                    'source' => "VARCHAR(32) NOT NULL DEFAULT 'account'",
                    'metadata_json' => "TEXT NULL /* Encrypted */",
                    'related_file_id' => "BIGINT UNSIGNED NULL",
                    'ip_address' => "VARCHAR(255) NULL /* Encrypted */",
                    'assigned_staff_user_id' => "BIGINT UNSIGNED NULL",
                    'hidden_from_others' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'hidden_by_admin_user_id' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    'last_reply_at' => "DATETIME NULL",
                    'last_user_reply_at' => "DATETIME NULL",
                    'last_staff_reply_at' => "DATETIME NULL",
                    'closed_at' => "DATETIME NULL",
                    'closed_by_user_id' => "BIGINT UNSIGNED NULL",
                    'closed_by_admin_id' => "BIGINT UNSIGNED NULL"
                ],
                'primary' => 'id',
                'indexes' => [
                    'support_tickets_public_id_unique' => "UNIQUE KEY support_tickets_public_id_unique (public_id)",
                    'support_tickets_user_status_idx' => "INDEX support_tickets_user_status_idx (user_id, status, updated_at)",
                    'support_tickets_type_status_updated_idx' => "INDEX support_tickets_type_status_updated_idx (ticket_type, status, updated_at)",
                    'support_tickets_status_updated_idx' => "INDEX support_tickets_status_updated_idx (status, updated_at)",
                    'support_tickets_priority_status_idx' => "INDEX support_tickets_priority_status_idx (priority, status, updated_at)",
                    'support_tickets_assigned_status_idx' => "INDEX support_tickets_assigned_status_idx (assigned_staff_user_id, status, updated_at)",
                    'support_tickets_hidden_status_idx' => "INDEX support_tickets_hidden_status_idx (hidden_from_others, status, updated_at)"
                ],
                'foreign_keys' => [
                    'support_tickets_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'support_tickets_assigned_staff_fk' => "FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL",
                    'support_tickets_hidden_by_admin_fk' => "FOREIGN KEY (`hidden_by_admin_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'support_ticket_messages' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'ticket_id' => "BIGINT UNSIGNED NOT NULL",
                    'author_type' => "ENUM('user','admin','system') NOT NULL",
                    'author_user_id' => "BIGINT UNSIGNED NULL",
                    'message_type' => "ENUM('intake','reply','note') NOT NULL DEFAULT 'reply'",
                    'body' => "TEXT NOT NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'support_ticket_messages_ticket_idx' => "INDEX support_ticket_messages_ticket_idx (ticket_id, created_at)",
                    'support_ticket_messages_author_idx' => "INDEX support_ticket_messages_author_idx (author_user_id, created_at)"
                ],
                'foreign_keys' => [
                    'support_ticket_messages_ticket_fk' => "FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE"
                ]
            ],
            'support_ticket_events' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'ticket_id' => "BIGINT UNSIGNED NOT NULL",
                    'event_type' => "VARCHAR(50) NOT NULL",
                    'actor_type' => "ENUM('user','admin','system') NOT NULL DEFAULT 'system'",
                    'actor_user_id' => "BIGINT UNSIGNED NULL",
                    'payload_json' => "TEXT NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'support_ticket_events_ticket_idx' => "INDEX support_ticket_events_ticket_idx (ticket_id, created_at)",
                    'support_ticket_events_type_idx' => "INDEX support_ticket_events_type_idx (event_type, created_at)"
                ],
                'foreign_keys' => [
                    'support_ticket_events_ticket_fk' => "FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE"
                ]
            ],
            'dmca_reports' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'reporter_name' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'reporter_email' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'infringing_url' => "TEXT NOT NULL /* Encrypted */",
                    'description' => "TEXT NOT NULL /* Encrypted */",
                    'signature' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'status' => "ENUM('pending', 'investigating', 'accepted', 'rejected') NOT NULL DEFAULT 'pending'",
                    'ip_address' => "VARCHAR(255) NULL /* Encrypted */",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'dmca_reports_status_created_idx' => "INDEX dmca_reports_status_created_idx (status, created_at, id)"
                ]
            ],
            'security_cache' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'ip_address' => "VARCHAR(255) NOT NULL /* HMAC lookup key */",
                    'is_vpn' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'proxy_intel_json' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'ip_lookup' => "INDEX ip_lookup (ip_address)"
                ]
            ],
            'download_limits' => [
                'columns' => [
                    'ip_address' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'window_start' => "BIGINT UNSIGNED NOT NULL",
                    'attempt_count' => "INT UNSIGNED NOT NULL DEFAULT 1"
                ],
                'primary' => ['ip_address', 'window_start']
            ],
            'download_bandwidth_usage' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'usage_date' => "DATE NOT NULL",
                    'actor_key' => "VARCHAR(96) NOT NULL",
                    'user_id' => "BIGINT UNSIGNED NULL",
                    'event_key' => "VARCHAR(80) NOT NULL",
                    'bytes_used' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'download_bandwidth_event_unique' => "UNIQUE INDEX download_bandwidth_event_unique (event_key)",
                    'download_bandwidth_actor_date_idx' => "INDEX download_bandwidth_actor_date_idx (actor_key, usage_date)",
                    'download_bandwidth_user_date_idx' => "INDEX download_bandwidth_user_date_idx (user_id, usage_date)"
                ],
                'foreign_keys' => [
                    'download_bandwidth_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'download_link_issues' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'file_short_id' => "VARCHAR(12) NOT NULL",
                    'token_hash' => "CHAR(64) NOT NULL",
                    'session_public_id' => "VARCHAR(32) NULL",
                    'issued_for_ip_hash' => "CHAR(64) NOT NULL",
                    'used_at' => "DATETIME NULL",
                    'expires_at' => "DATETIME NOT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'download_link_issues_token_hash' => "UNIQUE INDEX download_link_issues_token_hash (token_hash)",
                    'download_link_issues_file_expiry' => "INDEX download_link_issues_file_expiry (file_short_id, expires_at)",
                    'download_link_issues_session_idx' => "INDEX download_link_issues_session_idx (session_public_id)"
                ]
            ],
            'rate_limits' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'action' => "VARCHAR(50) NOT NULL",
                    'identifier' => "VARCHAR(128) NOT NULL",
                    'weight' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'action_identifier_created' => "INDEX action_identifier_created (action, identifier, created_at)"
                ]
            ],
            'trusted_proxies' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'ip_range' => "VARCHAR(64) NOT NULL",
                    'proxy_type' => "ENUM('cloudflare', 'custom') NOT NULL DEFAULT 'cloudflare'",
                    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'trusted_proxies_ip_range' => "UNIQUE INDEX trusted_proxies_ip_range (ip_range)"
                ]
            ],
            'system_stats' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL DEFAULT 1",
                    'total_files' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'total_users' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'total_storage_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'pending_withdrawals' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'pending_reports' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'last_updated' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id'
            ],
            'stats_history' => [
                'columns' => [
                    'date' => "DATE NOT NULL",
                    'uploads_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'downloads_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'active_users' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'revenue' => "DECIMAL(20,2) NOT NULL DEFAULT 0.00"
                ],
                'primary' => 'date'
            ],
            'reward_receipts' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'file_id' => "BIGINT UNSIGNED NOT NULL",
                    'session_id' => "BIGINT UNSIGNED NULL",
                    'source_event_key' => "VARCHAR(191) NULL",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'downloader_user_id' => "BIGINT UNSIGNED NULL",
                    'ip_address' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'ip_hash' => "CHAR(64) NOT NULL",
                    'ua_hash' => "VARCHAR(64) NULL",
                    'visitor_cookie_hash' => "VARCHAR(64) NULL",
                    'accept_language_hash' => "VARCHAR(64) NULL",
                    'timezone_offset' => "SMALLINT NULL",
                    'platform_bucket' => "VARCHAR(64) NULL",
                    'screen_bucket' => "VARCHAR(32) NULL",
                    'asn' => "VARCHAR(64) NULL",
                    'network_type' => "VARCHAR(32) NULL",
                    'country_code' => "CHAR(2) NULL",
                    'risk_score' => "INT NOT NULL DEFAULT 0",
                    'risk_level' => "VARCHAR(16) NULL",
                    'risk_reasons_json' => "JSON NULL",
                    'proxy_intel_risk_score' => "INT NOT NULL DEFAULT 0",
                    'proxy_intel_type' => "VARCHAR(32) NULL",
                    'proxy_intel_provider' => "VARCHAR(128) NULL",
                    'proxy_intel_last_seen' => "VARCHAR(64) NULL",
                    'proof_status' => "VARCHAR(32) NULL",
                    'processing_token' => "VARCHAR(64) NULL",
                    'processing_started_at' => "DATETIME NULL",
                    'status' => "ENUM('pending', 'processed', 'flagged') NOT NULL DEFAULT 'pending'",
                    'reward_counted' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'reward_status_idx' => "INDEX reward_status_idx (status, id)",
                    'receipt_status_created_idx' => "INDEX receipt_status_created_idx (status, created_at)",
                    'receipt_cookie_idx' => "INDEX receipt_cookie_idx (user_id, visitor_cookie_hash, created_at)",
                    'receipt_processing_idx' => "INDEX receipt_processing_idx (status, processing_token, processing_started_at, id)",
                    'receipt_source_event_unique' => "UNIQUE INDEX receipt_source_event_unique (source_event_key)",
                    'receipt_session_unique' => "UNIQUE INDEX receipt_session_unique (session_id)",
                    'reward_guard_idx' => "INDEX reward_guard_idx (user_id, file_id, ip_hash, created_at)"
                ],
                'foreign_keys' => [
                    'reward_receipts_file_fk' => "FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE",
                    'reward_receipts_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'download_completion_events' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'source' => "VARCHAR(32) NOT NULL",
                    'source_event_key' => "VARCHAR(64) NOT NULL",
                    'download_id' => "BIGINT UNSIGNED NOT NULL",
                    'file_id' => "BIGINT UNSIGNED NULL",
                    'status_code' => "VARCHAR(8) NULL",
                    'bytes_sent' => "BIGINT UNSIGNED NULL",
                    'remote_ip' => "VARCHAR(64) NULL",
                    'request_time_ms' => "INT UNSIGNED NULL",
                    'event_payload' => "LONGTEXT NULL",
                    'processing_status' => "VARCHAR(32) NULL",
                    'reason_code' => "VARCHAR(64) NULL",
                    'processed_at' => "DATETIME NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'download_completion_source_event' => "UNIQUE INDEX download_completion_source_event (source_event_key)",
                    'download_completion_status_reason' => "INDEX download_completion_status_reason (source, processing_status, reason_code, processed_at)",
                    'download_completion_processed' => "INDEX download_completion_processed (processed_at, id)",
                    'download_completion_download' => "INDEX download_completion_download (download_id)"
                ]
            ],
            'earnings' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'file_id' => "BIGINT UNSIGNED NULL",
                    'session_id' => "BIGINT UNSIGNED NULL",
                    'parent_earning_id' => "BIGINT UNSIGNED NULL",
                    'type' => "ENUM('download_reward','pps_reward','referral','bonus','withdrawal','aggregate_summary') NOT NULL",
                    'amount' => "DECIMAL(15,4) NOT NULL DEFAULT 0.0000",
                    'ip_hash' => "CHAR(64) NULL",
                    'risk_score' => "INT NOT NULL DEFAULT 0",
                    'risk_reasons_json' => "JSON NULL",
                    'hold_until' => "TIMESTAMP NULL",
                    'reviewed_by' => "BIGINT UNSIGNED NULL",
                    'reviewed_at' => "TIMESTAMP NULL",
                    'review_note' => "TEXT NULL",
                    'country_code' => "CHAR(2) NULL",
                    'network_type' => "VARCHAR(32) NULL",
                    'asn' => "VARCHAR(64) NULL",
                    'status' => "ENUM('held','flagged_review','cleared','reversed','paid','cancelled','pending') NOT NULL DEFAULT 'held'",
                    'description' => "TEXT NULL",
                    'metadata' => "JSON NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'earnings_user_date' => "INDEX earnings_user_date (user_id, created_at)",
                    'earnings_guard_idx' => "INDEX earnings_guard_idx (user_id, file_id, ip_hash, created_at)",
                    'earnings_status_hold_idx' => "INDEX earnings_status_hold_idx (status, hold_until, created_at)",
                    'earnings_parent_type_idx' => "INDEX earnings_parent_type_idx (parent_earning_id, type)"
                ],
                'foreign_keys' => [
                    'earnings_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'download_sessions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'public_id' => "CHAR(32) NOT NULL",
                    'file_id' => "BIGINT UNSIGNED NOT NULL",
                    'uploader_user_id' => "BIGINT UNSIGNED NOT NULL",
                    'downloader_user_id' => "BIGINT UNSIGNED NULL",
                    'delivery_mode' => "VARCHAR(32) NOT NULL DEFAULT 'php_proxy'",
                    'reward_mode' => "ENUM('download','stream') NOT NULL DEFAULT 'download'",
                    'status' => "ENUM('created','started','progressing','completed','aborted','expired','flagged') NOT NULL DEFAULT 'created'",
                    'ip_hash' => "VARCHAR(64) NOT NULL",
                    'ua_hash' => "VARCHAR(64) NULL",
                    'visitor_cookie_hash' => "VARCHAR(64) NULL",
                    'accept_language_hash' => "VARCHAR(64) NULL",
                    'timezone_offset' => "SMALLINT NULL",
                    'platform_bucket' => "VARCHAR(64) NULL",
                    'screen_bucket' => "VARCHAR(32) NULL",
                    'asn' => "VARCHAR(64) NULL",
                    'network_type' => "VARCHAR(32) NULL",
                    'country_code' => "CHAR(2) NULL",
                    'download_page_referrer_url' => "VARCHAR(2048) NULL",
                    'download_page_referrer_host' => "VARCHAR(255) NULL",
                    'download_page_referrer_internal' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'cloudflare_risk_score' => "INT NOT NULL DEFAULT 0",
                    'proxy_intel_risk_score' => "INT NOT NULL DEFAULT 0",
                    'proxy_intel_type' => "VARCHAR(32) NULL",
                    'proxy_intel_provider' => "VARCHAR(128) NULL",
                    'proxy_intel_last_seen' => "VARCHAR(64) NULL",
                    'bytes_expected' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'bytes_sent' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'percent_complete' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
                    'watch_seconds' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'watch_percent' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
                    'risk_score' => "INT NOT NULL DEFAULT 0",
                    'risk_level' => "ENUM('low','medium','high') NOT NULL DEFAULT 'low'",
                    'risk_reasons_json' => "JSON NULL",
                    'download_counted_at' => "TIMESTAMP NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    'expires_at' => "TIMESTAMP NULL"
                ],
                'primary' => 'id',
                'indexes' => [
                    'download_sessions_public_id' => "UNIQUE INDEX download_sessions_public_id (public_id)",
                    'session_status_idx' => "INDEX session_status_idx (status, created_at)",
                    'session_file_idx' => "INDEX session_file_idx (file_id, created_at)",
                    'session_uploader_idx' => "INDEX session_uploader_idx (uploader_user_id, created_at)",
                    'session_signature_idx' => "INDEX session_signature_idx (visitor_cookie_hash, ip_hash, ua_hash, created_at)"
                ]
            ],
            'download_session_events' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'session_id' => "BIGINT UNSIGNED NOT NULL",
                    'event_type' => "VARCHAR(32) NOT NULL",
                    'server_id' => "BIGINT UNSIGNED NULL",
                    'event_public_id' => "CHAR(32) NULL",
                    'nonce' => "VARCHAR(128) NULL",
                    'signature_valid' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'bytes_sent' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'watch_seconds' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'watch_percent' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
                    'source_ip_hash' => "VARCHAR(64) NULL",
                    'event_payload' => "JSON NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'download_session_events_public_id' => "UNIQUE INDEX download_session_events_public_id (event_public_id)",
                    'download_session_events_nonce' => "UNIQUE INDEX download_session_events_nonce (nonce)",
                    'session_event_idx' => "INDEX session_event_idx (session_id, created_at)"
                ],
                'foreign_keys' => [
                    'download_session_events_session_fk' => "FOREIGN KEY (`session_id`) REFERENCES `download_sessions`(`id`) ON DELETE CASCADE"
                ]
            ],
            'fraud_account_scores' => [
                'columns' => [
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'risk_score' => "INT NOT NULL DEFAULT 0",
                    'held_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'flagged_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'suspicious_file_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'suspicious_network_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'user_id'
            ],
            'fraud_account_controls' => [
                'columns' => [
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'trust_tier' => "VARCHAR(16) NOT NULL DEFAULT 'normal'",
                    'review_note' => "TEXT NULL",
                    'updated_by' => "BIGINT UNSIGNED NULL",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'user_id',
                'indexes' => [
                    'fraud_account_controls_tier_idx' => "INDEX fraud_account_controls_tier_idx (trust_tier, updated_at)"
                ]
            ],
            'fraud_network_summaries' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'asn' => "VARCHAR(64) NULL",
                    'country_code' => "CHAR(2) NULL",
                    'network_type' => "VARCHAR(32) NULL",
                    'session_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'held_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'flagged_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'fraud_network_lookup_idx' => "INDEX fraud_network_lookup_idx (country_code, network_type, updated_at)"
                ]
            ],
            'remote_reward_event_nonces' => [
                'columns' => [
                    'nonce' => "VARCHAR(128) NOT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'nonce'
            ],
            'admin_request_activity' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'request_type' => "VARCHAR(50) NOT NULL",
                    'request_id' => "BIGINT UNSIGNED NOT NULL",
                    'admin_user_id' => "BIGINT UNSIGNED NULL",
                    'activity_type' => "VARCHAR(32) NOT NULL",
                    'subject' => "VARCHAR(255) NULL",
                    'body' => "TEXT NULL",
                    'metadata_json' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'idx_request_lookup' => "INDEX idx_request_lookup (request_type, request_id, created_at)",
                    'idx_activity_type' => "INDEX idx_activity_type (activity_type, created_at)"
                ]
            ],
            'file_deletion_log' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'uploader_user_id' => "BIGINT UNSIGNED NOT NULL",
                    'original_file_id' => "BIGINT UNSIGNED NULL",
                    'original_filename' => "TEXT NOT NULL /* Encrypted */",
                    'delete_reason' => "TEXT NULL /* Encrypted */",
                    'deleted_by_user_id' => "BIGINT UNSIGNED NULL",
                    'deleted_by_role' => "VARCHAR(32) NOT NULL DEFAULT 'user'",
                    'deleted_by_label' => "TEXT NULL /* Encrypted */",
                    'delete_file_earnings' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'delete_file_earnings_authorized' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'rewards_reviewer_id' => "BIGINT UNSIGNED NULL",
                    'deleted_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'file_deletion_uploader_idx' => "INDEX file_deletion_uploader_idx (uploader_user_id, deleted_at)",
                    'file_deletion_actor_idx' => "INDEX file_deletion_actor_idx (deleted_by_user_id)"
                ]
            ],
            'withdrawals' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
                    'method' => "VARCHAR(50) NOT NULL",
                    'details' => "TEXT NOT NULL /* Encrypted */",
                    'status' => "ENUM('pending', 'approved', 'paid', 'rejected') NOT NULL DEFAULT 'pending'",
                    'admin_note' => "TEXT NULL /* Encrypted */",
                    'processed_at' => "DATETIME NULL",
                    'processed_by_admin_id' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'withdrawals_status_idx' => "INDEX withdrawals_status_idx (status, created_at)"
                ],
                'foreign_keys' => [
                    'withdrawals_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'ppd_tiers' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'name' => "VARCHAR(50) NOT NULL",
                    'rate_per_1000' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00"
                ],
                'primary' => 'id'
            ],
            'ppd_tier_countries' => [
                'columns' => [
                    'tier_id' => "INT UNSIGNED NOT NULL",
                    'country_code' => "CHAR(2) NOT NULL"
                ],
                'primary' => ['tier_id', 'country_code'],
                'foreign_keys' => [
                    'ppd_tier_countries_fk' => "FOREIGN KEY (`tier_id`) REFERENCES `ppd_tiers`(`id`) ON DELETE CASCADE"
                ]
            ],
            'stats_daily' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'day' => "DATE NOT NULL",
                    'downloads' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'earnings' => "DECIMAL(15,4) NOT NULL DEFAULT 0.0000"
                ],
                'primary' => 'id',
                'indexes' => [
                    'user_day' => "UNIQUE INDEX user_day (user_id, day)"
                ],
                'foreign_keys' => [
                    'stats_daily_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'user_two_factor' => [
                'columns' => [
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'secret_key' => "TEXT NOT NULL /* Encrypted */",
                    'is_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'recovery_codes' => "TEXT NULL /* Encrypted */"
                ],
                'primary' => 'user_id',
                'foreign_keys' => [
                    'u2f_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'user_two_factor_devices' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'trust_token' => "VARCHAR(64) NOT NULL",
                    'expires_at' => "TIMESTAMP NOT NULL"
                ],
                'primary' => 'id',
                'indexes' => [
                    'trust_lookup' => "INDEX trust_lookup (user_id, trust_token)"
                ],
                'foreign_keys' => [
                    'u2fd_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'plugins' => [
                'columns' => [
                    'id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'name' => "VARCHAR(100) NOT NULL",
                    'directory' => "VARCHAR(100) NOT NULL",
                    'version' => "VARCHAR(20) NOT NULL",
                    'is_active' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'directory' => "UNIQUE INDEX directory (directory)"
                ]
            ],
            'email_templates' => [
                'columns' => [
                    'template_key' => "VARCHAR(50) NOT NULL",
                    'subject' => "VARCHAR(255) NOT NULL",
                    'body' => "TEXT NOT NULL",
                    'description' => "TEXT NULL"
                ],
                'primary' => 'template_key'
            ],
            'site_content' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'page_key' => "VARCHAR(64) NOT NULL",
                    'block_key' => "VARCHAR(64) NOT NULL",
                    'locale' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
                    'content_type' => "ENUM('object', 'list', 'markdown', 'text') NOT NULL DEFAULT 'object'",
                    'content_json' => "LONGTEXT NOT NULL",
                    'updated_by' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'site_content_lookup' => "UNIQUE INDEX site_content_lookup (page_key, block_key, locale)",
                    'site_content_page_locale' => "INDEX site_content_page_locale (page_key, locale)"
                ],
                'foreign_keys' => [
                    'site_content_updated_by_fk' => "FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'site_content_revisions' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'page_key' => "VARCHAR(64) NOT NULL",
                    'locale' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
                    'snapshot_json' => "LONGTEXT NOT NULL",
                    'change_reason' => "VARCHAR(32) NOT NULL DEFAULT 'save'",
                    'restored_from_revision_id' => "BIGINT UNSIGNED NULL",
                    'created_by' => "BIGINT UNSIGNED NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'site_content_revisions_page_locale' => "INDEX site_content_revisions_page_locale (page_key, locale, created_at)",
                    'site_content_revisions_created_by' => "INDEX site_content_revisions_created_by (created_by)"
                ],
                'foreign_keys' => [
                    'site_content_revisions_created_by_fk' => "FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL"
                ]
            ],
            'site_content_preview_tokens' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'token_hash' => "CHAR(64) NOT NULL",
                    'page_key' => "VARCHAR(64) NOT NULL",
                    'locale' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
                    'payload_json' => "LONGTEXT NOT NULL",
                    'created_by' => "BIGINT UNSIGNED NOT NULL",
                    'expires_at' => "DATETIME NOT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'site_content_preview_tokens_hash' => "UNIQUE INDEX site_content_preview_tokens_hash (token_hash)",
                    'site_content_preview_tokens_expiry' => "INDEX site_content_preview_tokens_expiry (expires_at)",
                    'site_content_preview_tokens_creator' => "INDEX site_content_preview_tokens_creator (created_by, page_key)"
                ],
                'foreign_keys' => [
                    'site_content_preview_tokens_created_by_fk' => "FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE"
                ]
            ],
            'remote_upload_queue' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'user_id' => "BIGINT UNSIGNED NOT NULL",
                    'folder_id' => "BIGINT UNSIGNED NULL",
                    'url' => "TEXT NOT NULL",
                    'status' => "ENUM('pending', 'processing', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending'",
                    'attempt_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
                    'available_at' => "DATETIME NULL",
                    'started_at' => "DATETIME NULL",
                    'processed_at' => "DATETIME NULL",
                    'error_message' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'remote_upload_queue_status_idx' => "INDEX remote_upload_queue_status_idx (status, available_at, id)",
                    'remote_upload_queue_user_idx' => "INDEX remote_upload_queue_user_idx (user_id, created_at)"
                ],
                'foreign_keys' => [
                    'remote_upload_queue_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE",
                    'remote_upload_queue_folder_fk' => "FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL"
                ]
            ]
        ];

        // 2. Load Plugin Schemas
        if ($includePluginTables) {
            $activePlugins = empty($plugins) ? \App\Core\PluginManager::getActivePlugins() : $plugins;
            foreach ($activePlugins as $pluginDir) {
                // If it's just a string, we need to instantiate it
                if (is_string($pluginDir)) {
                    $pluginPath = dirname(__DIR__, 2) . '/Plugin/' . $pluginDir . '/' . $pluginDir . 'Plugin.php';
                    if (file_exists($pluginPath)) {
                        require_once $pluginPath;
                        $className = "\\Plugin\\{$pluginDir}\\{$pluginDir}Plugin";
                        if (class_exists($className)) {
                            $instance = new $className();
                            if (method_exists($instance, 'getDatabaseSchema')) {
                                $pluginSchema = $instance->getDatabaseSchema();
                                // Merge Tables
                                if (isset($pluginSchema['tables'])) {
                                    foreach ($pluginSchema['tables'] as $table => $def) {
                                        $schema[$table] = $def;
                                    }
                                }
                                // Merge Columns (ALTER TABLE)
                                if (isset($pluginSchema['columns'])) {
                                    foreach ($pluginSchema['columns'] as $table => $cols) {
                                        if (isset($schema[$table])) {
                                            $schema[$table]['columns'] = array_merge($schema[$table]['columns'], $cols);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $schema;
    }

    /**
     * Get encrypted columns for a specific table.
     */
    public static function getEncryptedColumns(string $tableName): array
    {
        $schema = self::getMasterSchema([], true);
        if (!isset($schema[$tableName])) return [];

        $encrypted = [];
        foreach ($schema[$tableName]['columns'] as $colName => $definition) {
            if (str_contains($definition, '/* Encrypted')) {
                $encrypted[] = $colName;
            }
        }
        return $encrypted;
    }

    public static function beginRepairWindow(): void
    {
        self::$explicitRepairDepth++;
    }

    public static function withRepairWindow(callable $callback)
    {
        self::beginRepairWindow();
        try {
            return $callback();
        } finally {
            self::endRepairWindow();
        }
    }

    public static function endRepairWindow(): void
    {
        if (self::$explicitRepairDepth > 0) {
            self::$explicitRepairDepth--;
        }
    }

    private static function repairWindowActive(): bool
    {
        return self::$explicitRepairDepth > 0;
    }

    /**
     * Ensure a subset of master-schema tables exists, with optional drift repair.
     */
    public static function ensureTables(array $tableNames, bool $repairDrift = false): void
    {
        if ($tableNames === []) {
            return;
        }

        if ($repairDrift && !self::repairWindowActive()) {
            $repairDrift = false;
        }

        $service = new self();
        $masterSchema = self::getMasterSchema([], true);
        $resolvedTableNames = [];

        foreach (array_values(array_unique($tableNames)) as $tableName) {
            if (!isset($masterSchema[$tableName])) {
                continue;
            }
            $resolvedTableNames[] = $tableName;
        }

        $schemaLockAcquired = false;
        try {
            if ($repairDrift) {
                $pdo = $service->getPdo();
                $schemaLockAcquired = $service->acquireSchemaSyncLock($pdo);
                if (!$schemaLockAcquired) {
                    throw new \RuntimeException('Another schema sync is already running. Wait for it to finish before starting another repair or install step.');
                }

                $subsetSchema = [];
                foreach ($resolvedTableNames as $tableName) {
                    $subsetSchema[$tableName] = $masterSchema[$tableName];
                }
                $nonAtomicRisks = $service->collectNonAtomicRepairRisks($subsetSchema);
                if ($nonAtomicRisks !== []) {
                    throw new \RuntimeException(
                        'Schema repair for required tables aborted before making changes because the remaining drift needs non-atomic rebuild work: '
                        . implode(' | ', $nonAtomicRisks)
                        . '. Restore a backup or run a staged manual migration for those tables, then re-run schema validation.'
                    );
                }
            }

            $service->syncTablePlan($resolvedTableNames, $masterSchema, $repairDrift);
        } finally {
            if ($schemaLockAcquired) {
                try {
                    $service->releaseSchemaSyncLock($service->getPdo());
                } catch (\Throwable $e) {
                }
            }
        }

        if (!empty($service->structuralErrors)) {
            throw new \RuntimeException(
                'Schema validation failed for required tables (' . implode(', ', array_values(array_unique($tableNames))) . '): '
                . implode(' | ', $service->structuralErrors)
            );
        }

        if (!$repairDrift && $service->driftDetected) {
            throw new \RuntimeException(
                'Database schema drift detected for required tables (' . implode(', ', array_values(array_unique($tableNames))) . '). '
                . 'Run Deep Repair from Admin > Configuration > Security before using this area.'
            );
        }
    }

    /**
     * Sync the database with the master schema.
     */
    public function sync(bool $repairDrift = false): array
    {
        $start = microtime(true);
        $this->logs = [];
        $this->driftDetected = false;
        $this->structuralErrors = [];
        $schemaLockAcquired = false;
        $this->log($repairDrift
            ? "Starting Schema Sync (Deep Repair Enabled)..."
            : "Starting Schema Sync (Deep Scan Only)...");

        try {
            $pdo = $this->getPdo();
            $schemaLockAcquired = $this->acquireSchemaSyncLock($pdo);
            if (!$schemaLockAcquired) {
                throw new Exception('Another schema sync is already running. Wait for it to finish before starting another repair or install step.');
            }

            // Re-fetch master schema with plugins
            $masterSchema = self::getMasterSchema([], true);
            if ($repairDrift) {
                $nonAtomicRisks = $this->collectNonAtomicRepairRisks($masterSchema);
                if ($nonAtomicRisks !== []) {
                    $error = 'Deep Repair aborted before making changes because the remaining drift needs non-atomic rebuild work: '
                        . implode(' | ', $nonAtomicRisks)
                        . '. Restore a backup or run a staged manual migration for those tables, then re-run schema validation.';
                    $this->persistSchemaHealth($pdo, true, $error);
                    $this->log($error);
                    return [
                        'success' => false,
                        'error' => $error,
                        'logs' => $this->logs,
                        'drift_detected' => true,
                        'structural_match' => false,
                        'structural_errors' => $nonAtomicRisks,
                    ];
                }
            }

            $this->syncTablePlan(array_keys($masterSchema), $masterSchema, $repairDrift);

            if (!empty($this->structuralErrors)) {
                $error = 'Schema repair encountered structural failures: ' . implode(' | ', $this->structuralErrors);
                $this->persistSchemaHealth($pdo, true, $error);
                $this->log($error);
                return [
                    'success' => false,
                    'error' => $error,
                    'logs' => $this->logs,
                    'drift_detected' => true,
                    'structural_match' => false,
                    'structural_errors' => $this->structuralErrors,
                ];
            }

            if ($this->driftDetected) {
                $error = $repairDrift
                    ? 'Schema drift remains after Deep Repair. Review the sync logs and resolve the remaining mismatches before treating the database as current.'
                    : 'Schema drift detected. Run Deep Repair to reconcile missing or drifted columns, indexes, and foreign keys before the database can be marked current.';
                $this->persistSchemaHealth($pdo, true, $error);
                $this->log($error);
                return [
                    'success' => false,
                    'error' => $error,
                    'logs' => $this->logs,
                    'drift_detected' => true,
                    'structural_match' => false,
                    'structural_errors' => [],
                ];
            }

            // Update Schema Version only after a full structural match.
            $this->log("Finalizing Version...");
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group, is_system) VALUES ('schema_version', ?, 'system', 1) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([self::SCHEMA_VERSION]);
            $this->persistSchemaHealth($pdo, false, '');
            $duration = round(microtime(true) - $start, 2);
            $this->log("Sync finished successfully in {$duration}s (Version: " . self::SCHEMA_VERSION . ")");

            return [
                'success' => true,
                'logs' => $this->logs,
                'drift_detected' => false,
                'structural_match' => true,
                'structural_errors' => [],
            ];

        } catch (Exception $e) {
            try {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $this->persistSchemaHealth($pdo, true, $e->getMessage());
                }
            } catch (Exception $inner) {
                $this->log("Schema health flag update failed: " . $inner->getMessage());
            }
            $this->log("Sync Failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'logs' => $this->logs,
                'drift_detected' => true,
                'structural_match' => false,
                'structural_errors' => $this->structuralErrors,
            ];
        } finally {
            if (!empty($schemaLockAcquired) && isset($pdo) && $pdo instanceof PDO) {
                $this->releaseSchemaSyncLock($pdo);
            }
        }
    }

    protected function runSyncTable(string $table, array $def, bool $repairDrift): void
    {
        $pdo = $this->getPdo();

        // 1. Check if table exists
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$check) {
            if (!$repairDrift) {
                $this->recordDrift("Table $table: Missing table");
                return;
            }
            $this->log("Creating table: $table");
            $this->createTable($table, $def);
            return;
        }

        // 2. Column Drifting
        $stmt = $pdo->query("DESCRIBE `$table` ");
        $existingCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tableDdl = $this->loadTableDdl($table);
        $existingColNames = array_column($existingCols, 'Field');

        foreach ($def['columns'] as $colName => $colDef) {
            if (!in_array($colName, $existingColNames)) {
                if (!$repairDrift) {
                    $this->recordDrift("Table $table: Missing column $colName");
                    continue;
                }
                $this->log("Table $table: Adding missing column $colName");
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colName` $colDef");
                continue;
            }

            $existingCol = null;
            foreach ($existingCols as $candidate) {
                if (($candidate['Field'] ?? null) === $colName) {
                    $candidate['__table_ddl'] = $tableDdl;
                    $existingCol = $candidate;
                    break;
                }
            }

            if ($existingCol !== null && !$this->isColumnDefinitionCompatible($existingCol, $colDef)) {
                if (!$repairDrift) {
                    $this->recordDrift("Table $table: Drifted column $colName");
                    continue;
                }
                $this->log("Table $table: Repairing drifted column $colName");
                $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$colName` $colDef");
            }
        }

        // 3. Index Drifting
        if (isset($def['indexes'])) {
            $stmtIdx = $pdo->query("SHOW INDEX FROM `$table` ");
            $existingIdxRows = $stmtIdx->fetchAll(PDO::FETCH_ASSOC);
            $existingIdx = [];

            foreach ($existingIdxRows as $row) {
                $keyName = (string)($row['Key_name'] ?? '');
                if ($keyName === '' || $keyName === 'PRIMARY') {
                    continue;
                }

                if (!isset($existingIdx[$keyName])) {
                    $existingIdx[$keyName] = [
                        'unique' => ((int)($row['Non_unique'] ?? 1) === 0),
                        'columns' => [],
                    ];
                }

                $existingIdx[$keyName]['columns'][(int)($row['Seq_in_index'] ?? 0)] = (string)($row['Column_name'] ?? '');
            }

            foreach ($existingIdx as &$indexMeta) {
                ksort($indexMeta['columns']);
                $indexMeta['columns'] = array_values(array_filter($indexMeta['columns'], static fn($col) => $col !== ''));
            }
            unset($indexMeta);

            foreach ($def['indexes'] as $idxName => $idxDef) {
                if (!isset($existingIdx[$idxName])) {
                    if (!$repairDrift) {
                        $this->recordDrift("Table $table: Missing index $idxName");
                        continue;
                    }
                    $this->log("Table $table: Adding missing index $idxName");
                    $pdo->exec("ALTER TABLE `$table` ADD $idxDef");
                    continue;
                }

                if (!$this->isIndexDefinitionCompatible($existingIdx[$idxName], $idxDef)) {
                    if (!$repairDrift) {
                        $this->recordDrift("Table $table: Drifted index $idxName");
                        continue;
                    }
                    $this->log("Table $table: Repairing drifted index $idxName");
                    $pdo->exec("ALTER TABLE `$table` DROP INDEX `$idxName`");
                    $pdo->exec("ALTER TABLE `$table` ADD $idxDef");
                }
            }
        }

        // 4. Foreign Key Drifting
        if (isset($def['foreign_keys'])) {
            $stmtFk = $pdo->prepare("
                SELECT
                    kcu.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    kcu.ORDINAL_POSITION,
                    rc.UPDATE_RULE,
                    rc.DELETE_RULE
                FROM information_schema.TABLE_CONSTRAINTS
                INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON kcu.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
                   AND kcu.TABLE_NAME = TABLE_CONSTRAINTS.TABLE_NAME
                   AND kcu.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
                INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
                   AND rc.TABLE_NAME = TABLE_CONSTRAINTS.TABLE_NAME
                   AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
                WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_CONSTRAINTS.TABLE_NAME = ?
                  AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
                ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ");
            $stmtFk->execute([$table]);
            $existingFkRows = $stmtFk->fetchAll(PDO::FETCH_ASSOC);
            $existingFks = [];

            foreach ($existingFkRows as $row) {
                $fkName = (string)($row['CONSTRAINT_NAME'] ?? '');
                if ($fkName === '') {
                    continue;
                }

                if (!isset($existingFks[$fkName])) {
                    $existingFks[$fkName] = [
                        'columns' => [],
                        'referenced_table' => (string)($row['REFERENCED_TABLE_NAME'] ?? ''),
                        'referenced_columns' => [],
                        'update_rule' => strtoupper((string)($row['UPDATE_RULE'] ?? 'RESTRICT')),
                        'delete_rule' => strtoupper((string)($row['DELETE_RULE'] ?? 'RESTRICT')),
                    ];
                }

                $existingFks[$fkName]['columns'][(int)($row['ORDINAL_POSITION'] ?? 0)] = (string)($row['COLUMN_NAME'] ?? '');
                $existingFks[$fkName]['referenced_columns'][(int)($row['ORDINAL_POSITION'] ?? 0)] = (string)($row['REFERENCED_COLUMN_NAME'] ?? '');
            }

            foreach ($existingFks as &$fkMeta) {
                ksort($fkMeta['columns']);
                $fkMeta['columns'] = array_values(array_filter($fkMeta['columns'], static fn($col) => $col !== ''));
                ksort($fkMeta['referenced_columns']);
                $fkMeta['referenced_columns'] = array_values(array_filter($fkMeta['referenced_columns'], static fn($col) => $col !== ''));
            }
            unset($fkMeta);

            foreach ($def['foreign_keys'] as $fkName => $fkDef) {
                $desiredFk = $this->parseForeignKeyDefinition($fkDef);
                $matchingExistingName = null;

                if (isset($existingFks[$fkName]) && $this->isForeignKeyDefinitionCompatible($existingFks[$fkName], $fkDef)) {
                    continue;
                }

                foreach ($existingFks as $existingName => $existingMeta) {
                    if ($this->isForeignKeyDefinitionCompatible($existingMeta, $fkDef)) {
                        $matchingExistingName = $existingName;
                        break;
                    }
                }

                if ($matchingExistingName !== null) {
                    continue;
                }

                $sameColumnForeignKeyName = null;
                if ($desiredFk !== null) {
                    foreach ($existingFks as $existingName => $existingMeta) {
                        if (($existingMeta['columns'] ?? []) === ($desiredFk['columns'] ?? [])) {
                            $sameColumnForeignKeyName = $existingName;
                            break;
                        }
                    }
                }

                if (!isset($existingFks[$fkName]) && $sameColumnForeignKeyName === null) {
                    if (!$repairDrift) {
                        $this->recordDrift("Table $table: Missing foreign key $fkName");
                        continue;
                    }
                    $this->log("Table $table: Adding missing foreign key $fkName");
                    try {
                        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$fkName` $fkDef");
                    } catch (Exception $e) {
                        $this->recordStructuralError("Failed to add foreign key $fkName to $table: " . $e->getMessage());
                    }
                    continue;
                }

                $foreignKeyToRepair = isset($existingFks[$fkName]) ? $fkName : $sameColumnForeignKeyName;
                if ($foreignKeyToRepair !== null) {
                    if (!$repairDrift) {
                        $this->recordDrift("Table $table: Drifted foreign key $fkName");
                        continue;
                    }
                    $this->log("Table $table: Repairing drifted foreign key $fkName");
                    try {
                        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$foreignKeyToRepair`");
                        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$fkName` $fkDef");
                    } catch (Exception $e) {
                        $this->recordStructuralError("Failed to repair foreign key $fkName on $table: " . $e->getMessage());
                    }
                }
            }
        }
    }

    private function syncTablePlan(array $tableNames, array $masterSchema, bool $repairDrift): void
    {
        $tableNames = array_values(array_filter($tableNames, static fn(string $tableName): bool => isset($masterSchema[$tableName])));
        if ($tableNames === []) {
            return;
        }

        $startIndex = 0;
        $checkpointPdo = null;
        $planSignature = null;

        if ($repairDrift) {
            $checkpointPdo = $this->getPdo();
            $checkpoint = $this->initializeRepairCheckpoint($checkpointPdo, $tableNames);
            $startIndex = (int)($checkpoint['next_table_index'] ?? 0);
            $planSignature = (string)($checkpoint['plan_signature'] ?? '');
            if (!empty($checkpoint['resumed']) && isset($tableNames[$startIndex])) {
                $this->log('Resuming Deep Repair from table: ' . $tableNames[$startIndex]);
            }
        }

        for ($i = $startIndex; $i < count($tableNames); $i++) {
            $tableName = $tableNames[$i];
            if ($checkpointPdo instanceof PDO && $planSignature !== null) {
                $this->persistRepairCheckpoint($checkpointPdo, [
                    'plan_signature' => $planSignature,
                    'repair_drift' => true,
                    'next_table_index' => $i,
                    'current_table' => $tableName,
                    'updated_at' => date('c'),
                ]);
            }

            try {
                $this->runSyncTable($tableName, $masterSchema[$tableName], $repairDrift);
            } catch (\Throwable $e) {
                if ($checkpointPdo instanceof PDO && $planSignature !== null) {
                    $this->persistRepairCheckpoint($checkpointPdo, [
                        'plan_signature' => $planSignature,
                        'repair_drift' => true,
                        'next_table_index' => $i,
                        'current_table' => $tableName,
                        'last_error' => $e->getMessage(),
                        'updated_at' => date('c'),
                    ]);
                }
                throw $e;
            }

            if ($checkpointPdo instanceof PDO && $planSignature !== null) {
                $this->persistRepairCheckpoint($checkpointPdo, [
                    'plan_signature' => $planSignature,
                    'repair_drift' => true,
                    'next_table_index' => $i + 1,
                    'current_table' => null,
                    'updated_at' => date('c'),
                ]);
            }
        }

        if ($checkpointPdo instanceof PDO && $repairDrift) {
            $this->clearRepairCheckpoint($checkpointPdo);
        }
    }

    private function initializeRepairCheckpoint(PDO $pdo, array $tableNames): array
    {
        $normalizedTables = array_values($tableNames);
        $planSignature = hash('sha256', self::SCHEMA_VERSION . '|' . implode('|', $normalizedTables));
        $existing = $this->loadRepairCheckpoint($pdo);
        $nextIndex = 0;
        $resumed = false;

        if (
            is_array($existing)
            && hash_equals((string)($existing['plan_signature'] ?? ''), $planSignature)
            && !empty($existing['repair_drift'])
        ) {
            $nextIndex = max(0, min(count($normalizedTables), (int)($existing['next_table_index'] ?? 0)));
            $resumed = true;
        }

        $state = [
            'plan_signature' => $planSignature,
            'repair_drift' => true,
            'next_table_index' => $nextIndex,
            'current_table' => $resumed ? ($existing['current_table'] ?? null) : null,
            'updated_at' => date('c'),
        ];
        $this->persistRepairCheckpoint($pdo, $state);
        $state['resumed'] = $resumed;
        return $state;
    }

    private function loadRepairCheckpoint(PDO $pdo): ?array
    {
        $stmt = $pdo->prepare("
            SELECT setting_value
            FROM settings
            WHERE setting_key = ?
              AND setting_group = 'system'
            LIMIT 1
        ");
        $stmt->execute([self::SCHEMA_REPAIR_CHECKPOINT_KEY]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function persistRepairCheckpoint(PDO $pdo, array $state): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group, is_system)
            VALUES (?, ?, 'system', 1)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_system = VALUES(is_system)
        ");
        $stmt->execute([
            self::SCHEMA_REPAIR_CHECKPOINT_KEY,
            json_encode($state, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function clearRepairCheckpoint(PDO $pdo): void
    {
        $stmt = $pdo->prepare("DELETE FROM settings WHERE setting_key = ? AND setting_group = 'system'");
        $stmt->execute([self::SCHEMA_REPAIR_CHECKPOINT_KEY]);
    }

    private function collectNonAtomicRepairRisks(array $masterSchema): array
    {
        $pdo = $this->getPdo();
        $risks = [];

        foreach ($masterSchema as $table => $def) {
            $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
            if (!$check) {
                continue;
            }

            $stmt = $pdo->query("DESCRIBE `$table` ");
            $existingCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tableDdl = $this->loadTableDdl($table);
            $existingColNames = array_column($existingCols, 'Field');
            foreach (($def['columns'] ?? []) as $colName => $colDef) {
                if (!in_array($colName, $existingColNames, true)) {
                    continue;
                }

                foreach ($existingCols as $candidate) {
                    if (($candidate['Field'] ?? null) === $colName) {
                        $candidate['__table_ddl'] = $tableDdl;
                    }
                    if (($candidate['Field'] ?? null) === $colName && !$this->isColumnDefinitionCompatible($candidate, $colDef)) {
                        $risks[] = "Table {$table}: drifted column {$colName}";
                        break;
                    }
                }
            }

            $stmtIdx = $pdo->query("SHOW INDEX FROM `$table` ");
            $existingIdxRows = $stmtIdx->fetchAll(PDO::FETCH_ASSOC);
            $existingIdx = [];
            foreach ($existingIdxRows as $row) {
                $keyName = (string)($row['Key_name'] ?? '');
                if ($keyName === '' || $keyName === 'PRIMARY') {
                    continue;
                }
                if (!isset($existingIdx[$keyName])) {
                    $existingIdx[$keyName] = [
                        'unique' => ((int)($row['Non_unique'] ?? 1) === 0),
                        'columns' => [],
                    ];
                }
                $existingIdx[$keyName]['columns'][(int)($row['Seq_in_index'] ?? 0)] = (string)($row['Column_name'] ?? '');
            }
            foreach ($existingIdx as &$indexMeta) {
                ksort($indexMeta['columns']);
                $indexMeta['columns'] = array_values(array_filter($indexMeta['columns'], static fn($col) => $col !== ''));
            }
            unset($indexMeta);
            foreach (($def['indexes'] ?? []) as $idxName => $idxDef) {
                if (isset($existingIdx[$idxName]) && !$this->isIndexDefinitionCompatible($existingIdx[$idxName], $idxDef)) {
                    $risks[] = "Table {$table}: drifted index {$idxName}";
                }
            }

            $stmtFk = $pdo->prepare("
                SELECT
                    kcu.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    kcu.ORDINAL_POSITION,
                    rc.UPDATE_RULE,
                    rc.DELETE_RULE
                FROM information_schema.TABLE_CONSTRAINTS
                INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON kcu.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
                   AND kcu.TABLE_NAME = TABLE_CONSTRAINTS.TABLE_NAME
                   AND kcu.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
                INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
                   AND rc.TABLE_NAME = TABLE_CONSTRAINTS.TABLE_NAME
                   AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
                WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_CONSTRAINTS.TABLE_NAME = ?
                  AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
                ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ");
            $stmtFk->execute([$table]);
            $existingFkRows = $stmtFk->fetchAll(PDO::FETCH_ASSOC);
            $existingFks = [];
            foreach ($existingFkRows as $row) {
                $fkName = (string)($row['CONSTRAINT_NAME'] ?? '');
                if ($fkName === '') {
                    continue;
                }
                if (!isset($existingFks[$fkName])) {
                    $existingFks[$fkName] = [
                        'columns' => [],
                        'referenced_table' => (string)($row['REFERENCED_TABLE_NAME'] ?? ''),
                        'referenced_columns' => [],
                        'update_rule' => strtoupper((string)($row['UPDATE_RULE'] ?? 'RESTRICT')),
                        'delete_rule' => strtoupper((string)($row['DELETE_RULE'] ?? 'RESTRICT')),
                    ];
                }
                $existingFks[$fkName]['columns'][(int)($row['ORDINAL_POSITION'] ?? 0)] = (string)($row['COLUMN_NAME'] ?? '');
                $existingFks[$fkName]['referenced_columns'][(int)($row['ORDINAL_POSITION'] ?? 0)] = (string)($row['REFERENCED_COLUMN_NAME'] ?? '');
            }
            foreach ($existingFks as &$fkMeta) {
                ksort($fkMeta['columns']);
                $fkMeta['columns'] = array_values(array_filter($fkMeta['columns'], static fn($col) => $col !== ''));
                ksort($fkMeta['referenced_columns']);
                $fkMeta['referenced_columns'] = array_values(array_filter($fkMeta['referenced_columns'], static fn($col) => $col !== ''));
            }
            unset($fkMeta);
            foreach (($def['foreign_keys'] ?? []) as $fkName => $fkDef) {
                if (isset($existingFks[$fkName]) && !$this->isForeignKeyDefinitionCompatible($existingFks[$fkName], $fkDef)) {
                    $risks[] = "Table {$table}: drifted foreign key {$fkName}";
                }
            }
        }

        return array_values(array_unique($risks));
    }

    private function acquireSchemaSyncLock(PDO $pdo): bool
    {
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute([self::SCHEMA_SYNC_LOCK_NAME]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseSchemaSyncLock(PDO $pdo): void
    {
        try {
            $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([self::SCHEMA_SYNC_LOCK_NAME]);
        } catch (Exception $e) {
            $this->log('Schema sync lock release failed: ' . $e->getMessage());
        }
    }

    private function createTable(string $table, array $def): void
    {
        $pdo = $this->getPdo();
        $colStrings = [];
        foreach ($def['columns'] as $name => $sql) {
            $colStrings[] = "`$name` $sql";
        }

        if (isset($def['primary'])) {
            $primary = is_array($def['primary']) ? implode('`, `', $def['primary']) : $def['primary'];
            $colStrings[] = "PRIMARY KEY (`$primary`)";
        }

        if (isset($def['indexes'])) {
            foreach ($def['indexes'] as $idx) {
                $colStrings[] = $idx;
            }
        }

        $sql = "CREATE TABLE `$table` (" . implode(', ', $colStrings) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        // Add foreign keys after table creation
        if (isset($def['foreign_keys'])) {
            foreach ($def['foreign_keys'] as $fkName => $fkDef) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$fkName` $fkDef");
                } catch (Exception $e) {
                    $this->recordStructuralError("Failed to add foreign key $fkName to $table: " . $e->getMessage());
                }
            }
        }
    }

    private function log(string $message): void
    {
        $this->logs[] = $message;
    }

    private function recordDrift(string $message): void
    {
        $this->driftDetected = true;
        $this->log($message);
    }

    private function recordStructuralError(string $message): void
    {
        $this->driftDetected = true;
        $this->structuralErrors[] = $message;
        $this->log($message);
    }

    private function persistSchemaHealth(PDO $pdo, bool $driftDetected, string $error): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_group, is_system)
             VALUES (?, ?, 'system', 1)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute(['db_drift_detected', $driftDetected ? '1' : '0']);
        $stmt->execute(['db_drift_error', $error]);
    }

    private function isColumnDefinitionCompatible(array $existingCol, string $desiredDef): bool
    {
        $normalizedDef = $this->normalizeColumnDefinitionForComparison((string)preg_replace('/\/\*.*?\*\//', '', $desiredDef));
        $existingType = $this->normalizeColumnDefinitionForComparison((string)($existingCol['Type'] ?? ''));
        $tableDdl = (string)($existingCol['__table_ddl'] ?? '');
        $fieldName = (string)($existingCol['Field'] ?? '');
        $existingNull = strtoupper(((string)($existingCol['Null'] ?? '')) === 'YES' ? 'NULL' : 'NOT NULL');
        $existingExtra = strtoupper(trim((string)($existingCol['Extra'] ?? '')));

        $jsonAliasMatch =
            str_contains($normalizedDef, 'JSON')
            && in_array($existingType, ['LONGTEXT', 'TEXT'], true)
            && $this->tableDdlShowsJsonValidation($tableDdl, $fieldName);

        if (($existingType === '' || !str_contains($normalizedDef, $existingType)) && !$jsonAliasMatch) {
            return false;
        }

        if (str_contains($normalizedDef, ' NOT NULL')) {
            if ($existingNull !== 'NOT NULL') {
                return false;
            }
        } elseif (preg_match('/(^|\s)NULL(\s|$)/', $normalizedDef) === 1) {
            if ($existingNull !== 'NULL') {
                return false;
            }
        }

        $wantsAutoIncrement = str_contains($normalizedDef, 'AUTO_INCREMENT');
        $hasAutoIncrement = str_contains($existingExtra, 'AUTO_INCREMENT');
        if ($wantsAutoIncrement !== $hasAutoIncrement) {
            return false;
        }

        if (preg_match('/DEFAULT\s+((?:CURRENT_TIMESTAMP(?:\(\))?)|(?:NULL)|(?:\'(?:[^\']|\'\')*\')|(?:-?\d+(?:\.\d+)?))/i', $desiredDef, $matches)) {
            $desiredDefault = $this->normalizeDefaultDefinitionForComparison((string)$matches[1]);
            $existingDefault = $existingCol['Default'];
            $existingDefault = $this->normalizeDefaultDefinitionForComparison($existingDefault === null ? 'NULL' : (string)$existingDefault);

            if ($desiredDefault !== $existingDefault) {
                return false;
            }
        }

        return true;
    }

    private function loadTableDdl(string $tableName): string
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("SHOW CREATE TABLE `$tableName`");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return '';
        }

        foreach (['Create Table', 'Create View'] as $key) {
            if (!empty($row[$key]) && is_string($row[$key])) {
                return $row[$key];
            }
        }

        return '';
    }

    private function tableDdlShowsJsonValidation(string $tableDdl, string $columnName): bool
    {
        if ($tableDdl === '' || $columnName === '') {
            return false;
        }

        $quotedColumn = preg_quote('`' . $columnName . '`', '/');
        if (preg_match('/' . $quotedColumn . '.*\bJSON\b/i', $tableDdl) === 1) {
            return true;
        }

        return preg_match('/JSON_VALID\s*\(\s*`' . preg_quote($columnName, '/') . '`\s*\)/i', $tableDdl) === 1;
    }

    private function normalizeColumnDefinitionForComparison(string $definition): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($definition)));
        $normalized = str_replace(', ', ',', $normalized);
        return preg_replace(
            '/\b(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\(\d+\)/',
            '$1',
            $normalized
        ) ?? $normalized;
    }

    private function normalizeDefaultDefinitionForComparison(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === 'CURRENT_TIMESTAMP()') {
            return 'CURRENT_TIMESTAMP';
        }
        if ($normalized !== '' && $normalized[0] === "'" && substr($normalized, -1) === "'") {
            $normalized = substr($normalized, 1, -1);
        }
        return $normalized;
    }

    private function isIndexDefinitionCompatible(array $existingIndex, string $desiredDef): bool
    {
        $desiredDef = trim($desiredDef);
        if (!preg_match('/^(UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $desiredDef, $matches)) {
            return true;
        }

        $desiredUnique = trim((string)($matches[1] ?? '')) !== '';
        $desiredColumnsRaw = trim((string)($matches[3] ?? ''));
        $desiredColumns = array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
            array_filter(array_map('trim', explode(',', $desiredColumnsRaw)))
        );

        $existingColumns = array_values($existingIndex['columns'] ?? []);
        $existingUnique = (bool)($existingIndex['unique'] ?? false);

        return $desiredUnique === $existingUnique && $desiredColumns === $existingColumns;
    }

    private function isForeignKeyDefinitionCompatible(array $existingFk, string $desiredDef): bool
    {
        $desiredFk = $this->parseForeignKeyDefinition($desiredDef);
        if ($desiredFk === null) {
            return true;
        }

        $existingColumns = array_values($existingFk['columns'] ?? []);
        $existingReferencedTable = (string)($existingFk['referenced_table'] ?? '');
        $existingReferencedColumns = array_values($existingFk['referenced_columns'] ?? []);
        $existingDeleteRule = strtoupper((string)($existingFk['delete_rule'] ?? 'RESTRICT'));
        $existingUpdateRule = strtoupper((string)($existingFk['update_rule'] ?? 'RESTRICT'));

        return ($desiredFk['columns'] ?? []) === $existingColumns
            && ($desiredFk['referenced_table'] ?? '') === $existingReferencedTable
            && ($desiredFk['referenced_columns'] ?? []) === $existingReferencedColumns
            && ($desiredFk['delete_rule'] ?? 'RESTRICT') === $existingDeleteRule
            && ($desiredFk['update_rule'] ?? 'RESTRICT') === $existingUpdateRule;
    }

    private function parseForeignKeyDefinition(string $definition): ?array
    {
        $definition = trim($definition);
        if (!preg_match(
            '/^FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]+)\)(.*)$/i',
            $definition,
            $matches
        )) {
            return null;
        }

        $columns = array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
            array_filter(array_map('trim', explode(',', trim((string)$matches[1]))))
        );
        $referencedTable = trim((string)$matches[2], " \t\n\r\0\x0B`");
        $referencedColumns = array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
            array_filter(array_map('trim', explode(',', trim((string)$matches[3]))))
        );
        $tail = strtoupper(trim((string)($matches[4] ?? '')));

        $deleteRule = 'RESTRICT';
        if (preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)/i', $tail, $deleteMatch)) {
            $deleteRule = strtoupper(trim((string)$deleteMatch[1]));
        }

        $updateRule = 'RESTRICT';
        if (preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)/i', $tail, $updateMatch)) {
            $updateRule = strtoupper(trim((string)$updateMatch[1]));
        }

        return [
            'columns' => $columns,
            'referenced_table' => $referencedTable,
            'referenced_columns' => $referencedColumns,
            'delete_rule' => $deleteRule,
            'update_rule' => $updateRule,
        ];
    }
}
