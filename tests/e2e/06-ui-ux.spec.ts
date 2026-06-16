import { test, expect } from './fixtures';

test.describe('UI/UX 行为验证', () => {

  test.beforeEach(async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
    await adminLoginPage.navigateToPlugin();
  });

  test('页面标题应显示"评论迁移"', async ({ page }) => {
    await expect(page.locator('.wrap h1')).toContainText('评论迁移');
  });

  test('多次选中/取消选中应正确更新计数', async ({ migratePage }) => {
    await migratePage.goto();
    const rows = await migratePage.getCommentRowCount();
    if (rows >= 2) {
      await migratePage.selectCommentsByIndices([0]);
      expect(await migratePage.getSelectedCount()).toBe(1);

      await migratePage.selectCommentsByIndices([1]);
      expect(await migratePage.getSelectedCount()).toBe(2);

      const cb = migratePage.page.locator('input[name="comment_ids[]"]').nth(0);
      await cb.uncheck();
      expect(await migratePage.getSelectedCount()).toBe(1);
    }
  });

  test('未选择父级评论时默认值为顶级层', async ({ migratePage }) => {
    await migratePage.goto();
    await expect(migratePage.page.locator('select.cmt-target-parent')).toHaveValue('');
  });

  test('Select2 文章下拉应能打开搜索框', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator&tab=migrate');
    await page.waitForSelector('.wp-list-table');

    const selection = page.locator('select.cmt-target-post + .select2-container .select2-selection');
    await selection.click();
    const searchField = page.locator('.select2-search__field');
    await expect(searchField).toBeVisible({ timeout: 5000 });
  });
});
