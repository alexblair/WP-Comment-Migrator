import { Page } from '@playwright/test';

const DEFAULT_USER = process.env.WP_ADMIN_USER || 'alexblairDay';
const DEFAULT_PASS = process.env.WP_ADMIN_PASS || '..@94F.._DDD';

export class AdminLoginPage {
  constructor(public readonly page: Page) {}

  async goto() {
    await this.page.goto('/wp-login.php');
  }

  async login(username?: string, password?: string) {
    const user = username || DEFAULT_USER;
    const pass = password || DEFAULT_PASS;
    await this.page.fill('#user_login', user);
    await this.page.fill('#user_pass', pass);
    await this.page.click('#wp-submit');
    await this.page.waitForURL(/wp-admin|wp-login/, { timeout: 15000 });
  }

  async isLoggedIn(): Promise<boolean> {
    return this.page.locator('#wpadminbar').isVisible();
  }

  async logout() {
    await this.page.goto('/wp-login.php?action=logout');
    await this.page.locator('#wp-submit').click();
  }

  async navigateToPlugin() {
    await this.page.goto('/wp-admin/admin.php?page=comment-migrator');
    await this.page.waitForSelector('.cmt-tab-content', { timeout: 15000 });
  }
}
