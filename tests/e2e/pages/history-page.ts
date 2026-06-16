import { Page } from '@playwright/test';

export class HistoryPage {
  constructor(public readonly page: Page) {}

  async goto() {
    await this.page.goto('/wp-admin/admin.php?page=comment-migrator&tab=history');
    await this.page.waitForSelector('.wp-list-table');
  }

  async getRecordCount(): Promise<number> {
    return this.page.locator('.wp-list-table tbody tr').count();
  }

  async getRecordSummary(index: number): Promise<string> {
    return this.page.locator('.column-summary').nth(index).textContent() || '';
  }

  async getRecordType(index: number): Promise<string> {
    return this.page.locator('.column-type').nth(index).textContent() || '';
  }

  async getRecordCountText(index: number): Promise<string> {
    return this.page.locator('.column-count').nth(index).textContent() || '';
  }

  async filterByType(type: string) {
    await this.page.selectOption('select[name="history_type"]', type);
    await this.page.locator('#filter_action').click();
    await this.page.waitForTimeout(500);
  }

  async clickRollbackSingle(index: number) {
    const rollbackBtns = this.page.locator('.cmt-rollback-single');
    await rollbackBtns.nth(index).click();
  }

  async selectRollbackRecords(indices: number[]) {
    const checkboxes = this.page.locator('input[name="log_ids[]"]');
    for (const idx of indices) {
      await checkboxes.nth(idx).check();
    }
  }

  async clickRollbackSelected() {
    await this.page.locator('.cmt-rollback-selected').click();
  }

  async getRecordTime(index: number): Promise<string> {
    return this.page.locator('.column-time').nth(index).textContent() || '';
  }

  async getRecordOperator(index: number): Promise<string> {
    return this.page.locator('.column-operator').nth(index).textContent() || '';
  }
}
