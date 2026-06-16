import { test, expect } from './fixtures';

test.describe('评论迁移 - 核心功能', () => {

  test.beforeEach(async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
  });

  test('迁移选项卡应显示评论列表表格', async ({ adminLoginPage }) => {
    await adminLoginPage.navigateToPlugin();
    const table = adminLoginPage.page.locator('.wp-list-table');
    await expect(table).toBeVisible();

    const headers = table.locator('thead th');
    const headerTexts = await headers.allTextContents();
    expect(headerTexts).toEqual(
      expect.arrayContaining([
        expect.stringContaining('评论作者'),
        expect.stringContaining('评论内容'),
        expect.stringContaining('来源文章'),
        expect.stringContaining('状态'),
        expect.stringContaining('日期'),
        expect.stringContaining('操作'),
      ])
    );
  });

  test('评论列表支持按状态筛选', async ({ page, migratePage }) => {
    await migratePage.goto();

    await migratePage.filterByStatus('approved');
    await expect(page).toHaveURL(/comment_status=approved/);

    await migratePage.filterByStatus('pending');
    await expect(page).toHaveURL(/comment_status=pending/);

    await migratePage.filterByStatus('spam');
    await expect(page).toHaveURL(/comment_status=spam/);
  });

  test('应正确显示评论选中计数', async ({ migratePage }) => {
    await migratePage.goto();
    const rows = await migratePage.getCommentRowCount();
    if (rows > 0) {
      await migratePage.selectCommentsByIndices([0]);
      const count = await migratePage.getSelectedCount();
      expect(count).toBe(1);
    }
  });

  test('选中一条评论时应显示父级评论选择器', async ({ migratePage }) => {
    await migratePage.goto();

    const parentSelector = migratePage.page.locator('.cmt-parent-selector');
    await expect(parentSelector).toBeHidden();

    const rows = await migratePage.getCommentRowCount();
    if (rows > 0) {
      await migratePage.selectCommentsByIndices([0]);
      await expect(parentSelector).toBeVisible();

      if (rows > 1) {
        await migratePage.selectCommentsByIndices([0, 1]);
        await expect(parentSelector).toBeHidden();
      }
    }
  });

  test('未选择评论时点击迁移应提示错误', async ({ migratePage }) => {
    await migratePage.goto();

    let alertMessage = '';
    migratePage.page.once('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });

    await migratePage.clickExecuteMigrate();
    expect(alertMessage).toContain('请至少选择一条评论');
  });

  test('选择了评论但未选目标文章时应提示错误', async ({ migratePage }) => {
    await migratePage.goto();

    const rows = await migratePage.getCommentRowCount();
    if (rows > 0) {
      await migratePage.selectCommentsByIndices([0]);

      let alertMessage = '';
      migratePage.page.once('dialog', (dialog) => {
        alertMessage = dialog.message();
        dialog.accept();
      });
      await migratePage.clickExecuteMigrate();
      expect(alertMessage).toContain('请选择目标文章');
    }
  });

  test('选择目标文章后应能加载父级评论', async ({ migratePage }) => {
    await migratePage.goto();

    const selection = migratePage.page.locator('select.cmt-target-post + .select2-container .select2-selection');
    await selection.click();
    const searchField = migratePage.page.locator('.select2-search__field');
    await searchField.fill('Hello');
    await migratePage.page.waitForTimeout(800);
    const option = migratePage.page.locator('.select2-results__option').first();
    if (await option.count() > 0) {
      await option.click();
      await migratePage.page.waitForTimeout(1000);
      const parentSelect = migratePage.page.locator('.cmt-target-parent option');
      const optionCount = await parentSelect.count();
      expect(optionCount).toBeGreaterThanOrEqual(1);
    }
  });
});
