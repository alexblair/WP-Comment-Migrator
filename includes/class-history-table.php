<?php
// includes/class-history-table.php
defined('ABSPATH') || die('No direct access.');

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

        $grouped = array();
        foreach ($items as $item) {
            $key = $item->migrate_key;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'migrate_key' => $key,
                    'created_at'  => $item->created_at,
                    'is_batch'    => $item->is_batch,
                    'operator_id' => (int) $item->operator_id,
                    'entries'     => array(),
                    'total_count' => 0,
                    'all_comments' => array(),
                );
            }
            $grouped[$key]['entries'][] = $item;
            $grouped[$key]['total_count'] += $item->comment_count;
            $data = json_decode($item->comment_data, true);
            if (is_array($data)) {
                $grouped[$key]['all_comments'] = array_merge($grouped[$key]['all_comments'], $data);
            }
        }

        $this->items = $grouped;
    }

    public function get_columns()
    {
        return array(
            'cb'       => '<input type="checkbox">',
            'time'     => '执行时间',
            'operator' => '操作人',
            'summary'  => '迁移摘要',
            'count'    => '评论数',
            'type'     => '类型',
            'actions'  => '操作',
        );
    }

    public function get_column_info()
    {
        if (isset($this->_column_headers) && is_array($this->_column_headers)) {
            return $this->_column_headers;
        }
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $primary = $this->get_primary_column_name();
        $this->_column_headers = array($columns, $hidden, $sortable, $primary);
        return $this->_column_headers;
    }

    public function get_primary_column_name()
    {
        return 'summary';
    }

    private function get_post_label($post_id, $title)
    {
        if ($title) {
            $label = $title;
            if (function_exists('mb_strlen') && mb_strlen($label) > 40) {
                $label = mb_substr($label, 0, 40) . '…';
            } elseif (strlen($label) > 40) {
                $label = substr($label, 0, 40) . '…';
            }
            return esc_html($label) . ' <small>#' . intval($post_id) . '</small>';
        }
        return '<em>#' . intval($post_id) . '</em>';
    }

    protected function column_cb($item)
    {
        $first = reset($item['entries']);
        return '<input type="checkbox" name="log_ids[]" value="' . intval($first->id) . '">';
    }

    protected function column_time($item)
    {
        return esc_html(mysql2date(get_option('date_format') . ' H:i:s', $item['created_at']));
    }

    protected function column_operator($item)
    {
        $uid = $item['operator_id'];
        if ($uid > 0) {
            $user = get_userdata($uid);
            if ($user) {
                $name = $user->display_name ? $user->display_name : $user->user_login;
                return esc_html($name);
            }
        }
        return '<em>—</em>';
    }

    protected function column_summary($item)
    {
        $lines = array();
        foreach ($item['entries'] as $entry) {
            $s = $this->get_post_label($entry->source_post_id, $entry->source_title);
            $t = $this->get_post_label($entry->target_post_id, $entry->target_title);
            $lines[] = '从 ' . $s . ' → 到 ' . $t;
        }

        $comments_html = array();
        $shown = 0;
        foreach ($item['all_comments'] as $cdata) {
            if ($shown >= 5) {
                $comments_html[] = '<em>…还有 ' . (count($item['all_comments']) - 5) . ' 条评论</em>';
                break;
            }
            $author = isset($cdata['comment_author']) ? $cdata['comment_author'] : '';
            $content = isset($cdata['comment_content']) ? wp_strip_all_tags($cdata['comment_content']) : '';
            if (function_exists('mb_strlen') && mb_strlen($content) > 30) {
                $content = mb_substr($content, 0, 30) . '…';
            } elseif (strlen($content) > 30) {
                $content = substr($content, 0, 30) . '…';
            }
            $cid = isset($cdata['comment_id']) ? $cdata['comment_id'] : '';
            $comments_html[] = esc_html("{$author}: {$content}") . ' <small>#' . intval($cid) . '</small>';
            $shown++;
        }

        $out = implode('<br>', $lines);
        $out .= '<br><small>' . implode('<br>', $comments_html) . '</small>';
        return $out;
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
        $current_type = isset($_GET['history_type']) ? sanitize_key($_GET['history_type']) : '';
        ?>
        <div class="alignleft actions">
            <select name="history_type">
                <option value="">全部类型</option>
                <option value="single" <?php selected($current_type, 'single'); ?>>单次迁移</option>
                <option value="batch" <?php selected($current_type, 'batch'); ?>>批量映射</option>
            </select>
            <?php submit_button('筛选', '', 'filter_action', false); ?>
        </div>
        <?php
    }
}
