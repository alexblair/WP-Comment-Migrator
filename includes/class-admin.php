<?php
// includes/class-admin.php
defined('ABSPATH') || die('No direct access.');

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
        add_action('admin_init', array($this, 'handle_post_actions'));
        add_action('wp_ajax_cmt_search_posts', array($this, 'ajax_search_posts'));
        add_action('wp_ajax_cmt_search_comments', array($this, 'ajax_search_comments'));
        add_action('wp_ajax_cmt_do_migrate', array($this, 'ajax_do_migrate'));
        add_action('wp_ajax_cmt_do_rollback', array($this, 'ajax_do_rollback'));
        add_action('wp_ajax_cmt_do_batch', array($this, 'ajax_do_batch'));
        add_action('wp_ajax_cmt_approve_comment', array($this, 'ajax_approve_comment'));
    }

    public function handle_post_actions()
    {
        if (!isset($_POST['cmt_settings_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cmt_settings_nonce'])), 'cmt_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        update_option('cmt_migrator_keep_tables', isset($_POST['cmt_keep_tables']) ? 1 : 0);
        wp_safe_redirect(add_query_arg('cmt_saved', '1', wp_get_referer()));
        exit;
    }

    public function add_admin_menu()
    {
        $hook = add_menu_page(
            '评论迁移',
            '评论迁移',
            'manage_options',
            CMT_MIGRATOR_MENU_SLUG,
            array($this, 'render_page'),
            'dashicons-admin-comments',
            25
        );
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_' . CMT_MIGRATOR_MENU_SLUG !== $hook) {
            return;
        }
        wp_enqueue_style('select2', CMT_MIGRATOR_PLUGIN_URL . 'assets/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('select2', CMT_MIGRATOR_PLUGIN_URL . 'assets/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('cmt-admin-css', CMT_MIGRATOR_PLUGIN_URL . 'assets/admin.css', array('select2'), CMT_MIGRATOR_VERSION);
        wp_enqueue_script('cmt-admin-js', CMT_MIGRATOR_PLUGIN_URL . 'assets/admin.js', array('jquery', 'select2'), CMT_MIGRATOR_VERSION, true);
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
        $this->current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'migrate';
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
            $url = admin_url('admin.php?page=' . CMT_MIGRATOR_MENU_SLUG . '&tab=' . $key);
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
            <input type="hidden" name="page" value="<?php echo CMT_MIGRATOR_MENU_SLUG; ?>">
            <input type="hidden" name="tab" value="migrate">
            <?php $table->search_box('搜索评论', 'comment-search'); ?>
            <?php $table->display(); ?>
        </form>
        <div class="cmt-bulk-actions">
            <span class="cmt-selected-count">已选择 <strong>0</strong> 条评论</span>
            <label>迁移至目标文章：</label>
            <select class="cmt-target-post" style="width:300px;"></select>
            <div class="cmt-parent-selector" style="display:none;">
                <label>回复至（可选）：</label>
                <select class="cmt-target-parent" style="width:300px;">
                    <option value="">顶级层（不回复）</option>
                </select>
            </div>
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
        if (isset($_GET['cmt_saved'])) {
            echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
        }
    }

    public function ajax_search_posts()
    {
        check_ajax_referer('cmt_migrator_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
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

    public function ajax_search_comments()
    {
        check_ajax_referer('cmt_migrator_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }

        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        if ($post_id <= 0) {
            wp_send_json(array());
        }

        $comments = get_comments(array(
            'post_id' => $post_id,
            'status'  => 'all',
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        ));

        $by_parent = array();
        foreach ($comments as $c) {
            $pid = (int) $c->comment_parent;
            if (!isset($by_parent[$pid])) {
                $by_parent[$pid] = array();
            }
            $by_parent[$pid][] = $c;
        }

        $results = array();
        $this->build_comment_tree($by_parent, 0, 0, $results);

        wp_send_json($results);
    }

    private function build_comment_tree(&$by_parent, $parent_id, $depth, &$results)
    {
        if (!isset($by_parent[$parent_id])) {
            return;
        }
        foreach ($by_parent[$parent_id] as $c) {
            $author = wp_trim_words($c->comment_author, 3);
            $content = wp_trim_words(wp_strip_all_tags($c->comment_content), 6);
            $prefix = $depth > 0 ? str_repeat('─', $depth) . ' ' : '';
            $results[] = array(
                'id'   => $c->comment_ID,
                'text' => $prefix . "{$author}: {$content} (#{$c->comment_ID})",
            );
            $this->build_comment_tree($by_parent, $c->comment_ID, $depth + 1, $results);
        }
    }

    public function ajax_do_migrate()
    {
        check_ajax_referer('cmt_migrator_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }

        $comment_ids = isset($_POST['comment_ids']) ? array_map('intval', $_POST['comment_ids']) : array();
        $comment_ids = array_values(array_filter($comment_ids, function ($id) {
            return $id > 0;
        }));
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $target_parent_id = isset($_POST['target_parent_id']) ? intval($_POST['target_parent_id']) : 0;

        if (empty($comment_ids) || $target_id <= 0) {
            wp_send_json_error(array('message' => '参数无效'));
        }

        $migration = new CMT_Migration();
        $result = $migration->execute($comment_ids, $target_id, $target_parent_id);

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

        $migrate_key = isset($_POST['migrate_key']) ? sanitize_text_field(wp_unslash($_POST['migrate_key'])) : '';
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

        $pairs = isset($_POST['pairs']) ? wp_unslash($_POST['pairs']) : array();
        $scope = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'all';

        if (empty($pairs) || !is_array($pairs)) {
            wp_send_json_error(array('message' => '未提供映射对'));
        }

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

    public function ajax_approve_comment()
    {
        check_ajax_referer('cmt_migrator_nonce', 'nonce');

        if (!current_user_can('moderate_comments')) {
            wp_die(-1);
        }

        $comment_id = isset($_POST['comment_id']) ? intval($_POST['comment_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

        $valid_statuses = array('approve', 'hold', 'spam', 'trash');
        if ($comment_id <= 0 || !in_array($status, $valid_statuses)) {
            wp_send_json_error(array('message' => '参数无效'));
        }

        $result = wp_set_comment_status($comment_id, $status);

        if (false === $result) {
            wp_send_json_error(array('message' => '操作失败'));
        }

        $comment = get_comment($comment_id);

        $label_map = array(
            '1'     => array('label' => '已核准', 'class' => 'cmt-status-approved'),
            '0'     => array('label' => '待审核', 'class' => 'cmt-status-pending'),
            'spam'  => array('label' => '垃圾评论', 'class' => 'cmt-status-spam'),
            'trash' => array('label' => '回收站', 'class' => 'cmt-status-trash'),
        );
        $st = isset($label_map[$comment->comment_approved]) ? $label_map[$comment->comment_approved] : array('label' => $comment->comment_approved, 'class' => '');
        $cid = intval($comment->comment_ID);
        $html = '<span class="cmt-status ' . esc_attr($st['class']) . '">' . esc_html($st['label']) . '</span>';

        if ('1' === $comment->comment_approved) {
            $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="hold">驳回</button>';
        } elseif ('0' === $comment->comment_approved) {
            $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="approve">通过</button>';
        }

        $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="spam">垃圾评论</button>';
        $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="trash">回收站</button>';

        wp_send_json_success(array('html' => $html, 'approved' => $comment->comment_approved));
    }
}
