import { test as base } from '@playwright/test';
import { AdminLoginPage } from './pages/admin-login';
import { MigratePage } from './pages/migrate-page';
import { BatchPage } from './pages/batch-page';
import { HistoryPage } from './pages/history-page';

export type TestFixtures = {
  adminLoginPage: AdminLoginPage;
  migratePage: MigratePage;
  batchPage: BatchPage;
  historyPage: HistoryPage;
};

export const test = base.extend<TestFixtures>({
  adminLoginPage: async ({ page }, use) => {
    await use(new AdminLoginPage(page));
  },
  migratePage: async ({ page }, use) => {
    await use(new MigratePage(page));
  },
  batchPage: async ({ page }, use) => {
    await use(new BatchPage(page));
  },
  historyPage: async ({ page }, use) => {
    await use(new HistoryPage(page));
  },
});

export { expect } from '@playwright/test';
