import { test, expect } from './fixtures';

test.describe('批量映射功能', () => {

  test.beforeEach(async ({ adminLoginPage }) => {
    await adminLoginPage.goto();
    await adminLoginPage.login();
  });

  test('批量映射页面应正确渲染', async ({ batchPage }) => {
    await batchPage.goto();
    await expect(batchPage.page.locator('.cmt-batch-wrap h2')).toContainText('批量映射列表');
    await expect(batchPage.page.locator('.cmt-batch-table')).toBeVisible();
  });

  test('默认应有一行映射配置', async ({ batchPage }) => {
    await batchPage.goto();
    const count = await batchPage.getRowCount();
    expect(count).toBe(1);
  });

  test('点击添加行按钮应增加一行映射配置', async ({ batchPage }) => {
    await batchPage.goto();
    await batchPage.addRow();
    expect(await batchPage.getRowCount()).toBe(2);
    await batchPage.addRow();
    expect(await batchPage.getRowCount()).toBe(3);
  });

  test('移除行时应保留至少一行', async ({ batchPage }) => {
    await batchPage.goto();
    await batchPage.addRow();
    await batchPage.addRow();
    expect(await batchPage.getRowCount()).toBe(3);

    await batchPage.removeRow(1);
    expect(await batchPage.getRowCount()).toBe(2);

    await batchPage.removeRow(0);
    expect(await batchPage.getRowCount()).toBe(1);

    let alertMessage = '';
    batchPage.page.once('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });
    await batchPage.removeRow(0);
    expect(alertMessage).toContain('至少保留一行');
    expect(await batchPage.getRowCount()).toBe(1);
  });

  test('迁移范围下拉框应包含预期的选项', async ({ batchPage }) => {
    await batchPage.goto();
    const options = batchPage.page.locator('#cmt-batch-scope option');
    await expect(options).toHaveCount(3);
    await expect(options.nth(0)).toContainText('全部评论');
    await expect(options.nth(1)).toContainText('仅已核准');
    await expect(options.nth(2)).toContainText('仅待审核');

    await batchPage.selectScope('approved');
    await expect(batchPage.page.locator('#cmt-batch-scope')).toHaveValue('approved');

    await batchPage.selectScope('pending');
    await expect(batchPage.page.locator('#cmt-batch-scope')).toHaveValue('pending');
  });

  test('未配置映射关系时执行应提示错误', async ({ batchPage }) => {
    await batchPage.goto();

    let alertMessage = '';
    batchPage.page.once('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });

    await batchPage.clickExecuteBatch();
    expect(alertMessage).toContain('至少配置一对映射关系');
  });

  test('CSV 导入区域应包含文件上传和解析按钮', async ({ batchPage }) => {
    await batchPage.goto();
    await expect(batchPage.page.locator('#cmt-csv-file')).toBeAttached();
    await expect(batchPage.page.locator('#cmt-csv-import')).toBeAttached();
  });

  test('CSV 导入空文件应提示解析失败', async ({ batchPage }) => {
    await batchPage.goto();

    let alertMessage = '';
    batchPage.page.on('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });

    const fileInput = batchPage.page.locator('#cmt-csv-file');
    await fileInput.setInputFiles({
      name: 'empty.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('', 'utf-8'),
    });
    await batchPage.page.locator('#cmt-csv-import').click();
    await batchPage.page.waitForTimeout(1000);
    expect(alertMessage).toContain('CSV 解析失败');
  });

  test('CSV 导入有效数据应正确生成映射行', async ({ batchPage }) => {
    await batchPage.goto();

    let alertMessage = '';
    batchPage.page.once('dialog', (dialog) => {
      alertMessage = dialog.message();
      dialog.accept();
    });

    const fileInput = batchPage.page.locator('#cmt-csv-file');
    await fileInput.setInputFiles({
      name: 'mappings.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('1,2\n3,4\n5,6', 'utf-8'),
    });
    await batchPage.page.locator('#cmt-csv-import').click();
    await batchPage.page.waitForTimeout(1500);

    expect(alertMessage).toContain('已解析');
    expect(alertMessage).toContain('3 对');

    const rowCount = await batchPage.getRowCount();
    expect(rowCount).toBe(3);

    const removeBtns = batchPage.page.locator('.cmt-batch-remove');
    await expect(removeBtns.first()).toBeAttached();
  });
});
