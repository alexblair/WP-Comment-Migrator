# WP Comment Migrator（评论迁移工具）

WordPress 插件，在文章/页面间迁移评论，支持批量映射和回滚。

## 关键命令

```bash
npm run test:e2e        # Playwright E2E 测试
npm run test:e2e:headed # 有头模式调试
npm run test:e2e:report # 查看 HTML 报告
```

环境变量见 `.env.example`：`WP_BASE_URL`、`WP_ADMIN_USER`、`WP_ADMIN_PASS`、`CHROME_PATH`。

## 架构

- 主入口 `comment-migrator.php` 线性加载 `includes/class-*.php`
- 无命名空间/autoloader，类前缀 `CMT_`，常量前缀 `CMT_MIGRATOR_`
- 自定义表 `{$wpdb->prefix}cmt_migration_logs`，用 `dbDelta` 创建
- 无 PHP 单元测试，仅 Playwright E2E（6 个 spec，4 个 page object）

## 硬约束

- **禁止修改本项目目录（`./`）之外的任何文件**——`../../../` 是生产 WordPress
- **E2E 测试时禁止对 MySQL 写操作**（连接的是生产库）
- PHP 7.3.10 兼容：禁止使用箭头函数、`??=`、`match`、命名参数等 7.3 不支持的特性
- 所有代码必须有**详细且完整的简体中文注释**（函数、类、代码块级别）
- WordPress 规范：hook、sanitization、escaping、nonce、capability 检查

## 安全模式

- 所有 AJAX handler 先 `check_ajax_referer('cmt_migrator_nonce', 'nonce')`，再 `current_user_can('manage_options')`
- 前端通过 `wp_localize_script()` 注入 `cmt_admin` 对象（含 AJAX URL 和 nonce）
- 所有 `$_GET`/`$_POST` 输入经过 `sanitize_*` + `wp_unslash` + `intval` 等类型转换

## 测试注意事项

- E2E 连接生产 WordPress 环境，`tests/e2e/pages/admin-login.ts` 硬编码了默认凭据
- 写测试时避免实际迁移/回滚（会修改生产数据），只测 UI 渲染和验证逻辑
- Playwright 配置位于 `tests/e2e/playwright.config.ts`，串行运行（workers:1）
