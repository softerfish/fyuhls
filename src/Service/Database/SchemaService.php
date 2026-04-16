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
    const SCHEMA_VERSION = '2.2.0';

    private $db;
    private array $logs = [];

    public function __construct($db = null)
    {
        $this->db = $db ?: Database::getInstance();
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
                    'level_type' => "ENUM('guest', 'free', 'paid', 'admin') NOT NULL",
                    'max_storage_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'max_upload_size' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'max_daily_downloads' => "INT UNSIGNED NOT NULL DEFAULT 0",
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
                    'password' => "VARCHAR(255) NOT NULL",
                    'role' => "ENUM('guest', 'user', 'admin') NOT NULL DEFAULT 'user'",
                    'package_id' => "INT UNSIGNED NOT NULL DEFAULT 2",
                    'referrer_id' => "BIGINT UNSIGNED NULL",
                    'status' => "ENUM('active', 'banned', 'pending') NOT NULL DEFAULT 'active'",
                    'email_verified' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
                    'verification_token' => "VARCHAR(255) NULL",
                    'reset_token' => "VARCHAR(255) NULL",
                    'reset_expires' => "DATETIME NULL",
                    'storage_used' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    'storage_warning_threshold' => "INT UNSIGNED NOT NULL DEFAULT 75",
                    'storage_warning_sent' => "TINYINT(1) NOT NULL DEFAULT 0",
                    'premium_expiry' => "DATETIME NULL DEFAULT NULL",
                    'api_key' => "VARCHAR(255) NULL /* Encrypted */",
                    'default_privacy' => "ENUM('public', 'private') NOT NULL DEFAULT 'public'",
                    'timezone' => "VARCHAR(100) NOT NULL DEFAULT 'UTC'",
                    'language' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
                    'payment_method' => "ENUM('paypal', 'stripe', 'bitcoin', 'wire') NULL",
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
                    'username_idx' => "UNIQUE INDEX username_idx (username)",
                    'email_idx' => "UNIQUE INDEX email_idx (email)",
                    'status_idx' => "INDEX status_idx (status)"
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
                    'file_hash' => "UNIQUE INDEX file_hash (file_hash)",
                    'file_hash_size_idx' => "INDEX file_hash_size_idx (file_hash, file_size)"
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
                    'deleted_restore_status' => "VARCHAR(32) NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'short_id' => "UNIQUE INDEX short_id (short_id)",
                    'status_idx' => "INDEX status_idx (status)",
                    'filename_idx' => "INDEX filename_idx (filename)",
                    'files_dashboard_idx' => "INDEX files_dashboard_idx (user_id, folder_id, status)"
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
                    'locked_at' => "TIMESTAMP NULL"
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
                    'multipart_upload_id' => "VARCHAR(255) NULL",
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
                    'upload_sessions_expiry' => "INDEX upload_sessions_expiry (expires_at)"
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
                    'upload_session_part_status' => "INDEX upload_session_part_status (upload_session_id, status)"
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
                    'last_used_ip' => "VARCHAR(64) NULL",
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
            'admin_activity_log' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'admin_id' => "BIGINT UNSIGNED NOT NULL",
                    'action' => "VARCHAR(100) NOT NULL",
                    'item_type' => "VARCHAR(50) NULL",
                    'item_id' => "BIGINT UNSIGNED NULL",
                    'details' => "TEXT NULL",
                    'ip_address' => "VARCHAR(45) NULL",
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
                'primary' => 'id'
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
                'primary' => 'id'
            ],
            'security_cache' => [
                'columns' => [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'ip_address' => "VARCHAR(255) NOT NULL /* Encrypted */",
                    'is_vpn' => "TINYINT(1) NOT NULL DEFAULT 0",
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
                    'processing_token' => "VARCHAR(64) NULL",
                    'processing_started_at' => "DATETIME NULL",
                    'status' => "ENUM('pending', 'processed', 'flagged') NOT NULL DEFAULT 'pending'",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'reward_status_idx' => "INDEX reward_status_idx (status, id)",
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
                    'type' => "VARCHAR(50) NOT NULL",
                    'amount' => "DECIMAL(15,4) NOT NULL DEFAULT 0.0000",
                    'ip_hash' => "CHAR(64) NULL",
                    'status' => "ENUM('pending', 'cleared', 'paid', 'rejected') NOT NULL DEFAULT 'pending'",
                    'metadata' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
                ],
                'primary' => 'id',
                'indexes' => [
                    'earnings_user_date' => "INDEX earnings_user_date (user_id, created_at)",
                    'earnings_guard_idx' => "INDEX earnings_guard_idx (user_id, file_id, ip_hash, created_at)"
                ],
                'foreign_keys' => [
                    'earnings_user_fk' => "FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE"
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

    /**
     * Sync the database with the master schema.
     */
    public function sync(bool $repairDrift = false): array
    {
        $start = microtime(true);
        $this->logs = [];
        $this->log("Starting Schema Sync (Deep Scanning Enabled)...");

        try {
            $pdo = $this->db->getConnection();
            
            // Re-fetch master schema with plugins
            $masterSchema = self::getMasterSchema([], true);

            foreach ($masterSchema as $tableName => $definition) {
                $this->syncTable($tableName, $definition, $repairDrift);
            }

            // Update Schema Version
            $this->log("Finalizing Version...");
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group, is_system) VALUES ('schema_version', ?, 'system', 1) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([self::SCHEMA_VERSION]);

            $duration = round(microtime(true) - $start, 2);
            $this->log("Sync finished successfully in {$duration}s (Version: " . self::SCHEMA_VERSION . ")");

            return [
                'success' => true,
                'logs' => $this->logs
            ];

        } catch (Exception $e) {
            $this->log("Sync Failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'logs' => $this->logs
            ];
        }
    }

    private function syncTable(string $table, array $def, bool $repairDrift): void
    {
        $pdo = $this->db->getConnection();
        
        // 1. Check if table exists
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$check) {
            $this->log("Creating table: $table");
            $this->createTable($table, $def);
            return;
        }

        if (!$repairDrift) return;

        // 2. Column Drifting
        $stmt = $pdo->query("DESCRIBE `$table` ");
        $existingCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingColNames = array_column($existingCols, 'Field');

        foreach ($def['columns'] as $colName => $colDef) {
            if (!in_array($colName, $existingColNames)) {
                $this->log("Table $table: Adding missing column $colName");
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colName` $colDef");
            }
        }

        // 3. Index Drifting
        if (isset($def['indexes'])) {
            $stmtIdx = $pdo->query("SHOW INDEX FROM `$table` ");
            $existingIdx = array_column($stmtIdx->fetchAll(PDO::FETCH_ASSOC), 'Key_name');

            foreach ($def['indexes'] as $idxName => $idxDef) {
                if (!in_array($idxName, $existingIdx)) {
                    $this->log("Table $table: Adding missing index $idxName");
                    $pdo->exec("ALTER TABLE `$table` ADD $idxDef");
                }
            }
        }
    }

    private function createTable(string $table, array $def): void
    {
        $pdo = $this->db->getConnection();
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
                    $this->log("Warning: Failed to add foreign key $fkName to $table: " . $e->getMessage());
                }
            }
        }
    }

    private function log(string $message): void
    {
        $this->logs[] = $message;
    }
}
