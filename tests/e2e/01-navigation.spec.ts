import { test, expect } from './fixtures';

test.describe('Plugin 导航与访问控制', () => {

  test('未登录用户访问插件页面应重定向到登录页', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await expect(page).toHaveURL(/wp-login\.php/);
  });

  test('管理员登录后能正常访问插件主页面', async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
    await adminLoginPage.navigateToPlugin();
    await expect(adminLoginPage.page.locator('h1')).toContainText('评论迁移');
  });

  test('插件页面应包含三个功能选项卡', async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
    await adminLoginPage.navigateToPlugin();

    const tabs = adminLoginPage.page.locator('.nav-tab-wrapper a');
    await expect(tabs).toHaveCount(3);
    await expect(tabs.nth(0)).toContainText('评论迁移');
    await expect(tabs.nth(1)).toContainText('批量映射');
    await expect(tabs.nth(2)).toContainText('迁移历史');
  });

  test('通过 URL 切换选项卡应正确高亮激活的选项卡', async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();

    const tabs = ['migrate', 'batch', 'history'];
    for (const tab of tabs) {
      await adminLoginPage.page.goto(`/wp-admin/admin.php?page=comment-migrator&tab=${tab}`);
      const activeTab = adminLoginPage.page.locator('.nav-tab-active');
      await expect(activeTab).toBeVisible();
      const expectedLabels: Record<string, string> = {
        migrate: '评论迁移',
        batch: '批量映射',
        history: '迁移历史',
      };
      await expect(activeTab).toContainText(expectedLabels[tab]);
    }
  });

  test('非管理员用户应无法访问插件', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await expect(page).toHaveURL(/wp-login\.php/);
  });

  test('插件页面应正确加载 Select2 和样式资源', async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
    await adminLoginPage.navigateToPlugin();

    await expect(adminLoginPage.page.locator('link[href*="select2"]').first()).toBeAttached();
    await expect(adminLoginPage.page.locator('link[href*="admin.css"]').first()).toBeAttached();
    await expect(adminLoginPage.page.locator('script[src*="select2"]').first()).toBeAttached();
    await expect(adminLoginPage.page.locator('script[src*="admin.js"]').first()).toBeAttached();
  });
});
