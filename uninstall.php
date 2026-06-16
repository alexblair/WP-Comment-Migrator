<?php
// uninstall.php
defined('WP_UNINSTALL_PLUGIN') || die();

$keep_tables = get_option('cmt_migrator_keep_tables', false);

if (!$keep_tables) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cmt_migration_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    delete_option('cmt_migrator_keep_tables');
    delete_option('cmt_migrator_db_version');
}
