<?php
// includes/class-batch.php
defined('ABSPATH') || die('No direct access.');

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
                    'source'  => $source_id,
                    'target'  => $target_id,
                    'success' => 0,
                    'failed'  => 0,
                    'message' => '参数无效或源目标相同',
                );
                $total_failed++;
                continue;
            }

            $status_map = array(
                'all'      => 'all',
                'approved' => 'approve',
                'pending'  => 'hold',
            );
            $status = isset($status_map[$scope]) ? $status_map[$scope] : 'all';
            $args = array(
                'post_id' => $source_id,
                'status'  => $status,
            );
            $comments = get_comments($args);

            if (empty($comments)) {
                $results[] = array(
                    'source'  => $source_id,
                    'target'  => $target_id,
                    'success' => 0,
                    'failed'  => 0,
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
            'results'       => $results,
            'total_success' => $total_success,
            'total_failed'  => $total_failed,
        );
    }
}
