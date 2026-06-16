import { Page } from '@playwright/test';

async function select2Search(page: Page, selectSelector: string, searchText: string) {
  const selection = page.locator(`${selectSelector} + .select2-container .select2-selection`);
  await selection.click();
  const searchField = page.locator('.select2-search__field');
  await searchField.fill(searchText);
  await page.waitForTimeout(800);
  await page.locator('.select2-results__option').first().click();
  await page.waitForTimeout(300);
}

export class BatchPage {
  constructor(public readonly page: Page) {}

  async goto() {
    await this.page.goto('/wp-admin/admin.php?page=comment-migrator&tab=batch');
    await this.page.waitForSelector('.cmt-batch-wrap');
  }

  async getRowCount(): Promise<number> {
    return this.page.locator('.cmt-batch-row').count();
  }

  async addRow() {
    await this.page.click('#cmt-batch-add-row');
    await this.page.waitForTimeout(500);
  }

  async removeRow(index: number) {
    const removeBtns = this.page.locator('.cmt-batch-remove');
    if (await removeBtns.nth(index).isVisible()) {
      await removeBtns.nth(index).click();
      await this.page.waitForTimeout(300);
    }
  }

  async setSourcePost(rowIndex: number, postTitle: string) {
    const row = this.page.locator('.cmt-batch-row').nth(rowIndex);
    const select = row.locator('select.cmt-batch-source');
    const selectId = await select.getAttribute('data-select2-id');
    const selection = this.page.locator(`[data-select2-id="${selectId}"] + .select2-container .select2-selection`);
    await selection.click();
    const searchField = this.page.locator('.select2-search__field');
    await searchField.fill(postTitle);
    await this.page.waitForTimeout(800);
    await this.page.locator('.select2-results__option').first().click();
    await this.page.waitForTimeout(300);
  }

  async setTargetPost(rowIndex: number, postTitle: string) {
    const row = this.page.locator('.cmt-batch-row').nth(rowIndex);
    const select = row.locator('select.cmt-batch-target');
    const selectId = await select.getAttribute('data-select2-id');
    const selection = this.page.locator(`[data-select2-id="${selectId}"] + .select2-container .select2-selection`);
    await selection.click();
    const searchField = this.page.locator('.select2-search__field');
    await searchField.fill(postTitle);
    await this.page.waitForTimeout(800);
    await this.page.locator('.select2-results__option').first().click();
    await this.page.waitForTimeout(300);
  }

  async selectScope(scope: string) {
    await this.page.selectOption('#cmt-batch-scope', scope);
  }

  async clickExecuteBatch() {
    await this.page.locator('#cmt-batch-execute').click();
    await this.page.waitForTimeout(2000);
  }

  async getResultText(): Promise<string> {
    const locator = this.page.locator('#cmt-batch-result');
    await locator.waitFor({ state: 'visible', timeout: 10000 });
    return locator.textContent() || '';
  }

  async importCSV(csvContent: string) {
    const fileInput = this.page.locator('#cmt-csv-file');
    await fileInput.setInputFiles({
      name: 'mappings.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(csvContent, 'utf-8'),
    });
    await this.page.locator('#cmt-csv-import').click();
    await this.page.waitForTimeout(2000);
  }

  async getRowSourceValue(rowIndex: number): Promise<string> {
    return this.page.locator('.cmt-batch-source').nth(rowIndex).inputValue();
  }

  async getRowTargetValue(rowIndex: number): Promise<string> {
    return this.page.locator('.cmt-batch-target').nth(rowIndex).inputValue();
  }
}
