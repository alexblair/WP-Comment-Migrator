import { test, expect } from './fixtures';

test.describe('AJAX 端点安全验证', () => {

  test.beforeEach(async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
  });

  test('前端 localized 数据应正确传递 AJAX URL 和 nonce', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await page.waitForSelector('.cmt-tab-content');
    const data = await page.evaluate(() => (window as any).cmt_admin);
    expect(data).toHaveProperty('ajax_url');
    expect(data).toHaveProperty('nonce');
    expect(data.ajax_url).toContain('admin-ajax.php');
    expect(typeof data.nonce).toBe('string');
    expect(data.nonce.length).toBeGreaterThan(0);
  });

  test('缺少 nonce 的 AJAX 请求应被拒绝', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await page.waitForSelector('.cmt-tab-content');

    const response = await page.evaluate(async () => {
      try {
        const res = await fetch((window as any).cmt_admin.ajax_url + '?action=cmt_search_posts&q=hello', {
          credentials: 'same-origin',
        });
        return await res.text();
      } catch {
        return 'error';
      }
    });
    expect(response).toContain('-1');
  });

  test('无效 nonce 的 AJAX 请求应返回 -1', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await page.waitForSelector('.cmt-tab-content');

    const response = await page.evaluate(async () => {
      try {
        const res = await fetch((window as any).cmt_admin.ajax_url + '?action=cmt_search_posts&q=hello&nonce=invalid', {
          credentials: 'same-origin',
        });
        return await res.text();
      } catch {
        return 'error';
      }
    });
    expect(response).toContain('-1');
  });

  test('搜索 API 应能处理特殊字符', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=comment-migrator');
    await page.waitForSelector('.cmt-tab-content');

    const response = await page.evaluate(async () => {
      const res = await fetch((window as any).cmt_admin.ajax_url
        + '?action=cmt_search_posts&q=<script>alert(1)</script>&nonce='
        + (window as any).cmt_admin.nonce);
      return res.json();
    });
    expect(Array.isArray(response)).toBe(true);
  });
});
