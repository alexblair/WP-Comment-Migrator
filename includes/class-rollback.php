<?php
// includes/class-rollback.php
defined('ABSPATH') || die('No direct access.');

class CMT_Rollback
{
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

                $comment = get_comment($cid);
                if (!$comment) {
                    $failed++;
                    continue;
                }

                $original_parent = isset($item['original_comment_parent']) ? intval($item['original_comment_parent']) : 0;
                wp_update_comment(array(
                    'comment_ID' => $cid,
                    'comment_post_ID' => $original_post_id,
                    'comment_parent' => $original_parent,
                ));

                $affected_posts[$comment->comment_post_ID] = true;
                $affected_posts[$original_post_id] = true;
                $success++;
            }

            $wpdb->delete($table, array('id' => $log->id), array('%d'));
        }

        foreach (array_keys($affected_posts) as $pid) {
            wp_update_comment_count($pid);
        }

        return array(
            'success' => $success,
            'failed'  => $failed,
            'message' => "回滚完成：成功 {$success} 条" . ($failed ? "，失败 {$failed} 条（部分评论可能已被删除）" : ''),
        );
    }

    public function rollback_by_ids(array $log_ids)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmt_migration_logs';
        $ids = array_map('intval', $log_ids);

        if (empty($ids)) {
            return array('success' => 0, 'failed' => 0, 'message' => '参数无效');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $keys = $wpdb->get_col(
            $wpdb->prepare("SELECT DISTINCT migrate_key FROM {$table} WHERE id IN ({$placeholders})", $ids)
        );

        $results = array();
        foreach ($keys as $key) {
            $results[] = $this->rollback($key);
        }

        $total_success = 0;
        $total_failed = 0;
        foreach ($results as $r) {
            $total_success += $r['success'];
            $total_failed += $r['failed'];
        }

        return array(
            'success' => $total_success,
            'failed'  => $total_failed,
            'message' => "批量回滚完成：成功 {$total_success} 条" . ($total_failed ? "，失败 {$total_failed} 条" : ''),
        );
    }
}
