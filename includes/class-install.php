<?php
// includes/class-install.php
defined('ABSPATH') || die('No direct access.');

class CMT_Install
{
    const DB_VERSION_OPTION = 'cmt_migrator_db_version';
    const DB_VERSION = '1.1.0';

    public static function activate()
    {
        self::create_table();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cmt_migration_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migrate_key VARCHAR(32) NOT NULL COMMENT '迁移唯一标识',
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL,
            comment_count INT UNSIGNED NOT NULL DEFAULT 0,
            comment_data LONGTEXT NOT NULL,
            source_title VARCHAR(255) DEFAULT '',
            target_title VARCHAR(255) DEFAULT '',
            is_batch TINYINT(1) DEFAULT 0,
            operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_migrate_key (migrate_key),
            INDEX idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function maybe_upgrade()
    {
        $current_ver = get_option(self::DB_VERSION_OPTION, '0');
        if (version_compare($current_ver, '1.1.0', '<')) {
            self::upgrade_110();
            update_option(self::DB_VERSION_OPTION, '1.1.0');
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    private static function upgrade_110()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cmt_migration_logs';
        $row = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'operator_id'");
        if (empty($row)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_batch");
        }
    }
}
