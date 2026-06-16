import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 1,
  workers: 1,
  reporter: [
    ['html', { outputFolder: '../../reports' }],
    ['list'],
  ],
  timeout: 90000,
  expect: {
    timeout: 15000,
  },
  use: {
    baseURL: process.env.WP_BASE_URL || 'https://alexblair.synology.me:999',
    navigationTimeout: 30000,
    actionTimeout: 15000,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        locale: 'zh-CN',
        launchOptions: {
          executablePath: process.env.CHROME_PATH || '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
          args: ['--headless', '--no-sandbox', '--disable-gpu'],
        },
      },
    },
  ],
});
