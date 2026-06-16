import { Page, Locator } from '@playwright/test';

async function select2Search(page: Page, selectSelector: string, searchText: string) {
  const selection = page.locator(`${selectSelector} + .select2-container .select2-selection`);
  await selection.click();
  const searchField = page.locator('.select2-search__field');
  await searchField.fill(searchText);
  await page.waitForTimeout(800);
  await page.locator('.select2-results__option').first().click();
  await page.waitForTimeout(300);
}

export class MigratePage {
  constructor(public readonly page: Page) {}

  async goto() {
    await this.page.goto('/wp-admin/admin.php?page=comment-migrator&tab=migrate');
    await this.page.waitForSelector('.wp-list-table');
  }

  async selectSourcePost(postTitle: string) {
    await select2Search(this.page, 'select.cmt-source-post', postTitle);
  }

  async selectTargetPost(postTitle: string) {
    await select2Search(this.page, 'select.cmt-target-post', postTitle);
  }

  async selectTargetParentComment(commentText: string) {
    await select2Search(this.page, 'select.cmt-target-parent', commentText);
  }

  async selectCommentsByIndices(indices: number[]) {
    const checkboxes = this.page.locator('input[name="comment_ids[]"]');
    for (const idx of indices) {
      await checkboxes.nth(idx).check();
    }
  }

  async selectAllComments() {
    await this.page.locator('#cb-select-all-1').check();
  }

  async getSelectedCount(): Promise<number> {
    const text = await this.page.locator('.cmt-selected-count strong').textContent();
    return parseInt(text || '0', 10);
  }

  async clickExecuteMigrate() {
    await this.page.locator('.cmt-execute-migrate').click();
  }

  async searchComments(keyword: string) {
    const searchInput = this.page.locator('#comment-search-input');
    if (await searchInput.isVisible()) {
      await searchInput.fill(keyword);
      await this.page.locator('#search-submit').click();
      await this.page.waitForTimeout(500);
    }
  }

  async filterByStatus(status: string) {
    await this.page.selectOption('#filter-by-status', status);
    await this.page.locator('#filter_action').click();
    await this.page.waitForTimeout(500);
  }

  async getCommentRowCount(): Promise<number> {
    return this.page.locator('.wp-list-table tbody tr').count();
  }

  async getCommentAuthor(index: number): Promise<string> {
    return this.page.locator('.column-comment_author').nth(index).textContent() || '';
  }

  async waitForMigrationComplete() {
    await this.page.waitForTimeout(2000);
  }

  async dismissDialogIfPresent() {
    this.page.on('dialog', (dialog) => dialog.accept());
  }
}
