import { test, expect } from './fixtures';

test.describe('迁移历史与回滚功能', () => {

  test.beforeEach(async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
  });

  test('历史页面应正确展示迁移记录列表', async ({ historyPage }) => {
    await historyPage.goto();
    await expect(historyPage.page.locator('.wp-list-table')).toBeVisible();
  });

  test('历史记录应包含正确的列头', async ({ historyPage }) => {
    await historyPage.goto();
    const headers = historyPage.page.locator('.wp-list-table thead th');
    const headerTexts = await headers.allTextContents();
    expect(headerTexts).toEqual(
      expect.arrayContaining([
        expect.stringContaining('执行时间'),
        expect.stringContaining('操作人'),
        expect.stringContaining('迁移摘要'),
        expect.stringContaining('评论数'),
        expect.stringContaining('类型'),
        expect.stringContaining('操作'),
      ])
    );
  });

  test('历史记录应能按类型筛选', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator&tab=history&history_type=single');
    await expect(page.locator('select[name="history_type"]')).toHaveValue('single');

    await page.goto('/wp-admin/admin.php?page=comment-migrator&tab=history&history_type=batch');
    await expect(page.locator('select[name="history_type"]')).toHaveValue('batch');

    await page.goto('/wp-admin/admin.php?page=comment-migrator&tab=history');
    await expect(page.locator('select[name="history_type"]')).toHaveValue('');
  });

  test('每条记录应有回滚按钮', async ({ historyPage }) => {
    await historyPage.goto();
    const count = await historyPage.getRecordCount();
    if (count > 0) {
      const rollbackBtns = historyPage.page.locator('.cmt-rollback-single');
      await expect(rollbackBtns.first()).toBeVisible();
    }
  });

  test('每条记录应包含复选框', async ({ historyPage }) => {
    await historyPage.goto();
    const count = await historyPage.getRecordCount();
    if (count > 0) {
      const checkboxes = historyPage.page.locator('input[name="log_ids[]"]');
      for (let i = 0; i < Math.min(count, 1); i++) {
        await expect(checkboxes.nth(i)).toBeVisible();
      }
    }
  });

  test('未选择记录时点击回滚所选应提示错误', async ({ historyPage }) => {
    await historyPage.goto();

    let alertMessage = '';
    historyPage.page.once('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });
    await historyPage.clickRollbackSelected();
    expect(alertMessage).toContain('请选择要回滚的记录');
  });

  test('设置区域应能保存"保留数据表"选项', async ({ historyPage }) => {
    await historyPage.goto();
    const checkbox = historyPage.page.locator('input[name="cmt_keep_tables"]');
    await expect(checkbox).toBeVisible();

    await checkbox.check();
    await historyPage.page.locator('input[name="cmt_save_settings"]').click();
    await expect(historyPage.page.locator('.notice-success')).toContainText('设置已保存');
  });

  test('"保留数据表"设置应能取消勾选', async ({ historyPage }) => {
    await historyPage.goto();
    const checkbox = historyPage.page.locator('input[name="cmt_keep_tables"]');

    await checkbox.uncheck();
    await historyPage.page.locator('input[name="cmt_save_settings"]').click();
    await expect(historyPage.page.locator('.notice-success')).toContainText('设置已保存');
  });
});
