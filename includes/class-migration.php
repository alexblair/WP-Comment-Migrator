<?php
// includes/class-migration.php
defined('ABSPATH') || die('No direct access.');

class CMT_Migration
{
    private $migrate_key = '';
    private $backup_data = array();
    private $source_post_ids = array();
    private $target_post_id = 0;

    public function __construct()
    {
        $this->migrate_key = wp_generate_password(32, false);
    }

    public function execute(array $comment_ids, $target_id, $target_parent_id = 0)
    {
        global $wpdb;

        $comment_ids = array_values(array_filter(array_map('intval', $comment_ids), function ($id) {
            return $id > 0;
        }));
        $this->target_post_id = intval($target_id);
        $target_parent_id = intval($target_parent_id);

        if (empty($comment_ids) || $this->target_post_id <= 0) {
            return array('success' => 0, 'failed' => 0, 'message' => '参数无效');
        }

        $target_post = get_post($this->target_post_id);
        if (!$target_post) {
            return array('success' => 0, 'failed' => 0, 'message' => '目标文章不存在');
        }

        $all_ids = $this->expand_with_descendants($comment_ids);

        if (empty($all_ids)) {
            return array('success' => 0, 'failed' => 0, 'message' => '没有可迁移的评论');
        }

        $ids_placeholder = implode(',', array_fill(0, count($all_ids), '%d'));
        $comments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$ids_placeholder})",
                $all_ids
            )
        );

        $to_migrate = array();
        $this->backup_data = array();
        $this->source_post_ids = array();
        $failed = 0;

        foreach ($comments as $comment) {
            if ((int) $comment->comment_post_ID === $this->target_post_id) {
                $failed++;
                continue;
            }
            $to_migrate[] = (int) $comment->comment_ID;
            $this->backup_data[] = array(
                'comment_id' => $comment->comment_ID,
                'original_comment_post_ID' => $comment->comment_post_ID,
                'original_comment_parent' => (int) $comment->comment_parent,
                'original_comment_date' => $comment->comment_date,
                'comment_author' => $comment->comment_author,
                'comment_content' => $comment->comment_content,
            );
            $this->source_post_ids[(int) $comment->comment_post_ID] = true;
        }

        if (empty($to_migrate)) {
            return array('success' => 0, 'failed' => $failed, 'message' => '没有可迁移的评论');
        }

        $total_count = count($to_migrate);

        $log_saved = $this->save_log($total_count);
        if (!$log_saved) {
            return array(
                'success' => 0,
                'failed'  => $failed,
                'message' => '写入迁移日志失败，操作已取消：' . $wpdb->last_error,
            );
        }

        $ids_placeholder = implode(',', array_fill(0, count($to_migrate), '%d'));
        $params = array_merge(array($this->target_post_id), $to_migrate);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_post_ID = %d WHERE comment_ID IN ({$ids_placeholder})",
                $params
            )
        );

        foreach (array_keys($this->source_post_ids) as $source_id) {
            wp_update_comment_count($source_id);
        }
        wp_update_comment_count($this->target_post_id);

        $migrated_set = array_flip($to_migrate);
        foreach ($comment_ids as $root_id) {
            if (isset($migrated_set[$root_id])) {
                $current_parent = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT comment_parent FROM {$wpdb->comments} WHERE comment_ID = %d",
                        $root_id
                    )
                );
                if (!isset($migrated_set[$current_parent])) {
                    $wpdb->update(
                        $wpdb->comments,
                        array('comment_parent' => $target_parent_id),
                        array('comment_ID' => $root_id),
                        array('%d'),
                        array('%d')
                    );
                }
            }
        }

        $this->update_log_data();

        $success = count($to_migrate);
        return array(
            'success' => $success,
            'failed'  => $failed,
            'migrate_key' => $this->migrate_key,
            'message' => "迁移完成：成功 {$success} 条" . ($failed ? "，失败 {$failed} 条" : ''),
        );
    }

    private function expand_with_descendants(array $comment_ids)
    {
        global $wpdb;
        $all = array();
        $visited = array();

        foreach ($comment_ids as $cid) {
            $cid = (int) $cid;
            $all[$cid] = true;
            $visited[$cid] = true;
        }

        $stack = $comment_ids;

        while (!empty($stack)) {
            $parent = (int) array_pop($stack);
            $children = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent = %d",
                    $parent
                )
            );
            foreach ($children as $child_id) {
                $child_id = (int) $child_id;
                if (!isset($visited[$child_id])) {
                    $visited[$child_id] = true;
                    $all[$child_id] = true;
                    $stack[] = $child_id;
                }
            }
        }

        return array_keys($all);
    }

    private function save_log($count)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';

        $source_ids = array_keys($this->source_post_ids);
        $first_source = !empty($source_ids) ? $source_ids[0] : 0;
        $source_title = '';
        $first_post = $first_source ? get_post($first_source) : null;
        if ($first_post) {
            $source_title = $first_post->post_title;
        }

        $target_title = '';
        $target_post = get_post($this->target_post_id);
        if ($target_post) {
            $target_title = $target_post->post_title;
        }

        $comment_data = json_encode($this->backup_data, JSON_UNESCAPED_UNICODE);
        if (false === $comment_data) {
            return array(
                'success' => 0,
                'failed'  => $failed,
                'message' => '序列化备份数据失败：' . json_last_error_msg(),
            );
        }

        $result = $wpdb->insert(
            $table,
            array(
                'migrate_key'    => $this->migrate_key,
                'source_post_id' => $first_source,
                'target_post_id' => $this->target_post_id,
                'comment_count'  => $count,
                'comment_data'   => $comment_data,
                'source_title'   => $source_title,
                'target_title'   => $target_title,
                'is_batch'       => 0,
                'operator_id'    => get_current_user_id(),
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d')
        );

        return false !== $result;
    }

    private function update_log_data()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';
        $comment_data = json_encode($this->backup_data, JSON_UNESCAPED_UNICODE);
        if (false !== $comment_data) {
            $wpdb->update(
                $table,
                array('comment_data' => $comment_data),
                array('migrate_key' => $this->migrate_key),
                array('%s'),
                array('%s')
            );
        }
    }

    public function get_migrate_key()
    {
        return $this->migrate_key;
    }
}
