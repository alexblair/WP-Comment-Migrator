# 评论迁移工具 (WP Comment Migrator)

WordPress 插件，在文章/页面间迁移评论，支持批量映射和回滚。

## 功能

- **一对一迁移** — 将单篇文章的评论迁移到另一篇
- **多对一合并** — 将多篇文章的评论合并到一篇
- **选择性迁移** — 勾选特定评论进行迁移
- **批量映射** — 配置多组映射关系，一次执行
- **回滚支持** — 随时撤销迁移操作
- **全简体中文界面**

## 要求

- WordPress ≥ 4.7
- PHP ≥ 7.0

## 安装

1. 将 `wp-comment-migrator` 目录上传到 `/wp-content/plugins/`
2. 在后台「插件」页面激活「评论迁移工具」
3. 菜单中出现「评论迁移」即可使用

## 结构

| 文件 | 作用 |
|---|---|
| `comment-migrator.php` | 插件入口，加载核心类 |
| `includes/class-install.php` | 激活/升级/卸载，创建自定义表 |
| `includes/class-admin.php` | 管理页面与 AJAX handler |
| `includes/class-migration.php` | 评论迁移核心逻辑 |
| `includes/class-rollback.php` | 回滚逻辑 |
| `includes/class-batch.php` | 批量映射执行 |
| `includes/class-list-table.php` | 评论列表 WP_List_Table |
| `includes/class-history-table.php` | 迁移记录 WP_List_Table |

## 测试

```bash
npm run test:e2e          # Playwright E2E
npm run test:e2e:headed   # 有头模式调试
```

参见 `tests/e2e/.env.example` 配置环境变量。

## License

GPL v2 or later
