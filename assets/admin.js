jQuery(function ($) {
    function countSelected() {
        return $('.wp-list-table input[name="comment_ids[]"]:checked').length;
    }

    function updateSelectedCount() {
        $('.cmt-selected-count strong').text(countSelected());
        updateParentSelector();
    }

    function updateParentSelector() {
        if (countSelected() === 1) {
            $('.cmt-parent-selector').show();
        } else {
            $('.cmt-parent-selector').hide();
        }
    }

    $(document).on('change', '.wp-list-table input[type=checkbox]', updateSelectedCount);

    function initPostSearch($select) {
        $select.select2({
            ajax: {
                url: cmt_admin.ajax_url,
                dataType: 'json',
                delay: 300,
                data: function (params) {
                    return {
                        action: 'cmt_search_posts',
                        q: params.term,
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
        });
    }

    function loadTargetComments(postId) {
        var $select = $('.cmt-target-parent');
        if (!postId) {
            $select.empty().append('<option value="">顶级层（不回复）</option>');
            return;
        }
        $.get(cmt_admin.ajax_url, {
            action: 'cmt_search_comments',
            post_id: postId,
            nonce: cmt_admin.nonce,
        }, function (data) {
            $select.empty().append('<option value="">顶级层（不回复）</option>');
            $.each(data, function (i, item) {
                $select.append('<option value="' + item.id + '">' + item.text + '</option>');
            });
            $select.trigger('change');
        });
    }

    initPostSearch($('.cmt-target-post'));
    initPostSearch($('.cmt-source-post'));

    $('.cmt-target-post').on('change', function () {
        loadTargetComments($(this).val());
    });

    $('.cmt-target-parent').select2({
        placeholder: '顶级层（不回复）',
        allowClear: true,
        width: '100%',
    });

    $('.cmt-execute-migrate').on('click', function () {
        var commentIds = [];
        $('.wp-list-table input[name="comment_ids[]"]:checked').each(function () {
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
            target_parent_id: $('.cmt-target-parent').val() || 0,
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

    function initBatchRow($row) {
        initPostSearch($row.find('.cmt-batch-source'));
        initPostSearch($row.find('.cmt-batch-target'));
    }

    initBatchRow($('.cmt-batch-row'));

    $('#cmt-batch-add-row').on('click', function () {
        var $row = $('.cmt-batch-row').first().clone();
        $row.find('select').val('').each(function () {
            if ($(this).data('select2')) {
                $(this).select2('destroy');
            }
        });
        $row.find('.cmt-batch-remove').show();
        $('#cmt-batch-rows').append($row);
        initBatchRow($row);
    });

    $(document).on('click', '.cmt-batch-remove', function (e) {
        e.preventDefault();
        if ($('.cmt-batch-row').length > 1) {
            var $row = $(this).closest('.cmt-batch-row');
            $row.find('select').each(function () {
                if ($(this).data('select2')) {
                    $(this).select2('destroy');
                }
            });
            $row.remove();
        } else {
            alert('至少保留一行');
        }
    });

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

            $('.cmt-batch-row').each(function () {
                var $this = $(this);
                $this.find('select').each(function () {
                    if ($(this).data('select2')) {
                        $(this).select2('destroy');
                    }
                });
            });
            var $template = $('.cmt-batch-row').first().clone();
            $('#cmt-batch-rows').empty();

            $.each(lines, function (i, line) {
                line = line.trim();
                if (!line) return;
                var parts = line.split(',');
                if (parts.length < 2) return;
                var sourceId = parseInt(parts[0].trim(), 10);
                var targetId = parseInt(parts[1].trim(), 10);
                if (isNaN(sourceId) || isNaN(targetId)) return;
                pairs.push({ source: sourceId, target: targetId });

                var $row = $template.clone();
                $row.find('select').val('').each(function () {
                    if ($(this).data('select2')) {
                        $(this).select2('destroy');
                    }
                });
                $row.find('.cmt-batch-remove').show();
                $('#cmt-batch-rows').append($row);
                initBatchRow($row);
            });

            if (pairs.length === 0) {
                alert('CSV 解析失败，请检查格式（每行：源文章ID,目标文章ID）');
            } else {
                alert('已解析 ' + pairs.length + ' 对映射关系。请确认下拉框中的文章是否正确后执行。');
            }
        };
        reader.readAsText(file, 'UTF-8');
    });

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
        var scopeLabels = { all: '全部评论', approved: '仅已核准', pending: '仅待审核' };
        var msg = '即将执行 ' + pairs.length + ' 对映射（' + (scopeLabels[scope] || scope) + '），确定执行吗？';

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
                    var srcLabel = '#' + r.source;
                    var tgtLabel = '#' + r.target;
                    html += '<li>' + srcLabel + ' → ' + tgtLabel + '：' + (r.message || '完成') + '</li>';
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

    $('.cmt-rollback-selected').on('click', function () {
        var logIds = [];
        $('.wp-list-table input[name="log_ids[]"]:checked').each(function () {
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
                alert(response.data.message);
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

    $(document).on('click', '.cmt-btn-status', function () {
        var $btn = $(this);
        var $td = $btn.closest('td');
        var commentId = $btn.data('comment-id');
        var status = $btn.data('status');

        $btn.prop('disabled', true);

        $.post(cmt_admin.ajax_url, {
            action: 'cmt_approve_comment',
            comment_id: commentId,
            status: status,
            nonce: cmt_admin.nonce,
        }, function (response) {
            if (response.success) {
                $td.html(response.data.html);
            } else {
                alert(response.data.message || '操作失败');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert('请求失败');
            $btn.prop('disabled', false);
        });
    });
});
