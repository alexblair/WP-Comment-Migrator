<?php
// includes/class-list-table.php
defined('ABSPATH') || die('No direct access.');

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

        $where_conditions = array('1=1');
        $query_args = array();

        if ($this->source_post_id > 0) {
            $where_conditions[] = 'c.comment_post_ID = %d';
            $query_args[] = $this->source_post_id;
        }

        $status = isset($_GET['comment_status']) ? sanitize_key($_GET['comment_status']) : '';
        if ($status && 'all' !== $status) {
            $status_map = array(
                'approved' => '1',
                'pending'  => '0',
                'spam'     => 'spam',
                'trash'    => 'trash',
            );
            if (isset($status_map[$status])) {
                $where_conditions[] = 'c.comment_approved = %s';
                $query_args[] = $status_map[$status];
            }
        }

        $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_conditions[] = '(c.comment_author LIKE %s OR c.comment_content LIKE %s)';
            $query_args[] = $search_like;
            $query_args[] = $search_like;
        }

        $where = 'WHERE ' . implode(' AND ', $where_conditions);

        $total_query = "SELECT COUNT(*) FROM {$wpdb->comments} c {$where}";
        if (!empty($query_args)) {
            $total_query = $wpdb->prepare($total_query, $query_args);
        }
        $total_items = $wpdb->get_var($total_query);

        $this->set_pagination_args(array(
            'total_items' => intval($total_items),
            'per_page'    => $per_page,
        ));

        $offset = ($current_page - 1) * $per_page;
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'comment_date';
        $order = isset($_GET['order']) && 'asc' === strtolower(sanitize_text_field(wp_unslash($_GET['order']))) ? 'ASC' : 'DESC';
        $allowed_orderby = array('comment_date', 'comment_author', 'comment_approved');
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'comment_date';
        }

        $data_sql = "SELECT c.* FROM {$wpdb->comments} c {$where} ORDER BY c.{$orderby} {$order} LIMIT %d OFFSET %d";
        $data_args = array_merge($query_args, array($per_page, $offset));
        $data_query = $wpdb->prepare($data_sql, $data_args);
        $this->items = $wpdb->get_results($data_query);
    }

    public function get_columns()
    {
        return array(
            'cb'               => '<input type="checkbox">',
            'comment_author'   => '评论作者',
            'comment_content'  => '评论内容',
            'comment_post_id'  => '来源文章',
            'comment_approved' => '状态',
            'comment_date'     => '日期',
            'actions'          => '操作',
        );
    }

    public function get_primary_column_name()
    {
        return 'comment_author';
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
        return isset($item->$column_name) ? esc_html($item->$column_name) : '—';
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
        $content = wp_strip_all_tags($item->comment_content);
        if (function_exists('mb_strlen') && mb_strlen($content) > 60) {
            $content = mb_substr($content, 0, 60) . '...';
        } elseif (!function_exists('mb_strlen') && strlen($content) > 60) {
            $content = substr($content, 0, 60) . '...';
        }
        return esc_html($content);
    }

    public function column_comment_post_id($item)
    {
        $id = 0;
        if (isset($item->comment_post_ID)) {
            $id = intval($item->comment_post_ID);
        } elseif (isset($item->comment_post_id)) {
            $id = intval($item->comment_post_id);
        }
        if ($id > 0) {
            $post = get_post($id);
            if ($post) {
                $title = trim($post->post_title);
                if ('' !== $title) {
                    if (function_exists('mb_strlen') && mb_strlen($title) > 30) {
                        $title = mb_substr($title, 0, 30) . '...';
                    } elseif (!function_exists('mb_strlen') && strlen($title) > 30) {
                        $title = substr($title, 0, 30) . '...';
                    }
                    $type_obj = get_post_type_object($post->post_type);
                    $type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
                    $preview_url = get_permalink($id);
                    if ($preview_url) {
                        return '<a href="' . esc_url($preview_url) . '" target="_blank" title="预览文章">' . esc_html("{$title} ({$type_label})") . '</a>';
                    }
                    return esc_html("{$title} ({$type_label})");
                }
                $edit_link = get_edit_post_link($id);
                if ($edit_link) {
                    return '<a href="' . esc_url($edit_link) . '">#' . $id . '</a>';
                }
            }
            return '<em>文章已删除 (#' . $id . ')</em>';
        }
        return '<em>—</em>';
    }

    protected function column_comment_approved($item)
    {
        $label_map = array(
            '1'     => array('label' => '已核准', 'class' => 'cmt-status-approved'),
            '0'     => array('label' => '待审核', 'class' => 'cmt-status-pending'),
            'spam'  => array('label' => '垃圾评论', 'class' => 'cmt-status-spam'),
            'trash' => array('label' => '回收站', 'class' => 'cmt-status-trash'),
        );
        $st = isset($label_map[$item->comment_approved]) ? $label_map[$item->comment_approved] : array('label' => $item->comment_approved, 'class' => '');
        $cid = intval($item->comment_ID);
        $html = '<span class="cmt-status ' . esc_attr($st['class']) . '">' . esc_html($st['label']) . '</span>';

        if ('1' === $item->comment_approved) {
            $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="hold">驳回</button>';
        } elseif ('0' === $item->comment_approved) {
            $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="approve">通过</button>';
        }

        $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="spam">垃圾评论</button>';
        $html .= ' <button type="button" class="button button-small cmt-btn-status" data-comment-id="' . $cid . '" data-status="trash">回收站</button>';

        return $html;
    }

    protected function column_comment_date($item)
    {
        $date = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item->comment_date);
        return esc_html($date);
    }

    protected function column_actions($item)
    {
        $edit_link = get_edit_comment_link($item->comment_ID);
        if ($edit_link) {
            return '<a href="' . esc_url($edit_link) . '" class="button button-small">编辑</a>';
        }
        return '';
    }

    public function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }
        $selected_post = isset($_GET['source_post_id']) ? intval($_GET['source_post_id']) : 0;
        $current_status = isset($_GET['comment_status']) ? sanitize_text_field(wp_unslash($_GET['comment_status'])) : 'all';
        ?>
        <div class="alignleft actions">
            <label for="filter-by-post" class="screen-reader-text">筛选来源文章</label>
            <select class="cmt-source-post" name="source_post_id" style="min-width:200px;">
                <option value="">全部文章</option>
                <?php if ($selected_post > 0) {
                    $p = get_post($selected_post);
                    if ($p) {
                        echo '<option value="' . intval($p->ID) . '" selected>' . esc_html($p->post_title) . '</option>';
                    }
                } ?>
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
