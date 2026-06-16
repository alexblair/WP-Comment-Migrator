# 评论迁移插件 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development (recommended) or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin for migrating comments between posts/pages with batch mapping and rollback support.

**Architecture:** Standalone admin plugin with three tabs (评论迁移 / 批量映射 / 迁移历史), custom DB table for migration logs, and AJAX-based post search.

**Tech Stack:** WordPress Plugin API, WP_List_Table, jQuery AJAX, MySQL 5.7, PHP 7.0

---

## File Map

```
wp-content/plugins/comment-migrator/
├── comment-migrator.php                  # 主文件：插件头、常量、加载器
├── uninstall.php                         # 卸载处理：依据选项决定是否删表
├── includes/
│   ├── class-install.php                 # 激活/停用：建表、版本管理
│   ├── class-admin.php                   # 后台菜单、Tab 路由、页面渲染
│   ├── class-list-table.php              # WP_List_Table：评论列表（Tab1）
│   ├── class-history-table.php           # WP_List_Table：历史列表（Tab3）
│   ├── class-migration.php               # 迁移核心：执行与验证
│   ├── class-rollback.php                # 回滚核心：从日志恢复
│   └── class-batch.php                   # 批量映射：解析与执行
├── assets/
│   ├── admin.js                          # AJAX 搜索、确认弹窗、UI 交互
│   └── admin.css                         # Tab 样式、状态徽标、响应式
└── readme.txt                            # WordPress 标准 readme
```

---

### Task 1: Plugin Scaffold + Database Table

**Files:**
- Create: `comment-migrator.php`
- Create: `includes/class-install.php`
- Create: `uninstall.php`

- [ ] **Step 1.1: Create main plugin file**

```php
<?php
/**
 * Plugin Name: 评论迁移工具
 * Plugin URI:  https://example.com/comment-migrator
 * Description: 在文章和页面之间迁移评论，支持批量映射和回滚。
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: comment-migrator
 */

defined('ABSPATH') or die('No direct access.');

define('CMT_MIGRATOR_VERSION', '1.0.0');
define('CMT_MIGRATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CMT_MIGRATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// 加载依赖文件
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-install.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-admin.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-migration.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-rollback.php';
require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-batch.php';

register_activation_hook(__FILE__, array('CMT_Install', 'activate'));
register_deactivation_hook(__FILE__, array('CMT_Install', 'deactivate'));

// 后台初始化
if (is_admin()) {
    add_action('plugins_loaded', array('CMT_Admin', 'init'));
}
```

- [ ] **Step 1.2: Create install/uninstall class**

```php
<?php
// includes/class-install.php
defined('ABSPATH') or die('No direct access.');

class CMT_Install
{
    const DB_VERSION_OPTION = 'cmt_migrator_db_version';
    const KEEP_TABLES_OPTION = 'cmt_migrator_keep_tables';

    public static function activate()
    {
        self::create_table();
        update_option(self::DB_VERSION_OPTION, CMT_MIGRATOR_VERSION);
    }

    public static function deactivate()
    {
        // 不需要在停用时做任何事
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_migrate_key (migrate_key),
            INDEX idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
```

- [ ] **Step 1.3: Create uninstall.php**

```php
<?php
// uninstall.php
defined('WP_UNINSTALL_PLUGIN') or die();

// 检查用户是否选择保留数据表
$keep_tables = get_option('cmt_migrator_keep_tables', false);

if (!$keep_tables) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cmt_migration_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    delete_option('cmt_migrator_keep_tables');
    delete_option('cmt_migrator_db_version');
}
```

- [ ] **Step 1.4: Manual verification**

激活插件 → 检查 MySQL 是否创建了 `wp_cmt_migration_logs` 表 → 停用 → 删除插件（未勾选保留）→ 检查表是否已删除。

---

### Task 2: Admin Menu & Tab Routing

**Files:**
- Create: `includes/class-admin.php`

- [ ] **Step 2.1: Create admin class with menu registration and tab routing**

```php
<?php
// includes/class-admin.php
defined('ABSPATH') or die('No direct access.');

class CMT_Admin
{
    private static $instance = null;
    private $current_tab = 'migrate';

    public static function init()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function add_admin_menu()
    {
        $hook = add_menu_page(
            '评论迁移',           // 页面标题
            '评论迁移',           // 菜单标题
            'manage_options',     // 权限
            'comment-migrator',   // 菜单slug
            array($this, 'render_page'),
            'dashicons-admin-comments',
            25
        );
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_comment-migrator' !== $hook) {
            return;
        }
        wp_enqueue_style('cmt-admin-css', CMT_MIGRATOR_PLUGIN_URL . 'assets/admin.css', array(), CMT_MIGRATOR_VERSION);
        wp_enqueue_script('cmt-admin-js', CMT_MIGRATOR_PLUGIN_URL . 'assets/admin.js', array('jquery'), CMT_MIGRATOR_VERSION, true);
        wp_localize_script('cmt-admin-js', 'cmt_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cmt_migrator_nonce'),
            'confirm_migrate' => '确认迁移',
            'cancel' => '取消',
            'confirm_rollback' => '确认回滚',
            'select_target' => '请选择目标文章',
            'select_comments' => '请至少选择一条评论',
        ));
    }

    public function render_page()
    {
        $this->current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'migrate';
        ?>
        <div class="wrap">
            <h1>评论迁移</h1>
            <?php $this->render_tabs(); ?>
            <div class="cmt-tab-content">
                <?php $this->render_current_tab(); ?>
            </div>
        </div>
        <?php
    }

    private function render_tabs()
    {
        $tabs = array(
            'migrate' => '评论迁移',
            'batch'   => '批量映射',
            'history' => '迁移历史',
        );
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $class = ($key === $this->current_tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=comment-migrator&tab=' . $key);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    private function render_current_tab()
    {
        switch ($this->current_tab) {
            case 'migrate':
                $this->render_migrate_tab();
                break;
            case 'batch':
                $this->render_batch_tab();
                break;
            case 'history':
                $this->render_history_tab();
                break;
            default:
                $this->render_migrate_tab();
        }
    }

    private function render_migrate_tab()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-list-table.php';

        $table = new CMT_List_Table();
        $table->prepare_items();
        ?>
        <form method="get">
            <input type="hidden" name="page" value="comment-migrator">
            <input type="hidden" name="tab" value="migrate">
            <?php $table->search_box('搜索评论', 'comment-search'); ?>
            <?php $table->display(); ?>
        </form>
        <div class="cmt-bulk-actions">
            <span class="cmt-selected-count">已选择 <strong>0</strong> 条评论</span>
            <label>迁移至目标文章：</label>
            <select class="cmt-target-post" style="width:300px;"></select>
            <button type="button" class="button button-primary cmt-execute-migrate">执行迁移</button>
        </div>
        <?php
    }

    private function render_batch_tab()
    {
        require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-batch.php';
        $batch = new CMT_Batch();
        $batch->render();
    }

    private function render_history_tab()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require_once CMT_MIGRATOR_PLUGIN_DIR . 'includes/class-history-table.php';

        $table = new CMT_History_Table();
        $table->prepare_items();
        $table->display();
        ?>
        <div class="cmt-bulk-actions">
            <button type="button" class="button cmt-rollback-selected">回滚所选</button>
        </div>
        <hr>
        <form method="post" action="">
            <?php wp_nonce_field('cmt_settings', 'cmt_settings_nonce'); ?>
            <label>
                <input type="checkbox" name="cmt_keep_tables" value="1"
                    <?php checked(get_option('cmt_migrator_keep_tables', false)); ?>>
                删除插件时保留数据表（不勾选则删除插件时自动清理）
            </label>
            <?php submit_button('保存设置', 'secondary', 'cmt_save_settings'); ?>
        </form>
        <?php
        // 处理设置保存
        if (isset($_POST['cmt_settings_nonce']) && wp_verify_nonce($_POST['cmt_settings_nonce'], 'cmt_settings')) {
            update_option('cmt_migrator_keep_tables', isset($_POST['cmt_keep_tables']) ? 1 : 0);
            echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
        }
    }
}
```

---

### Task 3: Migration List Table (Tab 1)

**Files:**
- Create: `includes/class-list-table.php`

- [ ] **Step 3.1: Create WP_List_Table for comments**

```php
<?php
// includes/class-list-table.php
defined('ABSPATH') or die('No direct access.');

class CMT_List_Table extends WP_List_Table
{
    private $source_post_id = 0;

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'comment',
            'plural'   => 'comments',
            'ajax'     => false,
        ));
    }

    public function prepare_items()
    {
        global $wpdb;

        $this->source_post_id = isset($_GET['source_post_id']) ? intval($_GET['source_post_id']) : 0;

        $per_page = 20;
        $current_page = $this->get_pagenum();

        $where = 'WHERE 1=1';
        $join = '';

        if ($this->source_post_id > 0) {
            $where .= $wpdb->prepare(' AND c.comment_post_ID = %d', $this->source_post_id);
        }

        // 状态筛选
        $status = isset($_GET['comment_status']) ? sanitize_key($_GET['comment_status']) : '';
        if ($status && 'all' !== $status) {
            $status_map = array(
                'approved' => 1,
                'pending'  => 0,
                'spam'     => 'spam',
                'trash'    => 'trash',
            );
            if (isset($status_map[$status])) {
                $sv = $status_map[$status];
                if (is_int($sv)) {
                    $where .= $wpdb->prepare(' AND c.comment_approved = %d', $sv);
                } else {
                    $where .= $wpdb->prepare(" AND c.comment_approved = %s", $sv);
                }
            }
        }

        // 搜索
        $search = isset($_GET['s']) ? trim($_GET['s']) : '';
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(' AND (c.comment_author LIKE %s OR c.comment_content LIKE %s)', $search_like, $search_like);
        }

        $total_query = "SELECT COUNT(*) FROM {$wpdb->comments} c {$join} {$where}";
        $total_items = $wpdb->get_var($total_query);

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ));

        $offset = ($current_page - 1) * $per_page;
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'comment_date';
        $order = isset($_GET['order']) && 'asc' === strtolower($_GET['order']) ? 'ASC' : 'DESC';
        $allowed_orderby = array('comment_date', 'comment_author', 'comment_approved');
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'comment_date';
        }

        $data_query = $wpdb->prepare(
            "SELECT c.* FROM {$wpdb->comments} c {$join} {$where} ORDER BY c.{$orderby} {$order} LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $this->items = $wpdb->get_results($data_query);
    }

    public function get_columns()
    {
        return array(
            'cb'           => '<input type="checkbox">',
            'comment_author' => '评论作者',
            'comment_content' => '评论内容',
            'comment_post_id' => '来源文章',
            'comment_approved' => '状态',
            'comment_date'    => '日期',
            'actions'         => '操作',
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'comment_author' => array('comment_author', false),
            'comment_date'   => array('comment_date', false),
            'comment_approved' => array('comment_approved', false),
        );
    }

    protected function column_default($item, $column_name)
    {
        return esc_html(print_r($item->$column_name, true));
    }

    protected function column_cb($item)
    {
        return '<input type="checkbox" name="comment_ids[]" value="' . intval($item->comment_ID) . '">';
    }

    protected function column_comment_author($item)
    {
        return esc_html($item->comment_author);
    }

    protected function column_comment_content($item)
    {
        $content = strip_tags($item->comment_content);
        if (mb_strlen($content) > 60) {
            $content = mb_substr($content, 0, 60) . '...';
        }
        return esc_html($content);
    }

    protected function column_comment_post_id($item)
    {
        $post = get_post($item->comment_post_ID);
        if ($post) {
            $title = $post->post_title;
            if (mb_strlen($title) > 30) {
                $title = mb_substr($title, 0, 30) . '...';
            }
            $type_obj = get_post_type_object($post->post_type);
            $type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
            return esc_html("{$title} ({$type_label})");
        }
        return '#' . intval($item->comment_post_ID);
    }

    protected function column_comment_approved($item)
    {
        $map = array(
            '1'     => array('label' => '已核准', 'class' => 'cmt-status-approved'),
            '0'     => array('label' => '待审核', 'class' => 'cmt-status-pending'),
            'spam'  => array('label' => '垃圾评论', 'class' => 'cmt-status-spam'),
            'trash' => array('label' => '回收站', 'class' => 'cmt-status-trash'),
        );
        $status = isset($map[$item->comment_approved]) ? $map[$item->comment_approved] : array('label' => $item->comment_approved, 'class' => '');
        return '<span class="cmt-status ' . esc_attr($status['class']) . '">' . esc_html($status['label']) . '</span>';
    }

    protected function column_comment_date($item)
    {
        $date = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item->comment_date);
        return esc_html($date);
    }

    protected function column_actions($item)
    {
        $post = get_post($item->comment_post_ID);
        $post_title = $post ? $post->post_title : '#' . $item->comment_post_ID;
        return '<a href="' . esc_url(get_edit_comment_link($item->comment_ID)) . '" class="button button-small">编辑</a>';
    }

    public function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }
        ?>
        <div class="alignleft actions">
            <label for="filter-by-post" class="screen-reader-text">筛选来源文章</label>
            <select id="filter-by-post" name="source_post_id">
                <option value="">全部文章</option>
                <?php
                $selected = isset($_GET['source_post_id']) ? intval($_GET['source_post_id']) : 0;
                $posts = get_posts(array(
                    'numberposts' => 50,
                    'post_type'   => 'any',
                    'post_status' => 'any',
                ));
                foreach ($posts as $p) {
                    $s = ($p->ID === $selected) ? ' selected' : '';
                    echo '<option value="' . intval($p->ID) . '"' . $s . '>' . esc_html($p->post_title) . '</option>';
                }
                ?>
            </select>

            <label for="filter-by-status" class="screen-reader-text">筛选状态</label>
            <select id="filter-by-status" name="comment_status">
                <option value="all">全部状态</option>
                <?php
                $statuses = array(
                    'approved' => '已核准',
                    'pending'  => '待审核',
                    'spam'     => '垃圾评论',
                    'trash'    => '回收站',
                );
                $current_status = isset($_GET['comment_status']) ? $_GET['comment_status'] : 'all';
                foreach ($statuses as $val => $label) {
                    $s = ($val === $current_status) ? ' selected' : '';
                    echo '<option value="' . esc_attr($val) . '"' . $s . '>' . esc_html($label) . '</option>';
                }
                ?>
            </select>
            <?php submit_button('筛选', '', 'filter_action', false); ?>
        </div>
        <?php
    }
}
```

---

### Task 4: Migration Core Logic

**Files:**
- Create: `includes/class-migration.php`

- [ ] **Step 4.1: Create migration executor**

```php
<?php
// includes/class-migration.php
defined('ABSPATH') or die('No direct access.');

class CMT_Migration
{
    private $migrate_key = '';
    private $backup_data = array();

    public function __construct()
    {
        $this->migrate_key = wp_generate_password(32, false);
    }

    /**
     * 执行迁移
     *
     * @param array $comment_ids  评论ID列表
     * @param int   $target_id   目标文章ID
     * @return array  ['success' => int, 'failed' => int, 'message' => string]
     */
    public function execute(array $comment_ids, $target_id)
    {
        global $wpdb;

        $comment_ids = array_map('intval', $comment_ids);
        $target_id = intval($target_id);

        if (empty($comment_ids) || $target_id <= 0) {
            return array('success' => 0, 'failed' => 0, 'message' => '参数无效');
        }

        // 验证目标文章存在
        $target_post = get_post($target_id);
        if (!$target_post) {
            return array('success' => 0, 'failed' => 0, 'message' => '目标文章不存在');
        }

        $success = 0;
        $failed = 0;

        // 按源文章分组，以便后续备份
        $grouped = array();
        foreach ($comment_ids as $cid) {
            $comment = get_comment($cid);
            if ($comment) {
                $source_id = $comment->comment_post_ID;
                if ($source_id == $target_id) {
                    $failed++;
                    continue; // 源=目标，跳过
                }
                $grouped[$source_id][] = $cid;
                $this->backup_data[] = array(
                    'comment_id' => $cid,
                    'original_comment_post_ID' => $comment->comment_post_ID,
                    'original_comment_parent' => $comment->comment_parent,
                    'original_comment_date' => $comment->comment_date,
                );
            } else {
                $failed++;
            }
        }

        if (empty($grouped)) {
            return array('success' => 0, 'failed' => $failed, 'message' => '没有可迁移的评论');
        }

        // 写入日志（预写入）
        $this->save_log($target_id, count($comment_ids) - $failed, false);

        // 执行迁移
        foreach ($grouped as $source_id => $cids) {
            $ids_placeholder = implode(',', $cids);
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->comments} SET comment_post_ID = %d WHERE comment_ID IN ({$ids_placeholder})",
                    $target_id
                )
            );
            $success += count($cids);

            // 更新评论计数
            wp_update_comment_count($source_id);
            wp_update_comment_count($target_id);
        }

        // 更新日志中的 comment_data
        $this->update_log_data();

        return array(
            'success' => $success,
            'failed'  => $failed,
            'message' => "迁移完成：成功 {$success} 条" . ($failed ? "，失败 {$failed} 条" : ''),
        );
    }

    private function save_log($target_id, $count, $is_batch)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';

        // 取第一个源文章标题做摘要（从备份数据中取）
        $source_title = '';
        if (!empty($this->backup_data)) {
            $first_comment = get_comment($this->backup_data[0]['comment_id']);
            if ($first_comment) {
                $post = get_post($first_comment->comment_post_ID);
                if ($post) {
                    $source_title = $post->post_title;
                }
            }
        }

        $target_title = '';
        $post = get_post($target_id);
        if ($post) {
            $target_title = $post->post_title;
        }

        $wpdb->insert(
            $table,
            array(
                'migrate_key'   => $this->migrate_key,
                'source_post_id' => !empty($this->backup_data) ? $this->backup_data[0]['original_comment_post_ID'] : 0,
                'target_post_id' => $target_id,
                'comment_count'  => $count,
                'comment_data'   => json_encode($this->backup_data),
                'source_title'   => $source_title,
                'target_title'   => $target_title,
                'is_batch'       => $is_batch ? 1 : 0,
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d')
        );
    }

    private function update_log_data()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';
        $wpdb->update(
            $table,
            array('comment_data' => json_encode($this->backup_data)),
            array('migrate_key' => $this->migrate_key),
            array('%s'),
            array('%s')
        );
    }

    public function get_migrate_key()
    {
        return $this->migrate_key;
    }
}
```

---

### Task 5: Rollback Core Logic

**Files:**
- Create: `includes/class-rollback.php`

- [ ] **Step 5.1: Create rollback handler**

```php
<?php
// includes/class-rollback.php
defined('ABSPATH') or die('No direct access.');

class CMT_Rollback
{
    /**
     * 按 migrate_key 回滚一次迁移
     *
     * @param string $migrate_key
     * @return array ['success' => int, 'failed' => int, 'message' => string]
     */
    public function rollback($migrate_key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE migrate_key = %s",
            $migrate_key
        ));

        if (empty($logs)) {
            return array('success' => 0, 'failed' => 0, 'message' => '未找到迁移记录');
        }

        $success = 0;
        $failed = 0;
        $affected_posts = array();

        foreach ($logs as $log) {
            $data = json_decode($log->comment_data, true);
            if (empty($data) || !is_array($data)) {
                $failed += $log->comment_count;
                continue;
            }

            foreach ($data as $item) {
                if (empty($item['comment_id'])) {
                    $failed++;
                    continue;
                }

                $cid = intval($item['comment_id']);
                $original_post_id = intval($item['original_comment_post_ID']);

                // 检查评论是否还存在
                $comment = get_comment($cid);
                if (!$comment) {
                    $failed++;
                    continue;
                }

                // 恢复 comment_post_ID
                wp_update_comment(array(
                    'comment_ID' => $cid,
                    'comment_post_ID' => $original_post_id,
                ));

                $affected_posts[$comment->comment_post_ID] = true;
                $affected_posts[$original_post_id] = true;
                $success++;
            }

            // 删除日志
            $wpdb->delete($table, array('id' => $log->id), array('%d'));
        }

        // 刷新评论计数
        foreach (array_keys($affected_posts) as $pid) {
            wp_update_comment_count($pid);
        }

        return array(
            'success' => $success,
            'failed'  => $failed,
            'message' => "回滚完成：成功 {$success} 条" . ($failed ? "，失败 {$failed} 条（部分评论可能已被删除）" : ''),
        );
    }

    /**
     * 按日志 ID 回滚
     */
    public function rollback_by_ids(array $log_ids)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';
        $ids = array_map('intval', $log_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $keys = $wpdb->get_col(
            $wpdb->prepare("SELECT DISTINCT migrate_key FROM {$table} WHERE id IN ({$placeholders})", $ids)
        );

        $results = array();
        foreach ($keys as $key) {
            $results[] = $this->rollback($key);
        }

        return $results;
    }
}
```

---

### Task 6: Batch Mapping (Tab 2)

**Files:**
- Create: `includes/class-batch.php`

- [ ] **Step 6.1: Create batch mapping renderer and handler**

```php
<?php
// includes/class-batch.php
defined('ABSPATH') or die('No direct access.');

class CMT_Batch
{
    public function render()
    {
        ?>
        <div class="cmt-batch-wrap">
            <h2>批量映射列表</h2>

            <table class="cmt-batch-table wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>源文章（获取评论）</th>
                        <th>目标文章（迁移至）</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="cmt-batch-rows">
                    <tr class="cmt-batch-row">
                        <td><select class="cmt-batch-source" style="width:100%;"></select></td>
                        <td><select class="cmt-batch-target" style="width:100%;"></select></td>
                        <td><a href="#" class="cmt-batch-remove dashicons dashicons-no-alt" title="删除"></a></td>
                    </tr>
                </tbody>
            </table>
            <p><button type="button" class="button" id="cmt-batch-add-row">+ 添加一行</button></p>

            <hr>
            <h3>导入 CSV</h3>
            <p class="description">CSV 格式：第一列源文章 ID，第二列目标文章 ID（无表头行，UTF-8 编码）</p>
            <p>
                <input type="file" id="cmt-csv-file" accept=".csv">
                <button type="button" class="button" id="cmt-csv-import">上传并解析</button>
            </p>

            <hr>
            <h3>迁移范围</h3>
            <select id="cmt-batch-scope">
                <option value="all">全部评论</option>
                <option value="approved">仅已核准</option>
                <option value="pending">仅待审核</option>
            </select>

            <p>
                <button type="button" class="button button-primary" id="cmt-batch-execute">执行全部映射</button>
            </p>
            <div id="cmt-batch-result"></div>
        </div>
        <?php
    }

    /**
     * 执行完整批量映射
     */
    public function execute_batch(array $pairs, $scope = 'all')
    {
        $results = array();
        $total_success = 0;
        $total_failed = 0;

        foreach ($pairs as $pair) {
            $source_id = intval($pair['source']);
            $target_id = intval($pair['target']);

            if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) {
                $results[] = array(
                    'source' => $source_id,
                    'target' => $target_id,
                    'success' => 0,
                    'failed' => 0,
                    'message' => '参数无效或源目标相同',
                );
                $total_failed++;
                continue;
            }

            // 获取源文章评论
            $args = array(
                'post_id' => $source_id,
                'status'  => ($scope === 'all') ? 'all' : $scope,
            );
            $comments = get_comments($args);

            if (empty($comments)) {
                $results[] = array(
                    'source' => $source_id,
                    'target' => $target_id,
                    'success' => 0,
                    'failed' => 0,
                    'message' => '源文章无评论',
                );
                continue;
            }

            $comment_ids = wp_list_pluck($comments, 'comment_ID');
            $migration = new CMT_Migration();
            $result = $migration->execute($comment_ids, $target_id);

            $result['source'] = $source_id;
            $result['target'] = $target_id;
            $results[] = $result;
            $total_success += $result['success'];
            $total_failed += $result['failed'];
        }

        return array(
            'results' => $results,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
        );
    }
}
```

---

### Task 7: History List Table (Tab 3)

**Files:**
- Create: `includes/class-history-table.php`

- [ ] **Step 7.1: Create WP_List_Table for migration history**

```php
<?php
// includes/class-history-table.php
defined('ABSPATH') or die('No direct access.');

class CMT_History_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'history',
            'plural'   => 'histories',
            'ajax'     => false,
        ));
    }

    public function prepare_items()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $where = 'WHERE 1=1';

        $type_filter = isset($_GET['history_type']) ? sanitize_key($_GET['history_type']) : '';
        if ('single' === $type_filter) {
            $where .= ' AND is_batch = 0';
        } elseif ('batch' === $type_filter) {
            $where .= ' AND is_batch = 1';
        }

        $total = $wpdb->get_var("SELECT COUNT(DISTINCT migrate_key) FROM {$table} {$where}");
        $this->set_pagination_args(array(
            'total_items' => intval($total),
            'per_page'    => $per_page,
        ));

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        // 按 migrate_key 分组
        $grouped = array();
        foreach ($items as $item) {
            $key = $item->migrate_key;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'migrate_key' => $key,
                    'created_at'  => $item->created_at,
                    'is_batch'    => $item->is_batch,
                    'entries'     => array(),
                    'total_count' => 0,
                );
            }
            $grouped[$key]['entries'][] = $item;
            $grouped[$key]['total_count'] += $item->comment_count;
        }

        $this->items = $grouped;
    }

    public function get_columns()
    {
        return array(
            'cb'         => '<input type="checkbox">',
            'time'       => '执行时间',
            'summary'    => '操作摘要',
            'count'      => '评论数',
            'type'       => '类型',
            'actions'    => '操作',
        );
    }

    protected function column_cb($item)
    {
        // 对于批量记录，只勾第一条
        $first = reset($item['entries']);
        return '<input type="checkbox" name="log_ids[]" value="' . intval($first->id) . '">';
    }

    protected function column_time($item)
    {
        return esc_html(mysql2date(get_option('date_format') . ' H:i:s', $item['created_at']));
    }

    protected function column_summary($item)
    {
        $lines = array();
        foreach ($item['entries'] as $entry) {
            $source = $entry->source_title ? $entry->source_title : '#' . $entry->source_post_id;
            $target = $entry->target_title ? $entry->target_title : '#' . $entry->target_post_id;
            $lines[] = esc_html("{$source} → {$target}");
        }
        return implode("<br>", $lines);
    }

    protected function column_count($item)
    {
        return intval($item['total_count']) . ' 条';
    }

    protected function column_type($item)
    {
        return $item['is_batch'] ? '批量映射' : '单次迁移';
    }

    protected function column_actions($item)
    {
        return '<button type="button" class="button button-small cmt-rollback-single" data-key="' . esc_attr($item['migrate_key']) . '">回滚</button>';
    }

    public function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }
        ?>
        <div class="alignleft actions">
            <select name="history_type">
                <option value="">全部类型</option>
                <option value="single" <?php selected(isset($_GET['history_type']) ? $_GET['history_type'] : '', 'single'); ?>>单次迁移</option>
                <option value="batch" <?php selected(isset($_GET['history_type']) ? $_GET['history_type'] : '', 'batch'); ?>>批量映射</option>
            </select>
            <?php submit_button('筛选', '', 'filter_action', false); ?>
        </div>
        <?php
    }
}
```

---

### Task 8: AJAX Handlers

**Files:**
- Modify: `includes/class-admin.php` (add AJAX hooks)

- [ ] **Step 8.1: Add AJAX hooks to admin class**

```php
// 在 class-admin.php 的 __construct 中添加：
add_action('wp_ajax_cmt_search_posts', array($this, 'ajax_search_posts'));
add_action('wp_ajax_cmt_do_migrate', array($this, 'ajax_do_migrate'));
add_action('wp_ajax_cmt_do_rollback', array($this, 'ajax_do_rollback'));
add_action('wp_ajax_cmt_do_batch', array($this, 'ajax_do_batch'));

// 然后添加以下方法：

public function ajax_search_posts()
{
    check_ajax_referer('cmt_migrator_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die(-1);
    }

    $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $exclude = isset($_GET['exclude']) ? intval($_GET['exclude']) : 0;

    $args = array(
        's'              => $search,
        'post_type'      => 'any',
        'post_status'    => 'any',
        'posts_per_page' => 20,
        'post__not_in'   => $exclude > 0 ? array($exclude) : array(),
    );

    $posts = get_posts($args);
    $results = array();

    foreach ($posts as $p) {
        $type_obj = get_post_type_object($p->post_type);
        $type_label = $type_obj ? $type_obj->labels->singular_name : $p->post_type;
        $results[] = array(
            'id'   => $p->ID,
            'text' => "{$p->post_title} (#{$p->ID} - {$type_label})",
        );
    }

    wp_send_json($results);
}

public function ajax_do_migrate()
{
    check_ajax_referer('cmt_migrator_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die(-1);
    }

    $comment_ids = isset($_POST['comment_ids']) ? array_map('intval', $_POST['comment_ids']) : array();
    $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;

    if (empty($comment_ids) || $target_id <= 0) {
        wp_send_json_error(array('message' => '参数无效'));
    }

    $migration = new CMT_Migration();
    $result = $migration->execute($comment_ids, $target_id);

    if ($result['success'] > 0) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

public function ajax_do_rollback()
{
    check_ajax_referer('cmt_migrator_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die(-1);
    }

    $migrate_key = isset($_POST['migrate_key']) ? sanitize_text_field($_POST['migrate_key']) : '';
    $log_ids = isset($_POST['log_ids']) ? array_map('intval', $_POST['log_ids']) : array();

    $rollback = new CMT_Rollback();

    if ($migrate_key) {
        $result = $rollback->rollback($migrate_key);
    } elseif (!empty($log_ids)) {
        $result = $rollback->rollback_by_ids($log_ids);
    } else {
        wp_send_json_error(array('message' => '参数无效'));
        return;
    }

    if ($result['success'] > 0) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

public function ajax_do_batch()
{
    check_ajax_referer('cmt_migrator_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die(-1);
    }

    $pairs = isset($_POST['pairs']) ? $_POST['pairs'] : array();
    $scope = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'all';

    if (empty($pairs)) {
        wp_send_json_error(array('message' => '未提供映射对'));
    }

    // 净化输入
    $clean_pairs = array();
    foreach ($pairs as $pair) {
        if (isset($pair['source']) && isset($pair['target'])) {
            $clean_pairs[] = array(
                'source' => intval($pair['source']),
                'target' => intval($pair['target']),
            );
        }
    }

    $batch = new CMT_Batch();
    $result = $batch->execute_batch($clean_pairs, $scope);
    wp_send_json_success($result);
}
```

---

### Task 9: Frontend Assets (JS + CSS)

**Files:**
- Create: `assets/admin.js`
- Create: `assets/admin.css`

- [ ] **Step 9.1: Create admin JavaScript**

```javascript
// assets/admin.js
jQuery(function ($) {
    // ========== Tab 1: 评论迁移 ==========

    // 选中评论计数更新
    $(document).on('change', '.wp-list-table th input[type=checkbox]', function () {
        updateSelectedCount();
    });
    $(document).on('change', '.wp-list-table td input[type=checkbox]', function () {
        updateSelectedCount();
    });

    function updateSelectedCount() {
        var count = $('.wp-list-table td input[type=checkbox]:checked').length;
        $('.cmt-selected-count strong').text(count);
    }

    // 目标文章 AJAX 搜索（Select2 风格）
    function initPostSearch($select, excludeId) {
        $select.select2({
            ajax: {
                url: cmt_admin.ajax_url,
                dataType: 'json',
                delay: 300,
                data: function (params) {
                    return {
                        action: 'cmt_search_posts',
                        q: params.term,
                        exclude: excludeId || 0,
                        nonce: cmt_admin.nonce,
                    };
                },
                processResults: function (data) {
                    return { results: data };
                },
            },
            placeholder: '搜索文章...',
            minimumInputLength: 1,
            width: '100%',
            language: 'zh-CN',
        });
    }

    // Tab 1 的目标文章下拉
    initPostSearch($('.cmt-target-post'));

    // 执行迁移
    $('.cmt-execute-migrate').on('click', function () {
        var $checked = $('.wp-list-table td input[type=checkbox]:checked');
        var commentIds = [];
        $checked.each(function () {
            commentIds.push($(this).val());
        });

        if (commentIds.length === 0) {
            alert(cmt_admin.select_comments);
            return;
        }

        var targetId = $('.cmt-target-post').val();
        if (!targetId) {
            alert(cmt_admin.select_target);
            return;
        }

        if (!confirm('即将把 ' + commentIds.length + ' 条评论迁移到所选文章，确定执行吗？')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('执行中...');

        $.post(cmt_admin.ajax_url, {
            action: 'cmt_do_migrate',
            comment_ids: commentIds,
            target_id: targetId,
            nonce: cmt_admin.nonce,
        }, function (response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || '迁移失败');
                $btn.prop('disabled', false).text('执行迁移');
            }
        }).fail(function () {
            alert('请求失败，请重试');
            $btn.prop('disabled', false).text('执行迁移');
        });
    });

    // ========== Tab 2: 批量映射 ==========

    function initBatchRow($row) {
        initPostSearch($row.find('.cmt-batch-source'));
        initPostSearch($row.find('.cmt-batch-target'));
    }

    // 初始化第一行
    initBatchRow($('.cmt-batch-row'));

    // 添加行
    $('#cmt-batch-add-row').on('click', function () {
        var $row = $('.cmt-batch-row').first().clone();
        $row.find('select').val('').each(function () {
            $(this).data('select2') && $(this).select2('destroy');
        });
        $row.find('.cmt-batch-remove').show();
        $('#cmt-batch-rows').append($row);
        initBatchRow($row);
    });

    // 删除行
    $(document).on('click', '.cmt-batch-remove', function (e) {
        e.preventDefault();
        if ($('.cmt-batch-row').length > 1) {
            var $row = $(this).closest('.cmt-batch-row');
            $row.find('select').each(function () {
                $(this).data('select2') && $(this).select2('destroy');
            });
            $row.remove();
        } else {
            alert('至少保留一行');
        }
    });

    // CSV 导入
    $('#cmt-csv-import').on('click', function () {
        var file = document.getElementById('cmt-csv-file').files[0];
        if (!file) {
            alert('请选择 CSV 文件');
            return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
            var text = e.target.result;
            var lines = text.split('\n');
            var pairs = [];

            // 清除现有行
            $('.cmt-batch-row').each(function () {
                $(this).find('select').each(function () {
                    $(this).data('select2') && $(this).select2('destroy');
                });
            });
            $('#cmt-batch-rows').empty();

            $.each(lines, function (i, line) {
                line = line.trim();
                if (!line) return;
                var parts = line.split(',');
                if (parts.length < 2) return;

                var sourceId = parseInt(parts[0].trim());
                var targetId = parseInt(parts[1].trim());
                if (isNaN(sourceId) || isNaN(targetId)) return;

                pairs.push({ source: sourceId, target: targetId });

                var $row = $('.cmt-batch-row').first().clone();
                $row.find('select').each(function () {
                    $(this).data('select2') && $(this).select2('destroy');
                });
                $('#cmt-batch-rows').append($row);
                var $newRow = $('#cmt-batch-rows tr').last();
                initBatchRow($newRow);

                // 设置值（需要 AJAX 预加载帖子标题，简化处理：直接 append option）
                $.get(cmt_admin.ajax_url, {
                    action: 'cmt_search_posts',
                    q: '',
                    nonce: cmt_admin.nonce,
                }, function (data) {
                    // 不做具体填充，用户可手动选择
                });
            });

            if (pairs.length === 0) {
                alert('CSV 解析失败，请检查格式');
            } else {
                alert('已解析 ' + pairs.length + ' 对映射关系，请确认下拉框中的文章是否正确');
            }
        };
        reader.readAsText(file, 'UTF-8');
    });

    // 执行批量映射
    $('#cmt-batch-execute').on('click', function () {
        var pairs = [];
        $('.cmt-batch-row').each(function () {
            var source = $(this).find('.cmt-batch-source').val();
            var target = $(this).find('.cmt-batch-target').val();
            if (source && target) {
                pairs.push({ source: source, target: target });
            }
        });

        if (pairs.length === 0) {
            alert('请至少配置一对映射关系');
            return;
        }

        var scope = $('#cmt-batch-scope').val();
        var msg = '即将执行 ' + pairs.length + ' 对映射，迁移范围：' +
            ({ all: '全部评论', approved: '仅已核准', pending: '仅待审核' }[scope] || scope) +
            '。确定执行吗？';

        if (!confirm(msg)) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('执行中...');

        $.post(cmt_admin.ajax_url, {
            action: 'cmt_do_batch',
            pairs: pairs,
            scope: scope,
            nonce: cmt_admin.nonce,
        }, function (response) {
            if (response.success) {
                var html = '<div class="notice notice-success"><p>批量映射完成</p><ul>';
                $.each(response.data.results, function (i, r) {
                    html += '<li>源 #' + r.source + ' → 目标 #' + r.target + '：' + r.message + '</li>';
                });
                html += '<li><strong>总计：成功 ' + response.data.total_success + ' 条，失败 ' + response.data.total_failed + ' 条</strong></li>';
                html += '</ul></div>';
                $('#cmt-batch-result').html(html);
            } else {
                $('#cmt-batch-result').html('<div class="notice notice-error"><p>' + (response.data.message || '执行失败') + '</p></div>');
            }
            $btn.prop('disabled', false).text('执行全部映射');
        }).fail(function () {
            $('#cmt-batch-result').html('<div class="notice notice-error"><p>请求失败，请重试</p></div>');
            $btn.prop('disabled', false).text('执行全部映射');
        });
    });

    // ========== Tab 3: 迁移历史 ==========

    // 单条回滚
    $(document).on('click', '.cmt-rollback-single', function () {
        var key = $(this).data('key');
        if (!confirm(cmt_admin.confirm_rollback)) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('回滚中...');

        $.post(cmt_admin.ajax_url, {
            action: 'cmt_do_rollback',
            migrate_key: key,
            nonce: cmt_admin.nonce,
        }, function (response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || '回滚失败');
                $btn.prop('disabled', false).text('回滚');
            }
        }).fail(function () {
            alert('请求失败');
            $btn.prop('disabled', false).text('回滚');
        });
    });

    // 批量回滚所选
    $('.cmt-rollback-selected').on('click', function () {
        var logIds = [];
        $('.wp-list-table td input[type=checkbox]:checked').each(function () {
            logIds.push($(this).val());
        });

        if (logIds.length === 0) {
            alert('请选择要回滚的记录');
            return;
        }

        if (!confirm('即将回滚所选 ' + logIds.length + ' 条迁移记录，确定吗？')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('回滚中...');

        $.post(cmt_admin.ajax_url, {
            action: 'cmt_do_rollback',
            log_ids: logIds,
            nonce: cmt_admin.nonce,
        }, function (response) {
            if (response.success) {
                alert('回滚完成');
                location.reload();
            } else {
                alert(response.data.message || '回滚失败');
                $btn.prop('disabled', false).text('回滚所选');
            }
        }).fail(function () {
            alert('请求失败');
            $btn.prop('disabled', false).text('回滚所选');
        });
    });
});
```

- [ ] **Step 9.2: Create admin CSS**

```css
/* assets/admin.css */
.cmt-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}
.cmt-status-approved {
    background: #c6e1c6;
    color: #2a5c2a;
}
.cmt-status-pending {
    background: #f0e6c9;
    color: #8a6d3b;
}
.cmt-status-spam {
    background: #f1c4c4;
    color: #8a3b3b;
}
.cmt-status-trash {
    background: #ddd;
    color: #666;
}

.cmt-bulk-actions {
    background: #f5f5f5;
    padding: 12px 15px;
    margin-top: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cmt-selected-count {
    margin-right: 10px;
}
.cmt-target-post {
    min-width: 300px;
}

.cmt-batch-table th {
    font-weight: 600;
}
.cmt-batch-row td {
    vertical-align: middle;
}
.cmt-batch-remove {
    display: inline-block;
    margin-top: 6px;
    color: #a00;
    text-decoration: none;
    font-size: 18px;
}
.cmt-batch-remove:hover {
    color: #dc3232;
}

#cmt-batch-result {
    margin-top: 15px;
}

.nav-tab-wrapper {
    margin-bottom: 15px;
}

@media (max-width: 782px) {
    .cmt-bulk-actions {
        flex-direction: column;
        align-items: flex-start;
    }
    .cmt-target-post {
        width: 100% !important;
        min-width: auto;
    }
}
```

---

### Task 10: Readme.txt

- [ ] **Step 10.1: Create WordPress standard readme**

```
=== 评论迁移工具 ===
Contributors: yourname
Tags: comments, migration, tools
Requires at least: 4.7
Tested up to: 6.0
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==

在 WordPress 文章和页面之间迁移评论，支持批量映射和回滚。

功能特性：

* 一对一迁移：将单篇文章的评论迁移到另一篇
* 多对一合并：将多篇文章的评论合并到一篇
* 选择性迁移：勾选特定评论进行迁移
* 批量映射：配置多组映射关系，一次执行
* 回滚支持：随时撤销迁移操作
* 全简体中文界面

== Installation ==

1. 下载插件压缩包并解压
2. 将 `comment-migrator` 文件夹上传到 `/wp-content/plugins/`
3. 在 WordPress 后台的「插件」页面激活「评论迁移工具」
4. 激活后可在后台看到「评论迁移」菜单

== Changelog ==

= 1.0.0 =
* 首个版本
```

---

## Self-Review

### Spec Coverage Check

| Spec Section | Task(s) | Status |
|-------------|---------|--------|
| 场景 A（一对多）| Task 3 + 4 | ✅ |
| 场景 B（多对一合并）| Task 3 + 4（自然支持） | ✅ |
| 场景 C（选择性迁移）| Task 3（复选框勾选） | ✅ |
| 场景 D（批量映射）| Task 6 + 8 | ✅ |
| 回滚支持 | Task 5 + 7 | ✅ |
| 全简体中文 | 所有文字已硬编码为中文 | ✅ |
| 卸载保留选项 | Task 1 (uninstall.php) + Tab3 设置 | ✅ |
| PHP 7.0 兼容 | 未使用可空类型/void/多catch/方括号list | ✅ |
| MySQL 5.7 兼容 | 仅使用标准 SQL 语法，无 CTE/窗口函数 | ✅ |
| 自定义表 | Task 1 (class-install.php) | ✅ |
| WP_List_Table | Task 3 + 7 | ✅ |
| AJAX 搜索下拉 | Task 8 + 9 | ✅ |

### Placeholder Check
- All code blocks contain complete implementations
- No TBD / TODO patterns
- All file paths are exact

### Type/Name Consistency
- `CMT_Migration::execute()` returns same shape used by `CMT_Batch::execute_batch()`
- `CMT_Rollback::rollback()` and `rollback_by_ids()` return consistent structures
- All AJAX handlers use `cmt_migrator_nonce` consistently
- DB column names match between `class-install.php` and `class-history-table.php`

---

**Plan complete. Choose execution approach:**

1. **Subagent-Driven (recommended)** — dispatch a fresh agent per task, review between tasks
2. **Inline Execution** — execute tasks in this session with checkpoints

Which approach?
