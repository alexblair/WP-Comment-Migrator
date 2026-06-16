<?php
/**
 * Plugin Name: 评论迁移工具
 * Plugin URI:  https://www.alexblair.org/
 * Description: 在文章和页面之间迁移评论，支持批量映射和回滚。
 * Version:     1.0.0
 * Author:      AlexBlair
 * License:     GPL v2 or later
 * Text Domain: comment-migrator
 */

defined('ABSPATH') || die('No direct access.');

define('CMT_MIGRATOR_VERSION', '1.0.0');
define('CMT_MIGRATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CMT_MIGRATOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CMT_MIGRATOR_MENU_SLUG', 'comment-migrator');

// 加载依赖文件
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-install.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-admin.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-migration.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-rollback.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-batch.php';

register_activation_hook(__FILE__, array('CMT_Install', 'activate'));

// 后台初始化
if (is_admin()) {
    add_action('plugins_loaded', array('CMT_Install', 'maybe_upgrade'));
    add_action('plugins_loaded', array('CMT_Admin', 'init'));
}
